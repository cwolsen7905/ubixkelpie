<?php

declare(strict_types=1);

namespace Kelpie\Service\Autofix;

use Kelpie\Autofix\EditScope;
use Kelpie\DataTransferObject\ProposedEdit;
use Kelpie\DataTransferObject\ProposedEdits;
use Kelpie\DataTransferObject\Thread;
use Kelpie\DataTransferObject\TriageLlmChoice;
use Kelpie\OpenRouter\OpenRouterClient;
use Kelpie\Prompt\ProposePrompt;
use Kelpie\Service\Triage\LlmFamilyService;
use Kelpie\Service\Triage\TriageLlmService;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Exception\DtoException;
use Ubix\Service\JsonService;

/**
 * Asks the propose model for exact-text edits for one thread, reading the file from the scratch checkout
 *
 * The model is `KELPIE_PROPOSE_MODEL`, default `openai/gpt-5.5`, and never from the family that wrote the review.
 * It sees the thread and the file the thread is on (all of it, or a window of lines around the thread when it
 * is large) and, given a scope, the other small files in it, and may create a file only at a path in it. Anything unusable (no file, a refused family, no answer, not JSON, no edits) comes back as a failure
 * reason and no edits; this class applies nothing.
 *
 * @see \Kelpie\Tests\Service\Autofix\ProposeEditsServiceTest PHPUnit test case
 */
final class ProposeEditsService
{
    private const string DEFAULT_MODEL = 'openai/gpt-5.5';

    private const int MAX_FILE_BYTES = 60000;

    private const int WINDOW_LINES = 250;

    private const int MAX_TOTAL_BYTES = 90000;

    private const int MAX_TOKENS = 4000;

    private const int MAX_SUMMARY_LENGTH = 500;

    /**
     * Constructor
     *
     * @param Logger           $logger           Logger
     * @param OpenRouterClient $openRouterClient The model client
     * @param ProposePrompt    $prompt           The prompt
     * @param LlmFamilyService $families         Keeps the model out of the review's family
     * @param JsonService      $jsonService      JSON service
     * @param TriageLlmService $llms             Keeps a model that trains on its prompts off private code
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly OpenRouterClient $openRouterClient,
        private readonly ProposePrompt $prompt,
        private readonly LlmFamilyService $families,
        private readonly JsonService $jsonService,
        private readonly TriageLlmService $llms,
    ) {
    }

    /**
     * The model that proposes
     *
     * @return string
     */
    public function model(): string
    {
        return (string) getenv('KELPIE_PROPOSE_MODEL') ?: self::DEFAULT_MODEL;
    }

    /**
     * Why the propose model must not see this project's code, or empty when it may
     *
     * @param ?string $project The GitLab project ID or path with namespace; null when not known
     *
     * @return string
     */
    public function refusal(?string $project): string
    {
        return $this->choice($project)->refusal;
    }

    /**
     * The model to use for this project's code, and why it may not be used if it may not
     *
     * @param ?string $project The GitLab project ID or path with namespace; null when not known
     *
     * @return TriageLlmChoice
     */
    public function choice(?string $project): TriageLlmChoice
    {
        return $this->llms->choosePropose($project, $this->model());
    }

