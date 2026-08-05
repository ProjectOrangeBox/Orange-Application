<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Failed login attempts, so the login endpoint can throttle guessing.
 *
 * orange/auth deliberately does not do this - rate limiting needs state shared
 * across requests, which is the application's to own, not a credential
 * checker's. This is that state, and application/api/models/LoginThrottleModel
 * is what reads it.
 *
 * Only failures are stored, and only until they age out of the counting window,
 * so the table stays small without a scheduled cleanup: LoginThrottleModel
 * prunes on write.
 *
 * `login` is 190 rather than 255 because it is part of an index and utf8mb4
 * spends 4 bytes per character - 255 would exceed the 767-byte index prefix
 * limit on older InnoDB row formats. The column holds the *normalized* login
 * (trimmed, lowercased), which is what auth looks the account up by; anything
 * longer is truncated on write, since a 10KB "email" is an attack, not a login.
 */
final class CreateLoginAttempts extends AbstractMigration
{
    public function change(): void
    {
        $this->table('login_attempts', ['signed' => false, 'collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('login', 'string', ['limit' => 190, 'null' => false])
            // 45 characters holds an IPv6 address, including the longest
            // IPv4-mapped form (::ffff:255.255.255.255)
            ->addColumn('ip', 'string', ['limit' => 45, 'null' => false])
            ->addColumn('attempted_at', 'datetime', ['null' => false])
            // Both counters are "how many failures for X since T", so the
            // window column belongs in each index - the count is answered from
            // the index alone.
            ->addIndex(['login', 'attempted_at'])
            ->addIndex(['ip', 'attempted_at'])
            ->create();
    }
}
