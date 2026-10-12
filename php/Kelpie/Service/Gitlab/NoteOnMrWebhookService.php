<?php

declare(strict_types=1);

namespace Kelpie\Service\Gitlab;

use Psr\Log\LoggerInterface as Logger;

/**
 * Handle a Note Hook payload when the target is a merge request
 *
 * Extracts project, MR, and comment details and logs them.
 * Non-MR notes are ignored (returns a short string; controller answers 200).
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
     * @return string A human-readable outcome string (an 'ignored: ...' reason when the note is skipped)
     */
    public function handle(array $payload): string
    {
        $project = is_array($payload['project'] ?? null) ? $payload['project'] : [];
        $mr      = is_array($payload['merge_request'] ?? null) ? $payload['merge_request'] : [];
        $user    = is_array($payload['user'] ?? null) ? $payload['user'] : [];
        $oa      = is_array($payload['object_attributes'] ?? null) ? $payload['object_attributes'] : [];

        if (($oa['noteable_type'] ?? '') !== 'MergeRequest') {
            return 'ignored: not an MR note';
        }

        $projectId      = is_int($mr['project_id'] ?? null) ? $mr['project_id'] : (is_int($project['id'] ?? null) ? $project['id'] : 0);
        $projectName    = is_string($project['name'] ?? null) ? $project['name'] : '';
        $projectPath    = is_string($project['path_with_namespace'] ?? null) ? $project['path_with_namespace'] : '';
        $mrIid          = is_int($mr['iid'] ?? null) ? $mr['iid'] : 0;
        $mrTitle        = is_string($mr['title'] ?? null) ? $mr['title'] : '';
        $authorUsername = is_string($user['username'] ?? null) ? $user['username'] : '';
        $commentBody    = is_string($oa['note'] ?? null) ? $oa['note'] : '';

        // Log length only, not the text, to avoid leaking review comments into logs.
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
