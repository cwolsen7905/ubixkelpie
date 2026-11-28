<?php

declare(strict_types=1);

namespace Kelpie\Service\Triage;

use Kelpie\DataTransferObject\CodeExcerpt;
use Kelpie\DataTransferObject\FixProposal;
use Kelpie\DataTransferObject\Thread;
use Kelpie\DataTransferObject\TriageVerdict;
use Kelpie\Enum\TriageClass;
use Kelpie\Enum\TriageConfidence;
use Kelpie\OpenRouter\OpenRouterClient;
use Kelpie\Policy\SafetyPolicy;
use Kelpie\Policy\TriageConfidencePolicy;
use Kelpie\Prompt\TriagePrompt;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Exception\DtoException;
use Ubix\Service\JsonService;

/**
 * Classifies one review thread: prompt, model, parse, safety policy
 *
 * A `fix` the model is not sure enough of is `needs_human` (`TriageConfidencePolicy`). Anything the model gets wrong (no answer, not JSON, an unknown class) is `needs_human`, never a guess.
 * The model is the project's `triage_model`, else `KELPIE_TRIAGE_MODEL`, default `deepseek/deepseek-v4.1-flash`
 * (`TriageLlmService`), never from the family that wrote the review (`LlmFamilyService`) and never a tier that
 * trains on its prompts on a project not known to be public: either way the verdict is `needs_human`, not a guess.
 *
 * @see \Kelpie\Tests\Service\Triage\TriageServiceTest PHPUnit test case
 */
final class TriageService
{
    // An OpenRouter id that must exist there: an unknown id fails every call, which reads as "the model gave no
    // answer". google/gemini-3-flash never existed; checked against openrouter.ai/api/v1/models on 2026-11-20.
    private const string DEFAULT_MODEL = 'deepseek/deepseek-v4.1-flash';

    // Room for a reasoning model's thinking: Gemini 3.x Flash spends reasoning tokens inside max_tokens, so the
    // client's default of 400 can end with empty content, which also reads as "the model gave no answer".
    private const int MAX_TOKENS = 2000;

    private const int MAX_REASON_LENGTH = 500;

    /**
     * Constructor
     *
     * @param Logger                  $logger           Logger
     * @param OpenRouterClient        $openRouterClient The model client
     * @param TriagePrompt            $triagePrompt     The prompt
     * @param SafetyPolicy            $safetyPolicy     The hard limits
     * @param JsonService             $jsonService      JSON service
     * @param LlmFamilyService        $families         Keeps the model out of the review's family
     * @param TriageLlmService        $models           Picks the model per project, keeps training tiers off private code
     * @param ?TriageConfidencePolicy $confidencePolicy The confidence a fix needs; the default threshold when null
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly OpenRouterClient $openRouterClient,
        private readonly TriagePrompt $triagePrompt,
        private readonly SafetyPolicy $safetyPolicy,
        private readonly JsonService $jsonService,
        private readonly LlmFamilyService $families,
        private readonly TriageLlmService $models,
        private readonly ?TriageConfidencePolicy $confidencePolicy = null,
    ) {
    }

    /**
     * Classify a thread
     *
     * @param Thread       $thread   The thread
     * @param ?FixProposal $proposal The proposed fix, when one exists (checked by the safety policy)
     * @param string       $steering What the author or a maintainer asked for with "Triage:", empty when nothing
     * @param ?string      $project  The GitLab project ID or path with namespace; null when not known, which keeps
     *                               a model that trains on its prompts from being called
     * @param ?CodeExcerpt $code     The code the thread is about at the MR's head (CodeContextService), when read
     *
     * @return TriageVerdict
     */
    public function triage(Thread $thread, ?FixProposal $proposal = null, string $steering = '', ?string $project = null, ?CodeExcerpt $code = null): TriageVerdict
    {
        $choice = $this->models->choose($project, self::DEFAULT_MODEL);
        $model  = $choice->model;

        if ($choice->refusal !== '') {
            return new TriageVerdict(TriageClass::NeedsHuman, $choice->refusal);
        }

        if ($this->families->sameAsReview($model, $choice->openSource)) {
            $this->logger->warning('Triage: model is from the review\'s family', ['model' => $model]);

            return new TriageVerdict(TriageClass::NeedsHuman, 'Triage refused: its model is from the same family as the review\'s, so it would not be a second opinion.');
        }

        $system     = $this->triagePrompt->system();
        $user       = $this->triagePrompt->forThread($thread, $steering, $code);
        $completion = $this->openRouterClient->chat($model, $system, $user, self::MAX_TOKENS);

        // The open-source model failed outright (a 404 when the account's data policy excludes its tier): ask the regular one once
        if ($completion === null && $choice->fallback !== '' && !$this->families->sameAsReview($choice->fallback, $choice->openSource)) {
            $this->logger->warning('Triage: model gave no answer, asking the fallback', ['fallback' => $choice->fallback, 'model' => $model]);
            $completion = $this->openRouterClient->chat($choice->fallback, $system, $user, self::MAX_TOKENS);
        }
        $answer = $completion?->text;

        if ($answer === null) {
            return new TriageVerdict(TriageClass::NeedsHuman, 'Triage unavailable: the model gave no answer.');
        }

        $verdict = $this->parse($answer);
        if ($verdict === null) {
            $this->logger->warning('Triage: unparseable model answer', ['discussion_id' => $thread->discussionId]);

            return new TriageVerdict(TriageClass::NeedsHuman, 'Triage could not read the model\'s answer.');
        }

        return $this->safetyPolicy->apply(($this->confidencePolicy ?? new TriageConfidencePolicy($this->logger))->apply(new TriageVerdict($verdict->class, $verdict->reason, $completion, $verdict->confidence)), $proposal);
    }

    /**
     * Read the model's JSON answer
     *
     * @param string $answer The raw text (a JSON object, possibly inside a code fence)
     *
     * @return ?TriageVerdict Null when it is not an object with a known class
     */
    private function parse(string $answer): ?TriageVerdict
    {
        $start = strpos($answer, '{');
        $end   = strrpos($answer, '}');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        try {
            $decoded = $this->jsonService->decode(substr($answer, $start, $end - $start + 1));
        } catch (DtoException $e) {
            $this->logger->debug('Triage: model answer is not JSON', ['error' => $e->getMessage()]);

            return null;
        }

        $class  = is_string($decoded['class'] ?? null) ? TriageClass::tryFrom($decoded['class']) : null;
        $reason = is_string($decoded['reason'] ?? null) ? trim($decoded['reason']) : '';

        if ($class === null) {
            return null;
        }

        return new TriageVerdict($class, mb_substr($reason !== '' ? $reason : 'No reason given.', 0, self::MAX_REASON_LENGTH), null, TriageConfidence::fromAnswer($decoded['confidence'] ?? null));
    }
}
