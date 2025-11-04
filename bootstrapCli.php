<?php

declare(strict_types=1);

use orange\framework\Application;

// setup the application ROOT
// handy for mocking data instead of hardwired a directory location based on the file
// you can just change __ROOT__ to something else then change it back for example
define('__ROOT__', __DIR__);

// bootstrap before anything else
require_once __ROOT__ . '/bootstrap.php';

// composer auto loader
require_once __ROOT__ . '/vendor/autoload.php';

Application::loadEnvironment(__ROOT__ . '/.env', __ROOT__ . '/.env-cli');
