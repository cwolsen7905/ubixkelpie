<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Triage;

use ArrayObject;
use DateTimeImmutable;
use Kelpie\DataTransferObject\Thread;
use Kelpie\GitLab\FileReader;
use Kelpie\Service\Triage\CodeContextService;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestInterface as Request;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Triage\CodeContextService
 *
 * What must hold:
 * - The file is the thread's position, else the first `path:line` its finding names, read at the MR's head
 * - The excerpt is numbered lines around the line, clamped to the file, and capped
 * - A thread naming no file, a path outside the repository, a binary or unreadable file gives no excerpt
 *
 * @coversDefaultClass \Kelpie\Service\Triage\CodeContextService
 */
final class CodeContextServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * @var ArrayObject<int, string>
     */
    private ArrayObject $requested;

    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(CodeContextService::class);
    }

    /**
     * A diff thread's file is read at the head, and the lines around its line are numbered
     *
     * @return void
     */
    public function testAThreadOnALineGetsTheLinesAroundIt(): void
    {
        $code = $this->service($this->file(200))->excerpt('7', 'head1', $this->thread('Check this.', 'src/a.go', 100));

        $this->assertSame('src/a.go', $code->path);
        $this->assertSame([60, 140], [$code->from, $code->to]);
        $this->assertSame('head1', $code->sha);
        $this->assertStringStartsWith("   60  line 60\n", $code->text);
        $this->assertStringContainsString("  100  line 100\n", $code->text);
        $this->assertStringEndsWith("  140  line 140\n", $code->text);
        $this->assertSame(['/api/v4/projects/7/repository/files/src%2Fa.go/raw?ref=head1'], $this->requested->getArrayCopy());
    }

    /**
     * A general note that names `path:line` in its text gets that file; the excerpt is clamped to the file
     *
     * @return void
     */
    public function testAGeneralNoteNamingALineGetsIt(): void
    {
        $code = $this->service($this->file(10))->excerpt('7', 'h', $this->thread("**major**: ids are unchecked\n\n`internal/store/sqlite/sqlite.go:1526`", null, null));

        $this->assertSame('internal/store/sqlite/sqlite.go', $code->path);
        $this->assertSame([1, 10], [$code->from, $code->to]);
    }

    /**
     * No file named, a path out of the repository, a binary file or a failed read: no excerpt, no error
     *
     * @return void
     */
    public function testNoExcerptWithoutAReadableFile(): void
    {
        $this->assertSame('', $this->service($this->file(5))->excerpt('7', 'h', $this->thread('Rename this please.', null, null))->text);
        $this->assertSame('', $this->service($this->file(5))->excerpt('7', 'h', $this->thread('See ../../etc/passwd:1', null, null))->text);
        $this->assertSame([], $this->requested->getArrayCopy(), 'nothing was read for them');
        $this->assertSame('', $this->service(new Psr7Response(200, [], "a\0b"))->excerpt('7', 'h', $this->thread('x', 'bin/a.png', 1))->text);
        $this->assertSame('', $this->service(new Psr7Response(404, [], ''))->excerpt('7', 'h', $this->thread('x', 'gone.go', 1))->text);
        $this->assertSame('', $this->service($this->file(5))->excerpt('7', '', $this->thread('x', 'a.go', 1))->text);
    }

    /**
     * Long lines shrink the window around the line named, which is always shown, to the size cap
     *
     * @return void
     */
    public function testTheExcerptIsCapped(): void
    {
        $long = implode("\n", array_fill(0, 100, str_repeat('y', 1000)));
        $code = $this->service(new Psr7Response(200, [], $long))->excerpt('7', 'h', $this->thread('x', 'a.go', 50));

        $this->assertLessThanOrEqual(CodeContextService::MAX_CHARS, strlen($code->text));
        $this->assertNotSame('', $code->text);
        $this->assertLessThanOrEqual(50, $code->from);
        $this->assertGreaterThanOrEqual(50, $code->to);
        $this->assertStringContainsString('   50  yyy', $code->text, 'the line the thread names is shown');

        $one = $this->service(new Psr7Response(200, [], str_repeat('z', 50000)))->excerpt('7', 'h', $this->thread('x', 'a.go', 1));
        $this->assertSame(CodeContextService::MAX_CHARS, strlen($one->text), 'one line over the cap is cut');
    }

    /**
     * Create the request log
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->requested = new ArrayObject();
    }

    /**
     * A file of numbered lines
     *
     * @param int $lines How many
     *
     * @return Psr7Response
     */
    private function file(int $lines): Psr7Response
    {
        $out = [];
        for ($n = 1; $n <= $lines; $n++) {
            $out[] = 'line ' . $n;
        }

        return new Psr7Response(200, [], implode("\n", $out));
    }

    /**
     * @param Psr7Response $response What GitLab answers
     *
     * @return CodeContextService
     */
    private function service(Psr7Response $response): CodeContextService
    {
        $requested = $this->requested;
        $http      = $this->createStub(HttpClient::class);
        $http->method('sendRequest')->willReturnCallback(static function (Request $request) use ($requested, $response): Psr7Response {
            $requested->append($request->getRequestTarget());

            return $response;
        });
        $logger = $this->createStub(Logger::class);

        return new CodeContextService($logger, new FileReader($logger, $http, new Psr17Factory(), 'https://gitlab.example.com/api/v4'));
    }

    /**
     * @param string  $body The finding
     * @param ?string $path The file, for a diff note
     * @param ?int    $line The line, for a diff note
     *
     * @return Thread
     */
    private function thread(string $body, ?string $path, ?int $line): Thread
    {
        $now = new DateTimeImmutable('2026-11-27T00:00:00Z');

        return new Thread('d1', 1, 'reviewer', $body, false, 1, $now, $now, $path, $line);
    }
}
