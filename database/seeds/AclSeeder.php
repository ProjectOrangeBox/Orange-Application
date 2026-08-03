<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

/**
 * The accounts, role and permissions the example runs on.
 *
 * The guest row is not decoration. orange/acl resolves every request without a
 * login to the id in its 'guest user' config - 2 by default - so if that row is
 * missing, every anonymous request fails rather than simply being unprivileged.
 * It is seeded inactive and with no usable password hash, because it is an
 * identity to fall back to and not an account anyone logs into.
 *
 * The admin password is the bcrypt hash of 'orange123'. This is example data in
 * a public repository: it is not a secret, must never be treated as one, and
 * should not survive into anything reachable.
 */
final class AclSeeder extends AbstractSeed
{
    public function run(): void
    {
        $this->table('orange_users')->insert([
            ['id' => 1, 'username' => 'admin', 'email' => 'admin@example.com', 'password' => '$2y$12$yvswuQcBPiKE636I66YLcuwEo5y3.DtWvoy0NrJXopJuAX0Jd/iwS', 'is_active' => 1, 'is_deleted' => 0],
            ['id' => 2, 'username' => 'guest', 'email' => 'guest@example.com', 'password' => '', 'is_active' => 0, 'is_deleted' => 0],
        ])->save();

        $this->table('orange_roles')->insert([
            ['id' => 1, 'name' => 'orders manager', 'description' => 'May create, edit and delete orders', 'is_active' => 1],
        ])->save();

        // Owned by the orders example rather than by acl itself - a bare acl
        // install has no opinion about orders.
        //
        // orders.update is here because OrderController::update() had no guard
        // at all: create and delete asked, and editing did not, so an
        // unauthenticated PUT could rewrite any order it knew the id of. A
        // permission the seed never defined is denied for everyone, which would
        // have made editing quietly impossible rather than open - the failure
        // has to move in the safe direction either way.
        $this->table('orange_permissions')->insert([
            ['id' => 1, 'key' => 'orders.create', 'description' => 'Create an order', 'group' => 'orders', 'is_active' => 1],
            ['id' => 2, 'key' => 'orders.delete', 'description' => 'Delete an order', 'group' => 'orders', 'is_active' => 1],
            ['id' => 3, 'key' => 'orders.update', 'description' => 'Edit an order', 'group' => 'orders', 'is_active' => 1],
        ])->save();

        $this->table('orange_role_permission')->insert([
            ['role_id' => 1, 'permission_id' => 1],
            ['role_id' => 1, 'permission_id' => 2],
            ['role_id' => 1, 'permission_id' => 3],
        ])->save();

        // Only the admin gets the role, so an anonymous request is refused by
        // the permission check rather than by an absent user record.
        $this->table('orange_user_role')->insert([
            ['user_id' => 1, 'role_id' => 1],
        ])->save();
    }
}
