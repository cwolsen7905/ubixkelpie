<?php

declare(strict_types=1);

namespace Kelpie\Service\Triage;

use Kelpie\Autofix\Attribution;
use Kelpie\DataTransferObject\IssueDetails;
use Kelpie\DataTransferObject\IssueTriageResult;
use Kelpie\DataTransferObject\IssueTriageVerdict;
use Kelpie\Enum\IssueTriageClass;
use Kelpie\Enum\IssueTriageOutcome;
use Kelpie\Enum\TriageConfidence;
use Kelpie\GitLab\IssueClient;
use Kelpie\OpenRouter\OpenRouterClient;
use Kelpie\Policy\TriageConfidencePolicy;
use Kelpie\Prompt\IssueTriagePrompt;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Exception\DtoException;
use Ubix\Service\JsonService;

/**
 * Triages one issue: one comment and one `kelpie::*` label, exactly once
 *
 * Idempotent by the comment itself. The comment carries a hidden marker, and a note by the bot
 * with that marker means the issue is already triaged, so a retry or a replay posts no second
 * comment. A crash between the comment and the label is repaired by the next run, which finds
 * the marker, reads the class from it and adds the label only. Triage never changes code, and
 * a failed model call posts nothing: the caller retries.
 *
 * A `fix` the model is not sure enough of is `needs_human` (`TriageConfidencePolicy`). When the model cannot tell what the
 * issue asks for it is `needs_context`: a short comment and the `kelpie::needs-context` label. A later run on such an issue
 * (the person replied) triages it again, and the newest comment's class wins. The old label stays: `IssueClient` cannot remove one.
 *
 * @see \Kelpie\Tests\Service\Triage\IssueTriageServiceTest PHPUnit test case
 */
final class IssueTriageService
{
    public const string SKIP_LABEL = 'kelpie:skip';

    private const string DEFAULT_MODEL = 'anthropic/claude-haiku-5.5';

    private const int MAX_REASON_LENGTH = 500;

    /**
     * Constructor
     *
     * @param Logger                  $logger            Logger
     * @param IssueClient             $issueClient       The GitLab issue client
     * @param OpenRouterClient        $openRouterClient  The model client
     * @param IssueTriagePrompt       $prompt            The issue prompt
     * @param JsonService             $jsonService       JSON service
     * @param UsageStampService       $usageStampService The usage footer
     * @param TriageLlmService        $models            Picks the model per project, keeps training tiers off private code
     * @param string                  $botUsername       The bot's username; only its notes count as an earlier triage
     * @param ?TriageConfidencePolicy $confidencePolicy  The confidence a fix needs; the default threshold when null
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly IssueClient $issueClient,
        private readonly OpenRouterClient $openRouterClient,
        private readonly IssueTriagePrompt $prompt,
        private readonly JsonService $jsonService,
        private readonly UsageStampService $usageStampService,
        private readonly TriageLlmService $models,
        private readonly string $botUsername = 'kelpie-bot',
        private readonly ?TriageConfidencePolicy $confidencePolicy = null,
    ) {
    }

    /**
     * Triage one issue
     *
     * @param string $project The project path with namespace
     * @param int    $iid     The issue number in the project
     * @param string $reply   A person's reply to an earlier needs-context comment, empty when there is none; it is fenced as data like the issue
     *
     * @return IssueTriageResult
     */
    public function triage(string $project, int $iid, string $reply = ''): IssueTriageResult
    {
        $issue = $this->issueClient->issue($project, $iid);
        if ($issue === null) {
            return new IssueTriageResult(IssueTriageOutcome::Failed, detail: 'issue could not be read');
        }

        $skip = $this->skipReason($issue);
        if ($skip !== null) {
            $this->logger->info('Issue triage: skipped', ['issue_iid' => $iid, 'project_path' => $project, 'reason' => $skip]);

            return new IssueTriageResult(IssueTriageOutcome::Skipped, detail: $skip);
        }

        $notes = $this->issueClient->notesBy($project, $iid, $this->botUsername);
        if ($notes === null) {
            return new IssueTriageResult(IssueTriageOutcome::Failed, detail: 'notes could not be read');
        }

        $class = $this->earlierClass($notes, $iid);
        if ($class === IssueTriageClass::NeedsContext && trim($reply) !== '') {
            // Asked for context and a person answered, so triage again, even when a crash left the label off: the new
            // verdict's comment is the newest marker from then on, and its label is added below
            $class = null;
        }
        if ($class === null) {
            $choice = $this->models->choose($project, self::DEFAULT_MODEL);
            if ($choice->refusal !== '') {
                $this->logger->warning('Issue triage: skipped, model refused', ['issue_iid' => $iid, 'project_path' => $project, 'reason' => $choice->refusal]);

                return new IssueTriageResult(IssueTriageOutcome::Skipped, detail: $choice->refusal);
            }

            $verdict = $this->classify($issue, $choice->model, $reply);
            if ($verdict === null && $choice->fallback !== '') {
                $this->logger->warning('Issue triage: model gave no answer, asking the fallback', ['fallback' => $choice->fallback, 'model' => $choice->model]);
                $verdict = $this->classify($issue, $choice->fallback, $reply);
            }
            if ($verdict === null) {
                return new IssueTriageResult(IssueTriageOutcome::Failed, detail: 'no usable model answer');
            }

            if (!$this->issueClient->comment($project, $iid, $this->commentBody($verdict, $iid))) {
                return new IssueTriageResult(IssueTriageOutcome::Failed, detail: 'comment could not be posted');
            }
            $class = $verdict->class;
        }

        if (!$this->issueClient->addLabel($project, $iid, $class->label())) {
            return new IssueTriageResult(IssueTriageOutcome::Failed, detail: 'label could not be added');
        }

        $this->logger->info('Issue triage: done', ['class' => $class->value, 'issue_iid' => $iid, 'project_path' => $project]);

        return new IssueTriageResult(IssueTriageOutcome::Triaged, $class);
    }

