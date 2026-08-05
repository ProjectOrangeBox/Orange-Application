<?php

declare(strict_types=1);

return [
    'routes' => [
        ['url' => '/assets', 'name' => 'assets'],
        ['url' => '/assets/js', 'name' => 'javascript'],
        ['url' => '/assets/css', 'name' => 'css'],
        ['url' => '/images', 'name' => 'images'],
        ['method' => 'get', 'url' => '/dashboard', 'callback' => [\application\welcome\controllers\DashboardController::class, 'index'], 'name' => 'dashboard'],
        ['method' => '*', 'url' => '/', 'callback' => [\application\welcome\controllers\MainController::class, 'index'], 'name' => 'home'],
        ['method' => 'post', 'url' => '/api/login', 'callback' => [\application\api\controllers\AuthController::class, 'login'], 'name' => 'auth_login'],
        ['method' => 'post', 'url' => '/api/logout', 'callback' => [\application\api\controllers\AuthController::class, 'logout'], 'name' => 'auth_logout'],
        ['method' => 'get', 'url' => '/api/me', 'callback' => [\application\api\controllers\AuthController::class, 'me'], 'name' => 'auth_me'],
        ['method' => 'get', 'url' => '/api/calendar/(\d{4}-\d{2})', 'callback' => [\application\api\controllers\CalendarController::class, 'month'], 'name' => 'calendar_month'],
        ['method' => 'get', 'url' => '/api/calendar/read/(\d+)', 'callback' => [\application\api\controllers\CalendarController::class, 'read'], 'name' => 'calendar_read'],
        ['method' => 'post', 'url' => '/api/calendar/create', 'callback' => [\application\api\controllers\CalendarController::class, 'create'], 'name' => 'calendar_create'],
        ['method' => 'put', 'url' => '/api/calendar/update/(\d+)', 'callback' => [\application\api\controllers\CalendarController::class, 'update'], 'name' => 'calendar_update'],
        ['method' => 'delete', 'url' => '/api/calendar/delete/(\d+)', 'callback' => [\application\api\controllers\CalendarController::class, 'delete'], 'name' => 'calendar_delete'],
        ['method' => 'get', 'url' => '/api/index', 'callback' => [\application\api\controllers\RestController::class, 'index'], 'name' => 'rest_index'],
        ['method' => 'get', 'url' => '/api/read/(\d+)', 'callback' => [\application\api\controllers\RestController::class, 'read'], 'name' => 'rest_read'],
        ['method' => 'post', 'url' => '/api/create', 'callback' => [\application\api\controllers\RestController::class, 'create'], 'name' => 'rest_create'],
        ['method' => 'put', 'url' => '/api/update/(\d+)', 'callback' => [\application\api\controllers\RestController::class, 'update'], 'name' => 'rest_update'],
        ['method' => 'delete', 'url' => '/api/delete/(\d+)', 'callback' => [\application\api\controllers\RestController::class, 'delete'], 'name' => 'rest_delete'],
        ['method' => '*', 'url' => '/api/welcome', 'callback' => [\application\api\controllers\WelcomeController::class, 'index'], 'name' => 'rest_home'],
        ['method' => 'get', 'url' => '/api/orders', 'callback' => [\application\orders\controllers\OrderController::class, 'index'], 'name' => 'orders_index'],
        ['method' => 'get', 'url' => '/api/orders/(\d+)', 'callback' => [\application\orders\controllers\OrderController::class, 'read'], 'name' => 'orders_read'],
        ['method' => 'post', 'url' => '/api/orders', 'callback' => [\application\orders\controllers\OrderController::class, 'create'], 'name' => 'orders_create'],
        ['method' => 'put', 'url' => '/api/orders/(\d+)', 'callback' => [\application\orders\controllers\OrderController::class, 'update'], 'name' => 'orders_update'],
        ['method' => 'delete', 'url' => '/api/orders/(\d+)', 'callback' => [\application\orders\controllers\OrderController::class, 'delete'], 'name' => 'orders_delete'],
        ['method' => 'get', 'url' => '/password/forgot', 'callback' => [\application\login\controllers\PasswordController::class, 'forgotForm'], 'name' => 'password_forgot'],
        ['method' => 'post', 'url' => '/password/forgot', 'callback' => [\application\login\controllers\PasswordController::class, 'forgot'], 'name' => 'password_forgot_submit'],
        ['method' => 'get', 'url' => '/password/reset', 'callback' => [\application\login\controllers\PasswordController::class, 'resetForm'], 'name' => 'password_reset'],
        ['method' => 'post', 'url' => '/password/reset', 'callback' => [\application\login\controllers\PasswordController::class, 'reset'], 'name' => 'password_reset_submit'],
        ['method' => 'get', 'url' => '/login', 'callback' => [\application\login\controllers\SessionController::class, 'form'], 'name' => 'login'],
        ['method' => 'post', 'url' => '/login', 'callback' => [\application\login\controllers\SessionController::class, 'login'], 'name' => 'login_submit'],
        ['method' => 'post', 'url' => '/logout', 'callback' => [\application\login\controllers\SessionController::class, 'logout'], 'name' => 'logout'],
        ['method' => 'get', 'url' => '/signup', 'callback' => [\application\login\controllers\SignupController::class, 'form'], 'name' => 'signup'],
        ['method' => 'post', 'url' => '/signup', 'callback' => [\application\login\controllers\SignupController::class, 'signup'], 'name' => 'signup_submit'],
        ['method' => 'get', 'url' => '/signup/confirm', 'callback' => [\application\login\controllers\SignupController::class, 'confirm'], 'name' => 'signup_confirm'],
    ]
];
