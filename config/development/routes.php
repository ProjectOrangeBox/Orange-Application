<?php

declare(strict_types=1);

use config\development\RouterDetector;

// RouterDetector is not autoloaded (composer only maps application\ and api\),
// so it has to be required explicitly.
require_once __DIR__ . '/RouterDetector.php';

return [
    // all of the routes need to be in this array
    'routes' => RouterDetector::detect([__ROOT__ . '/application', __ROOT__ . '/api'], [
        // these are used to get paths router::getUrl(...)
        // then if you need to change a path you simply need to change it here and not in mutiple files
        ['url' => '/assets', 'name' => 'assets'],
        ['url' => '/assets/js', 'name' => 'javascript'],
        ['url' => '/assets/css', 'name' => 'css'],
        ['url' => '/images', 'name' => 'images'],
    ]),
];
