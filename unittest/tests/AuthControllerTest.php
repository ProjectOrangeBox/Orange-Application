<?php

declare(strict_types=1);

use application\api\controllers\AuthController;
use application\models\LoginThrottleModel;
use orange\acl\User;
use orange\auth\Auth;
use orange\framework\Container;
use orange\framework\Data;
use orange\framework\Input;
use orange\framework\Output;
use orange\framework\interfaces\RouterInterface;

/**
 * The login endpoint's hardening, which is the part of it that lives here
 * rather than in orange/auth.
 *
 * Auth is the real class against an in-memory SQLite users table - stubbing it
 * would test the mock's idea of a wrong password instead of the real one, and
 * the single most important assertion in this file (that every credential
 * failure looks identical from outside) is only worth anything if the failures
 * are genuinely different underneath.
 *
 * User is stubbed: it needs an Acl and a session, and none of what is asserted
 * here depends on what a logged-in user turns out to be able to do.
 */
final class AuthControllerTest extends unitTestHelper
{
    protected const string PASSWORD = 'correct horse battery staple';
    protected const string EMAIL = 'active@example.com';
    protected const string IP = '203.0.113.5';

    protected PDO $pdo;
    protected Output $output;

    protected function setUp(): void
    {
        require_once MOCKDIR . '/applicationServiceMocks.php';

        $this->pdo = $this->makePdo();

        $container = Container::getInstance();
        $container->set('config', new MockConfigService());
        $container->set('router', $this->createStub(RouterInterface::class));
        $container->set('viewFinder', new MockViewFinderService());
        $container->set('auth', Auth::getInstance([], $this->pdo));
        $container->set('user', $this->createStub(User::class));
        $container->set('LoginThrottleModel', LoginThrottleModel::getInstance($this->pdo));
    }

