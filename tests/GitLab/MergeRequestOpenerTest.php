<?php

declare(strict_types=1);

namespace Kelpie\Tests\GitLab;

use Kelpie\GitLab\MergeRequestOpener;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Service\JsonService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\GitLab\MergeRequestOpener
 *
 * What must hold:
 * - Open merge requests from a branch are found by the source branch, and a failed read is null, not "none"
 * - A user is found by exact username
 * - A merge request is one POST with the branches, the labels and the reviewers, and a failure is null
 *
 * @coversDefaultClass \Kelpie\GitLab\MergeRequestOpener
 */
final class MergeRequestOpenerTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(MergeRequestOpener::class);
    }

    /**
     * Open merge requests of a branch
     *
     * @return void
     */
    public function testOpenFrom(): void
    {
        $one = new RecordingHttp(200, '[{"web_url":"https://g/x/-/merge_requests/3"}]');

        $this->assertSame([], $this->opener(new RecordingHttp(200, '[]'))->openFrom('org/proj', 'kelpie/fix-7-x'));
        $this->assertSame(['https://g/x/-/merge_requests/3'], $this->opener($one)->openFrom('org/proj', 'kelpie/fix-7-x'));
        $this->assertSame('GET https://gitlab.test/api/v4/projects/org%2Fproj/merge_requests?state=opened&source_branch=kelpie%2Ffix-7-x', $one->seen()[0]);
        $this->assertNull($this->opener(new RecordingHttp(500, '{}'))->openFrom('org/proj', 'b'));
    }

    /**
     * The head of a branch is its commit's sha, and anything else is null
     *
     * @return void
     */
    public function testBranchSha(): void
    {
        $sha  = str_repeat('a', 40);
        $http = new RecordingHttp(200, '{"commit":{"id":"' . $sha . '"}}');

        $this->assertSame($sha, $this->opener($http)->branchSha('org/proj', 'dev'));
        $this->assertSame('GET https://gitlab.test/api/v4/projects/org%2Fproj/repository/branches/dev', $http->seen()[0]);
        $this->assertNull($this->opener(new RecordingHttp(200, '{"commit":{"id":"short"}}'))->branchSha('org/proj', 'dev'));
        $this->assertNull($this->opener(new RecordingHttp(200, '{}'))->branchSha('org/proj', 'dev'));
        $this->assertNull($this->opener(new RecordingHttp(404, '{}'))->branchSha('org/proj', 'nope'));
    }

    /**
     * A user needs an exact username match
     *
     * @return void
     */
    public function testUserId(): void
    {
        $this->assertSame(9, $this->opener(new RecordingHttp(200, '[{"id":9,"username":"rev"}]'))->userId('rev'));
        $this->assertNull($this->opener(new RecordingHttp(200, '[{"id":9,"username":"rev2"}]'))->userId('rev'));
        $this->assertNull($this->opener(new RecordingHttp(200, '[]'))->userId('rev'));
        $this->assertNull($this->opener(new RecordingHttp(404, '{}'))->userId('rev'));
    }

    /**
     * Creating is one POST
     *
     * @return void
     */
    public function testCreate(): void
    {
        $http   = new RecordingHttp(201, '{"web_url":"https://g/x/-/merge_requests/4"}');
        $opener = $this->opener($http);

        $this->assertSame('https://g/x/-/merge_requests/4', $opener->create('org/proj', 'kelpie/fix-7-x', 'dev', 'Fix #7', 'Closes #7', ['kelpie'], [9]));
        $this->assertSame('POST https://gitlab.test/api/v4/projects/org%2Fproj/merge_requests', $http->seen()[0]);
        $this->assertStringContainsString('"reviewer_ids":[9]', $http->seen()[1]);
        $this->assertStringContainsString('"target_branch":"dev"', $http->seen()[1]);
        $this->assertStringContainsString('"labels":"kelpie"', $http->seen()[1]);

        $this->assertNull($this->opener(new RecordingHttp(400, '{}'))->create('org/proj', 'b', 'dev', 't', 'd', [], []));
    }

    /**
     * A request with no reviewers sends no reviewer field
     *
     * @return void
     */
    public function testNoReviewerField(): void
    {
        $http = new RecordingHttp(201, '{"web_url":"u"}');
        $this->opener($http)->create('org/proj', 'b', 'dev', 't', 'd', [], []);

        $this->assertStringNotContainsString('reviewer_ids', $http->seen()[1]);
    }

    /**
     * @param HttpClient $http The client
     *
     * @return MergeRequestOpener
     */
    private function opener(HttpClient $http): MergeRequestOpener
    {
        $logger  = $this->createStub(Logger::class);
        $factory = new Psr17Factory();

        return new MergeRequestOpener($logger, $http, $factory, $factory, 'https://gitlab.test/api/v4', new JsonService($logger));
    }
}
