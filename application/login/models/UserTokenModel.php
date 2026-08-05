<?php

declare(strict_types=1);

namespace application\login\models;

use orange\framework\base\Singleton;
use PDO;

/**
 * The tokens emailed to prove someone reads an address.
 *
 * Two flows use this and they are the same object underneath: a secret handed
 * to whoever opens that inbox, good once, good briefly. `purpose` is what keeps
 * them apart, and it is matched on verification - so a signup confirmation can
 * never be spent as a password reset.
 *
 * Three properties, and every one of them matters more than it looks:
 *
 *   hashed at rest   a live reset token is a password equivalent. Anyone who
 *                    can read this table with plaintext in it owns every
 *                    account with an outstanding reset - a backup, a log, a
 *                    read-only replica handed to an analyst.
 *   single use       consuming marks used_at, so a link forwarded, indexed by a
 *                    mail scanner, or left in a browser history opens nothing
 *                    the second time.
 *   short lived      the window in which a leaked link is worth anything.
 *
 * The plaintext token exists only between issue() returning it and the mail
 * being handed to the transport. Nothing stores it and nothing logs it.
 */
class UserTokenModel extends Singleton
{
    public const string PURPOSE_PASSWORD_RESET = 'password_reset';
    public const string PURPOSE_SIGNUP_CONFIRM = 'signup_confirm';

    /** @var list<string> the only values `purpose` may take */
    public const array PURPOSES = [self::PURPOSE_PASSWORD_RESET, self::PURPOSE_SIGNUP_CONFIRM];

    /**
     * An hour for a reset: long enough to walk to another machine and read the
     * mail, short enough that a link sitting in an unattended inbox stops being
     * a way in fairly quickly.
     */
    public const int PASSWORD_RESET_TTL = 3600;

    /**
     * A day for a confirmation. Nothing is at risk while it is outstanding -
     * the account it would activate cannot log in yet - and people sign up in
     * the evening and read their mail the next morning.
     */
    public const int SIGNUP_CONFIRM_TTL = 86400;

    /** 32 bytes of CSPRNG output, hex encoded, so 64 characters on the wire. */
    protected const int TOKEN_BYTES = 32;

    protected const string TABLE = 'user_tokens';

    /** As in LoginThrottleModel: formatted in PHP so one query works on MySQL and SQLite alike. */
    protected const string TIME_FORMAT = 'Y-m-d H:i:s';

    public function __construct(protected PDO $pdo)
    {
    }

    /**
     * Issue a token for one user and purpose, returning the plaintext to email.
     *
     * Any outstanding token for the same user and purpose is deleted first.
     * Otherwise requesting a second reset would leave the first link working,
     * and "I'll just request another one" would quietly widen the window rather
     * than restart it.
     *
     * @param int $userId The account the token acts on.
     * @param string $purpose One of self::PURPOSES.
     * @param int|null $now Override for the current time, for tests.
     * @return string The plaintext token - the only time it exists.
     */
    public function issue(int $userId, string $purpose, ?int $now = null): string
    {
        $now ??= time();
        $purpose = $this->assertPurpose($purpose);

        $this->invalidateFor($userId, $purpose);

        $token = bin2hex(random_bytes(self::TOKEN_BYTES));

        $statement = $this->pdo->prepare(
            'insert into `' . self::TABLE . '` (`user_id`, `purpose`, `token_hash`, `expires_at`, `created_at`)'
            . ' values (:user_id, :purpose, :token_hash, :expires_at, :created_at)'
        );

        $statement->execute([
            ':user_id' => $userId,
            ':purpose' => $purpose,
            ':token_hash' => $this->hash($token),
            ':expires_at' => date(self::TIME_FORMAT, $now + $this->ttlFor($purpose)),
            ':created_at' => date(self::TIME_FORMAT, $now),
        ]);

        $this->prune($now);

        return $token;
    }

