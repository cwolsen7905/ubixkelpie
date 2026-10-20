<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Review;

use Kelpie\DataTransferObject\Review\Finding;
use Kelpie\DataTransferObject\Review\ReviewDiff;
use Kelpie\DataTransferObject\Review\ReviewResult;
use Kelpie\DataTransferObject\Review\ReviewTally;
use Kelpie\DataTransferObject\Review\ReviewUsage;
use Kelpie\Enum\Review\Confidence;
use Kelpie\Enum\Review\Severity;
use Kelpie\Service\Review\SummaryRenderService;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Review\SummaryRenderService
 *
 * What must hold:
 * - A line starting with `/` in model or diff text cannot be a GitLab quick action
 * - The stamp names model, effort (or "default"), tokens and cost after a model call, and says "no model call" otherwise
 * - A thread body carries the marker with the finding id and the commit, which the next run reads back
 * - A clean review is a plain note naming model, commit and files; a review with findings counts them and lists what opened no thread
 *
 * @coversDefaultClass \Kelpie\Service\Review\SummaryRenderService
 */
final class SummaryRenderServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(SummaryRenderService::class);
    }

    /**
     * A leading slash becomes a character reference
     *
     * @return void
     */
    public function testNeutralizeDefusesQuickActions(): void
    {
        $text = "fine\n/merge\n  /approve\nnot/a/command";

        $this->assertSame("fine\n&#47;merge\n  &#47;approve\nnot/a/command", $this->render()->neutralize($text));
    }

    /**
     * The stamp after a model call
     *
     * @return void
     */
    public function testStampAfterAModelCall(): void
    {
        $stamp = $this->render()->stamp(SettingsBuilder::make(['effort' => 'high']), new ReviewUsage(12345, 999, 12345), true);

        $this->assertSame('_Model `openrouter/anthropic/claude-haiku-5.5`, effort high, 12.3k in / 999 out, $0.0123 (OpenRouter), on `abcdef12`._', $stamp);
        $this->assertStringContainsString('effort default', $this->render()->stamp(SettingsBuilder::make(), new ReviewUsage(), true));
    }

    /**
     * The stamp when no model ran
     *
     * @return void
     */
    public function testStampWithoutAModelCall(): void
    {
        $this->assertSame('_No model call on `abcdef12`: no model, effort, tokens or cost to report._', $this->render()->stamp(SettingsBuilder::make(), new ReviewUsage(), false));
    }

    /**
     * The note starts with the marker that carries round and commit, and ends with the stamp
     *
     * @return void
     */
    public function testNoteMarkerAndStamp(): void
    {
        $note = $this->render()->note(SettingsBuilder::make(), 'BODY', 2, 'STAMP');

        $this->assertStringStartsWith('<!-- ai-review:summary round=2 sha=abcdef1234567890abcdef1234567890abcdef12 -->' . "\nBODY", $note);
        $this->assertStringEndsWith("\n\nSTAMP", $note);
    }

    /**
     * A shadow note has its own marker and says it is not a review
     *
     * @return void
     */
    public function testShadowNoteIsLabelled(): void
    {
        $note = $this->render()->note(SettingsBuilder::make(['shadow' => true]), 'BODY', 1, 'S');

        $this->assertStringStartsWith('<!-- kelpie-review:shadow round=1 sha=', $note);
        $this->assertStringContainsString('Shadow run of the new review engine', $note);
    }

    /**
     * The thread body carries the marker, the position and the dismiss hint, with quick actions defused
     *
     * @return void
     */
    public function testThreadBody(): void
    {
        $body = $this->render()->threadBody($this->finding(15, "Explains\n/merge it"), 'abc123');

        $this->assertStringStartsWith('**major** (high confidence): Title', $body);
        $this->assertStringContainsString('`src/A.php:15`', $body);
        $this->assertStringContainsString('&#47;merge it', $body);
        $this->assertStringContainsString('resolve it with a reply starting `Dismissed: <reason>`', $body);
        $this->assertStringEndsWith('<!-- ai-review:fp=0123456789ab sha=abc123 -->', $body);
    }

    /**
     * Summary lines for findings with no thread
     *
     * @return void
     */
    public function testFindingLines(): void
    {
        $finding = $this->finding(0, "Line one\nline two");

        $this->assertSame('- `src/A.php`: **major** (high): Title. Line one line two', $this->render()->lineFor($finding, true));
        $this->assertSame('- `src/A.php`: Title', $this->render()->lineFor($finding, false));
        $this->assertSame('- `src/A.php:4`: Title', $this->render()->lineFor($this->finding(4, 'x'), false));
    }

    /**
     * A review with findings: verdict, counts, and the sections for what opened no thread
     *
     * @return void
     */
    public function testFindingsSummary(): void
    {
        $result = new ReviewResult('block', 'It moves money.', [$this->finding(1, 'a'), $this->finding(2, 'b')]);
        $tally  = new ReviewTally(posted: 1, dismissed: 2, stillOpen: 3, fileLevel: ['- F'], sameLine: ['- S'], stale: ['- T'], listed: ['- L']);

        $text = $this->render()->noteWithFindings(SettingsBuilder::make(), $result, $tally, 2);

        $this->assertStringStartsWith('**AI review: block**, round 2 of 3 (`advisory` mode)', $text);
        $this->assertStringContainsString('Findings: 2 major. New threads: 1. Already open: 3. Dismissed: 2.', $text);
        foreach (['Not tied to one line', '- F', 'the line already has an open thread', '- S', 'no longer reports', '- T', 'a shadow run opens no threads', '- L'] as $part) {
            $this->assertStringContainsString($part, $text);
        }
        $this->assertStringContainsString('Resolve a thread with `Dismissed: <reason>`', $text);
    }

    /**
     * A clean review names model, commit and files, and trims a long file list
     *
     * @return void
     */
    public function testCleanNote(): void
    {
        $files = [];
        for ($i = 1; $i <= 22; $i++) {
            $files[] = 'src/F' . $i . '.php';
        }

        $text = $this->render()->clean(SettingsBuilder::make(), new ReviewResult('pass', 'All fine.', []), new ReviewDiff('x', $files), 1);

        $this->assertStringStartsWith('**AI review: ran, no findings**, round 1 of 3 (`advisory` mode)', $text);
        $this->assertStringContainsString('reviewed commit `abcdef1234567890abcdef1234567890abcdef12`', $text);
        $this->assertStringContainsString('- `src/F20.php`', $text);
        $this->assertStringNotContainsString('src/F21.php', $text);
        $this->assertStringContainsString('- ... and 2 more', $text);
    }

    /**
     * @param int    $line The line
     * @param string $why  The reasoning
     *
     * @return Finding
     */
    private function finding(int $line, string $why): Finding
    {
        return new Finding(Severity::Major, Confidence::High, 'src/A.php', $line, 'Title', $why, 'slug', '0123456789ab');
    }

    /**
     * @return SummaryRenderService
     */
    private function render(): SummaryRenderService
    {
        return new SummaryRenderService($this->createStub(Logger::class));
    }
}
