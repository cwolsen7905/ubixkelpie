<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Review;

use Kelpie\DataTransferObject\Review\Finding;
use Kelpie\DataTransferObject\Review\ReviewResult;
use Kelpie\DataTransferObject\Review\ThreadMemory;
use Kelpie\DataTransferObject\Review\ThreadRow;
use Kelpie\Enum\Review\Confidence;
use Kelpie\Enum\Review\Severity;
use Kelpie\Service\Review\ThreadPostService;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Review\ThreadPostService
 *
 * What must hold:
 * - A new finding opens an inline thread carrying its marker; GitLab refusing the position falls back to a plain thread
 * - A dismissed finding and an already open one open nothing; a finding not about one line goes in the summary, not a thread
 * - A finding on a line an open thread covers is listed instead of posted, unless it is a blocker
 * - Standing high-confidence blockers and majors are counted whether or not they open a thread
 * - An open thread the model did not re-raise is listed as possibly fixed only when its lines changed
 * - A shadow run calls nothing and lists what it would open; a dry run prints it
 *
 * @coversDefaultClass \Kelpie\Service\Review\ThreadPostService
 */
final class ThreadPostServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(ThreadPostService::class);
    }

    /**
     * A new finding opens an inline thread with the marker the next run reads back
     *
     * @return void
     */
    public function testNewFindingOpensAnInlineThread(): void
    {
        $harness = new ReviewHarness($this->createStub(Logger::class));

        $tally = $harness->poster()->post(SettingsBuilder::make(), $this->review($this->finding('a.php', 5, 'First', 'id0000000001')), new ThreadMemory());

        $this->assertSame(1, $tally->posted);
        $thread = $harness->gitlab()->threads()[0];
        $this->assertIsString($thread['body']);
        $this->assertStringContainsString('<!-- ai-review:fp=id0000000001 sha=abcdef1234567890abcdef1234567890abcdef12 -->', $thread['body']);
        $this->assertIsArray($thread['position']);
        $this->assertSame(5, $thread['position']['new_line']);
    }

    /**
     * A refused inline position still opens a plain thread
     *
     * @return void
     */
    public function testRefusedPositionStillOpensAThread(): void
    {
        $harness = new ReviewHarness($this->createStub(Logger::class));
        $harness->gitlab()->refuseInline();

        $tally = $harness->poster()->post(SettingsBuilder::make(), $this->review($this->finding('a.php', 5, 'First', 'id0000000001')), new ThreadMemory());

        $this->assertSame([1, 0], [$tally->posted, $tally->failed]);
    }

    /**
     * Writes GitLab refuses are counted as failed
     *
     * @return void
     */
    public function testRefusedWritesAreFailures(): void
    {
        $harness = new ReviewHarness($this->createStub(Logger::class));
        $harness->gitlab()->refuseWrites();

        $tally = $harness->poster()->post(SettingsBuilder::make(), $this->review($this->finding('a.php', 5, 'First', 'id0000000001')), new ThreadMemory());

        $this->assertSame([0, 1], [$tally->posted, $tally->failed]);
    }

    /**
     * Dismissed and already open findings open nothing
     *
     * @return void
     */
    public function testDismissedAndOpenFindingsOpenNothing(): void
    {
        $harness = new ReviewHarness($this->createStub(Logger::class));
        $memory  = new ThreadMemory(open: [new ThreadRow('id0000000001', 'a.php', 'Open one', 5, '')], dismissed: [new ThreadRow('id0000000002', 'b.php', 'Dismissed one', 7, '')]);

        $tally = $harness->poster()->post(SettingsBuilder::make(), $this->review(
            $this->finding('a.php', 5, 'Open one', 'id0000000001'),
            $this->finding('b.php', 9, 'Dismissed one', 'id0000000002'),
        ), $memory);

        $this->assertSame([0, 1, 1], [$tally->posted, $tally->dismissed, $tally->stillOpen]);
        $this->assertSame([], $harness->gitlab()->threads());
    }

    /**
     * A finding with no line is in the summary, not a thread
     *
     * @return void
     */
    public function testFileLevelFindingIsListed(): void
    {
        $harness = new ReviewHarness($this->createStub(Logger::class));

        $tally = $harness->poster()->post(SettingsBuilder::make(), $this->review($this->finding('a.php', 0, 'Whole file', 'id0000000001')), new ThreadMemory());

        $this->assertSame(0, $tally->posted);
        $this->assertCount(1, $tally->fileLevel);
        $this->assertStringContainsString('`a.php`: **major** (high): Whole file.', $tally->fileLevel[0]);
        $this->assertSame([], $harness->gitlab()->threads());
    }

    /**
     * A reworded finding on a line an open thread covers is listed, but a blocker is still posted
     *
     * @return void
     */
    public function testSameLineIsListedExceptBlockers(): void
    {
        $harness = new ReviewHarness($this->createStub(Logger::class));
        $memory  = new ThreadMemory(open: [new ThreadRow('id0000000009', 'a.php', 'Original wording', 5, '')]);

        $tally = $harness->poster()->post(SettingsBuilder::make(), $this->review(
            $this->finding('a.php', 5, 'Reworded', 'id0000000001'),
            $this->finding('a.php', 5, 'A blocker', 'id0000000002', Severity::Blocker),
        ), $memory);

        $this->assertSame(1, $tally->posted);
        $this->assertCount(1, $tally->sameLine);
        $this->assertStringContainsString('Reworded', $tally->sameLine[0]);
        $this->assertCount(1, $harness->gitlab()->threads());
    }

    /**
     * High-confidence blockers and majors count, even when dismissed ones do not and medium ones never do
     *
     * @return void
     */
    public function testBlockingCount(): void
    {
        $harness = new ReviewHarness($this->createStub(Logger::class));
        $memory  = new ThreadMemory(dismissed: [new ThreadRow('id0000000003', 'c.php', 'Gone', 1, '')]);

        $tally = $harness->poster()->post(SettingsBuilder::make(), $this->review(
            $this->finding('a.php', 1, 'One', 'id0000000001', Severity::Blocker),
            $this->finding('a.php', 2, 'Two', 'id0000000002', Severity::Major, Confidence::Medium),
            $this->finding('c.php', 3, 'Gone', 'id0000000003', Severity::Blocker),
            $this->finding('a.php', 4, 'Four', 'id0000000004', Severity::Minor),
            $this->finding('a.php', 0, 'Five', 'id0000000005', Severity::Major),
        ), $memory);

        $this->assertSame(2, $tally->blocking);
    }

    /**
     * An open thread not re-raised is "possibly fixed" only when the lines it points at changed
     *
     * @return void
     */
    public function testStaleOnlyWhenTheLinesChanged(): void
    {
        $harness = new ReviewHarness($this->createStub(Logger::class));
        $harness->git()->answer('cat-file -e old1^{commit}', '')->answer('diff -U0 --no-color old1', "@@ -4 +4 @@\n");
        $memory = new ThreadMemory(open: [
            new ThreadRow('id0000000001', 'a.php', 'Moved code', 5, 'old1'),
            new ThreadRow('id0000000002', 'b.php', 'Untouched', 90, 'old1'),
            new ThreadRow('id0000000003', 'c.php', 'Unknown age', 5, ''),
        ]);

        $tally = $harness->poster()->post(SettingsBuilder::make(), $this->review(), $memory);

        $this->assertSame(['- `a.php`: Moved code'], $tally->stale);
        $this->assertSame(2, $tally->stillOpen);
    }

    /**
     * A re-raised open thread is never stale
     *
     * @return void
     */
    public function testReRaisedThreadIsNotStale(): void
    {
        $harness = new ReviewHarness($this->createStub(Logger::class));
        $harness->git()->answer('cat-file -e old1^{commit}', '')->answer('diff -U0 --no-color old1', "@@ -4 +4 @@\n");
        $memory = new ThreadMemory(open: [new ThreadRow('id0000000001', 'a.php', 'Moved code', 5, 'old1')]);

        $tally = $harness->poster()->post(SettingsBuilder::make(), $this->review($this->finding('a.php', 5, 'Moved code', 'id0000000001')), $memory);

        $this->assertSame([], $tally->stale);
    }

    /**
     * A shadow run calls GitLab not at all and lists what it would open
     *
     * @return void
     */
    public function testShadowRunListsAndCallsNothing(): void
    {
        $harness = new ReviewHarness($this->createStub(Logger::class));

        $tally = $harness->poster()->post(SettingsBuilder::make(['shadow' => true]), $this->review($this->finding('a.php', 5, 'First', 'id0000000001')), new ThreadMemory());

        $this->assertSame(1, $tally->posted);
        $this->assertCount(1, $tally->listed);
        $this->assertSame([], $harness->gitlab()->requests());
    }

    /**
     * A dry run prints the thread and calls nothing
     *
     * @return void
     */
    public function testDryRunPrintsTheThread(): void
    {
        $harness = new ReviewHarness($this->createStub(Logger::class));

        $tally = $harness->poster()->post(SettingsBuilder::make(['dryRun' => true]), $this->review($this->finding('a.php', 5, 'First', 'id0000000001')), new ThreadMemory());

        $this->assertSame(1, $tally->posted);
        $this->assertStringContainsString('--- would open a thread ---', $tally->output[0]);
        $this->assertSame([], $harness->gitlab()->requests());
    }

    /**
     * @param Finding ...$findings The findings
     *
     * @return ReviewResult
     */
    private function review(Finding ...$findings): ReviewResult
    {
        return new ReviewResult('concerns', 'Summary.', array_values($findings));
    }

    /**
     * @param string     $file       The file
     * @param int        $line       The line
     * @param string     $title      The title
     * @param string     $id         The id
     * @param Severity   $severity   The severity
     * @param Confidence $confidence The confidence
     *
     * @return Finding
     */
    private function finding(string $file, int $line, string $title, string $id, Severity $severity = Severity::Major, Confidence $confidence = Confidence::High): Finding
    {
        return new Finding($severity, $confidence, $file, $line, $title, 'because', 'slug-' . $id, $id);
    }
}
