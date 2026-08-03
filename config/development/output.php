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
    // The session-backed endpoints (/api/me, /api/orders) are fetched with
    // credentials: 'include' so the session_id cookie rides along. A credentialed
    // cross-origin request is only accepted by the browser when the response also
    // carries Access-Control-Allow-Credentials: true - without it the response is
    // discarded as a CORS error despite being a perfectly good 200. Development
    // only: production has no cross-origin front end to grant cookies to.
    'access-control-allow-credentials' => true,
];
