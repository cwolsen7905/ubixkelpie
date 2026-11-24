<?php

declare(strict_types=1);

namespace Kelpie\Tests\Settings;

use Kelpie\DataTransferObject\Project;
use Kelpie\Enum\ProjectMode;
use Kelpie\Settings\Exception\SettingsValidationException;
use Kelpie\Settings\ProjectSettingsValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Settings\ProjectSettingsValidator
 *
 * @coversDefaultClass \Kelpie\Settings\ProjectSettingsValidator
 */
final class ProjectSettingsValidatorTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(ProjectSettingsValidator::class);
    }

    /**
     * Test that a good patch becomes typed column changes, with cost normalised to two places
     *
     * @return void
     */
    public function testValidPatch(): void
    {
        $patch = (new ProjectSettingsValidator(new NullLogger()))->validate([
            'mode'     => 'push',
            'settings' => [
                'max_cost_usd_day'       => ['soft' => '3.5', 'hard' => '10'],
                'max_fix_commits_per_mr' => ['soft' => 5, 'hard' => null],
                'max_issues_per_day'     => ['soft' => 40, 'hard' => 100],
                'review_model'           => null,
                'triage_model'           => 'x/y',
            ],
        ], new Project('a/b', reviewModel: 'old/model'));

        $changes = $patch->changes;
        ksort($changes);

        $this->assertSame([
            'max_cost_usd_day'        => '3.50',
            'max_cost_usd_day_hard'   => '10.00',
            'max_fix_commits_per_mr'  => 5,
            'max_issues_per_day'      => 40,
            'max_issues_per_day_hard' => 100,
            'mode'                    => ProjectMode::Push,
            'review_model'            => null,
            'triage_model'            => 'x/y',
        ], $changes);
    }

    /**
     * Test that null on a hard cap means unlimited and is a real change from a set value
     *
     * @return void
     */
    public function testNullHardIsUnlimited(): void
    {
        $patch = (new ProjectSettingsValidator(new NullLogger()))->validate(
            ['settings' => ['max_cost_usd_day' => ['hard' => null]]],
            new Project('a/b', maxCostUsdDayHard: '5.00'),
        );

        $this->assertSame(['max_cost_usd_day_hard' => null], $patch->changes);
    }

    /**
     * Test that values equal to the current ones, and an empty body, change nothing
     *
     * @return void
     */
    public function testNoOpPatchIsEmpty(): void
    {
        $validator = new ProjectSettingsValidator(new NullLogger());
        $project   = new Project('a/b', ProjectMode::Propose);

        $this->assertSame([], $validator->validate([], $project)->changes);
        $this->assertSame([], $validator->validate(['mode' => 'propose', 'settings' => ['max_cost_usd_day' => ['soft' => '2'], 'triage_model' => null, 'max_issues_per_day' => ['hard' => null]]], $project)->changes);
    }

    /**
     * Test that each bad body is refused, naming the field
     *
     * @param array<mixed>|string|int|null $body  The decoded body
     * @param string                       $field The field that must be named
     *
     * @return void
     */
    #[DataProvider('invalidBodies')]
    public function testInvalidBodies(mixed $body, string $field): void
    {
        try {
            (new ProjectSettingsValidator(new NullLogger()))->validate($body, new Project('a/b', maxIssuesPerDayHard: 50));
            $this->fail('Expected a validation error for ' . $field);
        } catch (SettingsValidationException $e) {
            $this->assertContains($field, array_column($e->getErrors(), 'field'));
        }
    }

    /**
     * Test that every broken rule is reported together, not just the first
     *
     * @return void
     */
    public function testReportsEveryError(): void
    {
        try {
            (new ProjectSettingsValidator(new NullLogger()))->validate(['mode' => 'x', 'settings' => ['triage_model' => '']], new Project('a/b'));
            $this->fail('Expected errors');
        } catch (SettingsValidationException $e) {
            $this->assertSame(['mode', 'settings.triage_model'], array_column($e->getErrors(), 'field'));
        }
    }

    /**
     * Test that a stored cost above 9999.99 (the column holds up to 999999.99) does not make a valid patch fail the hard-vs-soft check
     *
     * @return void
     */
    public function testLargeStoredCostIsComparedCorrectly(): void
    {
        $project   = new Project('a/b', maxCostUsdDay: '12000.00', maxCostUsdDayHard: '15000.00');
        $validator = new ProjectSettingsValidator(new NullLogger());

        $this->assertSame(['max_cost_usd_day' => '3.00'], $validator->validate(['settings' => ['max_cost_usd_day' => ['soft' => '3']]], $project)->changes);

        try {
            $validator->validate(['settings' => ['max_cost_usd_day' => ['hard' => '5000']]], $project);
            $this->fail('A hard limit below the stored soft limit must be refused');
        } catch (SettingsValidationException $e) {
            $this->assertSame(['settings.max_cost_usd_day.hard'], array_column($e->getErrors(), 'field'));
        }
    }

    /**
     * Test that a cap with a bad value gets only that error, not a second one from comparing against the stored other side
     *
     * @return void
     */
    public function testBadValueGetsNoSecondError(): void
    {
        try {
            (new ProjectSettingsValidator(new NullLogger()))->validate(
                ['settings' => ['max_issues_per_day' => ['soft' => 0, 'hard' => 5]]],
                new Project('a/b'),
            );
            $this->fail('Expected a validation error');
        } catch (SettingsValidationException $e) {
            $this->assertSame(['settings.max_issues_per_day.soft'], array_column($e->getErrors(), 'field'));
        }
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function invalidBodies(): array
    {
        return [
            'a list'                 => [[1, 2], '(body)'],
            'cap not an object'      => [['settings' => ['max_issues_per_day' => 5]], 'settings.max_issues_per_day'],
            'commit hard not there'  => [['settings' => ['max_fix_commits_per_mr' => ['hard' => 9]]], 'settings.max_fix_commits_per_mr.hard'],
            'cost as number'         => [['settings' => ['max_cost_usd_day' => ['soft' => 2.5]]], 'settings.max_cost_usd_day.soft'],
            'cost negative'          => [['settings' => ['max_cost_usd_day' => ['hard' => '-1']]], 'settings.max_cost_usd_day.hard'],
            'cost three places'      => [['settings' => ['max_cost_usd_day' => ['soft' => '2.001']]], 'settings.max_cost_usd_day.soft'],
            'cost too large'         => [['settings' => ['max_cost_usd_day' => ['hard' => '10000']]], 'settings.max_cost_usd_day.hard'],
            'cost zero'              => [['settings' => ['max_cost_usd_day' => ['soft' => '0.00']]], 'settings.max_cost_usd_day.soft'],
            'count as float'         => [['settings' => ['max_issues_per_day' => ['soft' => 1.5]]], 'settings.max_issues_per_day.soft'],
            'count as string'        => [['settings' => ['max_issues_per_day' => ['soft' => '5']]], 'settings.max_issues_per_day.soft'],
            'count too large'        => [['settings' => ['max_issues_per_day' => ['soft' => 1000001]]], 'settings.max_issues_per_day.soft'],
            'hard below soft'        => [['settings' => ['max_cost_usd_day' => ['soft' => '5.00', 'hard' => '4.99']]], 'settings.max_cost_usd_day.hard'],
            'hard below stored soft' => [['settings' => ['max_issues_per_day' => ['soft' => 60]]], 'settings.max_issues_per_day.hard'],
            'model empty'            => [['settings' => ['triage_model' => '']], 'settings.triage_model'],
            'model not a string'     => [['settings' => ['review_model' => 5]], 'settings.review_model'],
            'model too long'         => [['settings' => ['review_model' => str_repeat('a', 101)]], 'settings.review_model'],
            'model whitespace'       => [['settings' => ['review_model' => 'a b']], 'settings.review_model'],
            'mode not a string'      => [['mode' => 1], 'mode'],
            'negative sentinel'      => [['settings' => ['max_issues_per_day' => ['hard' => -1]]], 'settings.max_issues_per_day.hard'],
            'not an object'          => ['push', '(body)'],
            'settings a list'        => [['settings' => [1]], 'settings'],
            'soft null'              => [['settings' => ['max_issues_per_day' => ['soft' => null]]], 'settings.max_issues_per_day.soft'],
            'unknown cap key'        => [['settings' => ['max_issues_per_day' => ['limit' => 1]]], 'settings.max_issues_per_day.limit'],
            'unknown mode'           => [['mode' => 'Push'], 'mode'],
            'unknown setting'        => [['settings' => ['protected_paths' => []]], 'settings.protected_paths'],
            'unknown top key'        => [['gate_command' => 'x'], 'gate_command'],
            'zero is not unlimited'  => [['settings' => ['max_issues_per_day' => ['hard' => 0]]], 'settings.max_issues_per_day.hard'],
        ];
    }
}
