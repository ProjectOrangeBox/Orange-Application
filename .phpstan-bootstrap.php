<?php

declare(strict_types=1);

// PHPStan-only stub: these constants are normally define()'d at real runtime
// (htdocs/index.php, Application::bootstrap()) before any config file loads,
// but PHPStan analyses config/*.php in isolation without ever running that
// bootstrap - so it needs to see them defined here to know their type.

if (!defined('__ROOT__')) {
    define('__ROOT__', __DIR__);
}

if (!defined('__WWW__')) {
    define('__WWW__', __DIR__ . '/htdocs');
}

if (!defined('ENVIRONMENT')) {
    // whatever literal value is picked here, PHPStan will (correctly, given
    // this stub) treat any `ENVIRONMENT != '...'` comparison against a
    // different literal as statically always-true/-false - see the matching
    // ignoreErrors entry for config/RouterDetector.php in phpstan.neon
    define('ENVIRONMENT', 'production');
}

if (!defined('DEBUG')) {
    define('DEBUG', true);
}

if (!defined('CHARSET')) {
    define('CHARSET', 'UTF-8');
}

if (!defined('UNDEFINED')) {
    define('UNDEFINED', chr(0));
}
