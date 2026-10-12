<?php

declare(strict_types=1);

use Psr\Container\ContainerInterface as Container;
use Psr\Log\LoggerInterface as Logger;
use Slim\App;
use Ubix\Renderer\JsonErrorRenderer;
use Ubix\SlimHandler\SlimErrorHandler;

/**
 * Apply middleware to the HelloApi Slim application
 *
 * Routing, body parsing and the uBixCore JSON error handler. Add
 * `Ubix\Middleware\*` (CORS, sessions, normalised host/IP) as you need them.
 *
 * @param App<Container> $app The Slim Framework application
 *
 * @return void
 */

return static function (App $app): void {
    $logger = $app->getContainer()?->get(Logger::class);
    assert($logger instanceof Logger || $logger === null);

    $app->addRoutingMiddleware();
    $app->addBodyParsingMiddleware();

    $slimErrorHandler = $app->getContainer()?->get(SlimErrorHandler::class);
    assert($slimErrorHandler instanceof SlimErrorHandler);
    $slimErrorHandler->registerErrorRenderer('application/json', JsonErrorRenderer::class);
    $slimErrorHandler->setDefaultErrorRenderer('application/json', JsonErrorRenderer::class);

    $errorMiddleware = $app->addErrorMiddleware(false, true, true, $logger);
    $errorMiddleware->setDefaultErrorHandler($slimErrorHandler);
};
