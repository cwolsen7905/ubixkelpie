<?php

declare(strict_types=1);

namespace Kelpie\Tests\Bootstrap;

use DI\Container;
use Kelpie\GitLab\GitlabClientFactory;
use Kelpie\OpenRouter\OpenRouterClient;
use Kelpie\OpenRouter\Review\ReviewChatClient;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface as HttpClient;
use ReflectionProperty;
use Ubix\HttpClient\CurlHttpClient;

/**
 * PHPUnit test case for bin/cli-dependencies.php
 *
 * What must hold:
 * - OpenRouter's clients (the text client and the review's chat client) get 150 s by default and OPENROUTER_HTTP_TIMEOUT seconds when it is set
 * - An unusable value falls back to the default
 * - The shared client, which GitLab uses, keeps the framework's own timeout
 */
final class CliDependenciesTest extends TestCase
{
    private const int DEFAULT_TIMEOUT = 150;

    private string|false $saved;

    /**
     * It uses the default when the variable is unset
     *
     * @return void
     */
    public function testOpenRouterTimeoutDefaults(): void
    {
        putenv('OPENROUTER_HTTP_TIMEOUT');

        self::assertSame(self::DEFAULT_TIMEOUT, $this->timeoutOf($this->container()->get(OpenRouterClient::class)));
    }

    /**
     * It passes the configured timeout to the OpenRouter client
     *
     * @return void
     */
    public function testOpenRouterTimeoutIsConfigurable(): void
    {
        putenv('OPENROUTER_HTTP_TIMEOUT=200');

        self::assertSame(200, $this->timeoutOf($this->container()->get(OpenRouterClient::class)));
    }

    /**
     * It gives the review's chat client the same timeout, since the review loop calls the model through it
     *
     * @return void
     */
    public function testReviewChatClientGetsTheOpenRouterTimeout(): void
    {
        putenv('OPENROUTER_HTTP_TIMEOUT');
        self::assertSame(self::DEFAULT_TIMEOUT, $this->timeoutOf($this->container()->get(ReviewChatClient::class)));

        putenv('OPENROUTER_HTTP_TIMEOUT=200');
        self::assertSame(200, $this->timeoutOf($this->container()->get(ReviewChatClient::class)));
    }

    /**
     * It ignores a value that is not a positive number
     *
     * @return void
     */
    public function testOpenRouterTimeoutFallsBackOnJunk(): void
    {
        putenv('OPENROUTER_HTTP_TIMEOUT=soon');

        self::assertSame(self::DEFAULT_TIMEOUT, $this->timeoutOf($this->container()->get(OpenRouterClient::class)));
    }

    /**
     * It leaves the shared client, and so GitLab's, at the framework's timeout
     *
     * @return void
     */
    public function testSharedClientKeepsItsTimeout(): void
    {
        putenv('OPENROUTER_HTTP_TIMEOUT=200');
        $container = $this->container();

        $shared = $container->get(HttpClient::class);
        self::assertNotSame(200, $this->timeoutOf($shared));
        self::assertSame($this->timeoutOf($shared), $this->timeoutOf($container->get(GitlabClientFactory::class)));
    }

    /**
     * Remember the variable the tests change
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->saved = getenv('OPENROUTER_HTTP_TIMEOUT');
    }

    /**
     * Put the variable back
     *
     * @return void
     */
    protected function tearDown(): void
    {
        putenv($this->saved === false ? 'OPENROUTER_HTTP_TIMEOUT' : 'OPENROUTER_HTTP_TIMEOUT=' . $this->saved);
    }

    /**
     * Build the CLI container
     *
     * @return Container
     */
    private function container(): Container
    {
        $build = require dirname(__DIR__, 2) . '/bin/cli-dependencies.php';
        self::assertIsCallable($build);
        $container = $build();
        self::assertInstanceOf(Container::class, $container);

        return $container;
    }

    /**
     * The total timeout of the cURL client held by a client or a factory
     *
     * @param mixed $holder A cURL client, or an object holding one as `httpClient`
     *
     * @return int
     */
    private function timeoutOf(mixed $holder): int
    {
        if (is_object($holder) && !$holder instanceof CurlHttpClient) {
            $property = new ReflectionProperty($holder, 'httpClient');
            $holder   = $property->getValue($holder);
        }
        self::assertInstanceOf(CurlHttpClient::class, $holder);
        $timeout = (new ReflectionProperty($holder, 'timeout'))->getValue($holder);
        self::assertIsInt($timeout);

        return $timeout;
    }
}
