<?php

declare(strict_types=1);

namespace Kelpie\Tests\GitLab;

use Kelpie\GitLab\JobLogReader;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Service\JsonService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\GitLab\JobLogReader
 *
 * What must hold:
 * - Failed jobs come back in the Pipeline Hook `builds` shape
 * - A log is cut to its tail, stripped of control codes, and credentials, markers and mentions are defused
 * - A failing call is null, never an exception
 *
 * @coversDefaultClass \Kelpie\GitLab\JobLogReader
 */
final class JobLogReaderTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(JobLogReader::class);
    }

    /**
     * Failed jobs are mapped
     *
     * @return void
     */
    public function testFailedJobs(): void
    {
        $gitlab = new FakeGitlab();
        $gitlab->pipeline('failed', [['allow_failure' => true, 'id' => 5, 'name' => 'a', 'stage' => 'test', 'status' => 'failed'], ['nope' => 1]]);

        $this->assertSame(
            [['allow_failure' => true, 'id' => 5, 'name' => 'a', 'stage' => 'test', 'status' => 'failed']],
            $this->reader($gitlab)->failedJobs(1, 9),
        );
    }

    /**
     * Only the tail is kept and it is cleaned
     *
     * @return void
     */
    public function testTailIsCleaned(): void
    {
        $lines  = [
            str_repeat('x', 20000) . "\e[31mFAIL\e[0m\r",
            'password=hunter2 and token: abc123',
            'https://user:pw@example.com/x glpat-abcdefghijklmnopqrst',
            '<!-- kelpie:claim --> ping @bob',
        ];
        $log    = implode("\n", $lines);
        $gitlab = new FakeGitlab();
        $gitlab->pipeline('failed', [], $log);

        $tail = $this->reader($gitlab)->tail(1, 55);

        $this->assertNotNull($tail);
        $this->assertLessThanOrEqual(JobLogReader::TAIL_BYTES + 200, strlen($tail));
        $this->assertStringContainsString('FAIL', $tail);
        foreach (["\e", "\r", 'hunter2', 'abc123', 'pw@', 'glpat-', '<!-- kelpie', '@bob'] as $leak) {
            $this->assertStringNotContainsString($leak, $tail);
        }
    }

    /**
     * An error status is null
     *
     * @return void
     */
    public function testErrorIsNull(): void
    {
        $http = $this->createStub(HttpClient::class);
        $http->method('sendRequest')->willReturn(new Psr7Response(404));
        $logger = $this->createStub(Logger::class);
        $reader = new JobLogReader($logger, $http, new Psr17Factory(), 'https://gitlab.test/api/v4', new JsonService($logger));

        $this->assertNull($reader->tail(1, 55));
        $this->assertSame([], $reader->failedJobs(1, 404));
    }

    /**
     * @param FakeGitlab $gitlab The fake
     *
     * @return JobLogReader
     */
    private function reader(FakeGitlab $gitlab): JobLogReader
    {
        $logger = $this->createStub(Logger::class);

        return new JobLogReader($logger, $gitlab, new Psr17Factory(), 'https://gitlab.test/api/v4', new JsonService($logger));
    }
}
