<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Autofix;

use Kelpie\DataTransferObject\AutofixJob;
use Kelpie\Policy\EventFilter;
use Kelpie\Repository\AutofixJob\AutofixJobSqlRepository;
use Kelpie\Service\Autofix\AutofixJobService;
use Kelpie\Service\Autofix\EnqueueService;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Service\Sql\SqlServiceInterface as SqlService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Autofix\EnqueueService
 *
 * @coversDefaultClass \Kelpie\Service\Autofix\EnqueueService
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
     * A job is logged and written to the job table
     *
     * @return void
     */
    public function testAJobIsLogged(): void
    {
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())->method('info')->with('GitLab webhook: autofix job', $this->callback(
            static function (array $context): bool {
                return $context['key'] === 'ubixsys/ubixkelpie!5@abc#d';
            },
        ));

        $payload = [
            'merge_request'     => ['iid' => 5, 'state' => 'opened', 'title' => 't', 'target_branch' => 'dev', 'last_commit' => ['id' => 'abc']],
            'object_attributes' => ['noteable_type' => 'MergeRequest', 'type' => 'DiffNote', 'discussion_id' => 'd'],
            'project'           => ['path_with_namespace' => 'ubixsys/ubixkelpie'],
            'user'              => ['username' => 'alice'],
        ];

        $this->assertSame('queued: ubixsys/ubixkelpie!5@abc#d (enqueued)', (new EnqueueService($logger, new EventFilter($logger), $this->jobs($logger), true))->handle('Note Hook', $payload));
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

        $this->assertSame('skipped: not an autofix event', (new EnqueueService($logger, new EventFilter($logger), $this->jobs($logger), true))->handle('Push Hook', []));
    }

    /**
     * The entry point returns the job itself, for the sweep
     *
     * @return void
     */
    public function testEnqueueReturnsTheJob(): void
    {
        $logger  = $this->createStub(Logger::class);
        $payload = [
            'merge_request'     => ['iid' => 5, 'state' => 'opened', 'title' => 't', 'target_branch' => 'dev', 'last_commit' => ['id' => 'abc']],
            'object_attributes' => ['noteable_type' => 'MergeRequest', 'type' => 'DiffNote', 'discussion_id' => 'd'],
            'project'           => ['path_with_namespace' => 'ubixsys/ubixkelpie'],
            'user'              => ['username' => 'alice'],
        ];

        $job = (new EnqueueService($logger, new EventFilter($logger), $this->jobs($logger), true))->enqueue('Note Hook', $payload);

        $this->assertInstanceOf(AutofixJob::class, $job);
        $this->assertSame('d', $job->discussionId);
        $this->assertSame('not an autofix event', (new EnqueueService($logger, new EventFilter($logger), $this->jobs($logger), true))->enqueue('Push Hook', []));
    }

    /**
     * Autofix off: the job is filtered and logged, but nothing is written
     *
     * @return void
     */
    public function testNothingIsWrittenWhileDisabled(): void
    {
        $logger  = $this->createStub(Logger::class);
        $payload = [
            'merge_request'     => ['iid' => 5, 'state' => 'opened', 'title' => 't', 'target_branch' => 'dev', 'last_commit' => ['id' => 'abc']],
            'object_attributes' => ['noteable_type' => 'MergeRequest', 'type' => 'DiffNote', 'discussion_id' => 'd'],
            'project'           => ['path_with_namespace' => 'ubixsys/ubixkelpie'],
            'user'              => ['username' => 'alice'],
        ];
        $sql     = $this->createMock(SqlService::class);
        $sql->expects($this->never())->method('query');

        $service = new EnqueueService($logger, new EventFilter($logger), new AutofixJobService($logger, new AutofixJobSqlRepository($logger, $sql)));

        $this->assertSame('skipped: autofix disabled', $service->handle('Note Hook', $payload));
    }

    /**
     * A finished review job becomes one fan-out row keyed by the head sha, with no MR yet
     *
     * @return void
     */
    public function testAFinishedReviewJobIsQueuedByHeadSha(): void
    {
        $logger  = $this->createStub(Logger::class);
        $payload = [
            'build_name'   => 'kelpie-review',
            'build_status' => 'success',
            'project'      => ['path_with_namespace' => 'ubixsys/ubixkelpie'],
            'sha'          => 'abc',
        ];
        $sql     = $this->createMock(SqlService::class);
        $sql->expects($this->once())->method('query')->with($this->stringContains('INSERT INTO ubixkelpie.autofix_jobs'), $this->callback(
            static function (array $p): bool {
                return $p['thread_key'] === 'review-abc' && $p['source'] === 'review' && $p['mr_iid'] === 0;
            },
        ))->willReturn(1);

        $service = new EnqueueService($logger, new EventFilter($logger), new AutofixJobService($logger, new AutofixJobSqlRepository($logger, $sql)), true);

        $this->assertSame('queued: ubixsys/ubixkelpie!0@abc (enqueued)', $service->handle('Job Hook', $payload));
    }

    /**
     * The job table over a SQL service that holds no rows
     *
     * @param Logger $logger Logger
     *
     * @return AutofixJobService
     */
    private function jobs(Logger $logger): AutofixJobService
    {
        $sql = $this->createStub(SqlService::class);
        $sql->method('query')->willReturn(1);
        $sql->method('lastInsertId')->willReturn('7');
        $sql->method('getColumn')->willReturn('0');

        return new AutofixJobService($logger, new AutofixJobSqlRepository($logger, $sql));
    }
}
