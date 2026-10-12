<?php

declare(strict_types=1);

/**
 * Web entry point for this uBixCore host project
 *
 * Thin by design: `Ubix\Bootstrap\http()` builds the Slim app for the app named
 * by `APP_NAME` from `app/<APP_NAME>/src/{Dependencies,Middleware,Routes}.php`.
 */

use function Ubix\Bootstrap\environment;
use function Ubix\Bootstrap\http;

require_once __DIR__ . '/../vendor/autoload.php';

$projectRoot = environment(dirname(__DIR__));

http($projectRoot, (string) getenv('APP_NAME'))->run();
