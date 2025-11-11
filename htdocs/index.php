<?php

declare(strict_types=1);

// All directories are based off of this root path and
// everything goes under this path for security and easier portability
define('__ROOT__', realpath(__DIR__ . '/../'));

// where is our public www directory?
define('__WWW__', __ROOT__ . '/htdocs');

// bootstrap before anything else
require_once __ROOT__ . '/bootstrap.php';

// load the standard composer autoloader
require_once __ROOT__ . '/vendor/autoload.php';

$application = \orange\framework\Application::make();

// Load the application environment
$application->loadEnvironment(__ROOT__ . '/.env');
$application->setConfigDirectories(
    __ROOT__ . '/config',
    __ROOT__ . '/config/' . ENVIRONMENT,
);

// and away we go!
$application->http();
