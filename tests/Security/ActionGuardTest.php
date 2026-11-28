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
                ['composer', 'install', '--no-interaction', '--no-scripts'],
                ['vendor/bin/phpcs'],
                ['vendor/bin/phpstan', 'analyse'],
                ['vendor/bin/phpunit'],
                ['claude', '-p', 'x', '--allowedTools', 'Read,Grep,Glob,Edit,Write', '--max-turns', '30'],
                ['rm', '-rf', '--', $tmp . '/kelpie-1'],
                ['git', '-c', 'core.fsmonitor=false', '-c', 'core.hooksPath=/dev/null', 'status'],
                ['git', '-c', 'core.fsmonitor=false', '-c', 'core.hooksPath=/dev/null', 'clone', '--no-tags', '--separate-git-dir', $tmp . '/k/git', '--branch', 'dev', '--', 'https://example.test/r.git', $tmp . '/k/repo'],
                ['make', 'check'],
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
                ['composer', 'install', '--no-interaction'],
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
     * Options that run a program or copy hooks are refused for clone, fetch and checkout
     *
     * @return void
     */
    public function testGitFetchOptionsThatRunProgramsAreRefused(): void
    {
        $guard = new ActionGuard($this->createStub(Logger::class));
        foreach (
            [
                ['git', 'clone', '--template=/tmp/hooks', '--', 'https://example.test/r.git', '/tmp/x'],
                ['git', 'clone', '--template', '/tmp/hooks', 'https://example.test/r.git'],
                ['git', 'clone', '--config', 'core.fsmonitor=/tmp/x', 'https://example.test/r.git'],
                ['git', 'clone', '--config=core.fsmonitor=/tmp/x', 'https://example.test/r.git'],
                ['git', 'clone', '-c', 'core.fsmonitor=/tmp/x', 'https://example.test/r.git'],
                ['git', 'clone', '-ccore.fsmonitor=/tmp/x', 'https://example.test/r.git'],
                ['git', 'clone', '--upload-pack=/tmp/x', 'https://example.test/r.git'],
                ['git', 'clone', '-u', '/tmp/x', 'https://example.test/r.git'],
                ['git', 'clone', '-u/tmp/x', 'https://example.test/r.git'],
                ['git', 'clone', '--receive-pack=/tmp/x', 'https://example.test/r.git'],
                ['git', 'clone', '--recurse-submodules', 'https://example.test/r.git'],
                ['git', 'clone', '--reference', '/other/repo', 'https://example.test/r.git'],
                ['git', 'clone', '--separate-git-dir=/tmp/g', 'https://example.test/r.git'],
                ['git', 'clone', '--templ=/tmp/hooks', 'https://example.test/r.git'],
                ['git', 'clone', '--upload=/tmp/x', 'https://example.test/r.git'],
                ['git', 'fetch', '--upload-pack=/tmp/x', 'origin'],
                ['git', 'fetch', '--receive-pack=/tmp/x', 'origin'],
                ['git', 'fetch', '--exec=/tmp/x', 'origin'],
                ['git', 'fetch', '--server-option=x', 'origin'],
                ['git', 'fetch', '-u', 'origin'],
                ['git', 'fetch', '--recurse-submodules', 'origin'],
                ['git', 'checkout', '--recurse-submodules', 'dev'],
                ['git', 'clone', 'ext::sh -c id', '/tmp/x'],
                ['git', 'clone', '--', 'ext::sh -c id', '/tmp/x'],
                ['git', 'clone', 'fd::3/repo', '/tmp/x'],
                ['git', 'clone', 'file:///other/repo', '/tmp/x'],
                ['git', 'clone', 'FILE:///other/repo', '/tmp/x'],
                ['git', 'clone', 'ssh://-oProxyCommand=id/x', '/tmp/x'],
                ['git', 'clone', 'git://example.test/r.git', '/tmp/x'],
                ['git', 'clone', 'http://example.test/r.git', '/tmp/x'],
                ['git', 'fetch', 'ext::sh -c id'],
            ] as $argv
        ) {
            $this->assertNotNull($guard->refusal($argv), implode(' ', $argv));
        }
    }

    /**
     * Plain clone, fetch, checkout and add stay allowed, including `-u` on add and an https URL
     *
     * @return void
     */
    public function testPlainGitFetchStaysAllowed(): void
    {
        $guard = new ActionGuard($this->createStub(Logger::class));
        foreach (
            [
                ['git', 'clone', '--no-tags', '--depth', '1', '--branch', 'dev', '--', 'https://example.test/r.git', '/tmp/x'],
                ['git', 'fetch', 'origin', 'dev'],
                ['git', 'fetch', '--no-tags', '--depth=1', 'origin', 'abc123'],
                ['git', 'checkout', '--detach', 'abc123'],
                ['git', 'add', '-u'],
                ['git', 'add', '-A'],
            ] as $argv
        ) {
            $this->assertNull($guard->refusal($argv), implode(' ', $argv));
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

    /**
     * The settings and options let through for Kelpie's own commands cannot be bent into what they exist to stop
     *
     * @return void
     */
    public function testOwnCommandAllowancesCannotBeBent(): void
    {
        $guard = new ActionGuard($this->createStub(Logger::class));
        $tmp   = rtrim(sys_get_temp_dir(), '/');
        foreach (
            [
                ['git', '-c', 'core.fsmonitor=/tmp/x.sh', 'status'],
                ['git', '-c', 'core.fsmonitor=true', 'status'],
                ['git', '-c', 'core.hooksPath=/tmp/hooks', 'status'],
                ['git', '-c', 'core.hooksPath=/dev/null/../tmp', 'status'],
                ['git', 'clone', '--separate-git-dir', '/etc/x', '--', 'https://example.test/r.git', $tmp . '/k/repo'],
                ['git', 'clone', '--separate-git-dir', $tmp . '/../etc', '--', 'https://example.test/r.git', $tmp . '/k/repo'],
                ['git', 'clone', '--separate-git-dir', $tmp . '/', '--', 'https://example.test/r.git', $tmp . '/k/repo'],
                ['git', 'clone', '--separate-git-dir=/etc/x', '--', 'https://example.test/r.git', $tmp . '/k/repo'],
                ['git', 'fetch', '--separate-git-dir', $tmp . '/k/git', 'origin'],
                ['make'],
                ['make', 'check', 'SHELL=/bin/sh'],
                ['make', '-f', '/tmp/evil', 'check'],
                ['make', 'check', 'deploy'],
                ['make', '--eval=x:', 'check'],
                ['/usr/bin/make', 'check'],
            ] as $argv
        ) {
            $this->assertNotNull($guard->refusal($argv), implode(' ', $argv));
        }
    }
}
