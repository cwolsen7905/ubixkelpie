<?php

declare(strict_types=1);

namespace Kelpie\Service\Autofix;

use FilesystemIterator;
use Kelpie\DataTransferObject\Completion;
use Kelpie\DataTransferObject\IssueDetails;
use Kelpie\DataTransferObject\ProposedEdits;
use Kelpie\OpenRouter\OpenRouterClient;
use Psr\Log\LoggerInterface as Logger;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;
use Ubix\Exception\DtoException;
use Ubix\Service\JsonService;

/**
 * Asks the propose model for exact-text edits that fix one issue, in a scratch checkout
 *
 * Two calls. The first sees the issue and the list of file paths and names up to three files; the second sees the issue
 * and those files and answers with the edits. The model is the one `ProposeEditsService` uses. Anything unusable (no
 * answer, a path not in the list, files too big, not JSON, no edits) comes back as a failure reason and no edits; this
 * class applies nothing. A per-issue token budget stops the second call when the first has already used it.
 *
 * @see \Kelpie\Tests\Service\Autofix\IssueProposeEditsServiceTest PHPUnit test case
 */
final class IssueProposeEditsService
{
    public const int TOKEN_BUDGET = 100000;

    private const int MAX_PATHS = 3000;

    private const int MAX_FILES = 3;

    private const int MAX_FILE_BYTES = 60000;

    private const int MAX_TOTAL_BYTES = 90000;

    private const int SELECT_TOKENS = 300;

    private const int EDIT_TOKENS = 4000;

    private const array SKIPPED_DIRECTORIES = ['.git', 'node_modules', 'vendor', '.phpstan.cache'];

    /**
     * Constructor
     *
     * @param Logger                $logger           Logger
     * @param OpenRouterClient      $openRouterClient The model client
     * @param IssueFixPromptService $prompt           The prompts
     * @param ProposeEditsService   $propose          Names the model and reads the model's JSON
     * @param JsonService           $jsonService      JSON service
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly OpenRouterClient $openRouterClient,
        private readonly IssueFixPromptService $prompt,
        private readonly ProposeEditsService $propose,
        private readonly JsonService $jsonService,
    ) {
    }

    /**
     * Ask for edits
     *
     * @param IssueDetails $issue     The issue
     * @param string       $directory The scratch checkout
     *
     * @return ProposedEdits
     */
    public function propose(IssueDetails $issue, string $directory): ProposedEdits
    {
        $model = $this->propose->model();
        $paths = $this->paths($directory);
        if ($paths === []) {
            return new ProposedEdits([], '', 'The checkout has no files to read.');
        }

        $selected = $this->openRouterClient->chat($model, $this->prompt->selectSystem(), $this->prompt->selectUser($issue, $paths), self::SELECT_TOKENS);
        if ($selected === null) {
            return new ProposedEdits([], '', 'The propose model gave no answer.');
        }

        $chosen = $this->chosen($selected->text, $paths);
        if ($chosen === []) {
            return new ProposedEdits([], '', 'The propose model named no file to change.', $selected);
        }
        if ($selected->promptTokens + $selected->completionTokens > self::TOKEN_BUDGET) {
            return new ProposedEdits([], '', 'The issue used up its token budget.', $selected);
        }

        $files = $this->read($directory, $chosen);
        if ($files === []) {
            return new ProposedEdits([], '', 'The files the model named are too large to show.', $selected);
        }

        $answer = $this->openRouterClient->chat($model, $this->prompt->editSystem(), $this->prompt->editUser($issue, $files), self::EDIT_TOKENS);
        if ($answer === null) {
            return new ProposedEdits([], '', 'The propose model gave no answer.', $selected);
        }

        $paid   = $this->sum($selected, $answer);
        $parsed = $this->propose->parse($answer->text);
        if ($parsed === null) {
            $this->logger->warning('Issue fix: unparseable model answer');

            return new ProposedEdits([], '', 'I could not read the propose model\'s answer.', $paid);
        }

        [$summary, $edits] = $parsed;
        if ($edits === []) {
            return new ProposedEdits([], $summary, $summary === '' ? 'The propose model found no safe edit.' : 'The propose model found no safe edit: ' . $summary, $paid);
        }

        $rows = [];
        foreach ($edits as $edit) {
            $rows[] = ['file' => $edit->file, 'new' => $edit->new, 'old' => $edit->old];
        }

        try {
            $json = $this->jsonService->encode($rows);
        } catch (DtoException $e) {
            $this->logger->debug('Issue fix: edits not encodable', ['error' => $e->getMessage()]);
            $json = '';
        }

        return new ProposedEdits($edits, $summary, '', $paid, $json);
    }

