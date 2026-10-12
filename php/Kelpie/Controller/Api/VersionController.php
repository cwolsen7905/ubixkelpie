<?php

declare(strict_types=1);

namespace Kelpie\Controller\Api;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ubix\Controller\AbstractController as Controller;

/**
 * Version controller for the KelpieApi app
 *
 * @see \Kelpie\Tests\Controller\Api\VersionControllerTest PHPUnit test case
 */
final class VersionController extends Controller
{
    /**
     * Report which build is running
     *
     * `APP_VERSION` is baked into the image at build time (the commit SHA, see
     * the Dockerfile); it is `unknown` for a build that did not set it.
     *
     * @param Request  $request  PSR request
     * @param Response $response PSR response
     *
     * @return Response
     */
    public function version(Request $request, Response $response): Response // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- Slim passes the request to every action
    {
        return $this->renderJson($response, [
            'app'     => getenv('APP_NAME') ?: 'unknown',
            'version' => getenv('APP_VERSION') ?: 'unknown',
        ]);
    }
}
