<?php

declare(strict_types=1);

use application\api\models\LoginThrottleModel;
use application\login\controllers\SessionController;
use orange\acl\User;
use orange\acl\interfaces\UserEntityInterface;
use orange\auth\Auth;
use orange\framework\Container;
use orange\framework\Data;
use orange\framework\Input;
use orange\framework\Output;
use orange\framework\interfaces\RouterInterface;
use orange\session\SessionInterface;

/**
 * The browser login: CSRF, throttling, and what a failure is allowed to say.
 *
 * Auth and LoginThrottleModel are the real classes against in-memory SQLite,
 * for the same reason as AuthControllerTest - the assertion that every
 * credential failure looks identical is only worth something if the failures
 * are genuinely different underneath.
 */
final class SessionControllerTest extends unitTestHelper
{
    protected const string PASSWORD = 'correct horse battery staple';
    protected const string EMAIL = 'active@example.com';

    protected PDO $pdo;
    protected TestSession $session;
    protected TestOutput $output;
    protected Data $data;
    protected MockViewService $view;
    protected MockViewFinderService $viewFinder;

    protected function setUp(): void
    {
        require_once MOCKDIR . '/applicationServiceMocks.php';

        $this->pdo = $this->makePdo();
        $this->session = new TestSession();
    }

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

        $statement = $pdo->prepare(
            'insert into orange_users (username, email, password, is_active, is_deleted)'
            . ' values (:username, :email, :password, 1, 0)'
        );

        $statement->execute([
            ':username' => 'Active',
            ':email' => self::EMAIL,
            ':password' => password_hash(self::PASSWORD, PASSWORD_DEFAULT),
        ]);