    /**
     * Whether the directory walk goes into an entry: not a symlink, not `.git`, dependencies or a cache
     *
     * @param SplFileInfo $current The entry
     *
     * @return bool
     */
    public function keep(SplFileInfo $current): bool
    {
        return !$current->isLink() && !($current->isDir() && in_array($current->getFilename(), self::SKIPPED_DIRECTORIES, true));
    }

    /**
     * The repo-relative paths of the checkout's files, skipping `.git`, dependencies and caches
     *
     * @param string $directory The checkout
     *
     * @return string[] Sorted, at most MAX_PATHS
     */
    private function paths(string $directory): array
    {
        $root = realpath($directory);
        if ($root === false) {
            return [];
        }

        $paths = [];
        try {
            $tree = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                [$this, 'keep'],
            ));
            foreach ($tree as $file) {
                if ($file instanceof SplFileInfo && $file->isFile() && count($paths) < self::MAX_PATHS) {
                    $paths[] = substr($file->getPathname(), strlen($root) + 1);
                }
            }
        } catch (Throwable $e) {
            $this->logger->warning('Issue fix: the checkout could not be listed', ['error' => $e->getMessage()]);

            return [];
        }

        sort($paths);

        return $paths;
    }

    /**
     * The paths the model named, kept only when they are in the list
     *
     * @param string   $answer The model's text
     * @param string[] $paths  The paths of the checkout
     *
     * @return string[]
     */
    private function chosen(string $answer, array $paths): array
    {
        $start = strpos($answer, '{');
        $end   = strrpos($answer, '}');
        if ($start === false || $end === false || $end < $start) {
            return [];
        }

        try {
            $decoded = $this->jsonService->decode(substr($answer, $start, $end - $start + 1));
        } catch (DtoException $e) {
            $this->logger->debug('Issue fix: model answer is not JSON', ['error' => $e->getMessage()]);

            return [];
        }

        $named = [];
        foreach (is_array($decoded['files'] ?? null) ? $decoded['files'] : [] as $path) {
            if (is_string($path) && in_array($path, $paths, true) && !in_array($path, $named, true)) {
                $named[] = $path;
            }
        }

        return array_slice($named, 0, self::MAX_FILES);
    }

    /**
     * Read the chosen files that are small enough to show whole, within a total
     *
     * @param string   $directory The checkout
     * @param string[] $chosen    The chosen paths
     *
     * @return array<string, string>
     */
    private function read(string $directory, array $chosen): array
    {
        $files = [];
        $total = 0;
        foreach ($chosen as $path) {
            $size = (int) filesize($directory . '/' . $path);
            if ($size > self::MAX_FILE_BYTES || $total + $size > self::MAX_TOTAL_BYTES) {
                continue;
            }
            $files[$path] = (string) file_get_contents($directory . '/' . $path);
            $total       += $size;
        }

        return $files;
    }

    /**
     * Both calls as one: the last answer's text, and the tokens and cost of the two
     *
     * @param Completion $first  The first call
     * @param Completion $second The second call
     *
     * @return Completion
     */
    private function sum(Completion $first, Completion $second): Completion
    {
        $cost = $first->costUsd === null && $second->costUsd === null ? null : ($first->costUsd ?? 0.0) + ($second->costUsd ?? 0.0);

        return new Completion($second->text, $second->model, $first->promptTokens + $second->promptTokens, $first->completionTokens + $second->completionTokens, $cost);
    }
}
