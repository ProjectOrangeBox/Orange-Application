<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

/**
 * The one row orange/acl cannot function without.
 *
 * User::load() resolves every request that has no login to the id in
 * 'guest user' (config/user.php, default 2). If that row is absent, an
 * anonymous request does not come back unprivileged - it fails outright. So
 * this is not demo data; it is part of installing the package, which is why it
 * ships with it.
 *
 * Deliberately nothing else. support/seed.sql also creates an administrator
 * with a published password, and that is fine for a file someone runs by hand
 * against a scratch database - but this seeder is copied into a real
 * application by installModule and may be run there without anyone reading it
 * first. A known-password account is not something a package should be able to
 * create as a side effect of being installed. Applications seed their own
 * accounts; see the webapp's AclSeeder for an example.
 *
 * The guest is is_active = 0 with no usable password hash, so nothing can log
 * in *as* guest. It is an identity to fall back to, not an account.
 *
 * Named for the package rather than the concept because seeders land in one
 * flat directory shared with whatever the application and every other package
 * wrote, and phinx addresses them by class name.
 */
final class OrangeAclSeeder extends AbstractSeed
{
    /** Matches 'guest user' in the package's config/user.php and config/acl.php. */
    private const int GUEST_USER_ID = 2;

    public function run(): void
    {
        $existing = $this->fetchRow(
            'select count(*) as found from `orange_users` where `id` = ' . self::GUEST_USER_ID
        );

        // seeders, unlike migrations, have no record of having run - phinx will
        // happily run this twice, and the second time would be a duplicate key
        // error on a table the application may already have data in
        if (is_array($existing) && (int) $existing['found'] > 0) {
            return;
        }

        $this->table('orange_users')->insert([
            [
                'id' => self::GUEST_USER_ID,
                'username' => 'guest',
                'email' => 'guest@example.com',
                'password' => '',
                'is_active' => 0,
                'is_deleted' => 0,
            ],
        ])->save();
    }
}
