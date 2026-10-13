<?php

declare(strict_types=1);

use DI\Container;
use DI\ContainerBuilder;
use Kelpie\Controller\Gitlab\GitlabWebhookController;
use Kelpie\HttpClient\ApprovalRetryHttpClient;
use Latte\Engine;
use Latte\Loaders\FileLoader;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger as MonologLogger;
use Monolog\Processor\UidProcessor;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface as HttpClient;
use Psr\Http\Message\RequestFactoryInterface as RequestFactory;
use Psr\Http\Message\ResponseFactoryInterface as ResponseFactory;
use Psr\Http\Message\StreamFactoryInterface as StreamFactory;
use Psr\Log\LoggerInterface as Logger;
use Slim\Psr7\Factory\ResponseFactory as SlimResponseFactory;
use Ubix\HttpClient\CurlHttpClient;
use Ubix\Middleware\CorsMiddleware;
use Ubix\Service\Ci\ApprovalWebhookService;

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

    // Sign-off job names the approval retry may re-run (kitg's and the framework's default).
    $approvalJobNames = array_values(array_filter(array_map('trim', explode(',', getenv('APPROVAL_JOB_NAMES') ?: 'require-approval,require-approval-mr'))));

    $container = new ContainerBuilder();
    $container->addDefinitions([
        Engine::class                  => autowire()->method('setAutoRefresh', true)->method('setTempDirectory', $cacheDir)->method('setLoader', new FileLoader($templateDir)),
        HttpClient::class              => autowire(CurlHttpClient::class)->constructorParameter('timeout', 20), // A slow GitLab must not hold a webhook worker
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
        // The approval service gets only the guard client, so it can read an MR and retry a job and
        // nothing else: Kelpie cannot approve or thumbs-up, whatever the token's scope.
        ApprovalRetryHttpClient::class => autowire()->constructorParameter('httpClient', get(HttpClient::class)),
        ApprovalWebhookService::class  => autowire()
            ->constructorParameter('httpClient', get(ApprovalRetryHttpClient::class))
            ->constructorParameter('apiUrl', getenv('GITLAB_API_URL') ?: '')
            ->constructorParameter('secret', getenv('GITLAB_WEBHOOK_SECRET') ?: '')
            ->constructorParameter('projectTokens', getenv('GITLAB_PROJECT_TOKENS') ?: '')
            ->constructorParameter('apiToken', getenv('GITLAB_API_TOKEN') ?: '')
            ->constructorParameter('jobNames', $approvalJobNames),
        GitlabWebhookController::class => autowire()->constructorParameter('secret', getenv('GITLAB_WEBHOOK_SECRET') ?: ''),
    ]);

    return $container->build();
};
