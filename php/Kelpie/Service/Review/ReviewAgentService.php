<?php

declare(strict_types=1);

namespace Kelpie\Service\Review;

use Kelpie\DataTransferObject\Review\ReviewSettings;
use Kelpie\DataTransferObject\Review\ReviewUsage;
use Kelpie\OpenRouter\Review\ReviewChatClient;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Exception\DtoException;
use Ubix\Service\JsonService;

/**
 * Runs the model over the diff: a bounded loop of tool calls (read, grep, glob) that ends in its answer
 *
 * The loop is the review's whole trust boundary and it is small on purpose. The tools only read the checkout
 * (see RepoToolsService); this process never puts the GitLab token or any other secret into the conversation.
 * The last allowed turn is sent without tools, so the model must answer.
 *
 * @see \Kelpie\Tests\Service\Review\ReviewAgentServiceTest PHPUnit test case
 */
final class ReviewAgentService
{
    /**
     * Constructor
     *
     * @param Logger             $logger             Logger
     * @param JsonService        $jsonService        JSON service
     * @param ReviewChatClient   $chatClient         One model call
     * @param RepoToolsService   $repoToolsService   The read-only tools
     * @param ReviewUsageService $reviewUsageService Sums the usage
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly JsonService $jsonService,
        private readonly ReviewChatClient $chatClient,
        private readonly RepoToolsService $repoToolsService,
        private readonly ReviewUsageService $reviewUsageService,
    ) {
    }

    /**
     * Ask for a review
     *
     * @param ReviewSettings $settings The settings
     * @param string         $system   The system prompt
     * @param string         $user     The context (title, standards, raised threads, diff) and any retry note
     *
     * @return array{0: ?string, 1: ReviewUsage} The model's final text (null when a call failed) and the usage so far
     */
    public function run(ReviewSettings $settings, string $system, string $user): array
    {
        $messages = [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]];
        $usage    = new ReviewUsage();
        $tools    = $this->repoToolsService->definitions();

        for ($turn = 1; $turn <= $settings->maxTurns; $turn++) {
            $last = $turn === $settings->maxTurns;
            $next = $this->chatClient->chat($settings->apiModel, $settings->effort, $messages, $last ? [] : $tools);
            if ($next === null) {
                return [null, $usage];
            }

            $usage = $this->reviewUsageService->plus($usage, new ReviewUsage($next->tokensIn, $next->tokensOut, $next->microCost));
            if ($next->toolCalls === [] || $last) {
                return [$next->content, $usage];
            }

            $calls = [];
            foreach ($next->toolCalls as $call) {
                $calls[] = ['function' => ['arguments' => $call['arguments'], 'name' => $call['name']], 'id' => $call['id'], 'type' => 'function'];
            }
            $messages[] = ['content' => $next->content, 'role' => 'assistant', 'tool_calls' => $calls];
            foreach ($next->toolCalls as $call) {
                $messages[] = ['role' => 'tool', 'tool_call_id' => $call['id'], 'content' => $this->tool($settings, $call['name'], $call['arguments'])];
            }
        }

        $this->logger->error('Review model loop ended without an answer');

        return [null, $usage];
    }

    /**
     * @param ReviewSettings $settings  The settings
     * @param string         $name      The tool
     * @param string         $arguments The arguments as the model wrote them
     *
     * @return string
     */
    private function tool(ReviewSettings $settings, string $name, string $arguments): string
    {
        try {
            $args = $this->jsonService->decode($arguments === '' ? '{}' : $arguments);
        } catch (DtoException $e) {
            $this->logger->debug('Review tool arguments are not JSON', ['error' => $e->getMessage()]);

            return 'error: the arguments are not valid JSON';
        }

        return $this->repoToolsService->run($settings->repo, $name, $args);
    }
}
