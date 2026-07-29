<?php

declare(strict_types=1);

return [
    'h1' => 'Hello World!',
    'this file' => __FILE__,
    'position' => 'Head Bottle Washer',
    'default services' => [],

    // Base (production-safe) error posture. A PHP notice or an uncaught PDOException
    // rendered to the browser leaks file paths, SQL, and - for a failed database
    // connection - the credentials in the DSN. Never display errors by default;
    // ENVIRONMENT=development opts back in via config/development/application.php.
    'display_errors' => 0,
    'display_startup_errors' => 0,
    // still *report* everything so the error handler and log see it - only the
    // displaying is turned off. (error_reporting(0) would silence the log too.)
    'error_reporting' => E_ALL,
];
