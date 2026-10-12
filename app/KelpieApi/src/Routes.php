<?php

declare(strict_types=1);

use Kelpie\Controller\Api\PreflightController;
use Kelpie\Controller\Api\VersionController;
use Kelpie\Controller\Gitlab\GitlabWebhookController;
use Kelpie\Controller\HealthController;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Exception\HttpNotFoundException;

/**
 * Register the KelpieApi routes
 *
 * @param App<\Psr\Container\ContainerInterface> $app The Slim Framework application
 *
 * @return void
 */

return static function (App $app): void {
    // phpcs:disable Generic.Functions.FunctionCallArgumentSpacing.TooMuchSpaceAfterComma
    // Readiness target: cheap, unauthenticated, touches nothing.
    $app->map(['GET'],     '/health',         HealthController::class . ':health');
    $app->map(['GET'],     '/version',        VersionController::class . ':version');

    // Every preflight. Without this, Slim answers OPTIONS as method-not-allowed
    // before CorsMiddleware adds its headers, and the browser refuses the real request.
    $app->map(['OPTIONS'], '/{routes:.*}',    PreflightController::class . ':options');

    // GitLab webhook receiver: Note Hook and Issue Hook.
    $app->map(['POST'],    '/gitlab/webhook', GitlabWebhookController::class . ':webhook');

    // phpcs:enable Generic.Functions.FunctionCallArgumentSpacing.TooMuchSpaceAfterComma

    //
    //  Anything else is a 404 rendered by the JSON error handler
    //
    $app->map(
        ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
        '/{routes:.+}',
        static function (Request $request): void {
            throw new HttpNotFoundException($request);
        },
    );
};
