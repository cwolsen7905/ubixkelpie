<?php

declare(strict_types=1);

namespace Kelpie\Service\Triage;

use Kelpie\DataTransferObject\FixProposal;
use Kelpie\DataTransferObject\Thread;
use Kelpie\DataTransferObject\TriageVerdict;
use Kelpie\Enum\TriageClass;
use Kelpie\OpenRouter\OpenRouterClient;
use Kelpie\Policy\SafetyPolicy;
use Kelpie\Prompt\TriagePrompt;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Exception\DtoException;
use Ubix\Service\JsonService;

/**
 * Classifies one review thread: prompt, model, parse, safety policy
 *
 * Anything the model gets wrong (no answer, not JSON, an unknown class) is `needs_human`, never a guess.
 * The model is `KELPIE_TRIAGE_MODEL`, default `google/gemini-3-flash` and never from the family that wrote the review
 * (`LlmFamilyService`): then the verdict is `needs_human`, not a guess.
 *
 * @see \Kelpie\Tests\Service\Triage\TriageServiceTest PHPUnit test case
 */
final class TriageService
{
    private const string DEFAULT_MODEL = 'google/gemini-3-flash';

    private const int MAX_REASON_LENGTH = 500;

    /**
     * Constructor
     *
     * @param Logger           $logger           Logger
     * @param OpenRouterClient $openRouterClient The model client
     * @param TriagePrompt     $triagePrompt     The prompt
     * @param SafetyPolicy     $safetyPolicy     The hard limits
     * @param JsonService      $jsonService      JSON service
     * @param LlmFamilyService $families         Keeps the model out of the review's family
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly OpenRouterClient $openRouterClient,
        private readonly TriagePrompt $triagePrompt,
        private readonly SafetyPolicy $safetyPolicy,
        private readonly JsonService $jsonService,
        private readonly LlmFamilyService $families,
    ) {
    }

    /**
     * Classify a thread
     *
     * @param Thread       $thread   The thread
     * @param ?FixProposal $proposal The proposed fix, when one exists (checked by the safety policy)
     *
     * @return TriageVerdict
     */
    public function triage(Thread $thread, ?FixProposal $proposal = null): TriageVerdict
    {
        $model = (string) getenv('KELPIE_TRIAGE_MODEL') ?: self::DEFAULT_MODEL;

        if ($this->families->sameAsReview($model)) {
            $this->logger->warning('Triage: model is from the review\'s family', ['model' => $model]);

            return new TriageVerdict(TriageClass::NeedsHuman, 'Triage refused: its model is from the same family as the review\'s, so it would not be a second opinion.');
        }

        $completion = $this->openRouterClient->chat($model, $this->triagePrompt->system(), $this->triagePrompt->forThread($thread));
        $answer     = $completion?->text;

        if ($answer === null) {
            return new TriageVerdict(TriageClass::NeedsHuman, 'Triage unavailable: the model gave no answer.');
        }

        $verdict = $this->parse($answer);
        if ($verdict === null) {
            $this->logger->warning('Triage: unparseable model answer', ['discussion_id' => $thread->discussionId]);

            return new TriageVerdict(TriageClass::NeedsHuman, 'Triage could not read the model\'s answer.');
        }

        return $this->safetyPolicy->apply(new TriageVerdict($verdict->class, $verdict->reason, $completion), $proposal);
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

        return new TriageVerdict($class, mb_substr($reason !== '' ? $reason : 'No reason given.', 0, self::MAX_REASON_LENGTH));
    }
}
