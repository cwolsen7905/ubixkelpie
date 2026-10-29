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
 * - Under both caps nothing is reached
 * - A project at its own cap is held; a project with no row is reported as not enrolled, not as over a cap
 * - Spend across projects at the global cap holds every project
 * - The global cap comes from the environment; unset or bad means the default, 0 blocks everything
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
    public function testProjectAtItsCapIsHeld(): void
    {
        $this->spend('g/p', 2.0);

        $check = $this->service()->check('g/p');

        $this->assertSame('project', $check->cap);
        $this->assertSame(2.0, $check->spent);
        $this->assertSame(2.0, $check->limit);
    }

    /**
     * @return void
     */
    public function testRerunsOnOneThreadAllCount(): void
    {
        $this->spend('g/p', 1.0);
        $this->spend('g/p', 1.0);

        $check = $this->service()->check('g/p');

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
    public function testGlobalCapHoldsAProjectThatIsUnderItsOwn(): void
    {
        putenv('KELPIE_MAX_COST_USD_DAY_GLOBAL=3.5');
        $this->spend('g/other', 2.0, 'a');
        $this->spend('g/other', 1.5, 'b');
        $this->spend('g/p', 0.1);

        $check = $this->service()->check('g/p');

        $this->assertSame('global', $check->cap);
        $this->assertSame(3.6, round($check->spent, 6));
        $this->assertSame(3.5, $check->limit);
    }

    /**
     * @return void
     */
    public function testGlobalCapDefaultsToTenWhenUnsetOrInvalid(): void
    {
        $this->spend('g/other', 9.99, 'a');
        $this->assertNull($this->service()->check('g/p')->cap);

        foreach (['', 'lots', '-1'] as $bad) {
            putenv('KELPIE_MAX_COST_USD_DAY_GLOBAL=' . $bad);
            $this->assertNull($this->service()->check('g/p')->cap, $bad);
        }

        $this->spend('g/other', 0.01, 'b');
        $this->assertSame('global', $this->service()->check('g/p')->cap);
    }

    /**
     * @return void
     */
    public function testZeroGlobalCapBlocksEverything(): void
    {
        putenv('KELPIE_MAX_COST_USD_DAY_GLOBAL=0');

        $this->assertSame('global', $this->service()->check('g/p')->cap);
    }

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->outcomes = new InMemoryOutcomeStore();
        putenv('KELPIE_MAX_COST_USD_DAY_GLOBAL');
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        putenv('KELPIE_MAX_COST_USD_DAY_GLOBAL');
    }

    /**
     * @return SpendCapService
     */
    private function service(): SpendCapService
    {
        $projects = $this->createStub(ProjectReader::class);
        $projects->method('getByPath')->willReturnCallback(
            static function (string $path): ?Project {
                return $path === 'g/none' ? null : new Project($path, ProjectMode::Propose);
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
