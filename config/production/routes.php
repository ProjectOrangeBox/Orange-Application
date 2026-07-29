<?php

declare(strict_types=1);

return [
    'routes' => [
        ['url' => '/assets', 'name' => 'assets'],
        ['url' => '/assets/js', 'name' => 'javascript'],
        ['url' => '/assets/css', 'name' => 'css'],
        ['url' => '/images', 'name' => 'images'],
        ['method' => '*', 'url' => '/', 'callback' => [\application\welcome\controllers\MainController::class, 'index'], 'name' => 'home'],
        ['method' => 'get', 'url' => '/api/calendar/(\d{4}-\d{2})', 'callback' => [\api\controllers\CalendarController::class, 'month'], 'name' => 'calendar_month'],
        ['method' => 'get', 'url' => '/api/calendar/read/(\d+)', 'callback' => [\api\controllers\CalendarController::class, 'read'], 'name' => 'calendar_read'],
        ['method' => 'post', 'url' => '/api/calendar/create', 'callback' => [\api\controllers\CalendarController::class, 'create'], 'name' => 'calendar_create'],
        ['method' => 'put', 'url' => '/api/calendar/update/(\d+)', 'callback' => [\api\controllers\CalendarController::class, 'update'], 'name' => 'calendar_update'],
        ['method' => 'delete', 'url' => '/api/calendar/delete/(\d+)', 'callback' => [\api\controllers\CalendarController::class, 'delete'], 'name' => 'calendar_delete'],
        ['method' => 'get', 'url' => '/api/index', 'callback' => [\api\controllers\RestController::class, 'index'], 'name' => 'rest_index'],
        ['method' => 'get', 'url' => '/api/read/(\d+)', 'callback' => [\api\controllers\RestController::class, 'read'], 'name' => 'rest_read'],
        ['method' => 'post', 'url' => '/api/create', 'callback' => [\api\controllers\RestController::class, 'create'], 'name' => 'rest_create'],
        ['method' => 'put', 'url' => '/api/update/(\d+)', 'callback' => [\api\controllers\RestController::class, 'update'], 'name' => 'rest_update'],
        ['method' => 'delete', 'url' => '/api/delete/(\d+)', 'callback' => [\api\controllers\RestController::class, 'delete'], 'name' => 'rest_delete'],
        ['method' => '*', 'url' => '/api/welcome', 'callback' => [\api\controllers\WelcomeController::class, 'index'], 'name' => 'rest_home'],
    ]
];
