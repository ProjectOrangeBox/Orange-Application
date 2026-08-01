<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The six tables orange/acl reads and writes.
 *
 * Order matters within this migration exactly as it does in the package's own
 * SQL: the join tables carry foreign keys, so the tables they point at are
 * created first. Phinx runs the statements in the order written, so the
 * grouping here is the ordering.
 *
 * Table names are fixed rather than configurable - they are constants on
 * orange\acl\dtos\AclTables, because a Dto's #[Table] attribute cannot read
 * config.
 */
final class CreateAclTables extends AbstractMigration
{
    public function change(): void
    {
        // Uniqueness spans soft-deleted rows on purpose: a deleted account keeps
        // its email and username reserved, so neither can be claimed by someone
        // else and quietly inherit anything still keyed to them.
        $this->table('orange_users', ['signed' => false, 'collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('username', 'string', ['null' => false, 'limit' => 64])
            ->addColumn('email', 'string', ['null' => false, 'limit' => 255])
            ->addColumn('password', 'string', ['null' => false, 'limit' => 255])
            ->addColumn('is_active', 'boolean', ['null' => false, 'default' => 0, 'signed' => false])
            ->addColumn('is_deleted', 'boolean', ['null' => false, 'default' => 0, 'signed' => false])
            ->addIndex(['email'], ['unique' => true, 'name' => 'idx_users_email_unique'])
            ->addIndex(['username'], ['unique' => true, 'name' => 'idx_users_username_unique'])
            // the models filter on both together when listing usable accounts
            ->addIndex(['is_active', 'is_deleted'], ['name' => 'idx_users_active_deleted'])
            ->create();

        $this->table('orange_roles', ['signed' => false, 'collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('name', 'string', ['null' => false, 'limit' => 128, 'default' => ''])
            ->addColumn('description', 'string', ['null' => false, 'limit' => 512, 'default' => ''])
            ->addColumn('migration', 'string', ['limit' => 128, 'null' => true])
            ->addColumn('is_active', 'boolean', ['null' => false, 'default' => 1, 'signed' => false])
            ->addIndex(['name'], ['unique' => true, 'name' => 'idx_roles_name_unique'])
            ->create();

        $this->table('orange_permissions', ['signed' => false, 'collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('key', 'string', ['null' => false, 'limit' => 255])
            ->addColumn('description', 'string', ['null' => false, 'limit' => 512])
            ->addColumn('group', 'string', ['null' => false, 'limit' => 128])
            ->addColumn('migration', 'string', ['limit' => 128, 'null' => true])
            ->addColumn('is_active', 'boolean', ['null' => false, 'default' => 1, 'signed' => false])
            ->addIndex(['key'], ['unique' => true, 'name' => 'idx_permissions_key_unique'])
            ->create();

        // id is the user id rather than its own sequence - meta is one row per
        // user, so the foreign key is also the primary key.
        // Every column in a primary key has to be declared NOT NULL explicitly -
        // phinx does not infer it from primary_key, and MySQL rejects a nullable
        // one with "All parts of a PRIMARY KEY must be NOT NULL".
        $this->table('orange_user_meta', ['id' => false, 'primary_key' => ['id'], 'collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('dashboard_url', 'string', ['null' => false, 'limit' => 255, 'default' => ''])
            ->addColumn('phone', 'string', ['null' => false, 'limit' => 64, 'default' => ''])
            ->addColumn('ext', 'string', ['null' => false, 'limit' => 32, 'default' => ''])
            ->addForeignKey('id', 'orange_users', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_user_meta_user'])
            ->create();

        // The secondary index on the trailing column is not redundant: the
        // composite primary key cannot serve a lookup by role_id alone, which is
        // exactly what deleting a role does.
        $this->table('orange_user_role', ['id' => false, 'primary_key' => ['user_id', 'role_id'], 'collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('user_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('role_id', 'integer', ['signed' => false, 'null' => false])
            ->addIndex(['role_id'], ['name' => 'idx_user_role_role'])
            ->addForeignKey('user_id', 'orange_users', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_user_role_user'])
            ->addForeignKey('role_id', 'orange_roles', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_user_role_role'])
            ->create();

        $this->table('orange_role_permission', ['id' => false, 'primary_key' => ['role_id', 'permission_id'], 'collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('role_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('permission_id', 'integer', ['signed' => false, 'null' => false])
            ->addIndex(['permission_id'], ['name' => 'idx_role_permission_permission'])
            ->addForeignKey('role_id', 'orange_roles', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_role_permission_role'])
            ->addForeignKey('permission_id', 'orange_permissions', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_role_permission_permission'])
            ->create();
    }
}
