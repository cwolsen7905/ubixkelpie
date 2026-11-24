<?php

declare(strict_types=1);

namespace Kelpie\Tests\Settings;

use Kelpie\DataTransferObject\Project;
use Kelpie\Enum\ProjectMode;
use Kelpie\Settings\ProjectSettingsRepresentation;
use Psr\Log\NullLogger;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Settings\ProjectSettingsRepresentation
 *
 * @coversDefaultClass \Kelpie\Settings\ProjectSettingsRepresentation
 */
final class ProjectSettingsRepresentationTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(ProjectSettingsRepresentation::class);
    }

    /**
     * Test the shape: every cap is soft/hard, unlimited is a null hard, the gate and protected paths are not shown
     *
     * @return void
     */
    public function testRepresent(): void
    {
        $project = new Project(
            path:              'a/b',
            mode:              ProjectMode::Propose,
            gateCommand:       'make secret',
            protectedPaths:    ['x/**'],
            reviewModel:       'x/y',
            maxCostUsdDayHard: '5.00',
        );

        $this->assertSame([
            'mode'     => 'propose',
            'path'     => 'a/b',
            'settings' => [
                'max_cost_usd_day'          => ['soft' => '2.00', 'hard' => '5.00'],
                'max_fix_commits_per_mr'    => ['soft' => 3, 'hard' => null],
                'max_issues_per_author_day' => ['soft' => 10, 'hard' => null],
                'max_issues_per_day'        => ['soft' => 30, 'hard' => null],
                'review_model'              => 'x/y',
                'triage_model'              => null,
            ],
        ], (new ProjectSettingsRepresentation(new NullLogger()))->represent($project));
    }
}