    /**
     * Spend a token, returning the user id it was issued to.
     *
     * Returns null for anything that is not a live token of that purpose -
     * forged, expired, already spent, or issued for the other flow. The caller
     * gets one bit back on purpose: there is nothing it could usefully do with
     * the difference, and telling a visitor which of those it was is telling an
     * attacker whether they guessed a real token.
     *
     * @param int|null $now Override for the current time, for tests.
     * @return int|null The user id, or null when the token is not usable.
     */
    public function consume(string $token, string $purpose, ?int $now = null): ?int
    {
        $now ??= time();
        $purpose = $this->assertPurpose($purpose);

        $row = $this->find($token, $purpose);

        if ($row === null) {
            return null;
        }

        if ($row['used_at'] !== null || strtotime((string) $row['expires_at']) < $now) {
            return null;
        }

        // Marked used in the same statement that checks it is unused, so two
        // requests arriving together cannot both spend it - the second updates
        // zero rows and is refused. A read-then-write would let both through.
        $statement = $this->pdo->prepare(
            'update `' . self::TABLE . '` set `used_at` = :used_at where `id` = :id and `used_at` is null'
        );

        $statement->execute([':used_at' => date(self::TIME_FORMAT, $now), ':id' => $row['id']]);

        return $statement->rowCount() === 1 ? (int) $row['user_id'] : null;
    }

    /**
     * Whether a token would be accepted, without spending it.
     *
     * What the GET behind an emailed link asks, so the form can be rendered -
     * or refused - before the visitor has typed anything. The POST that follows
     * calls consume(), which is what actually spends it.
     */
    public function isUsable(string $token, string $purpose, ?int $now = null): bool
    {
        $now ??= time();
        $row = $this->find($token, $this->assertPurpose($purpose));

        return $row !== null && $row['used_at'] === null && strtotime((string) $row['expires_at']) >= $now;
    }

    /**
     * Drop every outstanding token for one user and purpose.
     *
     * Called when a token is issued, and again when a password actually
     * changes - at that moment any other live reset link is a way back into an
     * account whose owner has just taken it back.
     */
    public function invalidateFor(int $userId, string $purpose): void
    {
        $statement = $this->pdo->prepare(
            'delete from `' . self::TABLE . '` where `user_id` = :user_id and `purpose` = :purpose'
        );

        $statement->execute([':user_id' => $userId, ':purpose' => $this->assertPurpose($purpose)]);
    }

    /**
     * Look one token up by its hash.
     *
     * @return array{id: int, user_id: int, expires_at: string, used_at: string|null}|null
     */
    protected function find(string $token, string $purpose): ?array
    {
        $statement = $this->pdo->prepare(
            'select `id`, `user_id`, `expires_at`, `used_at` from `' . self::TABLE . '`'
            . ' where `token_hash` = :token_hash and `purpose` = :purpose'
        );

        // matched on purpose as well as hash, so a confirmation token cannot be
        // presented to the reset flow even though both live in one table
        $statement->execute([':token_hash' => $this->hash($token), ':purpose' => $purpose]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'expires_at' => (string) $row['expires_at'],
            'used_at' => $row['used_at'] === null ? null : (string) $row['used_at'],
        ];
    }

    /**
     * Delete tokens no longer worth keeping.
     *
     * Expired ones can go immediately. Spent ones are kept for a grace period
     * so a second click on a link that just worked can still be told "already
     * used" rather than dropping to the same answer a forged token gets.
     */
    protected function prune(int $now): void
    {
        $statement = $this->pdo->prepare(
            'delete from `' . self::TABLE . '`'
            . ' where `expires_at` < :expired or (`used_at` is not null and `used_at` < :spent)'
        );

        $statement->execute([
            ':expired' => date(self::TIME_FORMAT, $now),
            ':spent' => date(self::TIME_FORMAT, $now - self::PASSWORD_RESET_TTL),
        ]);
    }

    /**
     * How long a token of this purpose lives.
     */
    protected function ttlFor(string $purpose): int
    {
        return $purpose === self::PURPOSE_SIGNUP_CONFIRM ? self::SIGNUP_CONFIRM_TTL : self::PASSWORD_RESET_TTL;
    }

    /**
     * The token as it is stored.
     *
     * SHA-256 rather than password_hash(): the input is already 32 bytes of
     * CSPRNG output, so there is no low-entropy secret for a slow hash to
     * protect, and verification has to be an indexed equality lookup rather
     * than a scan calling password_verify() on every row.
     */
    protected function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Refuse a purpose this model does not define.
     *
     * A typo would otherwise write a row nothing ever matches - a reset link
     * that silently never works, which is a miserable thing to debug.
     */
    protected function assertPurpose(string $purpose): string
    {
        if (!in_array($purpose, self::PURPOSES, true)) {
            throw new \InvalidArgumentException('Unknown token purpose "' . $purpose . '".');
        }

        return $purpose;
    }
}
