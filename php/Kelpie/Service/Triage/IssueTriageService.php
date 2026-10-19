<?php

declare(strict_types=1);

namespace Kelpie\Service\Triage;

use Kelpie\DataTransferObject\IssueDetails;
use Kelpie\DataTransferObject\IssueTriageResult;
use Kelpie\DataTransferObject\IssueTriageVerdict;
use Kelpie\Enum\IssueTriageClass;
use Kelpie\Enum\IssueTriageOutcome;
use Kelpie\GitLab\IssueClient;
use Kelpie\OpenRouter\OpenRouterClient;
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
     * @param Logger            $logger           Logger
     * @param IssueClient       $issueClient      The GitLab issue client
     * @param OpenRouterClient  $openRouterClient The model client
     * @param IssueTriagePrompt $prompt           The issue prompt
     * @param JsonService       $jsonService      JSON service
     * @param string            $botUsername      The bot's username; only its notes count as an earlier triage
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly IssueClient $issueClient,
        private readonly OpenRouterClient $openRouterClient,
        private readonly IssueTriagePrompt $prompt,
        private readonly JsonService $jsonService,
        private readonly string $botUsername = 'kelpie-bot',
    ) {
    }

    /**
     * Triage one issue
     *
     * @param string $project The project path with namespace
     * @param int    $iid     The issue number in the project
     *
     * @return IssueTriageResult
     */
    public function triage(string $project, int $iid): IssueTriageResult
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
        if ($class === null) {
            $verdict = $this->classify($issue);
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
     * The class of an earlier triage comment on this issue
     *
     * @param string[] $notes The bot's notes on the issue
     * @param int      $iid   The issue number
     *
     * @return ?IssueTriageClass
     */
    private function earlierClass(array $notes, int $iid): ?IssueTriageClass
    {
        foreach ($notes as $body) {
            if (preg_match('/<!-- kelpie:triage issue=' . $iid . ' class=([a-z_]+) -->/', $body, $m) === 1) {
                return IssueTriageClass::tryFrom($m[1]);
            }
        }

        return null;
    }

    /**
     * @param IssueDetails $issue The issue
     *
     * @return ?IssueTriageVerdict Null when the model gave nothing usable
     */
    private function classify(IssueDetails $issue): ?IssueTriageVerdict
    {
        $model  = (string) getenv('KELPIE_TRIAGE_MODEL') ?: self::DEFAULT_MODEL;
        $answer = $this->openRouterClient->complete($model, $this->prompt->system(), $this->prompt->forIssue($issue));
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

        return new IssueTriageVerdict($class, $this->cleanReason($reason));
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
        $note   = $verdict->class === IssueTriageClass::Fix ? 'Kelpie could attempt this. Fixing is not switched on yet, so Kelpie has not changed any code. ' . $optOut : 'Kelpie has not changed any code. ' . $optOut;

        return sprintf(
            "**Kelpie triage: %s**\n\n%s\n\n%s\n\n<!-- kelpie:triage issue=%d class=%s -->",
            $verdict->class->heading(),
            $verdict->reason,
            $note,
            $iid,
            $verdict->class->value,
        );
    }
}
