<?php

declare(strict_types=1);

namespace Kelpie\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ubix\Controller\AbstractController as Controller;

/**
 * Health check controller for the Kelpie apps
 *
 * @see \Kelpie\Tests\Controller\HealthControllerTest PHPUnit test case
 */
final class HealthController extends Controller
{
    /**
     * Report that the app is up
     *
     * @param Request  $request  PSR request
     * @param Response $response PSR response
     *
     * @return Response
     */
    public function health(Request $request, Response $response): Response // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- Slim passes the request to every action
    {
        return $this->renderJson($response, ['status' => 'ok', 'app' => getenv('APP_NAME') ?: 'unknown']);
    }
}
