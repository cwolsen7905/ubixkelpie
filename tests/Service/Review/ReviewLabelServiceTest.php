<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Review;

use Kelpie\DataTransferObject\Review\Finding;
use Kelpie\DataTransferObject\Review\ReviewResult;
use Kelpie\DataTransferObject\Review\ThreadMemory;
use Kelpie\DataTransferObject\Review\ThreadRow;
use Kelpie\Enum\Review\Confidence;
use Kelpie\Enum\Review\FindingsLevel;
use Kelpie\Enum\Review\ReviewStatus;
use Kelpie\Enum\Review\Severity;
use Kelpie\GitLab\Review\ReviewGitlab;
use Kelpie\Service\Review\FindingMemoryService;
use Kelpie\Service\Review\ReviewLabelService;
use Kelpie\Tests\GitLab\Review\FakeReviewGitlab;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Service\JsonService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Review\ReviewLabelService
 *
 * What must hold:
 * - Setting a status takes every other status off and leaves the MR's other labels alone; the same for the level
 * - A missing label is created once per run, with its colour and description; one the project has is not
 * - A shadow run, or a live run without a token or merge request, calls nothing; a dry run prints and calls nothing
 * - A refused label change never throws
 * - The level is the worst of the open threads and this run's undismissed findings; an open thread of unknown severity
 *   counts as a minor
 *
 * @coversDefaultClass \Kelpie\Service\Review\ReviewLabelService
 */
final class ReviewLabelServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(ReviewLabelService::class);
    }

    /**
     * One status and one level stay on, the rest of each set comes off, other labels stay
     *
     * @return void
     */
    public function testSetsAreExclusive(): void
    {
        $fake = (new FakeReviewGitlab())->withLabels([], ['backend', 'kelpie-review::reviewing', 'kelpie-findings::blocker']);

        $lines = $this->service($fake)->mark(SettingsBuilder::make(), ReviewStatus::Reviewed, FindingsLevel::Major);

        $this->assertSame([], $lines);
        $this->assertSame(['backend', 'kelpie-review::reviewed', 'kelpie-findings::major'], $fake->mrLabels());
    }

    /**
     * Without a level the findings label is left as it is
     *
     * @return void
     */
    public function testNoLevelLeavesTheFindingsLabel(): void
    {
        $fake = (new FakeReviewGitlab())->withLabels([], ['kelpie-review::clean', 'kelpie-findings::none']);

        $this->service($fake)->mark(SettingsBuilder::make(), ReviewStatus::Reviewing, null);

        $this->assertSame(['kelpie-findings::none', 'kelpie-review::reviewing'], $fake->mrLabels());
    }

    /**
     * Missing labels are created once per run with their colour and description; existing ones are not
     *
     * @return void
     */
    public function testCreatesMissingLabelsOnce(): void
    {
        $fake    = (new FakeReviewGitlab())->withLabels(['kelpie-review::clean']);
        $service = $this->service($fake);

        $service->mark(SettingsBuilder::make(), ReviewStatus::Reviewing, null);
        $service->mark(SettingsBuilder::make(), ReviewStatus::Clean, FindingsLevel::None);

        $this->assertCount(count(ReviewStatus::cases()) + count(FindingsLevel::cases()), $fake->projectLabels());
        $this->assertSame(['name' => 'kelpie-review::clean'], $fake->projectLabels()['kelpie-review::clean']);
        $this->assertSame(['color' => '#d9534f', 'description' => ReviewStatus::HardCap->description(), 'name' => 'kelpie-review::hard-cap'], $fake->projectLabels()['kelpie-review::hard-cap']);
        $this->assertCount(1, array_filter($fake->paths('GET'), static function (string $path): bool {
            return str_ends_with($path, '/labels');
        }));
    }

    /**
     * Shadow and unconfigured runs call nothing; a dry run prints what it would set
     *
     * @return void
     */
    public function testShadowDryAndUnconfiguredRuns(): void
    {
        $fake    = new FakeReviewGitlab();
        $service = $this->service($fake);

        $this->assertSame([], $service->mark(SettingsBuilder::make(['shadow' => true]), ReviewStatus::Clean, FindingsLevel::None));
        $this->assertSame([], $service->mark(SettingsBuilder::make(['token' => '']), ReviewStatus::Clean, FindingsLevel::None));
        $this->assertSame([], $service->mark(SettingsBuilder::make(['mrIid' => 0]), ReviewStatus::Clean, FindingsLevel::None));
        $dry = $service->mark(SettingsBuilder::make(['dryRun' => true]), ReviewStatus::LastRound, FindingsLevel::MinorOnly);

        $this->assertSame(["\n--- would set the labels: kelpie-review::last-round, kelpie-findings::minor-only ---"], $dry);
        $this->assertSame([], $fake->requests());
    }

    /**
     * A refused change is logged, not thrown
     *
     * @return void
     */
    public function testRefusedChangeDoesNotThrow(): void
    {
        $fake = (new FakeReviewGitlab())->refuseWrites();

        $this->assertSame([], $this->service($fake)->mark(SettingsBuilder::make(), ReviewStatus::Clean, FindingsLevel::None));
        $this->assertSame([], $fake->mrLabels());
    }

    /**
     * The worst open severity wins; dismissed findings do not count; unknown severity is a minor
     *
     * @return void
     */
    public function testLevel(): void
    {
        $service   = $this->service(new FakeReviewGitlab());
        $dismissed = new ThreadRow('id0000000002', 'b.php', 'Dismissed one', 7, '');
        $memory    = new ThreadMemory(open: [new ThreadRow('id0000000001', 'a.php', 'Open one', 5, '', Severity::Nit)], dismissed: [$dismissed]);
        $blocker   = new ReviewResult('block', 's', [$this->finding(Severity::Blocker, 'id0000000002', 'b.php', 'Dismissed one')]);
        $major     = new ReviewResult('concerns', 's', [$this->finding(Severity::Major, 'id0000000003', 'c.php', 'New one')]);

        $this->assertSame(FindingsLevel::None, $service->level(new ThreadMemory()));
        $this->assertSame(FindingsLevel::MinorOnly, $service->level($memory));
        $this->assertSame(FindingsLevel::MinorOnly, $service->level($memory, $blocker));
        $this->assertSame(FindingsLevel::Major, $service->level($memory, $major));
        $this->assertSame(FindingsLevel::MinorOnly, $service->level(new ThreadMemory(open: [new ThreadRow('id0000000004', 'd.php', 'Old', 1, '')])));
    }

    /**
     * @param Severity $severity The severity
     * @param string   $id       The finding id
     * @param string   $file     The file
     * @param string   $title    The title
     *
     * @return Finding
     */
    private function finding(Severity $severity, string $id, string $file, string $title): Finding
    {
        return new Finding($severity, Confidence::High, $file, 3, $title, 'why', 'slug', $id);
    }

    /**
     * @param FakeReviewGitlab $fake The fake GitLab
     *
     * @return ReviewLabelService
     */
    private function service(FakeReviewGitlab $fake): ReviewLabelService
    {
        $logger  = $this->createStub(Logger::class);
        $factory = new Psr17Factory();

        return new ReviewLabelService($logger, new ReviewGitlab($logger, $fake, $factory, $factory, new JsonService($logger)), new FindingMemoryService($logger));
    }
}
