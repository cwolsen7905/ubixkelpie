<?php

declare(strict_types=1);

namespace Kelpie\Tests\Repository\Project;

use Generator;
use Kelpie\Enum\ProjectMode;
use Kelpie\Repository\Project\ProjectSqlRepository;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Service\JsonService;
use Ubix\Service\Sql\SqlServiceInterface as SqlService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Repository\Project\ProjectSqlRepository
 *
 * What must hold:
 * - One project is read by path, and none is null
 * - The enrolled projects exclude off and come in path order
 * - Empty settings are null or empty, the protected paths are read from JSON, and unreadable JSON gives none
 * - A mode Kelpie does not know reads as off
 *
 * @coversDefaultClass \Kelpie\Repository\Project\ProjectSqlRepository
 */
final class ProjectSqlRepositoryTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(ProjectSqlRepository::class);
    }

    /**
     * A project by path, with every setting
     *
     * @return void
     */
    public function testGetByPath(): void
    {
        $sql = $this->createMock(SqlService::class);
        $sql->expects($this->once())->method('getRows')
            ->with($this->stringContains('WHERE path = :path'), ['path' => 'g/p'])
            ->willReturn($this->rows([$this->row(['gate_command' => 'make gate', 'protected_paths' => '["sql/**","a/b"]', 'triage_model' => 'x/y'])]));

        $project = $this->repository($this->createStub(Logger::class), $sql)->getByPath('g/p');

        $this->assertNotNull($project);
        $this->assertSame(ProjectMode::Push, $project->mode);
        $this->assertSame('make gate', $project->gateCommand);
        $this->assertSame(['sql/**', 'a/b'], $project->protectedPaths);
        $this->assertSame('x/y', $project->triageModel);
        $this->assertNull($project->reviewModel);
        $this->assertSame([30, 10, '2.00', 3], [$project->maxIssuesPerDay, $project->maxIssuesPerAuthorDay, $project->maxCostUsdDay, $project->maxFixCommitsPerMr]);
    }

    /**
     * No row
     *
     * @return void
     */
    public function testGetByPathMissing(): void
    {
        $sql = $this->createStub(SqlService::class);
        $sql->method('getRows')->willReturn($this->rows([]));

        $this->assertNull($this->repository($this->createStub(Logger::class), $sql)->getByPath('g/none'));
    }

    /**
     * The enrolled projects leave off out
     *
     * @return void
     */
    public function testGetEnrolled(): void
    {
        $sql = $this->createMock(SqlService::class);
        $sql->expects($this->once())->method('getRows')
            ->with($this->logicalAnd($this->stringContains("mode <> 'off'"), $this->stringContains('ORDER BY path')), [])
            ->willReturn($this->rows([$this->row(['path' => 'g/a']), $this->row(['path' => 'g/b', 'mode' => 'propose'])]));

        $projects = $this->repository($this->createStub(Logger::class), $sql)->getEnrolled();

        $this->assertSame(['g/a', 'g/b'], array_column($projects, 'path'));
        $this->assertSame(ProjectMode::Propose, $projects[1]->mode);
    }

    /**
     * Unknown mode and unreadable globs are safe
     *
     * @return void
     */
    public function testUnreadableValuesAreSafe(): void
    {
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->exactly(2))->method('warning');
        $sql = $this->createStub(SqlService::class);
        $sql->method('getRows')->willReturn($this->rows([$this->row(['mode' => 'yolo', 'protected_paths' => '{not json', 'gate_command' => ''])]));

        $project = $this->repository($logger, $sql)->getByPath('g/p');

        $this->assertNotNull($project);
        $this->assertSame(ProjectMode::Off, $project->mode);
        $this->assertSame([], $project->protectedPaths);
        $this->assertNull($project->gateCommand);
    }

    /**
     * @param Logger     $logger The logger
     * @param SqlService $sql    The SQL service
     *
     * @return ProjectSqlRepository
     */
    private function repository(Logger $logger, SqlService $sql): ProjectSqlRepository
    {
        return new ProjectSqlRepository($logger, $sql, new JsonService($this->createStub(Logger::class)));
    }

    /**
     * @param array<string, mixed> $overrides Columns to change
     *
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return $overrides + [
            'gate_command'              => null,
            'max_cost_usd_day'          => '2.00',
            'max_fix_commits_per_mr'    => '3',
            'max_issues_per_author_day' => '10',
            'max_issues_per_day'        => '30',
            'mode'                      => 'push',
            'path'                      => 'g/p',
            'protected_paths'           => null,
            'review_model'              => null,
            'triage_model'              => null,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows The rows
     *
     * @return Generator<int, array<string, mixed>>
     */
    private function rows(array $rows): Generator
    {
        yield from $rows;
    }
}
