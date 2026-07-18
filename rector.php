<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/application',
        __DIR__ . '/config',
        __DIR__ . '/bin',
        __DIR__ . '/htdocs',
    ])
    // plain-PHP view templates are rendered by ViewAbstract::generate(), which
    // extract()s data into scope right before require-ing them. Rector would
    // rewrite them against "variables" it cannot see are defined at render time,
    // so leave them untouched (mirrors the phpstan.neon excludePaths).
    ->withSkip([
        __DIR__ . '/application/*/views/*',
    ])
    // target the minimum supported runtime (orange/framework requires php >=8.4)
    // so refactorings never emit syntax newer than the floor the app runs on.
    ->withPhpSets(php84: true)
    // conservative, high-signal rule sets. Coding-style rules are intentionally
    // omitted - phpcs/PSR-12 owns formatting, and enabling them here would make
    // the two tools fight over the same code.
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
    );
