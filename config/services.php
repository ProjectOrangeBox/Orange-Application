<?php

declare(strict_types=1);

use api\models\RecordModel;
use orange\files\Files;
use orange\flashmsg\Flashmsg;
use orange\framework\interfaces\ContainerInterface;

return [
    'pdo' => function () {
        // create only the 1st time called and not before
        $env = env('db');

        $host = $env['host'] ?? 'localhost';
        $db = $env['database'] ?? '';
        $user = $env['username'] ?? '';
        $pass = $env['password'] ?? '';
        $charset = $env['charset'] ?? 'utf8mb4';
        $port = $env['port'] ?? 3306;

        $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";

        $options = [
            // Throw exceptions on errors
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            // Return database records as clean arrays
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Use genuine native prepared statements
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $pdo = new \PDO($dsn, $user, $pass, $options);
        } catch (\PDOException $e) {
            throw new \PDOException($e->getMessage(), (int)$e->getCode(), $e);
        }

        return $pdo;
    },
    'RecordModel' => fn(ContainerInterface $container): RecordModel => RecordModel::getInstance($container->pdo),
    // flash messages - delivered to JSON responses by the before.output
    // listener in event.php. Session argument is null (this app is a
    // stateless JSON API); pass a session service instead to enable the
    // cross-request redirect()/keep() flows
    'flash' => fn(ContainerInterface $container): Flashmsg => Flashmsg::getInstance(
        [],
        null,
        $container->input,
        $container->output,
        $container->data,
        $container->events,
    ),
    // uploaded-file handling (orange/files) - its config defaults 'mimes'
    // from the framework output config, so isOneOf('png', ...) extension
    // matching works with no wiring here
    'files' => fn(ContainerInterface $container): Files => Files::getInstance($container->config->files, $container->input),
];
