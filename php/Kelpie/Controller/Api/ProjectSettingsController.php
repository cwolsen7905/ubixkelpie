<?php

declare(strict_types=1);

namespace Kelpie\Controller\Api;

use Kelpie\Service\Settings\ProjectSettingsService;
use Kelpie\Settings\Exception\ProjectNotFoundException;
use Kelpie\Settings\Exception\SettingsValidationException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface as Logger;
use TypeError;
use Ubix\Controller\AbstractController as Controller;
use Ubix\Enum\StatusCode;
use Ubix\Exception\DtoException;
use Ubix\Renderer\TemplateRenderer;
use Ubix\Service\JsonService;

/**
 * Per-project settings API: list, read and edit (docs/settings-api.md)
 *
 * Behind ApiTokenMiddleware. Thin: it decodes the body, asks the service and maps its exceptions to 404 and 422.
 *
 * @see \Kelpie\Tests\Controller\Api\ProjectSettingsControllerTest PHPUnit test case
 */
final class ProjectSettingsController extends Controller
{
    /**
     * Constructor
     *
     * @param Logger                 $logger           Logger
     * @param TemplateRenderer       $templateRenderer Unused; required by parent
     * @param JsonService            $jsonService      JSON service from parent
     * @param ProjectSettingsService $service          The settings rules
     */
    public function __construct(
        Logger $logger,
        TemplateRenderer $templateRenderer,
        JsonService $jsonService,
        private readonly ProjectSettingsService $service,
    ) {
        parent::__construct($logger, $templateRenderer, $jsonService);
    }

    /**
     * GET /api/v1/projects
     *
     * @param Request  $request  PSR request
     * @param Response $response PSR response
     *
     * @return Response
     */
    public function list(Request $request, Response $response): Response // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- Slim passes the request to every action
    {
        return $this->renderJson($response, ['projects' => $this->service->list()]);
    }

    /**
     * GET /api/v1/projects/{path}
     *
     * @param Request  $request  PSR request
     * @param Response $response PSR response
     * @param string   $path     The project path, URL-encoded in the URL
     *
     * @return Response 404 when the project has no row
     */
    public function show(Request $request, Response $response, string $path): Response // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- Slim passes the request to every action
    {
        try {
            return $this->renderJson($response, $this->service->get($path));
        } catch (ProjectNotFoundException $e) {
            return $this->renderJson($response, ['error' => 'No such project'], StatusCode::NOT_FOUND);
        }
    }

    /**
     * PATCH /api/v1/projects/{path}
     *
     * @param Request  $request  PSR request; the body is a JSON object
     * @param Response $response PSR response
     * @param string   $path     The project path, URL-encoded in the URL
     *
     * @return Response 400 for a body that is not JSON, 404 for no such project, 422 with the broken rules
     */
    public function update(Request $request, Response $response, string $path): Response
    {
        try {
            $body = $this->jsonService->decode((string) $request->getBody());
        } catch (DtoException | TypeError $e) {
            return $this->renderJson($response, ['error' => 'The body is not valid JSON'], StatusCode::BAD_REQUEST);
        }

        try {
            return $this->renderJson($response, $this->service->update($path, $body));
        } catch (ProjectNotFoundException $e) {
            return $this->renderJson($response, ['error' => 'No such project'], StatusCode::NOT_FOUND);
        } catch (SettingsValidationException $e) {
            return $this->renderJson($response, ['error' => 'The settings are not valid', 'errors' => $e->getErrors()], StatusCode::UNPROCESSABLE_ENTITY);
        }
    }
}
