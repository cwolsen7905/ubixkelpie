<?php

declare(strict_types=1);

namespace Kelpie\Service\Gitlab;

use Psr\Log\LoggerInterface as Logger;

/**
 * Handle an Issue Hook payload when the action is open
 *
 * Extracts project and issue details and logs them.
 * Everything else is ignored (200, no error).
 *
 * @see \Kelpie\Tests\Service\Gitlab\IssueOpenWebhookServiceTest PHPUnit test case
 */
final class IssueOpenWebhookService
{
    /**
     * Constructor
     *
     * @param Logger $logger Logger
     */
    public function __construct(
        private readonly Logger $logger,
    ) {
    }

    /**
     * Process an Issue Hook payload for an open issue
     *
     * @param array<int|string, mixed> $payload The decoded webhook payload
     *
     * @return string A human-readable outcome string
     */
    public function handle(array $payload): string
    {
        $project = $payload['project'] ?? [];
        $issue   = $payload['object_attributes'] ?? [];
        $user    = $payload['user'] ?? [];
        $project = is_array($project) ? $project : [];
        $issue   = is_array($issue) ? $issue : [];
        $user    = is_array($user) ? $user : [];

        $projectId    = is_int($issue['project_id'] ?? null) ? (int) $issue['project_id'] : (is_int($project['id'] ?? null) ? (int) $project['id'] : 0);
        $projectName  = is_string($project['name'] ?? null) ? $project['name'] : '';
        $projectPath  = is_string($project['path_with_namespace'] ?? null) ? $project['path_with_namespace'] : '';
        $issueIid     = is_int($issue['iid'] ?? null) ? (int) $issue['iid'] : 0;
        $issueTitle   = is_string($issue['title'] ?? null) ? $issue['title'] : '';
        $userName     = is_string($user['name'] ?? null) ? $user['name'] : null;
        $userUsername = is_string($user['username'] ?? null) ? $user['username'] : null;
        $authorName   = $userName ?? $userUsername ?? '';

        $this->logger->info('GitLab webhook: issue open', [
            'author_name'  => $authorName,
            'issue_iid'    => $issueIid,
            'issue_title'  => $issueTitle,
            'project_id'   => $projectId,
            'project_name' => $projectName,
            'project_path' => $projectPath,
        ]);

        return 'opened: issue #' . $issueIid . ' in ' . $projectPath . ' by ' . $authorName;
    }
}
