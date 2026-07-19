<?php

declare(strict_types=1);

return [
    ['method' => '*', 'url' => '/', 'callback' => [\application\welcome\controllers\MainController::class, 'index'], 'name' => 'home'],
    ['method' => '*', 'url' => '/api/welcome', 'callback' => [\api\controllers\RestController::class, 'index'], 'name' => 'rest_home'],

    ['url' => '/assets', 'name' => 'assets'],
    ['url' => '/assets/js', 'name' => 'javascript'],
    ['url' => '/assets/css', 'name' => 'css'],
    ['url' => '/images', 'name' => 'images'],

];
