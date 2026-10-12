<?php

declare(strict_types=1);

use Psr\Container\ContainerInterface as Container;
use Psr\Log\LoggerInterface as Logger;
use Slim\App;
use Ubix\Middleware\CorsMiddleware;
use Ubix\Middleware\NormalizedHostMiddleware;
use Ubix\Middleware\NormalizedIpAddressMiddleware;
use Ubix\Renderer\JsonErrorRenderer;
use Ubix\SlimHandler\SlimErrorHandler;

/**
 * Apply middleware to the KelpieApi Slim application
 *
 * Routing, CORS, normalised host/IP, body parsing and the uBixCore JSON error
 * handler. There is no session or authentication middleware yet: KelpieApi has
 * no database and no product endpoints, and kitg's session auth needs both.
 * Add it with the first endpoint that needs a caller.
 *
 * @param App<Container> $app The Slim Framework application
 *
 * @return void
 */

return static function (App $app): void {
    $logger = $app->getContainer()?->get(Logger::class);
    assert($logger instanceof Logger || $logger === null);

    $app->addRoutingMiddleware();

    // Slim runs the last-added middleware first.
    $app->add(CorsMiddleware::class);
    $app->add(NormalizedHostMiddleware::class);
    $app->add(NormalizedIpAddressMiddleware::class);

    $app->addBodyParsingMiddleware();

    $slimErrorHandler = $app->getContainer()?->get(SlimErrorHandler::class);
    assert($slimErrorHandler instanceof SlimErrorHandler);
    $slimErrorHandler->registerErrorRenderer('application/json', JsonErrorRenderer::class);
    $slimErrorHandler->setDefaultErrorRenderer('application/json', JsonErrorRenderer::class);

    $errorMiddleware = $app->addErrorMiddleware(false, true, true, $logger);
    $errorMiddleware->setDefaultErrorHandler($slimErrorHandler);
};
