<?php

declare(strict_types=1);

namespace Kelpie\Tests\Security;

use Kelpie\Security\ActionGuard;
use Kelpie\Security\UnattendedAction;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Security\ActionGuard
 *
 * @coversDefaultClass \Kelpie\Security\ActionGuard
 */
final class ActionGuardTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(ActionGuard::class);
    }

    /**
     * The commands Kelpie's own runs use are allowed
     *
     * @return void
     */
    public function testOwnCommandsAreAllowed(): void
    {
        $guard = new ActionGuard($this->createStub(Logger::class));
        $tmp   = rtrim(sys_get_temp_dir(), '/');
        foreach (
            [
                ['git', 'clone', '--no-tags', '--branch', 'dev', '--', 'https://example.test/r.git', $tmp . '/x/repo'],
                ['git', 'checkout', '--detach', 'abc'],
                ['git', 'add', '-A'],
                ['git', 'diff', '--cached', '--numstat'],
                ['git', '-c', 'user.name=kelpie-bot', '-c', 'user.email=k@example.test', '-c', 'commit.gpgsign=false', 'commit', '--no-verify', '-m', 'x'],
                ['git', 'push', '--no-verify', 'origin', 'HEAD:refs/heads/feat'],
                ['git', '-c', 'safe.directory=*', '-C', '/repo', 'show', 'abc:file'],
                ['composer', 'install', '--no-interaction'],
                ['vendor/bin/phpcs'],
                ['vendor/bin/phpstan', 'analyse'],
                ['vendor/bin/phpunit'],
                ['claude', '-p', 'x', '--allowedTools', 'Read,Grep,Glob,Edit,Write', '--max-turns', '30'],
                ['rm', '-rf', '--', $tmp . '/kelpie-1'],
            ] as $argv
        ) {
            $this->assertNull($guard->refusal($argv), implode(' ', $argv));
        }
    }

    /**
     * Everything else is refused
     *
     * @return void
     */
    public function testUnlistedCommandsAreRefused(): void
    {
        $guard = new ActionGuard($this->createStub(Logger::class));
        foreach (
            [
                ['curl', 'https://evil.example'],
                ['sh', '-c', 'id'],
                ['php', '-r', 'x'],
                ['bash'],
                [],
                ['git', 'reset', '--hard'],
                ['git', 'push', '--force', 'origin', 'HEAD:refs/heads/x'],
                ['git', 'push', '-f', 'origin', 'HEAD:refs/heads/x'],
                ['git', 'push', 'origin', '+HEAD:refs/heads/x'],
                ['git', 'push', 'origin', ':dev'],
                ['git', 'push', '--delete', 'origin', 'dev'],
                ['git', 'commit', '--amend'],
                ['git', '-c', 'core.hooksPath=/tmp/h', 'commit', '-m', 'x'],
                ['git', '-c', 'alias.x=!sh', 'status'],
                ['git', '--exec-path=/tmp', 'status'],
                ['composer', 'run-script', 'x'],
                ['vendor/bin/rector'],
                ['claude', '-p', 'x', '--allowedTools', 'Bash'],
                ['claude', '-p', 'x', '--allowedTools', 'Read,Bash'],
                ['claude', '-p', 'x'],
                ['claude', '-p', 'x', '--allowedTools', 'Read', '--dangerously-skip-permissions'],
                ['rm', '-rf', '/'],
                ['rm', '-rf', '--', '/etc'],
                ['rm', '-rf', '--', sys_get_temp_dir() . '/../etc'],
                ['rm', '-rf', '--', sys_get_temp_dir()],
            ] as $argv
        ) {
            $this->assertNotNull($guard->refusal($argv), implode(' ', $argv));
        }
    }

    /**
     * A narrower list refuses what it leaves out
     *
     * @return void
     */
    public function testNarrowListRefusesWhatItOmits(): void
    {
        $guard = new ActionGuard($this->createStub(Logger::class), [UnattendedAction::GitRead, UnattendedAction::RunGate]);

        $this->assertNull($guard->refusal(['git', 'diff']));
        $this->assertSame('action not on the list: git_push', $guard->refusal(['git', 'push', 'origin', 'HEAD:refs/heads/x']));
        $this->assertFalse($guard->permits(UnattendedAction::SetLabel));
        $this->assertTrue($guard->permits(UnattendedAction::RunGate));
    }

    /**
     * Model tools: the read-only ones only
     *
     * @return void
     */
    public function testModelTools(): void
    {
        $guard = new ActionGuard($this->createStub(Logger::class));

        $this->assertTrue($guard->permitsTool('read'));
        $this->assertTrue($guard->permitsTool('grep'));
        $this->assertTrue($guard->permitsTool('glob'));
        $this->assertFalse($guard->permitsTool('bash'));
        $this->assertFalse($guard->permitsTool('write'));
        $this->assertFalse((new ActionGuard($this->createStub(Logger::class), []))->permitsTool('read'));
    }
}
