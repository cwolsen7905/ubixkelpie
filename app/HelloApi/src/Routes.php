<?php

declare(strict_types=1);

use Kelpie\Controller\HealthController;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Exception\HttpNotFoundException;

/**
 * Register the HelloApi routes
 *
 * @param App<\Psr\Container\ContainerInterface> $app The Slim Framework application
 *
 * @return void
 */

return static function (App $app): void {
    $app->map(['GET'], '/health', HealthController::class . ':health');

    //
    //  Anything else is a 404 rendered by the JSON error handler
    //
    $app->map(
        ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        '/{routes:.+}',
        static function (Request $request): void {
            throw new HttpNotFoundException($request);
        },
    );
};
