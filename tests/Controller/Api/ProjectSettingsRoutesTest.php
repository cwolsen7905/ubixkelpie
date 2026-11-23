<?php

declare(strict_types=1);

namespace Kelpie\Tests\Controller\Api;

use DI\Bridge\Slim\Bridge;
use DI\Container;
use DI\ContainerBuilder;
use Kelpie\DataTransferObject\Project;
use Kelpie\Enum\ProjectMode;
use Kelpie\Repository\Project\ProjectReaderInterface as ProjectReader;
use Kelpie\Repository\Project\ProjectWriterInterface as ProjectWriter;
use Kelpie\Security\ApiTokenMiddleware;
use Kelpie\Tests\Service\Settings\InMemoryProjectSettingsStore;
use LogicException;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface as ResponseFactory;
use Psr\Log\LoggerInterface as Logger;
use Psr\Log\NullLogger;
use Slim\App;
use Slim\Psr7\Factory\ResponseFactory as SlimResponseFactory;

use function DI\autowire;

/**
 * The settings API through the real route table: the group's routes, the token middleware, 405s and preflight
 *
 * What must hold:
 * - The route table builds (FastRoute refuses a method registered twice on one pattern)
 * - Every settings route needs the token, a preflight does not
 * - A project path may be URL-encoded; an unknown method is 405 with the token and 401 without
 */
final class ProjectSettingsRoutesTest extends TestCase
{
    private const string TOKEN = 'tok';

    /**
     * The list answers with the token and 401 without it
     *
     * @return void
     */
    public function testListNeedsTheToken(): void
    {
        $app = $this->app();

        $this->assertSame(401, $app->handle(new ServerRequest('GET', '/api/v1/projects'))->getStatusCode());
        $ok = $app->handle($this->request('GET', '/api/v1/projects'));
        $this->assertSame(200, $ok->getStatusCode());
        $this->assertStringContainsString('"path":"a\/a"', (string) $ok->getBody());
    }

    /**
     * A URL-encoded project path reads one project, and a project with no row is 404
     *
     * @return void
     */
    public function testEncodedPathReadsOneProject(): void
    {
        $app = $this->app();

        $ok = $app->handle($this->request('GET', '/api/v1/projects/a%2Fa'));
        $this->assertSame(200, $ok->getStatusCode());
        $this->assertStringContainsString('"path":"a\/a"', (string) $ok->getBody());
        $this->assertSame(404, $app->handle($this->request('GET', '/api/v1/projects/x%2Fy'))->getStatusCode());
    }

    /**
     * PATCH edits through the route, and the new state reads back
     *
     * @return void
     */
    public function testPatchEdits(): void
    {
        $app = $this->app();

        $patched = $app->handle($this->request('PATCH', '/api/v1/projects/a%2Fa', '{"mode":"push"}'));
        $this->assertSame(200, $patched->getStatusCode());
        $this->assertStringContainsString('"mode":"push"', (string) $app->handle($this->request('GET', '/api/v1/projects/a%2Fa'))->getBody());
    }

    /**
     * Methods the API does not have are 405 after the token check, 401 before it
     *
     * @return void
     */
    public function testOtherMethodsAre405(): void
    {
        $app = $this->app();

        foreach (['POST', 'PUT', 'DELETE'] as $method) {
            foreach (['/api/v1/projects', '/api/v1/projects/a%2Fa'] as $path) {
                $this->assertSame(405, $app->handle($this->request($method, $path))->getStatusCode(), $method . ' ' . $path);
                $this->assertSame(401, $app->handle(new ServerRequest($method, $path))->getStatusCode(), $method . ' ' . $path . ' without a token');
            }
        }
        $this->assertSame(405, $app->handle($this->request('PATCH', '/api/v1/projects'))->getStatusCode());
    }

    /**
     * A preflight to the list or one project is answered without a token
     *
     * @return void
     */
    public function testPreflightNeedsNoToken(): void
    {
        $app = $this->app();

        foreach (['/api/v1/projects', '/api/v1/projects/a%2Fa'] as $path) {
            $this->assertSame(204, $app->handle(new ServerRequest('OPTIONS', $path))->getStatusCode(), $path);
        }
    }

    /**
     * @param string  $method The HTTP method
     * @param string  $path   The path, as sent
     * @param ?string $body   The JSON body
     *
     * @return ServerRequest A request that carries the token
     */
    private function request(string $method, string $path, ?string $body = null): ServerRequest
    {
        $request = new ServerRequest($method, $path, ['Authorization' => 'Bearer ' . self::TOKEN]);

        return $body === null ? $request : $request->withBody(Stream::create($body));
    }

    /**
     * @return App<Container> The app with the real routes over an in-memory table
     *
     * @throws LogicException When Routes.php does not return a callable
     */
    private function app(): App
    {
        $store = new InMemoryProjectSettingsStore(new Project('a/a', ProjectMode::Propose), new Project('b/b'));

        $builder = new ContainerBuilder();
        $builder->addDefinitions([
            ApiTokenMiddleware::class => autowire()->constructorParameter('token', self::TOKEN),
            Logger::class             => new NullLogger(),
            ProjectReader::class      => $store,
            ProjectWriter::class      => $store,
            ResponseFactory::class    => autowire(SlimResponseFactory::class),
        ]);

        $app = Bridge::create($builder->build());
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(false, false, false);

        $routes = require dirname(__DIR__, 3) . '/app/KelpieApi/src/Routes.php';
        if (!is_callable($routes)) {
            throw new LogicException('Routes.php must return a callable');
        }
        $routes($app);

        return $app;
    }
}