        return $pdo;
    }

    /**
     * Build a controller around one request.
     *
     * @param array<string, mixed> $body
     */
    protected function makeController(string $method = 'get', array $body = [], bool $isGuest = true): SessionController
    {
        $input = Input::newInstance([
            'server' => [
                'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
                'REQUEST_METHOD' => strtoupper($method),
                'REMOTE_ADDR' => '203.0.113.5',
                'REQUEST_URI' => '/login',
            ],
            'input' => http_build_query($body),
            'request' => $body,
        ]);

        // newInstance(), not new: Output's constructor is protected (Singleton),
        // and newInstance() is the one method allowed to call it - on static::,
        // so it builds the subclass
        $this->output = TestOutput::newInstance([], $input);
        $this->data = Data::newInstance();
        $this->view = new MockViewService();
        $this->viewFinder = new MockViewFinderService();

        $entity = $this->createStub(UserEntityInterface::class);
        $entity->method('isGuest')->willReturn($isGuest);

        $user = $this->createStub(User::class);
        $user->method('load')->willReturn($entity);

        $container = Container::getInstance();
        $container->set('config', new MockConfigService());
        $container->set('input', $input);
        $container->set('output', $this->output);
        $container->set('data', $this->data);
        $container->set('view', $this->view);
        $container->set('router', $this->createStub(RouterInterface::class));
        $container->set('viewFinder', $this->viewFinder);
        $container->set('session', $this->session);
        $container->set('user', $user);
        $container->set('auth', Auth::getInstance([], $this->pdo));
        $container->set('LoginThrottleModel', LoginThrottleModel::getInstance($this->pdo));

        return new SessionController();
    }

    /**
     * Render the form once and return the token it issued - what a browser
     * would have in hand before posting.
     */
    protected function issuedToken(): string
    {
        $this->makeController('get')->form();

        return (string) $this->data['csrfToken'];
    }

    protected function failureCount(): int
    {
        return (int) $this->pdo->query('select count(*) from login_attempts')->fetchColumn();
    }

    /* the form */

    public function testFormRendersTheLoginView(): void
    {
        $this->makeController('get')->form();

        $this->assertCount(1, $this->view->renderCalls);
        $this->assertEquals('session/login', $this->view->renderCalls[0]['view']);
    }

    /**
     * The shared chrome reaches this module through the view map, not through a
     * relative path - which is what lets login live in its own module at all.
     */
    public function testTheChromePartialsAreResolvedUnderThisModulesNamespace(): void
    {
        $this->makeController('get')->form();

        $asked = array_map(
            fn(array $call): array => [$call['view'], $call['namespace']],
            $this->viewFinder->findCalls
        );

        // this module's own copy is looked for first and the shared one found
        // otherwise, so dropping in application/login/views/partials/nav.php
        // would take over the navbar here and change nothing anywhere else
        $this->assertContains(['partials/header', 'application/login'], $asked);
        $this->assertContains(['partials/nav', 'application/login'], $asked);
        $this->assertContains(['partials/footer', 'application/login'], $asked);

        // and the view is handed a file to include rather than a name
        $this->assertSame('partials/nav', $this->data['navPartial']);
    }

    public function testFormIssuesACsrfTokenAndRemembersIt(): void
    {
        $token = $this->issuedToken();

        // long enough to be worth having, and the same value the session holds
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $this->assertSame($token, $this->session->get('csrf_token'));
    }

    public function testTheSameSessionKeepsTheSameToken(): void
    {
        // a token minted per render would break the back button and a second tab
        $this->assertSame($this->issuedToken(), $this->issuedToken());
    }

    /* csrf */

    public function testLoginWithoutATokenIsRefused(): void
    {
        $controller = $this->makeController('post', ['email' => self::EMAIL, 'password' => self::PASSWORD]);

        $controller->login();

        // re-rendered with a complaint, not redirected to a logged-in page
        $this->assertEquals('session/login', $this->view->renderCalls[0]['view']);
        $this->assertStringContainsString('no longer valid', (string) $this->data['error']);
    }

    public function testLoginWithTheWrongTokenIsRefused(): void
    {
        $this->issuedToken();

        $controller = $this->makeController('post', [
            '_token' => str_repeat('0', 64),
            'email' => self::EMAIL,
            'password' => self::PASSWORD,
        ]);

        $controller->login();

        $this->assertStringContainsString('no longer valid', (string) $this->data['error']);
    }

    public function testACsrfFailureNeverChecksTheCredentials(): void
    {
        $this->makeController('post', ['email' => self::EMAIL, 'password' => 'wrong'])->login();

        // refused before auth ran, so nothing was counted against the account
        $this->assertSame(0, $this->failureCount());
    }

    public function testLogoutWithoutATokenChangesNothing(): void
    {
        $this->session->set('user_id', 1);

        $this->makeController('post', [], isGuest: false)->logout();

        // the guard is the token; a tokenless POST is redirected but not obeyed
        $this->assertSame(1, $this->session->get('user_id'));
    }

    /* credentials */

    public function testEveryCredentialFailureLooksTheSame(): void
    {
        $token = $this->issuedToken();

        $messages = [];

        foreach ([['nobody@example.com', self::PASSWORD], [self::EMAIL, 'wrong']] as [$email, $password]) {
            $this->makeController('post', ['_token' => $token, 'email' => $email, 'password' => $password])->login();

            $messages[] = (string) $this->data['error'];
        }

        $this->assertSame($messages[0], $messages[1]);
        $this->assertStringNotContainsString('password', mb_strtolower($messages[0]));
    }

    public function testTheSubmittedPasswordIsNeverHandedBack(): void
    {
        $token = $this->issuedToken();

        $this->makeController('post', ['_token' => $token, 'email' => self::EMAIL, 'password' => 'hunter2'])->login();

        // the email comes back so it need not be retyped; the password does not
        $this->assertSame(self::EMAIL, $this->data['email']);
        $this->assertStringNotContainsString('hunter2', json_encode((array) $this->data) ?: '');
    }

    public function testEmptyFieldsAreAnsweredButNotCounted(): void
    {
        $token = $this->issuedToken();

        $this->makeController('post', ['_token' => $token, 'email' => self::EMAIL, 'password' => ''])->login();

        $this->assertStringContainsString('fill in both', (string) $this->data['error']);
        $this->assertSame(0, $this->failureCount());
    }

    /* throttling - the same counter the api endpoint uses */

    public function testTheFormLoginSharesTheApiLoginsThrottle(): void
    {
        $token = $this->issuedToken();

        for ($attempt = 0; $attempt < LoginThrottleModel::MAX_PER_LOGIN; $attempt++) {
            $this->makeController('post', ['_token' => $token, 'email' => self::EMAIL, 'password' => 'wrong'])->login();
        }

        // a form login that skipped the throttle would be the way around it
        $this->assertSame(LoginThrottleModel::MAX_PER_LOGIN, $this->failureCount());

        $this->makeController('post', ['_token' => $token, 'email' => self::EMAIL, 'password' => 'wrong'])->login();

        $this->assertStringContainsString('Too many attempts', (string) $this->data['error']);
    }

    public function testTheRightPasswordIsRefusedOnceThrottled(): void
    {
        $token = $this->issuedToken();

        for ($attempt = 0; $attempt < LoginThrottleModel::MAX_PER_LOGIN; $attempt++) {
            $this->makeController('post', ['_token' => $token, 'email' => self::EMAIL, 'password' => 'wrong'])->login();
        }

        $this->makeController('post', ['_token' => $token, 'email' => self::EMAIL, 'password' => self::PASSWORD])->login();

        $this->assertStringContainsString('Too many attempts', (string) $this->data['error']);
    }

    /* success */

    public function testASuccessfulLoginRedirectsAndClearsItsFailures(): void
    {
        $token = $this->issuedToken();

        $this->makeController('post', ['_token' => $token, 'email' => self::EMAIL, 'password' => 'wrong'])->login();

        $this->assertSame(1, $this->failureCount());

        $controller = $this->makeController('post', ['_token' => $token, 'email' => self::EMAIL, 'password' => self::PASSWORD]);

        $controller->login();

        $this->assertSame(0, $this->failureCount());
        // 303, not the framework's configured 301 default - a cached permanent
        // redirect would strand the login form
        $this->assertSame(303, $this->output->getResponseCode());
        $this->assertNotEmpty(array_filter($this->output->getHeaders(), fn(string $h): bool => str_starts_with($h, 'Location: ')));
    }

    public function testLoggingInThrowsTheAnonymousTokenAway(): void
    {
        $token = $this->issuedToken();

        $this->makeController('post', ['_token' => $token, 'email' => self::EMAIL, 'password' => self::PASSWORD])->login();

        // the session id is regenerated on a privilege change but keeps its
        // contents, so the token has to be dropped deliberately
        $this->assertNull($this->session->get('csrf_token'));
    }

    public function testALoggedInVisitorIsSentAwayFromTheForm(): void
    {
        $controller = $this->makeController('get', [], isGuest: false);

        $controller->form();

        $this->assertSame([], $this->view->renderCalls);
        $this->assertSame(303, $this->output->getResponseCode());
    }
}

