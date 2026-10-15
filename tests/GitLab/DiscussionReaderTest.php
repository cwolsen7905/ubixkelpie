<?php

declare(strict_types=1);

namespace Kelpie\Tests\GitLab;

use DateTimeImmutable;
use Kelpie\GitLab\DiscussionReader;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestInterface as Request;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Service\JsonService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\GitLab\DiscussionReader
 *
 * What must hold:
 * - Only unresolved, resolvable threads are returned
 * - Resolved threads, non-resolvable threads, and system-only threads are excluded
 * - Diff notes carry file_path and line; general notes do not
 * - Pagination via X-Next-Page header works
 *
 * @coversDefaultClass \Kelpie\GitLab\DiscussionReader
 */
final class DiscussionReaderTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * HTTP request call count for pagination simulation
     */
    private int $callCount = 0;

    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(DiscussionReader::class);
    }

    /**
     * Only unresolved, resolvable threads are returned
     *
     * @return void
     */
    public function testOnlyUnresolvedResolvableThreadsAreReturned(): void
    {
        $reader  = $this->buildReader();
        $threads = $reader->read(1, 3);

        $this->assertCount(2, $threads);

        $discussionIds = [];
        foreach ($threads as $thread) {
            $discussionIds[] = $thread->discussionId;
        }

        $this->assertSame([3, 5], $discussionIds);
    }

    /**
     * Resolved threads are excluded
     *
     * @return void
     */
    public function testResolvedThreadsAreExcluded(): void
    {
        $reader  = $this->buildReader();
        $threads = $reader->read(1, 3);

        foreach ($threads as $thread) {
            $this->assertFalse($thread->resolved);
        }
    }

    /**
     * System notes are excluded from threads
     *
     * @return void
     */
    public function testSystemNotesAreExcluded(): void
    {
        $reader  = $this->buildReader();
        $threads = $reader->read(1, 3);

        $authors = [];
        foreach ($threads as $thread) {
            $authors[] = $thread->author;
        }

        $this->assertNotContains('system', $authors);
    }

    /**
     * Non-resolvable threads are excluded
     *
     * @return void
     */
    public function testNonResolvableThreadsAreExcluded(): void
    {
        $reader  = $this->buildReader();
        $threads = $reader->read(1, 3);

        foreach ($threads as $thread) {
            // The fixture has no non-resolvable thread in the returned set,
            // so we verify that the returned discussion IDs do not include them.
            $ids = [3, 5];
            $this->assertContains($thread->discussionId, $ids);
        }
    }

    /**
     * Diff notes carry file_path and line; general notes do not
     *
     * @return void
     */
    public function testDiffNotesCarryFilePathAndLine(): void
    {
        $reader  = $this->buildReader();
        $threads = $reader->read(1, 3);

        $threadById = [];
        foreach ($threads as $thread) {
            $threadById[$thread->discussionId] = $thread;
        }

        $this->assertArrayHasKey(3, $threadById);
        $this->assertSame('bob', $threadById[3]->author);
        $this->assertSame(42, $threadById[3]->line);

        $this->assertArrayHasKey(5, $threadById);
        $this->assertSame('dave', $threadById[5]->author);
        $this->assertNull($threadById[5]->line);
    }

    /**
     * Pagination via X-Next-Page header fetches the second page
     *
     * @return void
     */
    public function testPaginationFetchesSecondPage(): void
    {
        $reader  = $this->buildReader();
        $threads = $reader->read(1, 3);

        $discussionIds = [];
        foreach ($threads as $thread) {
            $discussionIds[] = $thread->discussionId;
        }

        $this->assertSame([3, 5], $discussionIds);
    }

    /**
     * Thread properties match the fixture data
     *
     * @return void
     */
    public function testThreadPropertiesMatchFixture(): void
    {
        $reader  = $this->buildReader();
        $threads = $reader->read(1, 3);

        $this->assertCount(2, $threads);

        $diffThread    = null;
        $generalThread = null;
        foreach ($threads as $thread) {
            if ($thread->filePath === 'src/processor.php') {
                $diffThread = $thread;
            } else {
                $generalThread = $thread;
            }
        }

        $this->assertNotNull($diffThread);
        $this->assertSame(3, $diffThread->discussionId);
        $this->assertSame(102, $diffThread->firstNoteId);
        $this->assertSame('bob', $diffThread->author);
        $this->assertSame('This should use a typed property.', $diffThread->body);
        $this->assertSame('src/processor.php', $diffThread->filePath);
        $this->assertSame(42, $diffThread->line);
        $this->assertFalse($diffThread->resolved);
        $this->assertInstanceOf(DateTimeImmutable::class, $diffThread->createdAt);
        $this->assertInstanceOf(DateTimeImmutable::class, $diffThread->updatedAt);
        $this->assertSame(2, $diffThread->noteCount);

        $this->assertNotNull($generalThread);
        $this->assertSame(5, $generalThread->discussionId);
        $this->assertSame(106, $generalThread->firstNoteId);
        $this->assertSame('dave', $generalThread->author);
        $this->assertSame('Please add a return type hint.', $generalThread->body);
        $this->assertNull($generalThread->filePath);
        $this->assertNull($generalThread->line);
        $this->assertFalse($generalThread->resolved);
        $this->assertSame(1, $generalThread->noteCount);
    }

    /**
     * Build a DiscussionReader with a mock HTTP client
     *
     * @return DiscussionReader
     */
    private function buildReader(): DiscussionReader
    {
        $this->callCount = 0;
        $requestFactory  = new Psr17Factory();
        $httpClient      = $this->createMock(HttpClient::class);
        $logger          = $this->createStub(Logger::class);
        $jsonService     = new JsonService($logger);

        $httpClient->method('sendRequest')
            ->willReturnCallback(function (Request $request) use ($requestFactory, $jsonService): Psr7Response {
                $uri = (string) $request->getUri();

                if (preg_match('#/merge_requests/3/discussions$#', $uri) === 1) {
                    $fixturePath = dirname(__DIR__) . '/GitLab/fixtures/mr_discussions.json';
                    $json        = file_get_contents($fixturePath);
                    assert(is_string($json));
                    $fixture = $jsonService->decode($json);

                    $firstPage  = array_slice($fixture, 0, 4);
                    $secondPage = array_slice($fixture, 4);

                    $this->callCount++;

                    if ($this->callCount === 1) {
                        $firstJson = $jsonService->encode($firstPage);
                        return (new Psr7Response(200, ['X-Next-Page' => '5']))
                            ->withBody($requestFactory->createStream($firstJson));
                    }

                    $secondJson = $jsonService->encode($secondPage);
                    return (new Psr7Response(200, ['X-Next-Page' => '']))
                        ->withBody($requestFactory->createStream($secondJson));
                }

                return new Psr7Response(404);
            });

        return new DiscussionReader(
            logger:         $logger,
            httpClient:     $httpClient,
            requestFactory: $requestFactory,
            apiUrl:         'https://gitlab.example.com/api/v4',
            jsonService:    $jsonService,
        );
    }
}
