<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Autofix;

use Kelpie\DataTransferObject\AutofixSettings;
use Kelpie\Service\Autofix\AutofixSettingsService;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Autofix\AutofixSettingsService
 *
 * What must hold:
 * - An empty environment gives the defaults: off, one project, the default model, claim TTL and sweep size
 * - Only the literal "true" enables autofix
 * - Lists are trimmed and drop empty entries; the API URL loses its trailing slash
 * - The git base URL is KELPIE_GIT_BASE_URL, else the API URL without its version suffix
 *
 * @coversDefaultClass \Kelpie\Service\Autofix\AutofixSettingsService
 */
final class AutofixSettingsServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    private const array NAMES = [
        'GITLAB_API_URL', 'KELPIE_BOT_GITLAB_TOKEN', 'GITLAB_API_TOKEN', 'KELPIE_AUTOFIX_ENABLED', 'KELPIE_AUTOFIX_PROJECTS',
        'CI_PROJECT_ID', 'CI_PROJECT_PATH', 'KELPIE_CLAIM_TTL_SECONDS', 'KELPIE_GIT_BASE_URL', 'KELPIE_FIXER_MODEL',
        'OPENROUTER_API_KEY', 'COMPOSER_AUTH', 'KELPIE_SWEEP_MAX_RUNS', 'KELPIE_BOT_USERNAME', 'KELPIE_SKIP_AUTHORS', 'KELPIE_SKIP_BRANCHES',
    ];

    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(AutofixSettingsService::class);
    }

    /**
     * An empty environment
     *
     * @return void
     */
    public function testDefaults(): void
    {
        $settings = $this->settings();

        $this->assertSame('', $settings->apiUrl);
        $this->assertSame('anthropic/claude-sonnet-5.5', $settings->fixerModel);
        $this->assertFalse($settings->enabled);
        $this->assertSame(['ubixsys/ubixkelpie'], $settings->projects);
        $this->assertSame(1800, $settings->claimTtl);
        $this->assertSame(5, $settings->sweepMaxRuns);
        $this->assertSame('kelpie-bot', $settings->botUsername);
    }

    /**
     * Only the literal "true" enables
     *
     * @return void
     */
    public function testOnlyTrueEnables(): void
    {
        putenv('KELPIE_AUTOFIX_ENABLED=1');
        $this->assertFalse($this->settings()->enabled);

        putenv('KELPIE_AUTOFIX_ENABLED=true');
        $this->assertTrue($this->settings()->enabled);
    }

    /**
     * The environment's values, trimmed
     *
     * @return void
     */
    public function testReadsTheEnvironment(): void
    {
        putenv('GITLAB_API_URL=https://gitlab.test/api/v4/');
        putenv('KELPIE_AUTOFIX_PROJECTS= a/b , ,c/d');
        putenv('KELPIE_SKIP_AUTHORS=x, y');
        putenv('KELPIE_SKIP_BRANCHES=release/');
        putenv('KELPIE_CLAIM_TTL_SECONDS=60');
        putenv('KELPIE_SWEEP_MAX_RUNS=2');
        putenv('KELPIE_FIXER_MODEL=some/model');

        $settings = $this->settings();

        $this->assertSame('https://gitlab.test/api/v4', $settings->apiUrl);
        $this->assertSame(['a/b', 'c/d'], $settings->projects);
        $this->assertSame(['x', 'y'], $settings->skipAuthors);
        $this->assertSame(['release/'], $settings->skipBranches);
        $this->assertSame(60, $settings->claimTtl);
        $this->assertSame(2, $settings->sweepMaxRuns);
        $this->assertSame('some/model', $settings->fixerModel);
    }

    /**
     * The git base URL
     *
     * @return void
     */
    public function testGitBaseUrl(): void
    {
        putenv('GITLAB_API_URL=https://gitlab.test/api/v4');
        $this->assertSame('https://gitlab.test', $this->settings()->gitBaseUrl);

        putenv('KELPIE_GIT_BASE_URL=https://git.test/');
        $this->assertSame('https://git.test', $this->settings()->gitBaseUrl);
    }

    /**
     * Start from an empty environment
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->clean();
    }

    /**
     * Clean the environment
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->clean();
    }

    /**
     * Unset every variable the settings read
     *
     * @return void
     */
    private function clean(): void
    {
        foreach (self::NAMES as $name) {
            putenv($name);
        }
    }

    /**
     * The settings of the current environment
     *
     * @return AutofixSettings
     */
    private function settings(): AutofixSettings
    {
        return (new AutofixSettingsService($this->createStub(Logger::class)))->fromEnvironment();
    }
}