    /**
     * Ask for edits
     *
     * @param Thread     $thread    The thread
     * @param string     $directory The scratch checkout at the head
     * @param string     $reason    Triage's reason for a fix
     * @param string     $steering  What the author or a maintainer asked for with "Triage:", empty when nothing
     * @param ?string    $project   The GitLab project ID or path with namespace; null when not known
     * @param ?EditScope $scope     The files the edits may touch; null shows the thread's file only
     *
     * @return ProposedEdits
     */
    public function propose(Thread $thread, string $directory, string $reason, string $steering = '', ?string $project = null, ?EditScope $scope = null): ProposedEdits
    {
        $choice  = $this->choice($project);
        $model   = $choice->model;
        $refusal = $choice->refusal;
        if ($refusal !== '') {
            return new ProposedEdits([], '', $refusal);
        }
        if ($this->families->sameAsReview($model, $choice->openSource)) {
            $this->logger->warning('Propose: model is from the review\'s family', ['model' => $model]);

            return new ProposedEdits([], '', 'The propose model is from the same family as the review\'s, so it would not be a second opinion.');
        }

        $path = $thread->filePath;
        if ($path === null || $path === '') {
            return new ProposedEdits([], '', 'The thread is not on a file, so there is no text to edit.');
        }

        $file = realpath($directory . '/' . $path);
        $root = realpath($directory);
        if ($file === false || $root === false || !str_starts_with($file, $root . '/') || !is_file($file)) {
            return new ProposedEdits([], '', 'The file the thread is on is not in the checkout.');
        }

        $content = (string) file_get_contents($file);
        $partial = false;
        if (strlen($content) > self::MAX_FILE_BYTES) {
            $content = $this->window($content, $thread->line ?? 1);
            $partial = true;
        }

        $others = [];
        $total  = strlen($content);
        foreach ($scope?->existing() ?? [] as $other) {
            $text = $other === $path ? null : $this->small($root, $other);
            if ($text !== null && $total + strlen($text) <= self::MAX_TOTAL_BYTES) {
                $others[$other] = $text;
                $total         += strlen($text);
            }
        }

        $completion = $this->openRouterClient->chat($model, $this->prompt->system(), $this->prompt->forThread($thread, $path, $content, $partial, $reason, $steering, $others, $scope?->creatable() ?? []), self::MAX_TOKENS);
        if ($completion === null) {
            return new ProposedEdits([], '', 'The propose model gave no answer.');
        }

        $parsed = $this->parse($completion->text);
        if ($parsed === null) {
            $this->logger->warning('Propose: unparseable model answer', ['discussion_id' => $thread->discussionId]);

            return new ProposedEdits([], '', 'I could not read the propose model\'s answer.', $completion);
        }

        [$summary, $edits] = $parsed;
        if ($edits === []) {
            return new ProposedEdits([], $summary, $summary === '' ? 'The propose model found no safe edit.' : 'The propose model found no safe edit: ' . $summary, $completion);
        }

        $rows = [];
        foreach ($edits as $edit) {
            $rows[] = ['file' => $edit->file, 'new' => $edit->new, 'old' => $edit->old];
        }

        return new ProposedEdits($edits, $summary, '', $completion, $this->jsonService->encode($rows));
    }

    /**
     * Read the model's JSON answer
     *
     * @param string $answer The raw text (a JSON object, possibly inside a code fence)
     *
     * @return ?array{0: string, 1: ProposedEdit[]} The summary and the edits, null when it is not the expected object
     */
    public function parse(string $answer): ?array
    {
        $start = strpos($answer, '{');
        $end   = strrpos($answer, '}');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        try {
            $decoded = $this->jsonService->decode(substr($answer, $start, $end - $start + 1));
        } catch (DtoException $e) {
            $this->logger->debug('Propose: model answer is not JSON', ['error' => $e->getMessage()]);

            return null;
        }

        $raw = $decoded['edits'] ?? [];
        if (!is_array($raw)) {
            return null;
        }

        $edits = [];
        foreach ($raw as $item) {
            if (!is_array($item) || !is_string($item['file'] ?? null) || !is_string($item['old'] ?? null) || !is_string($item['new'] ?? null)) {
                return null;
            }
            $edits[] = new ProposedEdit($item['file'], $item['old'], $item['new']);
        }

        $summary = is_string($decoded['summary'] ?? null) ? trim($decoded['summary']) : '';

        return [mb_substr($summary, 0, self::MAX_SUMMARY_LENGTH), $edits];
    }

    /**
     * A file's text when it is a plain file inside the checkout and small enough to show whole
     *
     * @param string $root The checkout's real path
     * @param string $path Repo-relative path
     *
     * @return ?string
     */
    private function small(string $root, string $path): ?string
    {
        $real = realpath($root . '/' . $path);
        if ($real === false || !is_file($real) || !str_starts_with($real, $root . '/') || filesize($real) > self::MAX_FILE_BYTES) {
            return null;
        }

        $text = (string) file_get_contents($real);

        return mb_check_encoding($text, 'UTF-8') ? $text : null;
    }

    /**
     * The lines around the thread, whole lines, so `old` can still match
     *
     * @param string $content The whole file
     * @param int    $line    The thread's line, from 1
     *
     * @return string
     */
    private function window(string $content, int $line): string
    {
        $lines = explode("\n", $content);
        $from  = max(0, $line - 1 - self::WINDOW_LINES);

        return implode("\n", array_slice($lines, $from, 2 * self::WINDOW_LINES + 1));
    }
}
