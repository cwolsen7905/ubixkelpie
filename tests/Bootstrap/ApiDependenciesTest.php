<?php

declare(strict_types=1);

namespace Kelpie\Tests\Bootstrap;

use DI\Container;
use Kelpie\DataTransferObject\AutofixSettings;
use Kelpie\GitLab\GitlabClientFactory;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * PHPUnit test case for app/KelpieApi/src/Dependencies.php
 *
 * What must hold:
 * - The webhook's GitLab clients (resolving threads on owner approval) get GitLab's URL and the bot token from the
 *   environment, as the CLI container's do, not the settings DTO's empty defaults
 */
final class ApiDependenciesTest extends TestCase
{
    private const array NAMES = ['GITLAB_API_URL', 'KELPIE_BOT_GITLAB_TOKEN'];

    /**
     * @var array<string, string|false>
     */
    private array $saved = [];

    /**
     * It reads GitLab's URL and the bot token from the environment
     *
     * @return void
     */
    public function testGitlabSettingsComeFromTheEnvironment(): void
    {
        putenv('GITLAB_API_URL=https://gitlab.example.com/api/v4/');
        putenv('KELPIE_BOT_GITLAB_TOKEN=bot-token');

        $settings = $this->container()->get(AutofixSettings::class);

        self::assertInstanceOf(AutofixSettings::class, $settings);
        self::assertSame('https://gitlab.example.com/api/v4', $settings->apiUrl);
        self::assertSame('bot-token', $settings->botToken);
    }

    /**
     * The factory the approval service reads threads with holds those settings
     *
     * @return void
     */
    public function testTheGitlabClientFactoryGetsThem(): void
    {
        putenv('GITLAB_API_URL=https://gitlab.example.com/api/v4');
        putenv('KELPIE_BOT_GITLAB_TOKEN=bot-token');

        $factory = $this->container()->get(GitlabClientFactory::class);
        self::assertInstanceOf(GitlabClientFactory::class, $factory);
        $settings = (new ReflectionProperty(GitlabClientFactory::class, 'settings'))->getValue($factory);

        self::assertInstanceOf(AutofixSettings::class, $settings);
        self::assertSame('https://gitlab.example.com/api/v4', $settings->apiUrl);
    }

    /**
     * Remember the variables the tests change
     *
     * @return void
     */
    protected function setUp(): void
    {
        foreach (self::NAMES as $name) {
            $this->saved[$name] = getenv($name);
        }
    }

    /**
     * Put the variables back
     *
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    /**
     * Build the API container
     *
     * @return Container
     */
    private function container(): Container
    {
        $build = require dirname(__DIR__, 2) . '/app/KelpieApi/src/Dependencies.php';
        self::assertIsCallable($build);
        $container = $build();
        self::assertInstanceOf(Container::class, $container);

        return $container;
    }
}
