<?php

declare(strict_types=1);

use DI\Container;
use DI\ContainerBuilder;
use Kelpie\Controller\Gitlab\GitlabWebhookController;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestFactoryInterface as RequestFactory;
use Psr\Http\Message\ResponseFactoryInterface as ResponseFactory;
use Psr\Http\Message\StreamFactoryInterface as StreamFactory;
use Psr\Log\LoggerInterface as Logger;
use Slim\Psr7\Factory\ResponseFactory as SlimResponseFactory;
use Ubix\HttpClient\CurlHttpClient;
use Ubix\Middleware\CorsMiddleware;

use function DI\autowire;
use function DI\get;

/**
 * Build the PHP-DI container for the KelpieApi app
 *
 * The KelpieApi container, HelloApi's plus CORS: a logger, the PSR-17
 * factories, an HTTP client and a Latte engine (the base controller renders
 * JSON and templates through it). Add your repositories and services here.
 */

return static function (): Container {
    $appName = getenv('APP_NAME') ?: 'KelpieApi';
    $root    = getenv('UBIX_PROJECT_ROOT') ?: dirname(__DIR__, 3); // The bootstrap exports the project root

    $relativeToRoot = static function (string $path) use ($root): string {
        return str_starts_with($path, '/') ? $path : $root . '/' . $path;
    };

    $cacheDir = $relativeToRoot(getenv('LATTE_CACHE_DIRECTORY') ?: 'var/cache/latte/');
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0777, true);
    }
    $templateDir = $relativeToRoot(getenv('LATTE_TEMPLATES_DIRECTORY') ?: 'templates/') . 'default';

    $allowedLevels = ['Alert', 'Critical', 'Debug', 'Emergency', 'Error', 'Info', 'Notice', 'Warning'];
    $logLevel      = ucfirst(getenv('LOG_LEVEL') ?: 'Info');
    $logLevel      = in_array($logLevel, $allowedLevels, true) ? $logLevel : 'Info';

    $container = new ContainerBuilder();
    $container->addDefinitions([
        Engine::class                  => autowire()->method('setAutoRefresh', true)->method('setTempDirectory', $cacheDir)->method('setLoader', new FileLoader($templateDir)),
        HttpClient::class              => autowire(CurlHttpClient::class),
        // Logs go to the container's stderr, not a file. PHP-FPM in this base
        // image sets catch_workers_output=yes and error_log=/dev/stderr, so a
        // worker's stderr becomes the container's, which is what fluent-bit
        // tails from /var/log/containers and ships to OpenSearch.
        //
        // A file inside the container was erased by every restart. On
        // 2026-09-25 the whole cluster's containers were replaced within six
        // minutes and every log with them, which turned "why did admin sign-in
        // fail?" into an unanswerable question.
        Logger::class                  => autowire(MonologLogger::class)->constructorParameter('name', $appName)->constructorParameter('handlers', [new StreamHandler('php://stderr', Level::fromName($logLevel))])->constructorParameter('processors', [new UidProcessor()]),
        // Browsers on the uBixSys domains and local dev; PATCH is listed because the framework default omits it.
        CorsMiddleware::class          => autowire()->constructorParameter('allowedOrigins', ['127.0.0.1', 'localhost', '.ubixsys.com'])->constructorParameter('allowedMethods', ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']),
        Psr17Factory::class            => autowire(Psr17Factory::class),
        RequestFactory::class          => get(Psr17Factory::class),
        ResponseFactory::class         => autowire(SlimResponseFactory::class),
        StreamFactory::class           => get(Psr17Factory::class),
        GitlabWebhookController::class => autowire()->constructorParameter('secret', getenv('GITLAB_WEBHOOK_SECRET') ?: ''),
    ]);

    return $container->build();
};
