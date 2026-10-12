<?php

declare(strict_types=1);

namespace Kelpie\Service\Gitlab;

use Psr\Log\LoggerInterface as Logger;

/**
 * Handle an Issue Hook payload when the action is open
 *
 * Extracts project and issue details and logs them.
 * Non-open issues are ignored (returns a short string; controller answers 200).
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
     * @return string A human-readable outcome string (an 'ignored: ...' reason when the issue is skipped)
     */
    public function handle(array $payload): string
    {
        $project = is_array($payload['project'] ?? null) ? $payload['project'] : [];
        $issue   = is_array($payload['object_attributes'] ?? null) ? $payload['object_attributes'] : [];
        $user    = is_array($payload['user'] ?? null) ? $payload['user'] : [];

        if (($issue['action'] ?? '') !== 'open') {
            return 'ignored: not an open event';
        }

        $projectId      = is_int($issue['project_id'] ?? null) ? $issue['project_id'] : (is_int($project['id'] ?? null) ? $project['id'] : 0);
        $projectName    = is_string($project['name'] ?? null) ? $project['name'] : '';
        $projectPath    = is_string($project['path_with_namespace'] ?? null) ? $project['path_with_namespace'] : '';
        $issueIid       = is_int($issue['iid'] ?? null) ? $issue['iid'] : 0;
        $issueTitle     = is_string($issue['title'] ?? null) ? $issue['title'] : '';
        $authorUsername = is_string($user['username'] ?? null) ? $user['username'] : '';

        $this->logger->info('GitLab webhook: issue open', [
            'author_username' => $authorUsername,
            'issue_iid'       => $issueIid,
            'issue_title'     => $issueTitle,
            'project_id'      => $projectId,
            'project_name'    => $projectName,
            'project_path'    => $projectPath,
        ]);

        return 'opened: issue #' . $issueIid . ' in ' . $projectPath . ' by ' . $authorUsername;
    }
}
