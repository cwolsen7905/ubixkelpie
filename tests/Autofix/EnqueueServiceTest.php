<?php

declare(strict_types=1);

namespace Kelpie\Tests\Autofix;

use Kelpie\Autofix\EnqueueService;
use Kelpie\Autofix\EventFilter;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Autofix\EnqueueService
 *
 * @coversDefaultClass \Kelpie\Autofix\EnqueueService
 */
final class EnqueueServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(EnqueueService::class);
    }

    /**
     * A job is logged and reported as queued
     *
     * @return void
     */
    public function testAJobIsLogged(): void
    {
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())->method('info')->with('GitLab webhook: autofix job', $this->callback(
            static function (array $context): bool {
                return $context['key'] === 'ubixsys/ubixkelpie!5@abc';
            },
        ));

        $payload = [
            'merge_request'     => ['iid' => 5, 'state' => 'opened', 'title' => 't', 'target_branch' => 'dev', 'last_commit' => ['id' => 'abc']],
            'object_attributes' => ['noteable_type' => 'MergeRequest', 'type' => 'DiffNote', 'discussion_id' => 'd'],
            'project'           => ['path_with_namespace' => 'ubixsys/ubixkelpie'],
            'user'              => ['username' => 'alice'],
        ];

        $this->assertSame('queued: ubixsys/ubixkelpie!5@abc', (new EnqueueService($logger, new EventFilter($logger)))->handle('Note Hook', $payload));
    }

    /**
     * A skip is logged with its reason
     *
     * @return void
     */
    public function testASkipIsReported(): void
    {
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())->method('info')->with('GitLab webhook: autofix skipped', ['event' => 'push hook', 'reason' => 'not an autofix event']);

        $this->assertSame('skipped: not an autofix event', (new EnqueueService($logger, new EventFilter($logger)))->handle('Push Hook', []));
    }
}
