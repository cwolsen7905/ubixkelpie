<?php

declare(strict_types=1);

namespace Kelpie\Tests\Sandbox;

use Kelpie\DataTransferObject\GateRun;
use Kelpie\DataTransferObject\GateStepResult;
use Kelpie\Sandbox\GateExecutorInterface as GateExecutor;
use Kelpie\Sandbox\GateRunner;
use Kelpie\Sandbox\LocalGateExecutor;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Sandbox\GateRunner
 *
 * What must hold:
 * - It runs composer install, phpcs, phpstan and phpunit in that order, and nothing after a red step
 * - The failed step and its output come back
 * - The environment is exact and has no GitLab token; COMPOSER_AUTH only when given
 * - composer install runs here; phpcs, phpstan and phpunit go to the executor
 * - A gate that could not run (the pod unavailable) is red with step "gate" and the reason, and so is a run that came
 *   back with fewer results than steps
 *
 * @coversDefaultClass \Kelpie\Sandbox\GateRunner
 */
final class GateRunnerTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(GateRunner::class);
    }

    /**
     * All green runs the four steps in order
     *
     * @return void
     */
    public function testGreenRunsAllSteps(): void
    {
        $runner = new ScriptedProcessRunner();

        $result = (new GateRunner($this->createStub(Logger::class), $runner, new LocalGateExecutor($this->createStub(Logger::class), $runner)))->run('/tmp/checkout/repo');

        $this->assertTrue($result->passed);
        $this->assertSame(['composer', 'vendor/bin/phpcs', 'vendor/bin/phpstan', 'vendor/bin/phpunit'], $this->programs($runner));
        $this->assertSame('/tmp/checkout/repo', $runner->calls()[0]['cwd']);
    }

    /**
     * The first red step stops the gate and is reported with its output
     *
     * @return void
     */
    public function testStopsAtFirstRedStep(): void
    {
        $runner = new ScriptedProcessRunner();
        $runner->fail('vendor/bin/phpstan');

        $result = (new GateRunner($this->createStub(Logger::class), $runner, new LocalGateExecutor($this->createStub(Logger::class), $runner)))->run('/tmp/checkout/repo');

        $this->assertFalse($result->passed);
        $this->assertSame('phpstan', $result->failedStep);
        $this->assertStringContainsString('vendor/bin/phpstan failed', $result->output);
        $this->assertSame(['composer', 'vendor/bin/phpcs', 'vendor/bin/phpstan'], $this->programs($runner));
    }

    /**
     * The environment is exact: no inherited token, COMPOSER_AUTH only when configured
     *
     * @return void
     */
    public function testEnvironment(): void
    {
        putenv('KELPIE_BOT_GITLAB_TOKEN=tok-not-real');
        $plain = new ScriptedProcessRunner();
        (new GateRunner($this->createStub(Logger::class), $plain, new LocalGateExecutor($this->createStub(Logger::class), $plain)))->run('/tmp/checkout/repo');
        $auth = new ScriptedProcessRunner();
        (new GateRunner($this->createStub(Logger::class), $auth, new LocalGateExecutor($this->createStub(Logger::class), $auth), '{"gitlab-token":{}}'))->run('/tmp/checkout/repo');
        putenv('KELPIE_BOT_GITLAB_TOKEN');

        $env = $plain->calls()[0]['env'];
        $this->assertSame(['HOME', 'PATH'], array_keys($env));
        $this->assertArrayNotHasKey('COMPOSER_AUTH', $env);
        $this->assertSame('{"gitlab-token":{}}', $auth->calls()[0]['env']['COMPOSER_AUTH']);
    }

    /**
     * Composer runs without scripts, and the registry credential reaches composer only, not the steps that run the repository's code
     *
     * @return void
     */
    public function testComposerRunsWithoutScriptsAndKeepsItsCredentialToItself(): void
    {
        $runner = new ScriptedProcessRunner();

        (new GateRunner($this->createStub(Logger::class), $runner, new LocalGateExecutor($this->createStub(Logger::class), $runner), '{"gitlab-token":{}}'))->run('/tmp/checkout/repo');

        $calls = $runner->calls();
        $this->assertContains('--no-scripts', $calls[0]['argv']);
        $this->assertContains('--no-plugins', $calls[0]['argv']);
        $this->assertArrayHasKey('COMPOSER_AUTH', $calls[0]['env']);
        foreach (array_slice($calls, 1) as $call) {
            $this->assertArrayNotHasKey('COMPOSER_AUTH', $call['env'], $call['argv'][0]);
        }
    }

    /**
     * Composer install runs on the process runner; the repository's own steps go to the executor
     *
     * @return void
     */
    public function testStepsAfterComposerGoToTheExecutor(): void
    {
        $runner   = new ScriptedProcessRunner();
        $executor = $this->createMock(GateExecutor::class);
        $executor->expects($this->once())->method('run')->with('/tmp/checkout/repo', [['vendor/bin/phpcs'], ['vendor/bin/phpstan', 'analyse', '--no-progress', '--no-interaction'], ['vendor/bin/phpunit']], 900)
            ->willReturn(new GateRun([new GateStepResult(0, false, ''), new GateStepResult(0, false, ''), new GateStepResult(0, false, '')]));

        $result = (new GateRunner($this->createStub(Logger::class), $runner, $executor))->run('/tmp/checkout/repo');

        $this->assertTrue($result->passed);
        $this->assertSame(['composer'], $this->programs($runner));
    }

    /**
     * A gate that could not run, or came back short, is red: never a pass
     *
     * @return void
     */
    public function testUnavailableOrShortIsRed(): void
    {
        foreach (
            [
                'short'       => [new GateRun([new GateStepResult(0, false, '')]), 'gate unavailable: not every step ran'],
                'timed out'   => [new GateRun([new GateStepResult(0, true, 'Timed out.')]), 'Timed out.'],
                'unavailable' => [new GateRun([], 'gate unavailable: no answer from the gate pod'), 'gate unavailable: no answer from the gate pod'],
            ] as $case => [$run, $output]
        ) {
            $executor = $this->createStub(GateExecutor::class);
            $executor->method('run')->willReturn($run);

            $result = (new GateRunner($this->createStub(Logger::class), new ScriptedProcessRunner(), $executor))->run('/tmp/checkout/repo');

            $this->assertFalse($result->passed, $case);
            $this->assertSame($output, $result->output, $case);
            $this->assertSame($case === 'timed out' ? 'phpcs' : 'gate', $result->failedStep, $case);
        }
    }

    /**
     * @param ScriptedProcessRunner $runner The runner
     *
     * @return string[]
     */
    private function programs(ScriptedProcessRunner $runner): array
    {
        return array_map(static function (array $call): string {
            return $call['argv'][0];
        }, $runner->calls());
    }
}
