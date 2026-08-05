<?php

declare(strict_types=1);

use application\login\models\UserAccountModel;

/**
 * The account writes neither orange/auth nor orange/acl provides.
 *
 * Against real SQLite, on the same table shape orange/acl owns, because the
 * claims worth making here are about what actually lands in a row - that a new
 * account is created switched off, that an address is stored the way auth will
 * later look it up, that a password is never stored as typed.
 */
final class UserAccountModelTest extends unitTestHelper
{
    private const string PASSWORD = 'a-long-enough-passphrase';

    protected PDO $pdo;
    protected UserAccountModel $model;

    protected function setUp(): void
    {
        $this->pdo = $this->makePdo();
        $this->model = UserAccountModel::newInstance($this->pdo);
    }

    protected function makePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $pdo->exec(<<<'SQL'
            CREATE TABLE orange_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL DEFAULT '' UNIQUE,
                email TEXT NOT NULL DEFAULT '' UNIQUE,
                password TEXT NOT NULL DEFAULT '',
                is_active INTEGER NOT NULL DEFAULT 0,
                is_deleted INTEGER NOT NULL DEFAULT 0
            )
            SQL);

        return $pdo;
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        $statement = $this->pdo->prepare('select * from orange_users where id = :id');
        $statement->execute([':id' => $id]);

        return (array) $statement->fetch(PDO::FETCH_ASSOC);
    }

    /* creating one */

    /**
     * The claim the whole confirmation flow rests on. An account that could log
     * in before confirming would mean anyone could sign up as anyone.
     */
    public function testANewAccountIsCreatedSwitchedOff(): void
    {
        $id = $this->model->createPending('someone', 'someone@example.com', self::PASSWORD);

        $this->assertSame(0, (int) $this->row($id)['is_active']);
    }

    public function testThePasswordIsNeverStoredAsTyped(): void
    {
        $id = $this->model->createPending('someone', 'someone@example.com', self::PASSWORD);

        $stored = (string) $this->row($id)['password'];

        $this->assertNotSame(self::PASSWORD, $stored);
        // and it is a hash orange/auth's password_verify() will accept
        $this->assertTrue(password_verify(self::PASSWORD, $stored));
    }

    /**
     * Stored the way auth looks accounts up, so one address cannot become two
     * accounts by being typed differently.
     */
    public function testTheAddressIsLowercasedAndTrimmed(): void
    {
        $id = $this->model->createPending('someone', '  SoMeOne@Example.COM  ', self::PASSWORD);

        $this->assertSame('someone@example.com', $this->row($id)['email']);
    }

    public function testTheUsernameIsTrimmed(): void
    {
        $id = $this->model->createPending('  someone  ', 'someone@example.com', self::PASSWORD);

        $this->assertSame('someone', $this->row($id)['username']);
    }

    /* finding one */

    public function testFindByEmailIgnoresHowTheAddressWasTyped(): void
    {
        $id = $this->model->createPending('someone', 'someone@example.com', self::PASSWORD);

        $found = $this->model->findByEmail('  SOMEONE@example.com ');

        $this->assertSame($id, $found['id'] ?? null);
    }

    public function testFindByEmailIsNullForAnUnknownAddress(): void
    {
        $this->assertNull($this->model->findByEmail('nobody@example.com'));
    }

    /**
     * An unconfirmed signup is inactive and still has to be findable, or
     * confirming it would create a second account instead.
     */
    public function testAnUnconfirmedAccountIsStillFound(): void
    {
        $this->model->createPending('someone', 'someone@example.com', self::PASSWORD);

        $found = $this->model->findByEmail('someone@example.com');

        $this->assertNotNull($found);
        $this->assertFalse($found['is_active']);
    }

    public function testADeletedAccountIsNotFound(): void
    {
        $id = $this->model->createPending('someone', 'someone@example.com', self::PASSWORD);

        $this->pdo->exec('update orange_users set is_deleted = 1 where id = ' . $id);

        $this->assertNull($this->model->findByEmail('someone@example.com'));
    }

    public function testEmailForIsTheReverseOfFindByEmail(): void
    {
        $id = $this->model->createPending('someone', 'someone@example.com', self::PASSWORD);

        $this->assertSame('someone@example.com', $this->model->emailFor($id));
    }

    public function testEmailForIsEmptyForAnUnknownId(): void
    {
        $this->assertSame('', $this->model->emailFor(9999));
    }

    /* what is taken */

    public function testEmailTakenSeesAnExistingAccount(): void
    {
        $this->model->createPending('someone', 'someone@example.com', self::PASSWORD);

        $this->assertTrue($this->model->emailTaken('SOMEONE@example.com'));
        $this->assertFalse($this->model->emailTaken('nobody@example.com'));
    }

    public function testUsernameTakenSeesAnExistingAccount(): void
    {
        $this->model->createPending('someone', 'someone@example.com', self::PASSWORD);

        $this->assertTrue($this->model->usernameTaken('someone'));
        $this->assertFalse($this->model->usernameTaken('somebody'));
    }

    /**
     * The index is the real guard - emailTaken() can lose a race with a
     * simultaneous signup, and SignupController catches what this throws.
     */
    public function testADuplicateAddressIsRefusedByTheDatabase(): void
    {
        $this->model->createPending('someone', 'someone@example.com', self::PASSWORD);

        $this->expectException(PDOException::class);

        $this->model->createPending('somebody', 'someone@example.com', self::PASSWORD);
    }

    /* changing one */

    public function testActivateTurnsAnAccountOn(): void
    {
        $id = $this->model->createPending('someone', 'someone@example.com', self::PASSWORD);

        $this->model->activate($id);

        $this->assertSame(1, (int) $this->row($id)['is_active']);
    }

    public function testActivateIsIdempotent(): void
    {
        $id = $this->model->createPending('someone', 'someone@example.com', self::PASSWORD);

        $this->model->activate($id);
        // a second click on one link is not an error
        $this->model->activate($id);

        $this->assertSame(1, (int) $this->row($id)['is_active']);
    }

    public function testUpdatePasswordReplacesTheHash(): void
    {
        $id = $this->model->createPending('someone', 'someone@example.com', self::PASSWORD);
        $before = (string) $this->row($id)['password'];

        $this->model->updatePassword($id, 'a-completely-different-one');

        $after = (string) $this->row($id)['password'];

        $this->assertNotSame($before, $after);
        $this->assertTrue(password_verify('a-completely-different-one', $after));
        $this->assertFalse(password_verify(self::PASSWORD, $after));
    }

    /**
     * Two accounts with the same password do not share a hash - password_hash()
     * salts every call, so a stolen table cannot be scanned for repeats.
     */
    public function testTheSamePasswordHashesDifferentlyForTwoAccounts(): void
    {
        $first = $this->model->createPending('one', 'one@example.com', self::PASSWORD);
        $second = $this->model->createPending('two', 'two@example.com', self::PASSWORD);

        $this->assertNotSame($this->row($first)['password'], $this->row($second)['password']);
    }
}
