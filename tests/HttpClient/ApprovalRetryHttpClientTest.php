<?php

declare(strict_types=1);

namespace Kelpie\Tests\HttpClient;

use Kelpie\HttpClient\ApprovalRetryHttpClient;
use Nyholm\Psr7\Response as Psr7Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestInterface as Request;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Exception\HttpClientException;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\HttpClient\ApprovalRetryHttpClient
 *
 * What must hold: the three calls the approval retry needs get through, and everything
 * else, above all approving and awarding an emoji, is refused before it is sent.
 *
 * @coversDefaultClass \Kelpie\HttpClient\ApprovalRetryHttpClient
 */
final class ApprovalRetryHttpClientTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Requests the inner client received, as "METHOD path"
     *
     * @var list<string>
     */
    private array $sent = [];

    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(ApprovalRetryHttpClient::class);
    }

    /**
     * Reading an MR and its jobs, and retrying or playing a job, are passed through
     *
     * @return void
     */
    public function testTheApprovalRetryCallsGoThrough(): void
    {
        $client = $this->buildClient();

        foreach (
            [
                ['GET', '/api/v4/projects/7/merge_requests/3'],
                ['GET', '/api/v4/projects/7/pipelines/99/jobs'],
                ['POST', '/api/v4/projects/7/jobs/12/retry'],
                ['POST', '/api/v4/projects/7/jobs/12/play'],
                ['POST', '/api/v4/projects/group%2Fproj/jobs/12/retry'],
            ] as [$method, $path]
        ) {
            $client->sendRequest(new ServerRequest($method, 'https://gitlab.example.com' . $path));
        }

        $this->assertCount(5, $this->sent);
    }

    /**
     * Approving, un-approving, awarding an emoji, merging, creating a pipeline and the rest are refused unsent
     *
     * @return void
     */
    public function testAnythingElseIsRefusedAndNeverSent(): void
    {
        $client = $this->buildClient();

        foreach (
            [
                ['POST', '/api/v4/projects/7/merge_requests/3/approve'],
                ['POST', '/api/v4/projects/7/merge_requests/3/unapprove'],
                ['POST', '/api/v4/projects/7/merge_requests/3/award_emoji'],
                ['POST', '/api/v4/projects/7/merge_requests/3/notes/5/award_emoji'],
                ['PUT', '/api/v4/projects/7/merge_requests/3/merge'],
                ['POST', '/api/v4/projects/7/merge_requests/3'],
                ['POST', '/api/v4/projects/7/pipeline'],
                ['POST', '/api/v4/projects/7/merge_requests/3/pipelines'],
                ['POST', '/api/v4/projects/7/jobs/12/cancel'],
                ['POST', '/api/v4/projects/7/jobs/12/retry/extra'],
                ['GET', '/api/v4/projects/7/jobs/12/retry'],
                ['DELETE', '/api/v4/projects/7/merge_requests/3'],
                ['GET', '/api/v4/user'],
            ] as [$method, $path]
        ) {
            try {
                $client->sendRequest(new ServerRequest($method, 'https://gitlab.example.com' . $path));
                $this->fail($method . ' ' . $path . ' was not refused');
            } catch (HttpClientException $e) {
                $this->assertStringStartsWith('Refused', $e->getMessage());
            }
        }

        $this->assertSame([], $this->sent);
    }

    /**
     * The method is matched case-insensitively, so a lower-case verb is no way round
     *
     * @return void
     */
    public function testALowerCaseVerbIsNoWayRound(): void
    {
        $this->expectException(HttpClientException::class);

        $this->buildClient()->sendRequest(new ServerRequest('post', 'https://gitlab.example.com/api/v4/projects/7/merge_requests/3/approve'));
    }

    /**
     * Build the guard around an inner client that records what reaches it
     *
     * @return ApprovalRetryHttpClient
     */
    private function buildClient(): ApprovalRetryHttpClient
    {
        $this->sent = [];
        $inner      = $this->createStub(HttpClient::class);
        $inner->method('sendRequest')->willReturnCallback(function (Request $request): Psr7Response {
            $this->sent[] = $request->getMethod() . ' ' . $request->getUri()->getPath();

            return new Psr7Response(200, [], '{}');
        });

        return new ApprovalRetryHttpClient($this->createStub(Logger::class), $inner);
    }
}
