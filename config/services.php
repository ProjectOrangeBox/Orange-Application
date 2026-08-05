<?php

declare(strict_types=1);

use application\api\models\RecordModel;
use application\api\models\CalendarEventModel;
use application\models\LoginThrottleModel;
use application\login\models\UserAccountModel;
use application\login\models\UserTokenModel;
use orange\acl\Acl;
use orange\acl\User;
use orange\auth\Auth;
use orange\mail\Mailer;
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
    // Brute-force throttling for POST /api/login. orange/auth names rate
    // limiting a non-goal - it needs state shared across requests - so the
    // application supplies it, backed by the same PDO handle auth itself uses.
    'LoginThrottleModel' => fn(ContainerInterface $container): LoginThrottleModel => LoginThrottleModel::getInstance($container->pdo),
    // Account writes and the emailed tokens behind signup and password reset.
    // Neither orange/auth nor orange/acl creates an account or changes a
    // password - an account's lifecycle belongs to the application that decides
    // what an account is - so these two supply the handful of writes they omit.
    'UserAccountModel' => fn(ContainerInterface $container): UserAccountModel => UserAccountModel::getInstance($container->pdo),
    'UserTokenModel' => fn(ContainerInterface $container): UserTokenModel => UserTokenModel::getInstance($container->pdo),
    'acl' => fn(ContainerInterface $container): Acl => Acl::getInstance([], $container->pdo),
    'auth' => fn(ContainerInterface $container): Auth => Auth::getInstance([], $container->pdo),
    'user' => fn(ContainerInterface $container): User => User::getInstance([], $container->acl, $container->session),

    /*
     * Outgoing mail.
     *
     * Configured entirely from .env's [mail] section rather than a config file,
     * because every value in it is deployment-specific: the relay, the return
     * address, and above all where mail must NOT go. In development the dsn
     * points at the Mailpit container, which accepts every message and delivers
     * none of them - see vendor/orange/mail/README.md for reading them.
     *
     * The package renders nothing. A controller renders its email as an ordinary
     * view and hands the string over, so an email template gets the same finder
     * and the same per-module override as any other view.
     */
    'mail' => function (): Mailer {
        $env = (array) env('mail', []);

        return Mailer::getInstance([
            // no fallback for the dsn: Mailer refuses to construct without one,
            // which is the point - a mailer pointed nowhere discards silently
            'dsn' => $env['dsn'] ?? '',
            'from' => $env['from'] ?? '',
            'from name' => $env['from_name'] ?? '',
            'catch all' => $env['catch_all'] ?? '',
            'subject prefix' => $env['subject_prefix'] ?? '',
        ]);
    },
];
