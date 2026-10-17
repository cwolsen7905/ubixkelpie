<?php

declare(strict_types=1);

namespace Kelpie\Tests\Sandbox;

use Kelpie\Sandbox\GateRunner;
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

        $result = (new GateRunner($this->createStub(Logger::class), $runner))->run('/tmp/checkout/repo');

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

        $result = (new GateRunner($this->createStub(Logger::class), $runner))->run('/tmp/checkout/repo');

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
        (new GateRunner($this->createStub(Logger::class), $plain))->run('/tmp/checkout/repo');
        $auth = new ScriptedProcessRunner();
        (new GateRunner($this->createStub(Logger::class), $auth, '{"gitlab-token":{}}'))->run('/tmp/checkout/repo');
        putenv('KELPIE_BOT_GITLAB_TOKEN');

        $env = $plain->calls()[0]['env'];
        $this->assertSame(['HOME', 'PATH'], array_keys($env));
        $this->assertArrayNotHasKey('COMPOSER_AUTH', $env);
        $this->assertSame('{"gitlab-token":{}}', $auth->calls()[0]['env']['COMPOSER_AUTH']);
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
