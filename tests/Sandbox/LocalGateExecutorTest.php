<?php

declare(strict_types=1);

namespace Kelpie\Tests\Sandbox;

use Kelpie\Sandbox\LocalGateExecutor;
use Kelpie\Sandbox\ProcOpenProcessRunner;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Sandbox\LocalGateExecutor
 *
 * What must hold:
 * - Steps run in order in the checkout, each with the timeout, and nothing after the first that fails
 * - The environment is HOME (the checkout's parent) and PATH, nothing else
 * - A failed step's output is the tail of stdout and stderr; a timed-out one says so
 *
 * @coversDefaultClass \Kelpie\Sandbox\LocalGateExecutor
 */
final class LocalGateExecutorTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(LocalGateExecutor::class);
    }

    /**
     * Every step passes: one result each, in order, with the checkout, the timeout and an exact environment
     *
     * @return void
     */
    public function testAllPass(): void
    {
        putenv('KELPIE_BOT_GITLAB_TOKEN=tok-not-real');
        $runner = new ScriptedProcessRunner();
        $run    = (new LocalGateExecutor($this->createStub(Logger::class), $runner))->run('/tmp/k/repo', [['vendor/bin/phpcs'], ['vendor/bin/phpunit']], 900);
        putenv('KELPIE_BOT_GITLAB_TOKEN');

        $this->assertSame('', $run->unavailable);
        $this->assertCount(2, $run->results);
        $this->assertSame(['vendor/bin/phpcs', 'vendor/bin/phpunit'], [$runner->calls()[0]['argv'][0], $runner->calls()[1]['argv'][0]]);
        $this->assertSame('/tmp/k/repo', $runner->calls()[0]['cwd']);
        $this->assertSame(900, $runner->calls()[0]['timeout']);
        $this->assertSame(['HOME', 'PATH'], array_keys($runner->calls()[0]['env']));
        $this->assertSame('/tmp/k', $runner->calls()[0]['env']['HOME']);
    }

    /**
     * The first failure stops the run and carries its output
     *
     * @return void
     */
    public function testStopsAtFirstFailure(): void
    {
        $runner = new ScriptedProcessRunner();
        $runner->fail('vendor/bin/phpstan');
        $run = (new LocalGateExecutor($this->createStub(Logger::class), $runner))->run('/tmp/k/repo', [['vendor/bin/phpcs'], ['vendor/bin/phpstan'], ['vendor/bin/phpunit']], 900);

        $this->assertCount(2, $run->results);
        $this->assertSame(0, $run->results[0]->exitCode);
        $this->assertSame(2, $run->results[1]->exitCode);
        $this->assertSame('vendor/bin/phpstan failed: line 1', $run->results[1]->output);
        $this->assertCount(2, $runner->calls());
    }

    /**
     * Output is capped to its tail, and a step killed for time says "Timed out."
     *
     * @return void
     */
    public function testOutputTailAndTimeout(): void
    {
        $logger = $this->createStub(Logger::class);
        $runner = new ProcOpenProcessRunner($logger);
        $noisy  = (new LocalGateExecutor($logger, $runner))->run(sys_get_temp_dir() . '/.', [['sh', '-c', 'printf "%07000d" 0; echo END >&2; exit 1']], 10);
        $slow   = (new LocalGateExecutor($logger, $runner))->run(sys_get_temp_dir() . '/.', [['sleep', '30']], 1);

        $this->assertSame(6000, strlen($noisy->results[0]->output));
        $this->assertStringEndsWith('END', $noisy->results[0]->output);
        $this->assertTrue($slow->results[0]->timedOut);
        $this->assertSame('Timed out.', $slow->results[0]->output);
    }
}
