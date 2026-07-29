<?php

/**
 * Development-only overrides, merged over config/application.php by the config
 * cascade (this directory is only on the search path when ENVIRONMENT=development).
 *
 * Errors go to the browser here because that is the fastest way to see them while
 * working. Keeping this in its own file - rather than a flag in the base config -
 * means production cannot accidentally inherit it.
 */

declare(strict_types=1);

return [
    'display_errors' => 1,
    'display_startup_errors' => 1,
    'error_reporting' => -1,
];
