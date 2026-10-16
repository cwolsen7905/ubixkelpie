<?php

declare(strict_types=1);

namespace Kelpie\Tests\GitLab;

use Kelpie\GitLab\MergeRequestLister;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Service\JsonService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\GitLab\MergeRequestLister
 *
 * What must hold:
 * - Open MRs are listed with their head pipeline; an MR lacking a field is dropped
 * - A failing call is an empty list or null, never an exception
 * - Only GET is sent
 *
 * @coversDefaultClass \Kelpie\GitLab\MergeRequestLister
 */
final class MergeRequestListerTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(MergeRequestLister::class);
    }

    /**
     * The MR and its pipeline are read
     *
     * @return void
     */
    public function testListsOpenMergeRequests(): void
    {
        $gitlab = new FakeGitlab();
        $gitlab->pipeline('failed', []);
        $list = $this->lister($gitlab)->open('ubixsys/ubixkelpie');

        $this->assertCount(1, $list);
        $this->assertSame(3, $list[0]->iid);
        $this->assertSame(1, $list[0]->projectId);
        $this->assertSame(9, $list[0]->pipelineId);
        $this->assertSame('failed', $list[0]->pipelineStatus);
        $this->assertSame('alice', $list[0]->pipelineUser);
        $this->assertSame(['GET /projects/ubixsys%2Fubixkelpie/merge_requests'], $gitlab->requests());
    }

    /**
     * An MR without a sha is dropped
     *
     * @return void
     */
    public function testIncompleteMergeRequestIsDropped(): void
    {
        $gitlab = new FakeGitlab();
        $gitlab->mergeRequestFields(['sha' => '']);

        $this->assertSame([], $this->lister($gitlab)->open('ubixsys/ubixkelpie'));
    }

    /**
     * An error status is an empty list
     *
     * @return void
     */
    public function testErrorIsEmpty(): void
    {
        $http = $this->createStub(HttpClient::class);
        $http->method('sendRequest')->willReturn(new Psr7Response(500));
        $logger = $this->createStub(Logger::class);
        $lister = new MergeRequestLister($logger, $http, new Psr17Factory(), 'https://gitlab.test/api/v4', new JsonService($logger));

        $this->assertSame([], $lister->open('g/p'));
        $this->assertNull($lister->commit(1, 'abc'));
    }

    /**
     * The commit message and date are read
     *
     * @return void
     */
    public function testCommit(): void
    {
        $gitlab = new FakeGitlab();
        $gitlab->commit('fix: y', '2026-02-03T04:05:06.000Z');
        $commit = $this->lister($gitlab)->commit(1, 'abc123');

        $this->assertNotNull($commit);
        $this->assertSame('fix: y', $commit['message']);
        $this->assertSame('2026-02-03', $commit['committedAt']->format('Y-m-d'));
    }

    /**
     * @param FakeGitlab $gitlab The fake
     *
     * @return MergeRequestLister
     */
    private function lister(FakeGitlab $gitlab): MergeRequestLister
    {
        $logger  = $this->createStub(Logger::class);
        $factory = new Psr17Factory();

        return new MergeRequestLister($logger, $gitlab, $factory, 'https://gitlab.test/api/v4', new JsonService($logger));
    }
}
