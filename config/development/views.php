<?php

declare(strict_types=1);

use config\development\ViewDetector;

// ViewDetector is not autoloaded (composer only maps application\),
// so it has to be required explicitly.
require_once __DIR__ . '/ViewDetector.php';

// Scans every PSR-4 root for views/ directories and builds both maps, then
// refreshes config/production/views.php so the snapshot stays in step with the
// filesystem while you work. Commit that file - production reads it directly and
// never touches the filesystem to find a view.
return ViewDetector::detect([
    // Hand-written entries, merged before anything is scanned so they hold a
    // fallback key against a file of the same name on disk.
    //
    // 'views' => ['application/welcome/main/index' => __ROOT__ . '/somewhere/else.php'],
    // 'view fallbacks' => ['errors/html/404' => __ROOT__ . '/application/errors/404.php'],
], __ROOT__ . '/config/production');
