<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Single-use, expiring tokens emailed to a user to prove they hold an address.
 *
 * One table for both flows rather than two, because they are the same object:
 * a secret handed to whoever reads that inbox, good once, good briefly. Only
 * `purpose` differs, and keeping it a column rather than a table means a reset
 * token can never be spent as a signup confirmation - the lookup matches on both.
 *
 * `token_hash` stores a SHA-256 of the token, never the token itself. A reset
 * token is a password equivalent for as long as it lives: anyone reading this
 * table with the plaintext in it could take over every account with an
 * outstanding reset. Hashing costs one hash per verification and removes that
 * entirely. It is SHA-256 rather than bcrypt because the token is already 32
 * bytes of CSPRNG output - there is no low-entropy secret here for a slow hash
 * to protect, and the lookup has to be an indexed equality match.
 *
 * `used_at` rather than deleting the row on use: a token that has been spent and
 * one that never existed are different facts, and keeping the first lets a
 * second click on the same link say "this link has already been used" instead of
 * the same blank refusal a forged token gets.
 *
 * Rows are pruned on write by UserTokenModel, the same way login_attempts is, so
 * the table stays bounded without a scheduled job.
 */
final class CreateUserTokens extends AbstractMigration
{
    public function change(): void
    {
        $this->table('user_tokens', ['signed' => false, 'collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('user_id', 'integer', ['signed' => false, 'null' => false])
            // 'password_reset' | 'signup_confirm' - see UserTokenModel::PURPOSES
            ->addColumn('purpose', 'string', ['limit' => 32, 'null' => false])
            // hex sha-256, so always exactly 64 characters
            ->addColumn('token_hash', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('expires_at', 'datetime', ['null' => false])
            ->addColumn('used_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            // The verification lookup is by hash alone, and a collision here
            // would mean two live tokens opening one door - so it is unique
            // rather than merely indexed.
            ->addIndex(['token_hash'], ['unique' => true, 'name' => 'idx_user_tokens_hash_unique'])
            // issuing a token invalidates that user's outstanding ones for the
            // same purpose, which is a delete by exactly this pair
            ->addIndex(['user_id', 'purpose'], ['name' => 'idx_user_tokens_user_purpose'])
            // pruning scans by expiry
            ->addIndex(['expires_at'], ['name' => 'idx_user_tokens_expires'])
            // an account going away takes its outstanding tokens with it -
            // otherwise a deleted user's live reset link still resolves to an id
            ->addForeignKey('user_id', 'orange_users', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
                'constraint' => 'fk_user_tokens_user',
            ])
            ->create();
    }
}
