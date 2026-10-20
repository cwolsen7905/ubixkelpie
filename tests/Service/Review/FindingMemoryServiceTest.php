<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Review;

use Kelpie\DataTransferObject\Review\Finding;
use Kelpie\DataTransferObject\Review\ThreadMemory;
use Kelpie\Enum\Review\Confidence;
use Kelpie\Enum\Review\Severity;
use Kelpie\Service\Review\FindingMemoryService;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Service\JsonService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Review\FindingMemoryService
 *
 * What must hold:
 * - A thread is open until someone resolves it; resolved with a person's `Dismissed:` reply it is dismissed; resolved otherwise it is forgotten
 * - A `Dismissed:` reply by the review's own token does not count
 * - A new finding matches a thread by id, or by file and title when the model reworded the slug
 * - The review's summary note is found by its marker, and a shadow run does not see the real one
 * - The model is told what is raised in words
 *
 * @coversDefaultClass \Kelpie\Service\Review\FindingMemoryService
 */
final class FindingMemoryServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(FindingMemoryService::class);
    }

    /**
     * Open threads and the summary note are read from the recorded discussions
     *
     * @return void
     */
    public function testReadsOpenThreadsAndTheSummary(): void
    {
        $memory = $this->memoryOf('existing-open.json');

        $this->assertCount(2, $memory->open);
        $this->assertSame([], $memory->dismissed);
        $this->assertSame(3, $memory->summaryNoteId);
        $this->assertSame([1, 'abc'], [$memory->summaryRound, $memory->summarySha]);
        $row = $memory->open[0];
        $this->assertSame(['aaaaaaaaaaaa', 'src/Transfer.php', 'Withdraw and deposit are saved separately', 15], [$row->id, $row->file, $row->title, $row->line]);
    }

    /**
     * A person's Dismissed reply on a resolved thread dismisses it; the thread is then not open
     *
     * @return void
     */
    public function testDismissedByAPerson(): void
    {
        $memory = $this->memoryOf('existing-discussions.json');

        $this->assertSame([], $memory->open);
        $this->assertCount(1, $memory->dismissed);
        $this->assertSame('3f425c301613', $memory->dismissed[0]->id);
    }

    /**
     * Resolved without the word, or dismissed by the bot itself, is neither open nor dismissed
     *
     * @return void
     */
    public function testResolvedAndBotDismissalsAreIgnored(): void
    {
        $first = "**major** (high confidence): T\n\n`a.php:1`\n<!-- ai-review:fp=aaaaaaaaaaaa -->";

        $fixed = $this->service()->read(SettingsBuilder::make(), [$this->resolvedThread($first, 'Fixed, thanks', 5)], 9);
        $bot   = $this->service()->read(SettingsBuilder::make(), [$this->resolvedThread($first, 'Dismissed: bot said so', 9)], 9);
        $lower = $this->service()->read(SettingsBuilder::make(), [$this->resolvedThread($first, '  dismissed: person says so', 5)], 9);

        $this->assertSame([[], []], [$fixed->open, $fixed->dismissed]);
        $this->assertSame([[], []], [$bot->open, $bot->dismissed]);
        $this->assertCount(1, $lower->dismissed);
    }

    /**
     * A shadow run looks for its own summary note, not the real review's
     *
     * @return void
     */
    public function testShadowRunDoesNotSeeTheRealSummary(): void
    {
        $discussions = $this->discussions('existing-open.json');

        $shadow = $this->service()->read(SettingsBuilder::make(['shadow' => true]), $discussions, 9);

        $this->assertNull($shadow->summaryNoteId);
        $own = $this->service()->read(SettingsBuilder::make(['shadow' => true]), [['notes' => [['body' => "<!-- kelpie-review:shadow round=2 sha=def -->\nx", 'id' => 8]]]], 9);
        $this->assertSame([8, 2, 'def'], [$own->summaryNoteId, $own->summaryRound, $own->summarySha]);
    }

    /**
     * A finding matches an open thread by id, by file and title, and not otherwise
     *
     * @return void
     */
    public function testMatchingByIdAndByFileAndTitle(): void
    {
        $memory  = $this->memoryOf('existing-open.json');
        $service = $this->service();

        $this->assertTrue($service->isOpen($memory, $this->finding('src/Fee.php', 3, 'MAGIC NUMBER in fee calculation', 'zzzzzzzzzzzz')));
        $this->assertTrue($service->isOpen($memory, $this->finding('src/Other.php', 3, 'whatever', 'aaaaaaaaaaaa')));
        $this->assertFalse($service->isOpen($memory, $this->finding('src/Fee.php', 3, 'A different problem', 'cccccccccccc')));
        $this->assertFalse($service->isDismissed($memory, $this->finding('src/Fee.php', 3, 'Magic number in fee calculation', 'bbbbbbbbbbbb')));
    }

    /**
     * An open thread on the same file and line covers the finding
     *
     * @return void
     */
    public function testCoversLine(): void
    {
        $memory = $this->memoryOf('existing-open.json');

        $this->assertTrue($this->service()->coversLine($memory, $this->finding('src/Transfer.php', 15, 'x', 'x1')));
        $this->assertFalse($this->service()->coversLine($memory, $this->finding('src/Transfer.php', 16, 'x', 'x1')));
        $this->assertFalse($this->service()->coversLine($memory, $this->finding('src/Fee.php', 15, 'x', 'x1')));
    }

    /**
     * The model is told what was raised in words, and what was dismissed
     *
     * @return void
     */
    public function testRaisedList(): void
    {
        $service = $this->service();

        $open      = $service->raisedList($this->memoryOf('existing-open.json'));
        $dismissed = $service->raisedList($this->memoryOf('existing-discussions.json'));

        $this->assertStringContainsString('- src/Fee.php: Magic number in fee calculation (open)', $open);
        $this->assertStringContainsString('(dismissed by a person; do not raise again)', $dismissed);
    }

    /**
     * @param string $first  The finding thread's first note
     * @param string $reply  The reply
     * @param int    $author The reply's author
     *
     * @return array<string, mixed>
     */
    private function resolvedThread(string $first, string $reply, int $author): array
    {
        return ['notes' => [['author' => ['id' => 9], 'body' => $first, 'id' => 1, 'resolved' => true], ['author' => ['id' => $author], 'body' => $reply, 'id' => 2, 'resolved' => true]]];
    }

    /**
     * @param string $fixture The discussions fixture
     *
     * @return ThreadMemory
     */
    private function memoryOf(string $fixture): ThreadMemory
    {
        return $this->service()->read(SettingsBuilder::make(), $this->discussions($fixture), 9);
    }

    /**
     * @param string $fixture The discussions fixture
     *
     * @return list<mixed>
     */
    private function discussions(string $fixture): array
    {
        return array_values((new JsonService($this->createStub(Logger::class)))->decode((string) file_get_contents(SettingsBuilder::fixtures() . '/' . $fixture)));
    }

    /**
     * @param string $file  The file
     * @param int    $line  The line
     * @param string $title The title
     * @param string $id    The id
     *
     * @return Finding
     */
    private function finding(string $file, int $line, string $title, string $id): Finding
    {
        return new Finding(Severity::Minor, Confidence::High, $file, $line, $title, 'why', 'slug', $id);
    }

    /**
     * @return FindingMemoryService
     */
    private function service(): FindingMemoryService
    {
        return new FindingMemoryService($this->createStub(Logger::class));
    }
}
