<?php

declare(strict_types=1);

namespace Kelpie\Tests\Bootstrap;

use DI\Container;
use Kelpie\GitLab\GitlabClientFactory;
use Kelpie\OpenRouter\OpenRouterClient;
use Kelpie\OpenRouter\Review\ReviewChatClient;
use Kelpie\Sandbox\ContainerProcessReaper;
use Kelpie\Sandbox\GateExecutorInterface as GateExecutor;
use Kelpie\Sandbox\GateServer;
use Kelpie\Sandbox\LocalGateExecutor;
use Kelpie\Sandbox\ProcOpenProcessRunner;
use Kelpie\Sandbox\RemoteGateExecutor;
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
 * - The gate's steps run here without KELPIE_GATE_URL, and in the gate pod with it, through a client whose timeout
 *   covers a whole gate (KELPIE_GATE_HTTP_TIMEOUT, default 3600 s)
 * - The gate server runs its (already checked) steps with the plain process runner, and clears the container between
 *   runs with the container reaper
 */
final class CliDependenciesTest extends TestCase
{
    private const int DEFAULT_TIMEOUT = 150;

    private string|false $saved;

    private string|false $savedGateUrl;

    private string|false $savedGateTimeout;

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
     * Without KELPIE_GATE_URL the gate runs here; with it, in the gate pod at that URL
     *
     * @return void
     */
    public function testGateExecutorFollowsTheGateUrl(): void
    {
        putenv('KELPIE_GATE_URL');
        self::assertInstanceOf(LocalGateExecutor::class, $this->container()->get(GateExecutor::class));

        putenv('KELPIE_GATE_URL=http://kelpie-gate:8090');
        putenv('KELPIE_GATE_HTTP_TIMEOUT');
        $remote = $this->container()->get(GateExecutor::class);
        self::assertInstanceOf(RemoteGateExecutor::class, $remote);
        self::assertSame('http://kelpie-gate:8090', (new ReflectionProperty($remote, 'url'))->getValue($remote));
        self::assertSame(3600, $this->timeoutOf($remote));

        putenv('KELPIE_GATE_HTTP_TIMEOUT=900');
        self::assertSame(900, $this->timeoutOf($this->container()->get(GateExecutor::class)));
    }

    /**
     * The gate server's runner is the plain one: it checks and wraps every step itself
     *
     * @return void
     */
    public function testGateServerRunsWithThePlainRunner(): void
    {
        $server = $this->container()->get(GateServer::class);
        self::assertInstanceOf(GateServer::class, $server);
        self::assertInstanceOf(ProcOpenProcessRunner::class, (new ReflectionProperty($server, 'runner'))->getValue($server));
        self::assertInstanceOf(ContainerProcessReaper::class, (new ReflectionProperty($server, 'reaper'))->getValue($server));
    }

    /**
     * Remember the variable the tests change
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->saved            = getenv('OPENROUTER_HTTP_TIMEOUT');
        $this->savedGateUrl     = getenv('KELPIE_GATE_URL');
        $this->savedGateTimeout = getenv('KELPIE_GATE_HTTP_TIMEOUT');
    }

    /**
     * Put the variable back
     *
     * @return void
     */
    protected function tearDown(): void
    {
        putenv($this->saved === false ? 'OPENROUTER_HTTP_TIMEOUT' : 'OPENROUTER_HTTP_TIMEOUT=' . $this->saved);
        putenv($this->savedGateUrl === false ? 'KELPIE_GATE_URL' : 'KELPIE_GATE_URL=' . $this->savedGateUrl);
        putenv($this->savedGateTimeout === false ? 'KELPIE_GATE_HTTP_TIMEOUT' : 'KELPIE_GATE_HTTP_TIMEOUT=' . $this->savedGateTimeout);
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