    /**
     * The two tables login touches: the accounts orange/auth reads, and the
     * failures the throttle counts. Column shapes follow the phinx migrations;
     * SQLite is close enough for both because the model does its date
     * arithmetic in PHP precisely so it does not depend on either dialect.
     */
    protected function makePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $pdo->exec(<<<'SQL'
            CREATE TABLE orange_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL DEFAULT '',
                email TEXT NOT NULL DEFAULT '',
                password TEXT NOT NULL DEFAULT '',
                is_active INTEGER NOT NULL DEFAULT 1,
                is_deleted INTEGER NOT NULL DEFAULT 0
            )
            SQL);

        $pdo->exec(<<<'SQL'
            CREATE TABLE login_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                login TEXT NOT NULL,
                ip TEXT NOT NULL,
                attempted_at TEXT NOT NULL
            )
            SQL);

        $hash = password_hash(self::PASSWORD, PASSWORD_DEFAULT);

        $statement = $pdo->prepare(
            'insert into orange_users (username, email, password, is_active, is_deleted)'
            . ' values (:username, :email, :password, :is_active, :is_deleted)'
        );

        $statement->execute([':username' => 'Active', ':email' => self::EMAIL, ':password' => $hash, ':is_active' => 1, ':is_deleted' => 0]);
        $statement->execute([':username' => 'Inactive', ':email' => 'inactive@example.com', ':password' => $hash, ':is_active' => 0, ':is_deleted' => 0]);

        return $pdo;
    }

    /**
     * Drive one request through a freshly built controller.
     *
     * Fresh because Input parses the body once, in its constructor - a second
     * request is a second Input, which is a second controller. The Output is
     * rebuilt with it so response codes and headers describe this call only.
     *
     * @param array<string, mixed> $body
     * @return array{0: string, 1: Output}
     */
    protected function post(array $body, string $contentType = 'application/json', string $ip = self::IP): array
    {
        return $this->send('login', $body, $contentType, $ip);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{0: string, 1: Output}
     */
    protected function send(string $method, array $body = [], string $contentType = 'application/json', string $ip = self::IP): array
    {
        $input = Input::newInstance([
            'server' => ['CONTENT_TYPE' => $contentType, 'REMOTE_ADDR' => $ip],
            'input' => json_encode($body) ?: '',
            'request' => [],
        ]);

        $output = Output::newInstance([], $input);

        $container = Container::getInstance();
        $container->set('input', $input);
        $container->set('output', $output);
        $container->set('data', Data::newInstance());

        $controller = new AuthController();

        return [$controller->$method(), $output];
    }

    /**
     * @return array<string, mixed>
     */
    protected function decode(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function failureCount(): int
    {
        return (int) $this->pdo->query('select count(*) as c from login_attempts')->fetchColumn();
    }

    /* content type */

    public function testFormEncodedLoginIsRefused(): void
    {
        // the whole point: a cross-origin <form> can only send this content
        // type, and cannot send application/json without a preflight
        [$json, $output] = $this->post(['email' => self::EMAIL, 'password' => self::PASSWORD], 'application/x-www-form-urlencoded');

        $this->assertSame(415, $output->getResponseCode());
        $this->assertArrayHasKey('msg', $this->decode($json));
    }

    public function testJsonContentTypeWithCharsetIsAccepted(): void
    {
        // parameters on the header are legal and must not be mistaken for a
        // different media type
        [, $output] = $this->post(['email' => self::EMAIL, 'password' => 'wrong'], 'application/json; charset=utf-8');

        $this->assertSame(401, $output->getResponseCode());
    }

    public function testARefusedContentTypeIsNotCountedAsAnAttempt(): void
    {
        $this->post(['email' => self::EMAIL, 'password' => 'wrong'], 'text/plain');

        $this->assertSame(0, $this->failureCount());
    }

    /* what a failure is allowed to say */

    public function testUnknownAccountWrongPasswordAndInactiveAccountAreIndistinguishable(): void
    {
        [$unknown] = $this->post(['email' => 'nobody@example.com', 'password' => self::PASSWORD]);
        [$wrong] = $this->post(['email' => self::EMAIL, 'password' => 'wrong']);
        // reached only after password_verify() succeeds, so auth's own message
        // ("Your user is not active.") would confirm both the address and the
        // password. The controller replaces it.
        [$inactive] = $this->post(['email' => 'inactive@example.com', 'password' => self::PASSWORD]);

        $this->assertSame($this->decode($unknown), $this->decode($wrong));
        $this->assertSame($this->decode($unknown), $this->decode($inactive));
        $this->assertSame('Login Error.', $this->decode($inactive)['msg']);
    }

    public function testFailureResponseNeverEchoesTheSubmittedPassword(): void
    {
        [$json] = $this->post(['email' => self::EMAIL, 'password' => 'hunter2']);

        $this->assertStringNotContainsString('hunter2', $json);
    }

    /* throttling */

    public function testRepeatedFailuresAgainstOneLoginAreThrottled(): void
    {
        for ($attempt = 0; $attempt < LoginThrottleModel::MAX_PER_LOGIN; $attempt++) {
            [, $output] = $this->post(['email' => self::EMAIL, 'password' => 'wrong']);

            $this->assertSame(401, $output->getResponseCode(), 'attempt ' . $attempt . ' should still be answered');
        }

        [$json, $output] = $this->post(['email' => self::EMAIL, 'password' => 'wrong']);

        $this->assertSame(429, $output->getResponseCode());
        $this->assertArrayHasKey('msg', $this->decode($json));

        $retryAfter = array_values(array_filter($output->getHeaders(), fn(string $header): bool => str_starts_with($header, 'Retry-After: ')));

        $this->assertCount(1, $retryAfter);
        $this->assertGreaterThan(0, (int) substr($retryAfter[0], strlen('Retry-After: ')));
    }

    public function testTheRightPasswordIsRefusedOnceTheLoginIsThrottled(): void
    {
        for ($attempt = 0; $attempt < LoginThrottleModel::MAX_PER_LOGIN; $attempt++) {
            $this->post(['email' => self::EMAIL, 'password' => 'wrong']);
        }

        // a throttle a correct password walks straight through is not one -
        // the guessing that got here would have already succeeded
        [, $output] = $this->post(['email' => self::EMAIL, 'password' => self::PASSWORD]);

        $this->assertSame(429, $output->getResponseCode());
    }

    public function testThrottleCountsTheNormalizedLogin(): void
    {
        // auth trims and lowercases before looking the account up, so counting
        // the raw string would give one account as many allowances as an
        // attacker can think of ways to type it
        $spellings = ['  ACTIVE@example.com ', 'Active@Example.Com', 'active@EXAMPLE.com', ' active@example.com', 'ACTIVE@EXAMPLE.COM'];

        foreach ($spellings as $spelling) {
            $this->post(['email' => $spelling, 'password' => 'wrong']);
        }

        [, $output] = $this->post(['email' => self::EMAIL, 'password' => 'wrong']);

        $this->assertSame(429, $output->getResponseCode());
    }

    public function testEmptyFieldsAreAnsweredButNotCounted(): void
    {
        // otherwise anyone could spend a victim's whole allowance without ever
        // guessing at their password
        for ($attempt = 0; $attempt <= LoginThrottleModel::MAX_PER_LOGIN; $attempt++) {
            [, $output] = $this->post(['email' => self::EMAIL, 'password' => '']);

            $this->assertSame(401, $output->getResponseCode());
        }

        $this->assertSame(0, $this->failureCount());
    }

    public function testAnotherAddressIsNotThrottledByThisOnesFailures(): void
    {
        for ($attempt = 0; $attempt < LoginThrottleModel::MAX_PER_LOGIN; $attempt++) {
            $this->post(['email' => 'first@example.com', 'password' => 'wrong'], 'application/json', '203.0.113.5');
        }

        // per-login and per-ip are separate counters; a different login from a
        // different address shares neither
        [, $output] = $this->post(['email' => 'second@example.com', 'password' => 'wrong'], 'application/json', '198.51.100.7');

        $this->assertSame(401, $output->getResponseCode());
    }

    public function testOneAddressWorkingThroughManyAccountsIsThrottled(): void
    {
        // each login stays under its own limit; the address does not
        for ($attempt = 0; $attempt < LoginThrottleModel::MAX_PER_IP; $attempt++) {
            [, $output] = $this->post(['email' => 'user' . $attempt . '@example.com', 'password' => 'wrong']);

            $this->assertSame(401, $output->getResponseCode());
        }

        [, $output] = $this->post(['email' => 'onemore@example.com', 'password' => 'wrong']);

        $this->assertSame(429, $output->getResponseCode());
    }

    public function testSuccessClearsThatLoginsFailures(): void
    {
        $this->post(['email' => self::EMAIL, 'password' => 'wrong']);
        $this->post(['email' => self::EMAIL, 'password' => 'wrong']);

        $this->assertSame(2, $this->failureCount());

        [, $output] = $this->post(['email' => self::EMAIL, 'password' => self::PASSWORD]);

        $this->assertSame(200, $output->getResponseCode());
        $this->assertSame(0, $this->failureCount());
    }

    /* caching */

    public function testIdentityResponsesAreNotCacheable(): void
    {
        [, $loginOutput] = $this->post(['email' => self::EMAIL, 'password' => self::PASSWORD]);
        [, $meOutput] = $this->send('me');

        // /api/me names the caller and lists what they may do - a shared cache
        // handing that to the next person to ask for it is a data leak
        foreach ([$loginOutput, $meOutput] as $output) {
            $this->assertContains('Cache-Control: no-store', $output->getHeaders());
        }
    }
}