/**
 * A session that actually stores things, which a stub does not - the CSRF round
 * trip is the whole point of most of the tests above.
 */
class TestSession implements SessionInterface
{
    /** @var array<string, mixed> */
    private array $store = [];

    public function __get(string $key): mixed
    {
        return $this->store[$key] ?? null;
    }

    public function __set(string $key, mixed $value): void
    {
        $this->store[$key] = $value;
    }

    public function __isset(string $key): bool
    {
        return isset($this->store[$key]);
    }

    public function __unset(string $key): void
    {
        unset($this->store[$key]);
    }

    public function start(array $customOptions = []): bool
    {
        return true;
    }

    public function activate(): bool
    {
        return true;
    }

    public function isActive(): bool
    {
        return true;
    }

    public function destroy(): bool
    {
        $this->store = [];

        return true;
    }

    public function destroyCookie(): bool
    {
        return true;
    }

    public function stop(): bool
    {
        return true;
    }

    public function abort(): bool
    {
        return true;
    }

    public function has(string $key): bool
    {
        return isset($this->store[$key]);
    }

    public function get(string $key): mixed
    {
        return $this->store[$key] ?? null;
    }

    public function getAll(): array
    {
        return $this->store;
    }

    public function getMulti(array $keys): array
    {
        $found = [];

        foreach ($keys as $key) {
            $found[$key] = $this->store[$key] ?? null;
        }

        return $found;
    }

    public function set(string $key, mixed $value): static
    {
        $this->store[$key] = $value;

        return $this;
    }

    public function setMulti(array $items): static
    {
        foreach ($items as $key => $value) {
            $this->store[$key] = $value;
        }

        return $this;
    }

    public function remove(string $key): static
    {
        unset($this->store[$key]);

        return $this;
    }

    public function removeMulti(array $keys): static
    {
        foreach ($keys as $key) {
            unset($this->store[$key]);
        }

        return $this;
    }

    public function removeAll(): static
    {
        $this->store = [];

        return $this;
    }

    public function regenerateId(bool $deleteOldSession = false): bool
    {
        // a real regenerate keeps the contents, which is exactly why the
        // controller has to drop the csrf token itself
        return true;
    }

    public function reset(): bool
    {
        return true;
    }

    public function getFlash(string $key): mixed
    {
        return $this->store[$key] ?? null;
    }

    public function setFlash(string $key, mixed $value): static
    {
        $this->store[$key] = $value;

        return $this;
    }

    public function removeFlash(string $key): static
    {
        unset($this->store[$key]);

        return $this;
    }

    public function getTemp(string $key): mixed
    {
        return $this->store[$key] ?? null;
    }

    public function setTemp(string $key, mixed $value, int $ttl = 60): static
    {
        $this->store[$key] = $value;

        return $this;
    }

    public function removeTemp(string $key): static
    {
        unset($this->store[$key]);

        return $this;
    }

    public function id(?string $newId = null): string|false
    {
        return 'test-session-id';
    }

    public function gc(): int|false
    {
        return 0;
    }
}

/**
 * Output that does not actually exit, header() or echo.
 *
 * redirect() ends a real request by design; these three hooks are protected
 * precisely so a test can watch what was sent instead of being terminated by it.
 */
class TestOutput extends Output
{
    /**
     * ConfigurationTrait finds a service's defaults from the class's *own* file
     * location and short name - which for a subclass declared in a test file is
     * "unittest/tests/config/testoutput.php", a file that has no business
     * existing. Point it back at the real Output's config instead.
     */
    protected function determineConfigPath(?string $arg): string
    {
        return __ROOT__ . '/vendor/orange/framework/src/config/output.php';
    }

    protected function phpExit(int $status = 0): void
    {
        // deliberately does not exit - the assertions come after the redirect
    }

    protected function phpHeader(string $header, bool $replace = false): void
    {
        // headers are read back through getHeaders() instead of being sent
    }

    protected function phpEcho(string $string): void
    {
        // PHPUnit fails a test that writes to stdout during a header send
    }
}