    /**
     * Why this issue gets no triage, or null when it does
     *
     * @param IssueDetails $issue The issue
     *
     * @return ?string
     */
    private function skipReason(IssueDetails $issue): ?string
    {
        return match (true) {
            $issue->confidential                              => 'confidential issue',
            in_array(self::SKIP_LABEL, $issue->labels, true)  => 'opted out with ' . self::SKIP_LABEL,
            $issue->author === $this->botUsername             => 'opened by the bot',
            default                                           => null,
        };
    }

    /**
     * The class of the newest triage comment on this issue
     *
     * @param string[] $notes The bot's notes on the issue
     * @param int      $iid   The issue number
     *
     * @return ?IssueTriageClass
     */
    private function earlierClass(array $notes, int $iid): ?IssueTriageClass
    {
        $class = null;
        foreach ($notes as $body) {
            if (preg_match('/<!-- kelpie:triage issue=' . $iid . ' class=([a-z_]+) -->/', $body, $m) === 1) {
                // The newest known class wins: a later triage replaces an earlier one, and an unknown class is skipped so it never wipes a valid one
                $class = IssueTriageClass::tryFrom($m[1]) ?? $class;
            }
        }

        return $class;
    }

    /**
     * @param IssueDetails $issue The issue
     * @param string       $model The model to ask (`TriageLlmService`)
     * @param string       $reply A person's reply to a needs-context comment, empty when none
     *
     * @return ?IssueTriageVerdict Null when the model gave nothing usable
     */
    private function classify(IssueDetails $issue, string $model, string $reply): ?IssueTriageVerdict
    {
        $completion = $this->openRouterClient->chat($model, $this->prompt->system(), $this->prompt->forIssue($issue, $reply));
        $answer     = $completion?->text;
        if ($answer === null) {
            return null;
        }

        $start = strpos($answer, '{');
        $end   = strrpos($answer, '}');
        if ($start === false || $end === false || $end < $start) {
            $this->logger->warning('Issue triage: unparseable model answer');

            return null;
        }

        try {
            $decoded = $this->jsonService->decode(substr($answer, $start, $end - $start + 1));
        } catch (DtoException $e) {
            $this->logger->warning('Issue triage: model answer is not JSON', ['error' => $e->getMessage()]);

            return null;
        }

        $class  = is_string($decoded['class'] ?? null) ? IssueTriageClass::tryFrom($decoded['class']) : null;
        $reason = is_string($decoded['reason'] ?? null) ? trim($decoded['reason']) : '';
        if ($class === null) {
            $this->logger->warning('Issue triage: model answer has no known class');

            return null;
        }

        return ($this->confidencePolicy ?? new TriageConfidencePolicy($this->logger))->applyToIssue(new IssueTriageVerdict($class, $this->cleanReason($reason), $completion, TriageConfidence::fromAnswer($decoded['confidence'] ?? null)));
    }

    /**
     * Make the model's reason safe to post: capped, no comment markers, no pings
     *
     * @param string $reason The raw reason
     *
     * @return string
     */
    private function cleanReason(string $reason): string
    {
        $reason = str_replace(['<!--', '-->', '@'], ['', '', '@' . "\u{200B}"], $reason);
        $reason = trim(mb_substr($reason, 0, self::MAX_REASON_LENGTH));

        return $reason !== '' ? $reason : 'No reason given.';
    }

    /**
     * The comment text
     *
     * @param IssueTriageVerdict $verdict The verdict
     * @param int                $iid     The issue number
     *
     * @return string
     */
    private function commentBody(IssueTriageVerdict $verdict, int $iid): string
    {
        $optOut = 'Add the `' . self::SKIP_LABEL . '` label to stop Kelpie on this issue.';
        $note   = match ($verdict->class) {
            IssueTriageClass::Fix          => 'Kelpie could attempt this. Fixing is not switched on yet, so Kelpie has not changed any code. ' . $optOut,
            IssueTriageClass::NeedsContext => 'Reply on this issue with what is missing and Kelpie will look at it again. Kelpie has not changed any code. ' . $optOut,
            default                        => 'Kelpie has not changed any code. ' . $optOut,
        };

        return sprintf(
            "**Kelpie triage: %s**\n\n%s\n\n%s\n\n<!-- kelpie:triage issue=%d class=%s -->",
            $verdict->class->heading(),
            $verdict->reason,
            $this->usageStampService->append($note, $verdict->completion, Attribution::Triage),
            $iid,
            $verdict->class->value,
        );
    }
}
