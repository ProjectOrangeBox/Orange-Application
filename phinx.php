<?php

declare(strict_types=1);

/*
 * Phinx configuration.
 *
 * Credentials come from .env's [db] section rather than being repeated here,
 * so there is one place to change them and no second copy to forget.
 *
 * Migrations and seeds live under database/, which already existed and is
 * where someone would look, rather than phinx's default db/.
 *
 *   composer db:migrate     apply pending migrations
 *   composer db:seed        run the seeders
 *   composer db:export      regenerate the sandbox's initdb SQL from both
 *
 * The 'scratch' environment is what db:export builds into - a throwaway
 * database it creates, migrates, seeds, dumps and drops. Nothing else should
 * point at it.
 */

$env = parse_ini_file(__DIR__ . '/.env', true, INI_SCANNER_TYPED);
$db = is_array($env) && isset($env['db']) && is_array($env['db']) ? $env['db'] : [];

/**
 * The database host, resolved for wherever this is actually running.
 *
 * .env says host.docker.internal because that is how the app container reaches
 * the MySQL container. Phinx is usually run from the host, where that name does
 * not resolve at all - so fall back to 127.0.0.1 rather than failing with a DNS
 * error that says nothing about the cause. Same approach orange/cache's test
 * suite takes for the same reason.
 */
$resolveHost = static function (string $host): string {
    if ($host === '' || gethostbyname($host) === $host) {
        // gethostbyname() returns its input unchanged when it cannot resolve
        return '127.0.0.1';
    }

    return $host;
};

$host = (string) getenv('PHINX_DB_HOST') ?: $resolveHost((string) ($db['host'] ?? ''));
$name = (string) getenv('PHINX_DB_NAME') ?: (string) ($db['database'] ?? 'webapp');

$connection = [
    'adapter' => 'mysql',
    'host' => $host,
    'name' => $name,
    'user' => (string) ($db['username'] ?? ''),
    'pass' => (string) ($db['password'] ?? ''),
    'port' => (int) ($db['port'] ?? 3306),
    'charset' => (string) ($db['charset'] ?? 'utf8mb4'),
];

return [
    'paths' => [
        'migrations' => __DIR__ . '/database/migrations',
        'seeds' => __DIR__ . '/database/seeds',
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => 'development',
        'development' => $connection,
        // db:export overrides the name via PHINX_DB_NAME; this entry exists so
        // the scratch run is an explicit environment rather than a surprise
        // pointed at whatever 'development' happens to be.
        'scratch' => $connection,
    ],
    'version_order' => 'creation',
];
