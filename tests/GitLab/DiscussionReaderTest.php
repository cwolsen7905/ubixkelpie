<?php

declare(strict_types=1);

namespace Kelpie\Tests\GitLab;

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
 * - Only unresolved, resolvable threads are returned (resolved, non-resolvable and system-only are not)
 * - Diff notes carry file path and line; general notes do not
 * - Pagination follows X-Next-Page with the page number, and stops
 * - A failing page is logged and ends the read without throwing
 *
 * @coversDefaultClass \Kelpie\GitLab\DiscussionReader
 */
final class DiscussionReaderTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * URLs requested through the stubbed HTTP client, in order
     *
     * @var list<string>
     */
    private array $requested = [];

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
     * Only unresolved, resolvable threads are returned, across both pages
     *
     * @return void
     */
    public function testOnlyUnresolvedResolvableThreadsAreReturned(): void
    {
        $threads = $this->buildReader($this->fixtureResponder())->read(1, 3);

        $this->assertSame(
            [str_repeat('b', 40), str_repeat('e', 40), str_repeat('f', 40)],
            array_column($threads, 'discussionId'),
        );

        foreach ($threads as $thread) {
            $this->assertFalse($thread->resolved);
        }
    }

    /**
     * Pagination requests page 1 then page 2 and stops when X-Next-Page is empty
     *
     * @return void
     */
    public function testPaginationRequestsEachPageOnce(): void
    {
        $this->buildReader($this->fixtureResponder())->read(7, 3);

        $this->assertSame([
            'https://gitlab.example.com/api/v4/projects/7/merge_requests/3/discussions?per_page=100&page=1',
            'https://gitlab.example.com/api/v4/projects/7/merge_requests/3/discussions?per_page=100&page=2',
        ], $this->requested);
    }

    /**
     * A thread carries the first human note's fields and counts only human notes
     *
     * @return void
     */
    public function testDiffThreadFieldsComeFromTheFirstNote(): void
    {
        $thread = $this->buildReader($this->fixtureResponder())->read(1, 3)[0];

        $this->assertSame(101, $thread->firstNoteId);
        $this->assertSame('bob', $thread->author);
        $this->assertSame('This should use a typed property.', $thread->body);
        $this->assertSame('src/processor.php', $thread->filePath);
        $this->assertSame(42, $thread->line);
        $this->assertSame(2, $thread->noteCount);
        $this->assertSame('2026-10-02T09:00:00+00:00', $thread->createdAt->format('c'));
        $this->assertSame('2026-10-02T11:30:00+00:00', $thread->updatedAt->format('c'));
    }

    /**
     * General notes have no file path or line; a leading system note is not the first note
     *
     * @return void
     */
    public function testGeneralAndSystemLeadingThreads(): void
    {
        $threads = $this->buildReader($this->fixtureResponder())->read(1, 3);

        $this->assertNull($threads[1]->filePath);
        $this->assertNull($threads[1]->line);
        $this->assertSame('dave', $threads[1]->author);

        $this->assertSame(107, $threads[2]->firstNoteId);
        $this->assertSame('erin', $threads[2]->author);
        $this->assertSame(1, $threads[2]->noteCount);
    }

    /**
     * A non-200 response is logged and yields what was read so far, without throwing
     *
     * @return void
     */
    public function testHttpErrorEndsTheReadAndIsLogged(): void
    {
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())->method('error');

        $reader = $this->buildReader(static function (): Psr7Response {
            return new Psr7Response(500);
        }, $logger);

        $this->assertSame([], $reader->read(1, 3));
    }

    /**
     * An undecodable body is logged and yields no threads, without throwing
     *
     * @return void
     */
    public function testMalformedJsonEndsTheReadAndIsLogged(): void
    {
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())->method('error');

        $reader = $this->buildReader(static function (): Psr7Response {
            return new Psr7Response(200, [], '{not json');
        }, $logger);

        $this->assertSame([], $reader->read(1, 3));
    }

    /**
     * Build a responder serving the two fixture pages, recording each requested URL
     *
     * @return callable(Request): Psr7Response
     */
    private function fixtureResponder(): callable
    {
        return function (Request $request): Psr7Response {
            $uri               = (string) $request->getUri();
            $this->requested[] = $uri;
            $page              = str_ends_with($uri, 'page=1') ? 1 : 2;
            $json              = file_get_contents(__DIR__ . '/fixtures/mr_discussions_page' . $page . '.json');

            return new Psr7Response(200, ['X-Next-Page' => $page === 1 ? '2' : ''], $json === false ? '' : $json);
        };
    }

    /**
     * Build a DiscussionReader whose HTTP client answers with the given responder
     *
     * @param callable(Request): Psr7Response $responder Produces the response for a request
     * @param ?Logger                         $logger    Logger (a stub when null)
     *
     * @return DiscussionReader
     */
    private function buildReader(callable $responder, ?Logger $logger = null): DiscussionReader
    {
        $logger   ??= $this->createStub(Logger::class);
        $httpClient = $this->createStub(HttpClient::class);
        $httpClient->method('sendRequest')->willReturnCallback($responder);

        return new DiscussionReader(
            logger:         $logger,
            httpClient:     $httpClient,
            requestFactory: new Psr17Factory(),
            apiUrl:         'https://gitlab.example.com/api/v4',
            jsonService:    new JsonService($logger),
        );
    }
}
