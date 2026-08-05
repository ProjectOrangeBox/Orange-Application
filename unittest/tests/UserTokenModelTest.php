<?php

declare(strict_types=1);

use application\login\models\UserTokenModel;

/**
 * The three properties an emailed token has to have, and the ways it can fail.
 *
 * Against real SQLite rather than a mock, because most of what is being claimed
 * here is claimed by SQL - the uniqueness of the hash, the atomicity of spending
 * one, what a delete removes - and a mock would agree with whatever the code
 * happened to do.
 */
final class UserTokenModelTest extends unitTestHelper
{
    private const int USER_ID = 1;
    private const int OTHER_USER_ID = 2;

    protected PDO $pdo;
    protected UserTokenModel $model;

    protected function setUp(): void
    {
        $this->pdo = $this->makePdo();
        $this->model = UserTokenModel::newInstance($this->pdo);
    }

    protected function makePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $pdo->exec(<<<'SQL'
            CREATE TABLE user_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                purpose TEXT NOT NULL,
                token_hash TEXT NOT NULL UNIQUE,
                expires_at TEXT NOT NULL,
                used_at TEXT DEFAULT NULL,
                created_at TEXT NOT NULL
            )
            SQL);

        return $pdo;
    }

    private function rowCount(): int
    {
        return (int) $this->pdo->query('select count(*) from user_tokens')->fetchColumn();
    }

    /* what a token is */

    public function testIssueReturnsATokenAndStoresARow(): void
    {
        $token = $this->model->issue(self::USER_ID, UserTokenModel::PURPOSE_PASSWORD_RESET);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $this->assertSame(1, $this->rowCount());
    }

    /**
     * The property everything else rests on: reading this table does not hand
     * anyone a working token.
     */
    public function testThePlaintextTokenIsNeverStored(): void
    {
        $token = $this->model->issue(self::USER_ID, UserTokenModel::PURPOSE_PASSWORD_RESET);

        $stored = (string) $this->pdo->query('select token_hash from user_tokens')->fetchColumn();

        $this->assertNotSame($token, $stored);
        $this->assertSame(hash('sha256', $token), $stored);
    }

    public function testTwoTokensAreNeverTheSame(): void
    {
        $first = $this->model->issue(self::USER_ID, UserTokenModel::PURPOSE_PASSWORD_RESET);
        $second = $this->model->issue(self::OTHER_USER_ID, UserTokenModel::PURPOSE_PASSWORD_RESET);

        $this->assertNotSame($first, $second);
    }

    /* spending one */

    public function testConsumeReturnsTheUserItWasIssuedTo(): void
    {
        $token = $this->model->issue(self::USER_ID, UserTokenModel::PURPOSE_PASSWORD_RESET);

        $this->assertSame(self::USER_ID, $this->model->consume($token, UserTokenModel::PURPOSE_PASSWORD_RESET));
    }

    public function testATokenIsGoodExactlyOnce(): void
    {
        $token = $this->model->issue(self::USER_ID, UserTokenModel::PURPOSE_PASSWORD_RESET);

        $this->assertSame(self::USER_ID, $this->model->consume($token, UserTokenModel::PURPOSE_PASSWORD_RESET));
        // a link forwarded, indexed by a mail scanner, or left in a browser
        // history opens nothing the second time
        $this->assertNull($this->model->consume($token, UserTokenModel::PURPOSE_PASSWORD_RESET));
    }

    public function testAForgedTokenIsRefused(): void
    {
        $this->model->issue(self::USER_ID, UserTokenModel::PURPOSE_PASSWORD_RESET);

        $this->assertNull($this->model->consume(str_repeat('a', 64), UserTokenModel::PURPOSE_PASSWORD_RESET));
    }

    public function testAnExpiredTokenIsRefused(): void
    {
        $issuedAt = time() - UserTokenModel::PASSWORD_RESET_TTL - 1;

        $token = $this->model->issue(self::USER_ID, UserTokenModel::PURPOSE_PASSWORD_RESET, $issuedAt);

        $this->assertNull($this->model->consume($token, UserTokenModel::PURPOSE_PASSWORD_RESET));
    }

    public function testATokenIsStillGoodJustBeforeItExpires(): void
    {
        $now = time();
        $token = $this->model->issue(self::USER_ID, UserTokenModel::PURPOSE_PASSWORD_RESET, $now);

        $justInTime = $now + UserTokenModel::PASSWORD_RESET_TTL - 1;

        $this->assertSame(self::USER_ID, $this->model->consume($token, UserTokenModel::PURPOSE_PASSWORD_RESET, $justInTime));
    }

    /**
     * The reason one table for two flows is safe: purpose is matched, not
     * merely recorded.
     */
    public function testATokenCannotBeSpentOnTheOtherFlow(): void
    {
        $token = $this->model->issue(self::USER_ID, UserTokenModel::PURPOSE_SIGNUP_CONFIRM);

        $this->assertNull($this->model->consume($token, UserTokenModel::PURPOSE_PASSWORD_RESET));
        // and is still good for the flow it was issued for
        $this->assertSame(self::USER_ID, $this->model->consume($token, UserTokenModel::PURPOSE_SIGNUP_CONFIRM));
    }

    public function testTheTwoPurposesHaveDifferentLifetimes(): void
    {
        // a confirmation is outstanding overnight; a reset is not
        $this->assertGreaterThan(UserTokenModel::PASSWORD_RESET_TTL, UserTokenModel::SIGNUP_CONFIRM_TTL);
    }

    public function testAnUnknownPurposeThrowsRatherThanWritingADeadRow(): void
    {
        // a typo would otherwise write a row nothing ever matches - a link that
        // silently never works
        $this->expectException(InvalidArgumentException::class);

        $this->model->issue(self::USER_ID, 'not_a_purpose');
    }

    /* invalidation */

    public function testIssuingASecondTokenKillsTheFirst(): void
    {
        $first = $this->model->issue(self::USER_ID, UserTokenModel::PURPOSE_PASSWORD_RESET);
        $second = $this->model->issue(self::USER_ID, UserTokenModel::PURPOSE_PASSWORD_RESET);

        // "I'll just request another one" restarts the window rather than
        // widening it - two live links would be two ways in
        $this->assertNull($this->model->consume($first, UserTokenModel::PURPOSE_PASSWORD_RESET));
        $this->assertSame(self::USER_ID, $this->model->consume($second, UserTokenModel::PURPOSE_PASSWORD_RESET));
    }

    public function testIssuingLeavesAnotherUsersTokenAlone(): void
    {
        $theirs = $this->model->issue(self::OTHER_USER_ID, UserTokenModel::PURPOSE_PASSWORD_RESET);

        $this->model->issue(self::USER_ID, UserTokenModel::PURPOSE_PASSWORD_RESET);

        $this->assertSame(self::OTHER_USER_ID, $this->model->consume($theirs, UserTokenModel::PURPOSE_PASSWORD_RESET));
    }

    public function testIssuingLeavesTheOtherPurposeAlone(): void
    {
        $confirm = $this->model->issue(self::USER_ID, UserTokenModel::PURPOSE_SIGNUP_CONFIRM);

        $this->model->issue(self::USER_ID, UserTokenModel::PURPOSE_PASSWORD_RESET);

        $this->assertSame(self::USER_ID, $this->model->consume($confirm, UserTokenModel::PURPOSE_SIGNUP_CONFIRM));
    }

    public function testInvalidateForDropsEveryOutstandingToken(): void
    {
        $token = $this->model->issue(self::USER_ID, UserTokenModel::PURPOSE_PASSWORD_RESET);

        $this->model->invalidateFor(self::USER_ID, UserTokenModel::PURPOSE_PASSWORD_RESET);

        $this->assertNull($this->model->consume($token, UserTokenModel::PURPOSE_PASSWORD_RESET));
    }

    /* looking without spending */

    public function testIsUsableAnswersWithoutConsuming(): void
    {
        $token = $this->model->issue(self::USER_ID, UserTokenModel::PURPOSE_PASSWORD_RESET);

        $this->assertTrue($this->model->isUsable($token, UserTokenModel::PURPOSE_PASSWORD_RESET));
        // what the GET behind a link asks, so rendering the form does not spend
        // the token the POST still needs
        $this->assertSame(self::USER_ID, $this->model->consume($token, UserTokenModel::PURPOSE_PASSWORD_RESET));
    }

    public function testIsUsableIsFalseOnceSpent(): void
    {
        $token = $this->model->issue(self::USER_ID, UserTokenModel::PURPOSE_PASSWORD_RESET);

        $this->model->consume($token, UserTokenModel::PURPOSE_PASSWORD_RESET);

        $this->assertFalse($this->model->isUsable($token, UserTokenModel::PURPOSE_PASSWORD_RESET));
    }

    public function testIsUsableIsFalseForAForgedToken(): void
    {
        $this->assertFalse($this->model->isUsable(str_repeat('b', 64), UserTokenModel::PURPOSE_PASSWORD_RESET));
    }

    /* housekeeping */

    public function testIssuingPrunesTokensThatExpiredLongAgo(): void
    {
        // an old token for somebody else, well past its expiry
        $this->model->issue(self::OTHER_USER_ID, UserTokenModel::PURPOSE_PASSWORD_RESET, time() - 100000);

        $this->assertSame(1, $this->rowCount());

        // the table stays bounded by the request rate alone, with no scheduled job
        $this->model->issue(self::USER_ID, UserTokenModel::PURPOSE_PASSWORD_RESET);

        $this->assertSame(1, $this->rowCount());
    }
}
