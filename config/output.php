<?php

/**
 * CORS is deliberately absent from this base config.
 *
 * It used to be enabled here with 'allowed cors' => ['http://localhost:3000'],
 * which meant production also ran with cross-origin sharing switched on for a
 * development origin - a setting that could only ever be wrong there. It now
 * lives in config/development/output.php, which is only on the search path when
 * ENVIRONMENT=development.
 *
 * A production deployment that genuinely needs CORS should add
 * config/production/output.php naming its real front-end origins.
 */

declare(strict_types=1);

return [];
