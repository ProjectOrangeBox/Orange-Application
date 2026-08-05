<?php

declare(strict_types=1);

use application\models\LoginThrottleModel;

/**
 * The throttle's time-dependent behavior, which AuthControllerTest cannot
 * reach: everything there happens inside one window, because it happens inside
 * one second.
 */
final class LoginThrottleModelTest extends unitTestHelper
{
    protected const string LOGIN = 'someone@example.com';
    protected const string IP = '203.0.113.5';

    protected PDO $pdo;
    // untyped to match unitTestHelper's own declaration
    protected $instance;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE login_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                login TEXT NOT NULL,
                ip TEXT NOT NULL,
                attempted_at TEXT NOT NULL
            )
            SQL);

        $this->instance = LoginThrottleModel::getInstance($this->pdo);
    }

    protected function rows(): int
    {
        return (int) $this->pdo->query('select count(*) as c from login_attempts')->fetchColumn();
    }

    /**
     * Fill one login's allowance, all at $at.
     */
    protected function fillLogin(int $at, string $login = self::LOGIN): void
    {
        for ($attempt = 0; $attempt < LoginThrottleModel::MAX_PER_LOGIN; $attempt++) {
            $this->instance->recordFailure($login, self::IP, $at);
        }
    }

    public function testAnUnknownLoginIsNotThrottled(): void
    {
        $this->assertSame(0, $this->instance->retryAfter(self::LOGIN, self::IP));
    }

    public function testTheLastAttemptUnderTheLimitIsStillAllowed(): void
    {
        $now = time();

        for ($attempt = 0; $attempt < LoginThrottleModel::MAX_PER_LOGIN - 1; $attempt++) {
            $this->instance->recordFailure(self::LOGIN, self::IP, $now);
        }

        $this->assertSame(0, $this->instance->retryAfter(self::LOGIN, self::IP, $now));
    }

    public function testTheWaitIsWhatIsLeftOfTheWindow(): void
    {
        $now = time();

        $this->fillLogin($now - 60);

        // the counter drops back under its limit as its oldest failure ages
        // out, so a minute in, that is fifteen minutes minus a minute
        $this->assertSame(LoginThrottleModel::WINDOW_SECONDS - 60, $this->instance->retryAfter(self::LOGIN, self::IP, $now));
    }

    public function testTheThrottleHealsOnItsOwn(): void
    {
        $now = time();

        $this->fillLogin($now - LoginThrottleModel::WINDOW_SECONDS - 1);

        // no administrator, no scheduled job: a lockout an attacker can trigger
        // against someone else's account for free has to expire by itself
        $this->assertSame(0, $this->instance->retryAfter(self::LOGIN, self::IP, $now));
    }

    public function testAThrottledCallerIsNeverToldToWaitZeroSeconds(): void
    {
        $now = time();

        // exactly on the window's edge: still counted (the cutoff is
        // inclusive), so the answer has to be a wait a client can act on
        $this->fillLogin($now - LoginThrottleModel::WINDOW_SECONDS);

        $this->assertGreaterThan(0, $this->instance->retryAfter(self::LOGIN, self::IP, $now));
    }

    public function testWritingPrunesFailuresTooOldToCount(): void
    {
        $now = time();

        $this->instance->recordFailure(self::LOGIN, self::IP, $now - LoginThrottleModel::WINDOW_SECONDS - 1);

        $this->assertSame(1, $this->rows());

        $this->instance->recordFailure('other@example.com', self::IP, $now);

        // the stale row is gone, the fresh one is not - which is what keeps the
        // table bounded without a scheduled cleanup
        $this->assertSame(1, $this->rows());
    }

    public function testClearForgetsOnlyThatLogin(): void
    {
        $now = time();

        $this->fillLogin($now, self::LOGIN);
        $this->fillLogin($now, 'other@example.com');

        $this->instance->clear(self::LOGIN);

        $this->assertSame(0, $this->instance->retryAfter(self::LOGIN, '', $now));
        $this->assertGreaterThan(0, $this->instance->retryAfter('other@example.com', '', $now));
    }

    public function testClearReclaimsOnlyTheFailuresSpentOnThatLogin(): void
    {
        $now = time();

        // an attacker holding one valid account, guessing at everyone else's
        for ($attempt = 0; $attempt < LoginThrottleModel::MAX_PER_IP - 1; $attempt++) {
            $this->instance->recordFailure('victim' . $attempt . '@example.com', self::IP, $now);
        }

        $this->instance->recordFailure('attacker@example.com', self::IP, $now);

        $this->assertGreaterThan(0, $this->instance->retryAfter('', self::IP, $now));

        // logging into their own account is not a reset button
        $this->instance->clear('attacker@example.com');

        // they get back the one failure they spent on themselves, and nothing
        // more - the ones spent on other people's logins are still counted, so
        // a single further guess trips the address again
        $this->assertSame(0, $this->instance->retryAfter('', self::IP, $now));

        $this->instance->recordFailure('victim99@example.com', self::IP, $now);

        $this->assertGreaterThan(0, $this->instance->retryAfter('', self::IP, $now));
    }

    public function testAnAbsurdlyLongLoginIsCountedRatherThanFatal(): void
    {
        $now = time();

        // strict-mode MySQL rejects an over-long value outright, which would
        // turn a guess into a 500 - and a 500 that skips the counter
        $login = str_repeat('a', 5000) . '@example.com';

        for ($attempt = 0; $attempt < LoginThrottleModel::MAX_PER_LOGIN; $attempt++) {
            $this->instance->recordFailure($login, self::IP, $now);
        }

        $this->assertGreaterThan(0, $this->instance->retryAfter($login, self::IP, $now));
    }

    public function testARequestWithNoAddressIsSimplyNotCountedByAddress(): void
    {
        $now = time();

        for ($attempt = 0; $attempt < LoginThrottleModel::MAX_PER_IP; $attempt++) {
            $this->instance->recordFailure('user' . $attempt . '@example.com', '', $now);
        }

        // a CLI run, or a proxy that stripped it: there is nothing to count by,
        // and lumping them all under one empty key would throttle everyone
        $this->assertSame(0, $this->instance->retryAfter('nobody@example.com', '', $now));
    }
}
