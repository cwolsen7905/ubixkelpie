<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Autofix;

use Kelpie\DataTransferObject\GateRun;
use Kelpie\DataTransferObject\ProcessResult;
use Kelpie\Sandbox\GateExecutorInterface as GateExecutor;
use Kelpie\Sandbox\GateRunner;
use Kelpie\Sandbox\LocalGateExecutor;
use Kelpie\Sandbox\ProcessRunnerInterface as ProcessRunner;
use Kelpie\Service\Autofix\IssueGateService;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Autofix\IssueGateService
 *
 * What must hold:
 * - A Makefile with a check target is gated by `make check`, with no token or key in the environment
 * - Any other checkout is gated as a PHP project
 * - A red `make check` reports its step and the output's tail
 * - `make check` and a project's gate command go to the gate executor, which nothing bypasses; an unavailable gate is red
 *
 * @coversDefaultClass \Kelpie\Service\Autofix\IssueGateService
 */
final class IssueGateServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    private string $dir = '';

    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(IssueGateService::class);
    }

    /**
     * Green `make check`
     *
     * @return void
     */
    public function testMakeCheckPasses(): void
    {
        file_put_contents($this->dir . '/Makefile', "check:\n\ttrue\n");
        $runner = $this->createMock(ProcessRunner::class);
        $runner->expects($this->once())->method('run')->with(['make', 'check'], $this->dir, $this->callback(static function (array $env): bool {
            return array_keys($env) === ['HOME', 'PATH'];
        }), $this->greaterThan(0))->willReturn(new ProcessResult(0, '', ''));

        $result = $this->service($runner)->run($this->dir);
        $this->assertTrue($result->passed);
        $this->assertTrue($result->local);
        $this->assertSame('`make check` passed.', $result->ran);
    }

    /**
     * The gate command `ci` runs nothing and says the pipeline is the gate
     *
     * @return void
     */
    public function testPipelineGateRunsNothing(): void
    {
        file_put_contents($this->dir . '/Makefile', "check:\n\ttrue\n");
        $runner = $this->createMock(ProcessRunner::class);
        $runner->expects($this->never())->method('run');

        $result = $this->service($runner)->run($this->dir, ' ci ');

        $this->assertTrue($result->passed);
        $this->assertFalse($result->local);
        $this->assertStringContainsString('merge request pipeline is its gate', $result->ran);
    }

    /**
     * Red `make check`
     *
     * @return void
     */
    public function testMakeCheckFails(): void
    {
        file_put_contents($this->dir . '/Makefile', "check:\n\tfalse\n");
        $runner = $this->createStub(ProcessRunner::class);
        $runner->method('run')->willReturn(new ProcessResult(2, str_repeat('a', 7000) . 'END', 'boom'));

        $result = $this->service($runner)->run($this->dir);

        $this->assertFalse($result->passed);
        $this->assertSame('make check', $result->failedStep);
        $this->assertSame(6000, strlen($result->output));
        $this->assertStringEndsWith('boom', $result->output);
    }

    /**
     * A project's own gate command is run as given, split on spaces, whatever the Makefile says
     *
     * @return void
     */
    public function testProjectGateCommand(): void
    {
        file_put_contents($this->dir . '/Makefile', "check:\n\ttrue\n");
        $runner = $this->createMock(ProcessRunner::class);
        $runner->expects($this->once())->method('run')->with(['go', 'test', './...'], $this->dir)->willReturn(new ProcessResult(1, 'FAIL', ''));

        $result = $this->service($runner)->run($this->dir, '  go test   ./... ');

        $this->assertSame('go test ./...', $result->failedStep);
    }

    /**
     * A timeout is said so
     *
     * @return void
     */
    public function testTimeout(): void
    {
        file_put_contents($this->dir . '/Makefile', "check:\n\ttrue\n");
        $runner = $this->createStub(ProcessRunner::class);
        $runner->method('run')->willReturn(new ProcessResult(-1, 'x', 'y', true));

        $this->assertSame('Timed out.', $this->service($runner)->run($this->dir)->output);
    }

    /**
     * No Makefile, or one without a check target, falls back to the PHP gate (composer install is its first step)
     *
     * @return void
     */
    public function testFallsBackToThePhpGate(): void
    {
        file_put_contents($this->dir . '/Makefile', "build:\n\ttrue\n");
        $runner = $this->createMock(ProcessRunner::class);
        $runner->expects($this->once())->method('run')->with($this->callback(static function (array $argv): bool {
            return $argv[0] === 'composer';
        }))->willReturn(new ProcessResult(1, 'no registry', ''));

        $result = $this->service($runner)->run($this->dir);

        $this->assertSame('composer install', $result->failedStep);
    }

    /**
     * `make check` goes to the gate executor (the gate pod when configured), and an unavailable gate is red
     *
     * @return void
     */
    public function testMakeCheckGoesToTheExecutor(): void
    {
        file_put_contents($this->dir . '/Makefile', "check:\n\ttrue\n");
        $logger = $this->createStub(Logger::class);
        $runner = $this->createMock(ProcessRunner::class);
        $runner->expects($this->never())->method('run');
        $executor = $this->createMock(GateExecutor::class);
        $executor->expects($this->once())->method('run')->with($this->dir, [['make', 'check']], $this->greaterThan(0))->willReturn(new GateRun([], 'gate unavailable: no answer from the gate pod'));

        $result = (new IssueGateService($logger, new GateRunner($logger, $runner, $executor)))->run($this->dir);

        $this->assertFalse($result->passed);
        $this->assertSame('gate unavailable: no answer from the gate pod', $result->output);
    }

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/kelpie-igs-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        if (str_contains($this->dir, 'kelpie-igs-test-')) {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
    }

    /**
     * @param ProcessRunner $runner The process runner
     *
     * @return IssueGateService
     */
    private function service(ProcessRunner $runner): IssueGateService
    {
        $logger = $this->createStub(Logger::class);

        return new IssueGateService($logger, new GateRunner($logger, $runner, new LocalGateExecutor($logger, $runner)));
    }
}
