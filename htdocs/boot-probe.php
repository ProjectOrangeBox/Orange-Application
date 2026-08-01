<?php

// TEMPORARY probe: boot exactly as index.php does, then inspect input.
define('__ROOT__', realpath(__DIR__ . '/../'));
define('__WWW__', __ROOT__ . '/htdocs');
require_once __ROOT__ . '/vendor/autoload.php';
$container = orange\framework\Application::make([__ROOT__ . '/.env'])->run();
header('Content-Type: application/json');
echo json_encode([
    'input_cookie' => $container->input->cookie(),
    'input_http_cookie' => $container->input->server('HTTP_COOKIE'),
    'fromGlobals_now' => orange\framework\Input::fromGlobals()['cookie'],
]);
