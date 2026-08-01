<?php

declare(strict_types=1);

use application\api\models\RecordModel;
use application\api\models\CalendarEventModel;
use orange\acl\Acl;
use orange\acl\User;
use orange\auth\Auth;
use orange\negotiate\Negotiate;
use orange\session\Session;
use application\orders\models\OrderModel;
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

    /*
     * Authentication and authorization, which the orders module uses to guard
     * its write endpoints.
     *
     * Three services rather than one because they answer different questions,
     * and only together do they answer "may this request do this":
     *
     *   auth    who is this - checks a password, nothing more
     *   acl     what may a user do - roles and permissions, no session
     *   user    who is this *request* - the session-aware pairing of the two
     *
     * Each is passed [] for its config: every one of these packages ships its
     * own defaults and merges what it is given over the top, so an empty array
     * means "the defaults", not "no configuration".
     */
    /*
     * The session, started and configured for how this app is actually served.
     *
     * Two things have to be done here or logging in silently does nothing.
     *
     * aplus's Session does not start itself, and nothing complains when it has
     * not been started: reads return nothing and writes are discarded. A login
     * therefore appears to succeed and the very next request is anonymous again.
     *
     * And orange/session deliberately pins cookie_secure => true, because the
     * session cookie is the highest-value cookie the app has. The quick start
     * serves http://localhost:8080, and a browser drops a Secure cookie sent
     * over plaintext - so on http the session can never persist. Derived from
     * ENVIRONMENT rather than hardcoded either way: production is secure by
     * default, development is not, and SESSION_COOKIE_SECURE in .env overrides
     * both for anyone serving development over TLS.
     */
    'session' => function (ContainerInterface $container): Session {
        // orange/framework used to drop every cookie here - setGlobals()
        // emitted 'cookie' and Input's constructor read 'cookies', so
        // input->cookie() was always empty and a login never survived one
        // request. Fixed in the framework (Input.php), not worked around here.
        $cookies = (array) $container->input->cookie();
        $sessionId = $cookies['session_id'] ?? null;

        $override = env('SESSION_COOKIE_SECURE', null);

        $secure = $override === null
            ? ENVIRONMENT === 'production'
            : filter_var($override, FILTER_VALIDATE_BOOL);

        $session = new Session(['cookie_secure' => $secure]);

        if (!$session->isActive()) {
            // 'session_id' is aplus's cookie name. Set before start(), because
            // start() is what would otherwise read it from the cookie that is
            // no longer there.
            if (is_string($sessionId) && $sessionId !== '') {
                session_id($sessionId);
            }

            $session->start();
        }

        return $session;
    },
    'acl' => fn(ContainerInterface $container): Acl => Acl::getInstance([], $container->pdo),
    'auth' => fn(ContainerInterface $container): Auth => Auth::getInstance([], $container->pdo),
    'user' => fn(ContainerInterface $container): User => User::getInstance([], $container->acl, $container->session),
];
