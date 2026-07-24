<?php

declare(strict_types=1);

// Production route table.
//
// RouterDetector refuses to run outside development, so these routes cannot be
// discovered from the #[Route] attributes at runtime here - they are a snapshot.
// Regenerate this list whenever a #[Route] attribute changes:
//
//     RouterDetector::export([__ROOT__ . '/application', __ROOT__ . '/api'], [...]);
//
// The 'routes' key is required. The cascading config loader merges this file
// over vendor/orange/framework/src/config/routes.php with array_replace_recursive(),
// and that file expects the route list under 'routes'. A bare list would merge
// in as top-level numeric keys and leave 'routes' empty.

return [
    'routes' => [
        // these are used to get paths router::getUrl(...)
        ['url' => '/assets', 'name' => 'assets'],
        ['url' => '/assets/js', 'name' => 'javascript'],
        ['url' => '/assets/css', 'name' => 'css'],
        ['url' => '/images', 'name' => 'images'],

        ['method' => '*', 'url' => '/', 'callback' => [\application\welcome\controllers\MainController::class, 'index'], 'name' => 'home'],

        ['method' => 'get', 'url' => '/api/index', 'callback' => [\api\controllers\RestController::class, 'index'], 'name' => 'rest_index'],
        ['method' => 'get', 'url' => '/api/read/(\d+)', 'callback' => [\api\controllers\RestController::class, 'read'], 'name' => 'rest_read'],
        ['method' => 'post', 'url' => '/api/create', 'callback' => [\api\controllers\RestController::class, 'create'], 'name' => 'rest_create'],
        ['method' => 'put', 'url' => '/api/update/(\d+)', 'callback' => [\api\controllers\RestController::class, 'update'], 'name' => 'rest_update'],
        ['method' => 'delete', 'url' => '/api/delete/(\d+)', 'callback' => [\api\controllers\RestController::class, 'delete'], 'name' => 'rest_delete'],

        ['method' => '*', 'url' => '/api/welcome', 'callback' => [\api\controllers\WelcomeController::class, 'index'], 'name' => 'rest_home'],
    ],
];
