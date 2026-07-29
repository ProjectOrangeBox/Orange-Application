<?php

/**
 * Development-only output overrides, merged over config/output.php.
 *
 * The Vite/npm dev server runs on a different port than the PHP app, which makes
 * every call from it cross-origin - hence CORS here and nowhere else.
 */

declare(strict_types=1);

return [
    'enable cors' => true,
    'allowed cors' => ['http://localhost:3000'],
];
