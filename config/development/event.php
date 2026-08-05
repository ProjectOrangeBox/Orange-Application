<?php

declare(strict_types=1);

use config\development\ConfigDetector;

// ConfigDetector is not autoloaded (composer only maps application\),
// so it has to be required explicitly.
require_once __DIR__ . '/ConfigDetector.php';

/*
 * Development-only event listeners. This file is on the config search path only
 * when ENVIRONMENT=development, so nothing here can run in production.
 */

return [
    /*
     * Keep config/production/config.php in step with the config files it is
     * built from.
     *
     * Development never reads the snapshot - Config discovers and merges the
     * cascade live - so a stale one costs nothing until deploy, which is exactly
     * when it is expensive to notice. This closes that gap.
     *
     * It shells out rather than building the snapshot in-process, and it has to:
     * Application define()s ENVIRONMENT and DEBUG from .env behind a defined()
     * guard, so by the time a development request reaches this listener both are
     * already development values and cannot be redefined. A snapshot built here
     * would say "production" on the tin and hold DEBUG=true and the
     * config/development overrides. bin/configExport sets them before booting,
     * which is the only way to get this right.
     *
     * Failure is logged and never fatal - a developer whose PHP binary is not
     * where PHP_BINARY says should still get their page.
     */
    'before.router' => function (): void {
        $root = __ROOT__;
        $snapshot = $root . '/config/production/config.php';

        $directories = [
            $root . '/vendor/orange/framework/src/config',
            $root . '/config',
            $root . '/config/production',
        ];

        if (!ConfigDetector::isStale($directories, $snapshot)) {
            return;
        }

        logMsg('INFO', 'config snapshot is stale, rebuilding: ' . $snapshot);

        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/configExport') . ' 2>&1';

        exec($command, $output, $status);

        if ($status !== 0) {
            logMsg('ERROR', 'config snapshot rebuild failed: ' . implode(' | ', $output));
        }
    },
];
