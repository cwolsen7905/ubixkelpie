<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Spend;

use DateTimeImmutable;
use Kelpie\DataTransferObject\Project;
use Kelpie\DataTransferObject\ThreadOutcomeRecord;
use Kelpie\Enum\ProjectMode;
use Kelpie\Enum\ThreadOutcome;
use Kelpie\Repository\Project\ProjectReaderInterface as ProjectReader;
use Kelpie\Service\Spend\SpendCapService;
use Kelpie\Tests\Service\Autofix\InMemoryOutcomeStore;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Spend\SpendCapService
 *
 * What must hold:
 * - Under both caps nothing is reached; passing a soft cap does not stop the run
 * - A project at its own hard cap is held; a project with no row is reported as not enrolled, not as over a cap
 * - Spend across projects at the global hard cap holds every project
 * - The global hard cap comes from the environment; unset or bad means unlimited, 0 blocks everything
 *
 * @coversDefaultClass \Kelpie\Service\Spend\SpendCapService
 */
final class SpendCapServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    private InMemoryOutcomeStore $outcomes;

    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(SpendCapService::class);
    }

    /**
     * @return void
     */
    public function testUnderBothCapsIsNotExceeded(): void
    {
        $this->spend('g/p', 1.99);

        $this->assertNull($this->service()->check('g/p')->cap);
    }

    /**
     * @return void
     */
    public function testProjectAtItsHardCapIsHeld(): void
    {
        $this->spend('g/p', 2.0);

        $check = $this->service('2.00')->check('g/p');

        $this->assertSame('project', $check->cap);
        $this->assertSame(2.0, $check->spent);
        $this->assertSame(2.0, $check->limit);
    }

    /**
     * Passing the soft cap does not stop the run while the hard cap is unset
     *
     * @return void
     */
    public function testProjectSoftCapIsNotAStop(): void
    {
        $this->spend('g/p', 2.0);

        $this->assertNull($this->service()->check('g/p')->cap);
    }

    /**
     * The soft warning is logged once per project per day, not on every check
     *
     * @return void
     */
    public function testSoftWarningIsLoggedOncePerDay(): void
    {
        $this->spend('g/p', 2.0);
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())->method('warning')->with('Soft spend cap passed: project daily spend');
        $projects = $this->createStub(ProjectReader::class);
        $projects->method('getByPath')->willReturn(new Project('g/p', ProjectMode::Propose));
        $service = new SpendCapService($logger, $projects, $this->outcomes);

        $service->check('g/p');
        $service->check('g/p');
        $service->check('g/p');
    }

    /**
     * @return void
     */
    public function testRerunsOnOneThreadAllCount(): void
    {
        $this->spend('g/p', 1.0);
        $this->spend('g/p', 1.0);

        $check = $this->service('2.00')->check('g/p');

        $this->assertSame('project', $check->cap);
        $this->assertSame(2.0, $check->spent);
    }

    /**
     * @return void
     */
    public function testYesterdaysSpendIsNotCountedToday(): void
    {
        $this->spend('g/p', 5.0);
        $this->outcomes->backdate('g/p', 1, 'd1', new DateTimeImmutable('yesterday 12:00'));
        $this->spend('g/p', 0.1);

        $check = $this->service()->check('g/p');

        $this->assertNull($check->cap);
    }

    /**
     * @return void
     */
    public function testProjectWithoutARowIsNotEnrolled(): void
    {
        $this->assertSame('not_enrolled', $this->service()->check('g/none')->cap);
    }

    /**
     * @return void
     */
    public function testGlobalHardCapHoldsAProjectThatIsUnderItsOwn(): void
    {
        putenv('KELPIE_MAX_COST_USD_DAY_GLOBAL_HARD=3.5');
        $this->spend('g/other', 2.0, 'a');
        $this->spend('g/other', 1.5, 'b');
        $this->spend('g/p', 0.1);

        $check = $this->service()->check('g/p');

        $this->assertSame('global', $check->cap);
        $this->assertSame(3.6, round($check->spent, 6));
        $this->assertSame(3.5, $check->limit);
    }

    /**
     * The global hard cap is unlimited when it is unset or not a non-negative number, so spend never stops; set, it holds
     *
     * @return void
     */
    public function testGlobalHardCapIsUnlimitedWhenUnsetOrInvalid(): void
    {
        $this->spend('g/other', 99.99, 'a');
        $this->assertNull($this->service()->check('g/p')->cap);

        foreach (['', 'lots', '-1'] as $bad) {
            putenv('KELPIE_MAX_COST_USD_DAY_GLOBAL_HARD=' . $bad);
            $this->assertNull($this->service()->check('g/p')->cap, $bad);
        }

        putenv('KELPIE_MAX_COST_USD_DAY_GLOBAL_HARD=10');
        $this->assertSame('global', $this->service()->check('g/p')->cap);
    }

    /**
     * @return void
     */
    public function testZeroGlobalHardCapBlocksEverything(): void
    {
        putenv('KELPIE_MAX_COST_USD_DAY_GLOBAL_HARD=0');

        $this->assertSame('global', $this->service()->check('g/p')->cap);
    }

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->outcomes = new InMemoryOutcomeStore();
        putenv('KELPIE_MAX_COST_USD_DAY_GLOBAL');
        putenv('KELPIE_MAX_COST_USD_DAY_GLOBAL_HARD');
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        putenv('KELPIE_MAX_COST_USD_DAY_GLOBAL');
        putenv('KELPIE_MAX_COST_USD_DAY_GLOBAL_HARD');
    }

    /**
     * @param ?string $projectHardCap The project's daily spend hard cap; null for unlimited
     *
     * @return SpendCapService
     */
    private function service(?string $projectHardCap = null): SpendCapService
    {
        $projects = $this->createStub(ProjectReader::class);
        $projects->method('getByPath')->willReturnCallback(
            static function (string $path) use ($projectHardCap): ?Project {
                return $path === 'g/none' ? null : new Project($path, ProjectMode::Propose, maxCostUsdDayHard: $projectHardCap);
            },
        );

        return new SpendCapService($this->createStub(Logger::class), $projects, $this->outcomes);
    }

    /**
     * @param string $project The project path
     * @param float  $cost    The cost of the run
     * @param string $thread  The discussion ID
     *
     * @return void
     */
    private function spend(string $project, float $cost, string $thread = 'd1'): void
    {
        $this->outcomes->record(new ThreadOutcomeRecord($project, 1, $thread, 'sha', 'fix', 'propose', ThreadOutcome::Proposed, '', '', '', '', 0, 0, $cost));
    }
}
