<?php

declare(strict_types=1);

use orange\framework\Application;

/**
 * FrankenPHP worker entry point (production only).
 *
 * Classic mode boots PHP, the autoloader and the framework on every single
 * request. In worker mode this script is executed once and then parks in the
 * loop below, so PHP startup, opcache, the Composer autoloader and the parsed
 * .env are all reused across requests.
 *
 * Each request still gets a freshly built DI container: Application::make()
 * caches the environment and config directories, but http() calls bootstrap()
 * which rebuilds the container and every service from scratch. That keeps
 * request state (input, output, session) from leaking between requests.
 *
 * This file lives outside htdocs/ so it is never reachable over HTTP.
 * Selected by docker-entrypoint.sh when ENVIRONMENT=production in .env.
 */

// All directories are based off of this root path and
// everything goes under this path for security and easier portability
define('__ROOT__', realpath(__DIR__));

// where is our public www directory?
define('__WWW__', __ROOT__ . '/htdocs');

// bootstrap before anything else
if (file_exists(__ROOT__ . '/bootstrap.php')) {
    require_once __ROOT__ . '/bootstrap.php';
}

// load the standard composer autoloader
require_once __ROOT__ . '/vendor/autoload.php';

// frankenphp_handle_request() only exists inside a FrankenPHP worker process,
// so static analysis and IDEs will flag it as undefined - that is expected.
// Fail loudly rather than fatally if this file is ever run by a plain PHP CLI.
if (!function_exists('frankenphp_handle_request')) {
    fwrite(STDERR, 'worker.php must be run by FrankenPHP in worker mode.' . PHP_EOL);

    exit(1);
}

// Handled once per request by FrankenPHP, with the superglobals already
// repopulated for that request.
$handler = static function (): void {
    // no config directories are passed on purpose - see htdocs/index.php
    Application::make([__ROOT__ . '/.env'])->http();
};

// Restart the worker process after this many requests so any slow leak in a
// long-lived service is bounded. 0 disables the limit.
$maxRequests = (int) (getenv('MAX_REQUESTS') ?: 0);
$handled = 0;

while (frankenphp_handle_request($handler)) {
    $handled++;

    // Services are rebuilt per request, so collect the garbage they leave behind.
    gc_collect_cycles();

    if ($maxRequests > 0 && $handled >= $maxRequests) {
        break;
    }
}
