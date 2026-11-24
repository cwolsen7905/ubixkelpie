<?php

declare(strict_types=1);

use Kelpie\Controller\Api\PreflightController;
use Kelpie\Controller\Api\ProjectSettingsController;
use Kelpie\Controller\Api\VersionController;
use Kelpie\Controller\Gitlab\GitlabWebhookController;
use Kelpie\Controller\HealthController;
use Kelpie\Security\ApiTokenMiddleware;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Routing\RouteCollectorProxy;

/**
 * Register the KelpieApi routes
 *
 * @param App<\Psr\Container\ContainerInterface> $app The Slim Framework application
 *
 * @return void
 */

return static function (App $app): void {
    // phpcs:disable Generic.Functions.FunctionCallArgumentSpacing.TooMuchSpaceAfterComma -- vertical alignment of route parameters
    // Readiness target: cheap, unauthenticated, touches nothing.
    $app->map(['GET'],     '/health',         HealthController::class . ':health');
    $app->map(['GET'],     '/version',        VersionController::class . ':version');

    // Every preflight. Without this, Slim answers OPTIONS as method-not-allowed
    // before CorsMiddleware adds its headers, and the browser refuses the real request.
    $app->map(['OPTIONS'], '/{routes:.*}',    PreflightController::class . ':options');

    // GitLab webhook receiver: Note Hook and Issue Hook.
    $app->map(['POST'],    '/gitlab/webhook', GitlabWebhookController::class . ':webhook');

    // Settings API (docs/settings-api.md): bearer token only, never the webhook secret. The project path is URL-encoded
    // in the URL (`ubixsys%2Fubixkelpie`). Other methods on these paths are 405, after the token check.
    $app->group('/api/v1/projects', static function (RouteCollectorProxy $group): void {
        $notAllowed = static function (string ...$allowed): callable {
            return static function (Request $request) use ($allowed): void {
                throw (new HttpMethodNotAllowedException($request))->setAllowedMethods($allowed);
            };
        };

        // Each method is registered once: Slim's any() would register GET and OPTIONS again, which FastRoute refuses, and
        // OPTIONS must stay with the preflight route above (no token for a preflight).
        $group->get('', ProjectSettingsController::class . ':list');
        $group->post('', $notAllowed('GET'));
        $group->put('', $notAllowed('GET'));
        $group->patch('', $notAllowed('GET'));
        $group->delete('', $notAllowed('GET'));
        $group->get('/{path:.+}', ProjectSettingsController::class . ':show');
        $group->patch('/{path:.+}', ProjectSettingsController::class . ':update');
        $group->post('/{path:.+}', $notAllowed('GET', 'PATCH'));
        $group->put('/{path:.+}', $notAllowed('GET', 'PATCH'));
        $group->delete('/{path:.+}', $notAllowed('GET', 'PATCH'));
    })->add(ApiTokenMiddleware::class);

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
