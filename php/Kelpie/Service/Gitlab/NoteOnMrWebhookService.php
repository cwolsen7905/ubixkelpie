<?php

declare(strict_types=1);

namespace Kelpie\Service\Gitlab;

use Psr\Log\LoggerInterface as Logger;

/**
 * Handle a Note Hook payload when the target is a merge request
 *
 * Extracts project, MR, and comment details and logs them.
 * Everything else is ignored (200, no error).
 *
 * @see \Kelpie\Tests\Service\Gitlab\NoteOnMrWebhookServiceTest PHPUnit test case
 */
final class NoteOnMrWebhookService
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
     * Process a Note Hook payload for an MR
     *
     * @param array<int|string, mixed> $payload The decoded webhook payload
     *
     * @return string A human-readable outcome string
     */
    public function handle(array $payload): string
    {
        $project = $payload['project'] ?? [];
        $mr      = $payload['object_attributes'] ?? [];
        $user    = $payload['user'] ?? [];
        $project = is_array($project) ? $project : [];
        $mr      = is_array($mr) ? $mr : [];
        $user    = is_array($user) ? $user : [];

        $projectId      = is_int($mr['project_id'] ?? null) ? (int) $mr['project_id'] : (is_int($project['id'] ?? null) ? (int) $project['id'] : 0);
        $projectName    = is_string($project['name'] ?? null) ? $project['name'] : '';
        $projectPath    = is_string($project['path_with_namespace'] ?? null) ? $project['path_with_namespace'] : '';
        $mrIid          = is_int($mr['iid'] ?? null) ? (int) $mr['iid'] : 0;
        $mrTitle        = is_string($mr['title'] ?? null) ? $mr['title'] : '';
        $authorUsername = is_string($user['username'] ?? null) ? $user['username'] : '';
        $commentBody    = is_string($mr['message'] ?? null) ? $mr['message'] : '';

        $this->logger->info('GitLab webhook: note on MR', [
            'author_username' => $authorUsername,
            'comment_length'  => strlen($commentBody),
            'mr_iid'          => $mrIid,
            'mr_title'        => $mrTitle,
            'project_id'      => $projectId,
            'project_name'    => $projectName,
            'project_path'    => $projectPath,
        ]);

        return 'noted: MR !' . $mrIid . ' in ' . $projectPath . ' by ' . $authorUsername;
    }
}
