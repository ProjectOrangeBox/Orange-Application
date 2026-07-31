<?php

declare(strict_types=1);

use api\models\RecordModel;
use api\models\CalendarEventModel;
use orange\negotiate\Negotiate;
use orders\models\OrderModel;
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
    'CalendarEventModel' => fn(ContainerInterface $container): CalendarEventModel => CalendarEventModel::getInstance($container->pdo),
    'OrderModel' => fn(ContainerInterface $container): OrderModel => OrderModel::getInstance($container->pdo),
    // Reads the request's Accept header, so it needs the input service - the
    // orders module uses it to serve one route as either JSON or CSV.
    'negotiate' => fn(ContainerInterface $container): Negotiate => Negotiate::getInstance($container->input),
];
