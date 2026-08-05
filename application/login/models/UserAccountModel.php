<?php

declare(strict_types=1);

namespace application\login\models;

use orange\framework\base\Singleton;
use PDO;

/**
 * Writing to the accounts table, which nothing else in the stack does.
 *
 * orange/auth answers one question - does this password match this login - and
 * orange/acl answers what an account may do. Neither creates an account, and
 * neither changes a password: an account's lifecycle belongs to the application
 * that decides what an account *is*, so it lives here beside the controllers
 * that own signup and reset.
 *
 * The table is orange/acl's (see the create_acl_tables migration), so the column
 * names and the is_active / is_deleted convention are its, not this model's.
 * What this adds is only the handful of writes those two packages leave out.
 */
class UserAccountModel extends Singleton
{
    /** password_hash() cost, matched to the hash AclSeeder ships. */
    public const int HASH_COST = 12;

    protected const string TABLE = 'orange_users';

    /** Matches the column widths in the migration. */
    protected const int MAX_USERNAME_LENGTH = 64;
    protected const int MAX_EMAIL_LENGTH = 255;

    public function __construct(protected PDO $pdo)
    {
    }

    /**
     * Find one account by email, deleted ones excluded.
     *
     * Inactive accounts are *included*: a signup awaiting confirmation is
     * inactive and still has to be findable, or confirming it would create a
     * second account instead.
     *
     * @return array{id: int, username: string, email: string, is_active: bool}|null
     */
    public function findByEmail(string $email): ?array
    {
        $statement = $this->pdo->prepare(
            'select `id`, `username`, `email`, `is_active` from `' . self::TABLE . '`'
            . ' where `email` = :email and `is_deleted` = 0'
        );

        $statement->execute([':email' => $this->normalizeEmail($email)]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'username' => (string) $row['username'],
            'email' => (string) $row['email'],
            'is_active' => (bool) $row['is_active'],
        ];
    }

    /**
     * The email on one account, or '' when there is no such account.
     *
     * The reverse of findByEmail(), for the places that hold an id because a
     * token resolved to one and need the address it belongs to.
     */
    public function emailFor(int $userId): string
    {
        $statement = $this->pdo->prepare(
            'select `email` from `' . self::TABLE . '` where `id` = :id and `is_deleted` = 0'
        );

        $statement->execute([':id' => $userId]);

        $email = $statement->fetchColumn();

        return is_string($email) ? $email : '';
    }

    /**
     * Whether an address is already spoken for.
     *
     * Only ever asked *inside* the signup flow, never answered to an
     * unauthenticated visitor as a yes/no - see SignupController, which
     * responds identically either way. The unique index is the real guard;
     * this is what turns the race it would otherwise lose into a message.
     */
    public function emailTaken(string $email): bool
    {
        return $this->findByEmail($email) !== null;
    }

    /**
     * Whether a username is already spoken for.
     *
     * This one *is* answered to the visitor. A username is public - it appears
     * in the navbar of anyone using it - so refusing a duplicate leaks nothing
     * an attacker could not learn by looking, and silently mangling someone's
     * chosen name to make it unique is worse than saying it is taken.
     */
    public function usernameTaken(string $username): bool
    {
        $statement = $this->pdo->prepare(
            'select 1 from `' . self::TABLE . '` where `username` = :username and `is_deleted` = 0'
        );

        $statement->execute([':username' => $this->clamp($username, self::MAX_USERNAME_LENGTH)]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Create an account, inactive, and return its id.
     *
     * Inactive is not a parameter. Every account this application creates
     * starts unusable and is activated by confirming the address, which is the
     * only thing that makes the confirmation email load-bearing rather than
     * decorative: an account that could log in before confirming would mean
     * anyone could sign up as anyone.
     */
    public function createPending(string $username, string $email, string $password): int
    {
        $statement = $this->pdo->prepare(
            'insert into `' . self::TABLE . '` (`username`, `email`, `password`, `is_active`, `is_deleted`)'
            . ' values (:username, :email, :password, 0, 0)'
        );

        $statement->execute([
            ':username' => $this->clamp($username, self::MAX_USERNAME_LENGTH),
            ':email' => $this->normalizeEmail($email),
            ':password' => $this->hash($password),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Turn a confirmed account on.
     *
     * Idempotent - confirming twice is a second click on one link, not an
     * error, and the token layer has already refused the second one anyway.
     */
    public function activate(int $userId): void
    {
        $statement = $this->pdo->prepare(
            'update `' . self::TABLE . '` set `is_active` = 1 where `id` = :id and `is_deleted` = 0'
        );

        $statement->execute([':id' => $userId]);
    }

    /**
     * Replace an account's password.
     *
     * The caller is responsible for having proved the request came from the
     * address on the account - this does no checking of its own, because by the
     * time it is reached the token has already been spent.
     */
    public function updatePassword(int $userId, string $password): void
    {
        $statement = $this->pdo->prepare(
            'update `' . self::TABLE . '` set `password` = :password where `id` = :id and `is_deleted` = 0'
        );

        $statement->execute([':password' => $this->hash($password), ':id' => $userId]);
    }

    /**
     * Hash a password for storage.
     *
     * PASSWORD_DEFAULT, so the algorithm follows PHP rather than being frozen
     * here - orange/auth verifies with password_verify(), which reads the
     * algorithm back out of the stored hash.
     */
    protected function hash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT, ['cost' => self::HASH_COST]);
    }

    /**
     * Lowercased and trimmed, the way auth looks accounts up - so one address
     * cannot become two accounts by being typed differently.
     */
    protected function normalizeEmail(string $email): string
    {
        return $this->clamp(mb_strtolower($email), self::MAX_EMAIL_LENGTH);
    }

    /**
     * Trim and cut a value to what its column can hold.
     */
    protected function clamp(string $value, int $length): string
    {
        return mb_substr(trim($value), 0, $length);
    }
}
