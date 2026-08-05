<?php

declare(strict_types=1);

namespace application\api\models;

use orange\framework\base\Singleton;
use PDO;

/**
 * Brute-force throttling for the login endpoint.
 *
 * orange/auth states this as a deliberate non-goal: rate limiting needs state
 * shared across requests, and a class whose whole job is "does this password
 * match" has no business owning a table. So it lives here, in the application,
 * beside the controller that is the only thing able to say what a request is.
 *
 * Two counters, because they answer different attacks:
 *
 *   per login  one account being guessed at, from however many addresses
 *   per ip     one address working through a list of accounts
 *
 * Both are *windows*, not lockouts. A locked-out account is a denial-of-service
 * an attacker can trigger for free by guessing at someone else's email; a
 * window heals on its own, so the worst an attacker buys with that is fifteen
 * minutes, and buys it again only by keeping the attack running. The per-ip
 * limit is set well above the per-login one so a shared address - an office
 * behind one NAT, a handful of people fumbling passwords on a Monday - does not
 * trip it in normal use.
 *
 * Success clears the failures recorded against that login, and only those. It
 * is not a reset of the address counter, which is the distinction that makes
 * the second limit worth having: someone holding one valid account can log
 * into it between guesses, but doing so reclaims only the failures they spent
 * on their own account - every failure against somebody else's login stays
 * counted against their address.
 *
 * Stored in the database rather than a cache because that is the only shared
 * state this application already requires. APCu is per-process and would let a
 * second php-fpm worker start the count over; the database is where auth is
 * already reading from, so a throttle that depends on it adds no new way for
 * login to be unavailable.
 */
class LoginThrottleModel extends Singleton
{
    /**
     * How far back failures are counted, in seconds. Also how long a tripped
     * counter takes to heal, since it heals as its oldest failure ages out.
     */
    public const int WINDOW_SECONDS = 900;

    /** Failures against one login within the window before it is refused. */
    public const int MAX_PER_LOGIN = 5;

    /** Failures from one address within the window before it is refused. */
    public const int MAX_PER_IP = 30;

    protected const string TABLE = 'login_attempts';

    /**
     * Timestamps are formatted in PHP and compared as strings, which is true
     * of both MySQL DATETIME and the SQLite the unit tests run against. Doing
     * the arithmetic here rather than in SQL is what keeps one query working
     * on both.
     */
    protected const string TIME_FORMAT = 'Y-m-d H:i:s';

    /** Matches the column widths in the migration. */
    protected const int MAX_LOGIN_LENGTH = 190;
    protected const int MAX_IP_LENGTH = 45;

    public function __construct(protected PDO $pdo)
    {
    }

    /**
     * Seconds the caller must wait before another attempt is accepted, or 0
     * when this login and address are both under their limits.
     *
     * @param string $login Normalized login (trimmed, lowercased).
     * @param string $ip    Client address; '' skips the per-address counter.
     * @param int|null $now Override for the current time, for tests.
     */
    public function retryAfter(string $login, string $ip, ?int $now = null): int
    {
        $now ??= time();
        $cutoff = date(self::TIME_FORMAT, $now - self::WINDOW_SECONDS);

        $wait = 0;

        /** @var array<int, array{0: string, 1: string, 2: int}> $counters */
        $counters = [
            ['login', $this->clamp($login, self::MAX_LOGIN_LENGTH), self::MAX_PER_LOGIN],
            ['ip', $this->clamp($ip, self::MAX_IP_LENGTH), self::MAX_PER_IP],
        ];

        foreach ($counters as [$column, $value, $max]) {
            // an empty login or a request with no address (CLI, a proxy that
            // stripped it) simply isn't counted - there is nothing to count by
            if ($value === '') {
                continue;
            }

            [$failures, $oldest] = $this->countSince($column, $value, $cutoff);

            if ($failures < $max) {
                continue;
            }

            // the counter drops back under its limit the moment its oldest
            // failure falls out of the window, so that is when to come back
            $expiresAt = (int) strtotime($oldest) + self::WINDOW_SECONDS;

            // never advertise 0 while refusing - a client told to wait no time
            // at all will simply retry immediately
            $wait = max($wait, max(1, $expiresAt - $now));
        }

        return $wait;
    }

    /**
     * Record one failed attempt, and drop everything already too old to count.
     *
     * Pruning here rather than on a schedule keeps the table bounded by the
     * failure rate alone: a row is written only when someone gets a password
     * wrong, and is deleted one window later by whoever gets one wrong next.
     */
    public function recordFailure(string $login, string $ip, ?int $now = null): void
    {
        $now ??= time();

        $statement = $this->pdo->prepare(
            'insert into `' . self::TABLE . '` (`login`, `ip`, `attempted_at`) values (:login, :ip, :attempted_at)'
        );

        $statement->execute([
            // clamped, not rejected: an over-long login is a failed attempt
            // like any other and still deserves to be counted. Unclamped it
            // would be strict-mode MySQL's problem instead, which turns a
            // guess into a 500.
            ':login' => $this->clamp($login, self::MAX_LOGIN_LENGTH),
            ':ip' => $this->clamp($ip, self::MAX_IP_LENGTH),
            ':attempted_at' => date(self::TIME_FORMAT, $now),
        ]);

        $this->prune($now);
    }

    /**
     * Forget a login's failures after it authenticates successfully.
     *
     * Scoped to the login on purpose: this is not a way to clear an address.
     * See the class docblock.
     */
    public function clear(string $login): void
    {
        $login = $this->clamp($login, self::MAX_LOGIN_LENGTH);

        if ($login === '') {
            return;
        }

        $statement = $this->pdo->prepare('delete from `' . self::TABLE . '` where `login` = :login');

        $statement->execute([':login' => $login]);
    }

    /**
     * Failures for one counter since $cutoff, and the oldest of them.
     *
     * @return array{0: int, 1: string} [failures, oldest attempted_at]
     */
    protected function countSince(string $column, string $value, string $cutoff): array
    {
        // $column is never user input - it is one of the two literals in
        // retryAfter() - which is why it can be interpolated. Identifiers
        // cannot be bound as parameters; $value and $cutoff are.
        $statement = $this->pdo->prepare(
            'select count(*) as failures, min(`attempted_at`) as oldest from `' . self::TABLE . '`'
            . ' where `' . $column . '` = :value and `attempted_at` >= :cutoff'
        );

        $statement->execute([':value' => $value, ':cutoff' => $cutoff]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return [0, ''];
        }

        return [(int) $row['failures'], (string) ($row['oldest'] ?? '')];
    }

    /**
     * Delete failures too old to count toward any limit.
     */
    protected function prune(int $now): void
    {
        $statement = $this->pdo->prepare('delete from `' . self::TABLE . '` where `attempted_at` < :cutoff');

        $statement->execute([':cutoff' => date(self::TIME_FORMAT, $now - self::WINDOW_SECONDS)]);
    }

    /**
     * Trim and cut a value to what its column can hold.
     */
    protected function clamp(string $value, int $length): string
    {
        return mb_substr(trim($value), 0, $length);
    }
}
