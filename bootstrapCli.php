<?php

declare(strict_types=1);

// user cli script bootstraper

use orange\framework\Application;

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

// and away we go! CLI scripts (bin/*, vendor/*/bin/*) do:
//   $container = require __ROOT__ . '/bootstrapCli.php';
return Application::make([__ROOT__ . '/.env'], [__ROOT__ . '/config'])->run();
