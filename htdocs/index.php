<?php

declare(strict_types=1);

use orange\framework\Application;

// All directories are based off of this root path and
// everything goes under this path for security and easier portability
define('__ROOT__', realpath(__DIR__ . '/../'));

// where is our public www directory?
define('__WWW__', __ROOT__ . '/htdocs');

// bootstrap before anything else
if (file_exists(__ROOT__ . '/bootstrap.php')) {
    require_once __ROOT__ . '/bootstrap.php';
}

// load the standard composer autoloader
require_once __ROOT__ . '/vendor/autoload.php';

// and away we go!
// no config directories are passed on purpose - Application only appends
// config/{ENVIRONMENT} to the cascade when the caller supplies none, so passing
// config/ explicitly would silently disable the per-environment overrides
Application::make([__ROOT__ . '/.env'])->http();
