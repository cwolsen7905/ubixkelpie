<?php

declare(strict_types=1);

namespace Kelpie\Controller\Api;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ubix\Controller\AbstractController as Controller;

/**
 * CORS preflight controller for the KelpieApi app
 *
 * @see \Kelpie\Tests\Controller\Api\PreflightControllerTest PHPUnit test case
 */
final class PreflightController extends Controller
{
    /**
     * Answer a preflight; CorsMiddleware adds the headers
     *
     * @param Request  $request  PSR request
     * @param Response $response PSR response
     *
     * @return Response
     */
    public function options(Request $request, Response $response): Response // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- Slim passes the request to every action
    {
        return $response->withStatus(204);
    }
}
