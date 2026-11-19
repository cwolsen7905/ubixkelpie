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
     * Every thread comes back with its notes and its state: resolved and non-resolvable ones too, system-only ones not
     *
     * @return void
     */
    public function testDiscussionsReturnEveryThreadWithItsNotes(): void
    {
        $threads = $this->buildReader($this->fixtureResponder())->discussions(1, 3);

        $initials = [];
        foreach ($threads as $thread) {
            $initials[] = $thread->discussionId[0];
        }
        $this->assertSame(['b', 'c', 'd', 'e', 'f'], $initials);
        $this->assertSame([false, true, false, false, false], array_column($threads, 'resolved'));
        $this->assertSame([true, true, false, true, true], array_column($threads, 'resolvable'));
        $this->assertSame([101, 102], array_column($threads[0]->notes, 'id'));
        $this->assertSame('bob', $threads[0]->notes[0]->author);
        $this->assertSame([107], array_column($threads[4]->notes, 'id'));
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
     * The newest add event of the label gives when it was put on; other labels, removals, a failed read and a history longer than the pages followed give nothing
     *
     * @return void
     */
    public function testLabelAddedAt(): void
    {
        $events = (new JsonService($this->createStub(Logger::class)))->encode([
            ['action' => 'add', 'created_at' => '2026-10-01T10:00:00.000Z', 'label' => ['name' => 'kelpie::working']],
            ['action' => 'remove', 'created_at' => '2026-10-01T10:30:00.000Z', 'label' => ['name' => 'kelpie::working']],
            ['action' => 'add', 'created_at' => '2026-10-01T11:00:00.000Z', 'label' => ['name' => 'kelpie::working']],
            ['action' => 'add', 'created_at' => '2026-10-01T12:00:00.000Z', 'label' => ['name' => 'bug']],
        ]);
        $reader = $this->buildReader(static function () use ($events): Psr7Response {
            return new Psr7Response(200, [], $events);
        });

        $this->assertSame('2026-10-01T11:00:00', $reader->labelAddedAt(1, 3, 'kelpie::working')?->format('Y-m-d\TH:i:s'));
        $this->assertNull($reader->labelAddedAt(1, 3, 'kelpie::pushed'));

        $failing = $this->buildReader(static function (): Psr7Response {
            return new Psr7Response(500);
        }, $this->createStub(Logger::class));
        $broken  = $this->buildReader(static function (): Psr7Response {
            return new Psr7Response(200, [], '{not json');
        }, $this->createStub(Logger::class));

        $endless = $this->buildReader(static function (): Psr7Response {
            return new Psr7Response(200, ['X-Next-Page' => '9'], '[{"action":"add","created_at":"2026-10-01T10:00:00.000Z","label":{"name":"kelpie::working"}}]');
        });

        $this->assertNull($endless->labelAddedAt(1, 3, 'kelpie::working'));
        $this->assertNull($failing->labelAddedAt(1, 3, 'kelpie::working'));
        $this->assertNull($broken->labelAddedAt(1, 3, 'kelpie::working'));
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
