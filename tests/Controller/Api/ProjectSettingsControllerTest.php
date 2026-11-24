<?php

declare(strict_types=1);

namespace Kelpie\Tests\Controller\Api;

use Kelpie\Controller\Api\ProjectSettingsController;
use Kelpie\DataTransferObject\Project;
use Kelpie\Enum\ProjectMode;
use Kelpie\Service\Settings\ProjectSettingsService;
use Kelpie\Settings\ProjectSettingsRepresentation;
use Kelpie\Settings\ProjectSettingsValidator;
use Kelpie\Tests\Service\Settings\InMemoryProjectSettingsStore;
use Latte\Engine;
use Nyholm\Psr7\Response as Psr7Response;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\NullLogger;
use Ubix\Renderer\TemplateRenderer;
use Ubix\Service\JsonService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Controller\Api\ProjectSettingsController
 *
 * @coversDefaultClass \Kelpie\Controller\Api\ProjectSettingsController
 */
final class ProjectSettingsControllerTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(ProjectSettingsController::class);
    }

    /**
     * Test the list: 200 with every project
     *
     * @return void
     */
    public function testList(): void
    {
        $response = $this->controller()->list(new ServerRequest('GET', '/api/v1/projects'), new Psr7Response());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertMatchesRegularExpression('/"path":"a\/a".*"path":"b\/b"/', $this->body($response));
    }

    /**
     * Test that one project reads back, and an unknown one is 404
     *
     * @return void
     */
    public function testShow(): void
    {
        $ok = $this->controller()->show(new ServerRequest('GET', '/'), new Psr7Response(), 'a/a');
        $no = $this->controller()->show(new ServerRequest('GET', '/'), new Psr7Response(), 'x/y');

        $this->assertSame(200, $ok->getStatusCode());
        $this->assertStringContainsString('"mode":"propose"', $this->body($ok));
        $this->assertSame(404, $no->getStatusCode());
    }

    /**
     * Test that a good PATCH answers the new representation
     *
     * @return void
     */
    public function testUpdate(): void
    {
        $response = $this->controller()->update($this->patch('{"settings":{"max_cost_usd_day":{"hard":"9.00"}}}'), new Psr7Response(), 'a/a');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('"max_cost_usd_day":{"soft":"2.00","hard":"9.00"}', $this->body($response));
    }

    /**
     * Test the failures: not JSON is 400, a broken rule 422 with the list, no such project 404
     *
     * @return void
     */
    public function testUpdateFailures(): void
    {
        $controller = $this->controller();

        $this->assertSame(400, $controller->update($this->patch('{nope'), new Psr7Response(), 'a/a')->getStatusCode());
        $this->assertSame(400, $controller->update($this->patch(''), new Psr7Response(), 'a/a')->getStatusCode());

        $bad = $controller->update($this->patch('{"mode":"root"}'), new Psr7Response(), 'a/a');
        $this->assertSame(422, $bad->getStatusCode());
        $this->assertStringContainsString('"field":"mode"', $this->body($bad));

        $this->assertSame(404, $controller->update($this->patch('{"mode":"push"}'), new Psr7Response(), 'x/y')->getStatusCode());
    }

    /**
     * @param string $json The request body
     *
     * @return ServerRequest
     */
    private function patch(string $json): ServerRequest
    {
        return (new ServerRequest('PATCH', '/'))->withBody(Stream::create($json));
    }

    /**
     * @param Response $response The response
     *
     * @return string The body, with JSON's escaped slashes undone so paths read as they are
     */
    private function body(Response $response): string
    {
        return str_replace('\\/', '/', (string) $response->getBody());
    }

    /**
     * @return ProjectSettingsController
     */
    private function controller(): ProjectSettingsController
    {
        $store   = new InMemoryProjectSettingsStore(new Project('a/a', ProjectMode::Propose), new Project('b/b'));
        $logger  = new NullLogger();
        $service = new ProjectSettingsService($logger, $store, $store, new ProjectSettingsValidator(new NullLogger()), new ProjectSettingsRepresentation(new NullLogger()));

        return new ProjectSettingsController($logger, new TemplateRenderer($logger, new Engine()), new JsonService($logger), $service);
    }
}
