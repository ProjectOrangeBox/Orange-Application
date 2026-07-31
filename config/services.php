<?php

declare(strict_types=1);

use api\models\RecordModel;
use api\models\CalendarEventModel;
use orange\acl\Acl;
use orange\acl\User;
use orange\auth\Auth;
use orange\negotiate\Negotiate;
use orange\session\Session;
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
        // The session id has to be recovered from the raw Cookie header rather
        // than from $_COOKIE, because by the time this service is built there
        // is no usable $_COOKIE to read.
        //
        // Two things conspire. Input::setGlobals() captures the superglobals
        // during bootstrap and then unsets them - and with auto_globals_jit=On
        // (the default, and what this container runs) $_COOKIE is not
        // materialised until something references it by name, so what Input
        // captures is frequently an empty array. input->cookie() then reports
        // no cookies at all even though the request plainly sent some;
        // input->server('HTTP_COOKIE') still has the header verbatim.
        //
        // Reassigning $_COOKIE here would not help either: once a superglobal
        // has been unset, the auto-global binding is broken and an assignment
        // inside a function just makes an ordinary local.
        //
        // The visible symptom of all this is a login that appears to work and
        // is forgotten by the very next request, with nothing logged anywhere.
        $sessionId = null;
        $cookies = (array) $container->input->cookie();

        if (isset($cookies['session_id']) && is_string($cookies['session_id'])) {
            $sessionId = $cookies['session_id'];
        } else {
            $header = $container->input->server('HTTP_COOKIE');

            if (is_string($header)) {
                foreach (explode(';', $header) as $pair) {
                    [$name, $value] = array_pad(explode('=', trim($pair), 2), 2, '');

                    if ($name === 'session_id') {
                        $sessionId = urldecode($value);

                        break;
                    }
                }
            }
        }

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
