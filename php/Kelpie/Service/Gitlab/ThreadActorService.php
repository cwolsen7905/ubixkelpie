<?php

declare(strict_types=1);

namespace Kelpie\Service\Gitlab;

use Psr\Log\LoggerInterface as Logger;

/**
 * Decides who may direct Kelpie in a merge request thread: the MR's author, or a maintainer of the project
 *
 * @see \Kelpie\Tests\Service\Gitlab\ThreadActorServiceTest PHPUnit test case
 */
final class ThreadActorService
{
    /**
     * GitLab's Maintainer access level
     */
    public const int MAINTAINER = 40;

    /**
     * Constructor
     *
     * @param Logger                    $logger Logger
     * @param MemberAccessReaderService $access Reads a user's access level in a project
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly MemberAccessReaderService $access,
    ) {
    }

    /**
     * Whether a user may direct Kelpie on this merge request
     *
     * @param int $projectId  The GitLab project ID
     * @param int $userId     The user who reacted or replied
     * @param int $mrAuthorId The user who opened the merge request
     *
     * @return bool False when the level cannot be read: unknown is not allowed
     */
    public function may(int $projectId, int $userId, int $mrAuthorId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        if ($mrAuthorId > 0 && $userId === $mrAuthorId) {
            return true;
        }

        $level = $this->access->level($projectId, $userId);
        if ($level === null) {
            $this->logger->warning('Thread actor: access level could not be read, treated as not allowed', ['project_id' => $projectId, 'user_id' => $userId]);
        }

        return ($level ?? 0) >= self::MAINTAINER;
    }
}
