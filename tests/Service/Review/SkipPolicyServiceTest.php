<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Review;

use Kelpie\DataTransferObject\Review\ReviewDiff;
use Kelpie\Service\Review\SkipPolicyService;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Review\SkipPolicyService
 *
 * What must hold:
 * - `Draft:` and `WIP:` titles are drafts, in any case; a title that merely contains the word is not
 * - An empty diff and a docs-only diff are skipped with a reason; one code file makes it reviewable
 * - The size cap is on lines or bytes, and its note names both numbers
 * - Past the round cap the review stops unless the extra label is on the merge request
 *
 * @coversDefaultClass \Kelpie\Service\Review\SkipPolicyService
 */
final class SkipPolicyServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(SkipPolicyService::class);
    }

    /**
     * Draft and WIP prefixes are drafts
     *
     * @return void
     */
    public function testDraftTitles(): void
    {
        $policy = $this->policy();

        $this->assertTrue($policy->isDraft('Draft: add x'));
        $this->assertTrue($policy->isDraft('  wip add x'));
        $this->assertTrue($policy->isDraft('WIP: add x'));
        $this->assertFalse($policy->isDraft('Add the draft feature'));
        $this->assertFalse($policy->isDraft('Wipe the cache'));
    }

    /**
     * Nothing reviewable and docs-only are skipped; code is not
     *
     * @return void
     */
    public function testSkipReasons(): void
    {
        $policy = $this->policy();

        $this->assertStringContainsString('nothing reviewable', (string) $policy->skipReason(new ReviewDiff('', [])));
        $this->assertSame('only docs changed', $policy->skipReason(new ReviewDiff('x', ['README.md', 'docs/a.rst', 'LICENSE', 'CHANGELOG-1.0', 'notes.txt', 'a/b.adoc'])));
        $this->assertNull($policy->skipReason(new ReviewDiff('x', ['README.md', 'src/A.php'])));
    }

    /**
     * Either cap trips the size guard
     *
     * @return void
     */
    public function testSizeCaps(): void
    {
        $policy = $this->policy();
        $diff   = new ReviewDiff("a\nb\nc\n", ['src/A.php']);

        $this->assertNull($policy->tooLarge(SettingsBuilder::make(), $diff));
        $this->assertStringContainsString('3 lines / 6 bytes (limit 2 lines / 100 bytes)', (string) $policy->tooLarge(SettingsBuilder::make(['maxBytes' => 100, 'maxLines' => 2]), $diff));
        $this->assertStringContainsString('limit 100 lines / 5 bytes', (string) $policy->tooLarge(SettingsBuilder::make(['maxBytes' => 5, 'maxLines' => 100]), $diff));
    }

    /**
     * A fourth round needs the label
     *
     * @return void
     */
    public function testRoundCap(): void
    {
        $policy = $this->policy();

        $this->assertFalse($policy->roundsExhausted(SettingsBuilder::make(), 3));
        $this->assertTrue($policy->roundsExhausted(SettingsBuilder::make(), 4));
        $this->assertFalse($policy->roundsExhausted(SettingsBuilder::make(['labels' => ['review:extra']]), 4));
    }

    /**
     * @return SkipPolicyService
     */
    private function policy(): SkipPolicyService
    {
        return new SkipPolicyService($this->createStub(Logger::class));
    }
}
