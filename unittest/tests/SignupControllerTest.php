<?php

declare(strict_types=1);

use application\login\controllers\SignupController;
use application\login\models\UserAccountModel;
use application\login\models\UserTokenModel;
use orange\acl\User;
use orange\acl\interfaces\UserEntityInterface;
use orange\framework\Container;
use orange\framework\Data;
use orange\framework\Input;
use orange\framework\interfaces\RouterInterface;
use orange\mail\CollectorMailer;

/**
 * Making an account, and the two things that stop it being a free oracle.
 *
 * The same enumeration property PasswordControllerTest guards, approached from
 * the other side: a signup form that says "that email is already registered"
 * answers the same question a reset form must not. So a taken address gets the
 * page a free one gets, and the person who *owns* the address is told instead.
 *
 * And the account starts switched off, which is what makes the confirmation
 * mail worth sending at all.
 *
 * TestSession and TestOutput come from SessionControllerTest, required
 * explicitly so this file also passes when run on its own.
 */
require_once __DIR__ . '/SessionControllerTest.php';

final class SignupControllerTest extends unitTestHelper
{
    private const string TAKEN_EMAIL = 'taken@example.com';
    private const string TAKEN_USERNAME = 'taken';
    private const string NEW_EMAIL = 'newcomer@example.com';
    private const string NEW_USERNAME = 'newcomer';
    private const string PASSWORD = 'a-long-enough-passphrase';

    protected PDO $pdo;
    protected TestSession $session;
    protected TestOutput $output;
    protected Data $data;
    protected MockViewService $view;
    protected CollectorMailer $mailer;
    protected UserAccountModel $accounts;
    protected UserTokenModel $tokens;
    /**
     * Set before makeController() by the one test that needs to assert a call
     * was *not* made; every other test gets a plain stub, which is what PHPUnit
     * wants when nothing is being expected of it.
     */
    protected ?User $userDouble = null;

    protected function setUp(): void
    {
        require_once MOCKDIR . '/applicationServiceMocks.php';

        $this->pdo = $this->makePdo();
        $this->session = new TestSession();
        $this->accounts = UserAccountModel::newInstance($this->pdo);
        $this->tokens = UserTokenModel::newInstance($this->pdo);
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

        $pdo->exec(
            "insert into orange_users (username, email, password, is_active) values"
            . " ('" . self::TAKEN_USERNAME . "', '" . self::TAKEN_EMAIL . "', '', 1)"
        );

        return $pdo;
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $query
     */
    protected function makeController(string $method = 'get', array $body = [], array $query = [], bool $isGuest = true): SignupController
    {
        $input = Input::newInstance([
            'server' => [
                'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
                'REQUEST_METHOD' => strtoupper($method),
                'REMOTE_ADDR' => '203.0.113.5',
                'REQUEST_URI' => '/signup',
                'HTTP_HOST' => 'example.test',
            ],
            'input' => http_build_query($body),
            'request' => $body,
            'query' => $query,
        ]);

        $this->output = TestOutput::newInstance([], $input);
        $this->data = Data::newInstance();
        $this->view = new MockViewService();
        $this->mailer = CollectorMailer::newInstance();

        $entity = $this->createStub(UserEntityInterface::class);
        $entity->method('isGuest')->willReturn($isGuest);

        $user = $this->userDouble ?? $this->createStub(User::class);
        $user->method('load')->willReturn($entity);

        $router = $this->createStub(RouterInterface::class);
        $router->method('getUrl')->willReturnCallback(static fn(string $name): string => '/' . $name);

        $container = Container::getInstance();
        $container->set('config', new MockConfigService());
        $container->set('input', $input);
        $container->set('output', $this->output);
        $container->set('data', $this->data);
        $container->set('view', $this->view);
        $container->set('router', $router);
        $container->set('viewFinder', new MockViewFinderService());
        $container->set('session', $this->session);
        $container->set('user', $user);
        $container->set('mail', $this->mailer);
        $container->set('UserAccountModel', $this->accounts);
        $container->set('UserTokenModel', $this->tokens);

        return new SignupController();
    }

    protected function issuedCsrfToken(): string
    {
        $this->makeController('get')->form();

        return (string) $this->data['csrfToken'];
    }

    /** The page shown - the last render that was not an email body. */
    protected function renderedView(): string
    {
        $pages = array_values(array_filter(
            $this->view->renderCalls,
            static fn(array $call): bool => !str_starts_with((string) $call['view'], 'mail/')
        ));

        return (string) ($pages === [] ? '' : $pages[array_key_last($pages)]['view']);
    }

    /** @return array<string, mixed> */
    protected function renderedWith(string $view): array
    {
        foreach ($this->view->renderCalls as $call) {
            if ($call['view'] === $view) {
                return (array) $call['data'];
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function body(array $overrides = []): array
    {
        return $overrides + [
            '_token' => $this->issuedCsrfToken(),
            'username' => self::NEW_USERNAME,
            'email' => self::NEW_EMAIL,
            'password' => self::PASSWORD,
            'passwordConfirm' => self::PASSWORD,
        ];
    }

    protected function accountCount(): int
    {
        return (int) $this->pdo->query('select count(*) from orange_users')->fetchColumn();
    }

    /* creating one */

    public function testAValidSignupCreatesAnAccount(): void
    {
        $this->makeController('post', $this->body())->signup();

        $this->assertSame('signup/check-email', $this->renderedView());
        $this->assertNotNull($this->accounts->findByEmail(self::NEW_EMAIL));
    }

    /**
     * The claim the whole flow rests on. An account usable before confirming
     * would mean anyone could sign up as anyone.
     */
    public function testTheNewAccountCannotBeUsedYet(): void
    {
        $this->makeController('post', $this->body())->signup();

        $this->assertFalse($this->accounts->findByEmail(self::NEW_EMAIL)['is_active']);
    }

    public function testTheConfirmationMailCarriesAWorkingLink(): void
    {
        $this->makeController('post', $this->body())->signup();

        $this->assertCount(1, $this->mailer->sentTo(self::NEW_EMAIL));
        $this->assertSame('Confirm your account', $this->mailer->last()?->subject);

        $link = (string) ($this->renderedWith('mail/signup-confirm')['link'] ?? '');

        $this->assertStringStartsWith('http://example.test/signup_confirm?token=', $link);

        preg_match('/token=([0-9a-f]{64})/', $link, $matches);

        $this->assertTrue($this->tokens->isUsable($matches[1] ?? '', UserTokenModel::PURPOSE_SIGNUP_CONFIRM));
    }

    public function testBothBodiesAreSent(): void
    {
        $this->makeController('post', $this->body())->signup();

        $this->assertNotSame('', (string) $this->mailer->last()?->html);
        $this->assertNotSame('', (string) $this->mailer->last()?->text);
    }

    /* what a taken address does, and does not, reveal */

    /**
     * The page a taken address gets is the page a free one gets, word for word.
     * Anything else here answers "does this address have an account".
     */
    public function testATakenAddressGetsTheSamePageAsAFreeOne(): void
    {
        $this->makeController('post', $this->body())->signup();
        $free = [$this->renderedView(), (string) $this->data['message']];

        $this->makeController('post', $this->body(['username' => 'somebodyelse', 'email' => self::TAKEN_EMAIL]))->signup();
        $taken = [$this->renderedView(), (string) $this->data['message']];

        $this->assertSame($free, $taken);
    }

    public function testATakenAddressCreatesNoSecondAccount(): void
    {
        $before = $this->accountCount();

        $this->makeController('post', $this->body(['username' => 'somebodyelse', 'email' => self::TAKEN_EMAIL]))->signup();

        $this->assertSame($before, $this->accountCount());
    }

    /**
     * The mail that goes out instead is to the address's real owner, and is
     * useful to exactly one person: them.
     */
    public function testATakenAddressTellsItsOwnerSomebodyTried(): void
    {
        $this->makeController('post', $this->body(['username' => 'somebodyelse', 'email' => self::TAKEN_EMAIL]))->signup();

        $this->assertCount(1, $this->mailer->sentTo(self::TAKEN_EMAIL));
        $this->assertSame('Someone tried to sign up with your address', $this->mailer->last()?->subject);
    }

    /**
     * No token in that message. A link that acted on the account would be a
     * lever handed to whoever probed the address.
     */
    public function testTheOwnerNoticeCarriesNoToken(): void
    {
        $this->makeController('post', $this->body(['username' => 'somebodyelse', 'email' => self::TAKEN_EMAIL]))->signup();

        $data = $this->renderedWith('mail/signup-in-use');

        $this->assertArrayNotHasKey('link', $data);
        $this->assertSame(
            0,
            (int) $this->pdo->query('select count(*) from user_tokens')->fetchColumn()
        );
    }

    /**
     * A username is public - it shows in the navbar of whoever holds it - so
     * refusing a duplicate leaks nothing and is the kinder answer.
     */
    public function testATakenUsernameIsAnsweredPlainly(): void
    {
        $this->makeController('post', $this->body(['username' => self::TAKEN_USERNAME]))->signup();

        $this->assertSame('signup/index', $this->renderedView());
        $this->assertContains('That username is taken.', (array) $this->data['errors']);
        $this->assertCount(0, $this->mailer);
    }

    /* refusals */

    public function testASignupWithoutACsrfTokenCreatesNothing(): void
    {
        $before = $this->accountCount();

        $this->makeController('post', [
            'username' => self::NEW_USERNAME,
            'email' => self::NEW_EMAIL,
            'password' => self::PASSWORD,
            'passwordConfirm' => self::PASSWORD,
        ])->signup();

        $this->assertSame('signup/index', $this->renderedView());
        $this->assertSame($before, $this->accountCount());
        $this->assertCount(0, $this->mailer);
    }

    public function testAShortPasswordIsRefused(): void
    {
        $before = $this->accountCount();

        $this->makeController('post', $this->body(['password' => 'short', 'passwordConfirm' => 'short']))->signup();

        $this->assertSame('signup/index', $this->renderedView());
        $this->assertSame($before, $this->accountCount());
    }

    public function testAMismatchedConfirmationIsRefused(): void
    {
        $this->makeController('post', $this->body(['passwordConfirm' => 'something-else-entirely']))->signup();

        $this->assertSame('signup/index', $this->renderedView());
        $this->assertNull($this->accounts->findByEmail(self::NEW_EMAIL));
    }

    public function testTheSubmittedPasswordIsNeverHandedBack(): void
    {
        $this->makeController('post', $this->body(['username' => self::TAKEN_USERNAME]))->signup();

        // the address comes back so it need not be retyped; the username does
        // not, because that is the field that has to change
        $this->assertSame(self::NEW_EMAIL, (string) $this->data['email']);
        $this->assertSame('', (string) $this->data['username']);
        // and neither password field is handed back at all
        $this->assertFalse($this->data->has('password'));
        $this->assertFalse($this->data->has('passwordConfirm'));
    }

    public function testAnAlreadySignedInVisitorIsSentAway(): void
    {
        $this->makeController('get', [], [], isGuest: false)->form();

        $this->assertSame([], $this->view->renderCalls);
        $this->assertEquals(303, $this->output->getResponseCode());
    }

    /* confirming */

    public function testConfirmingActivatesTheAccount(): void
    {
        $this->makeController('post', $this->body())->signup();

        $link = (string) ($this->renderedWith('mail/signup-confirm')['link'] ?? '');
        preg_match('/token=([0-9a-f]{64})/', $link, $matches);

        $this->makeController('get', [], ['token' => $matches[1]])->confirm();

        $this->assertSame('signup/confirmed', $this->renderedView());
        $this->assertTrue($this->accounts->findByEmail(self::NEW_EMAIL)['is_active']);
    }

    /**
     * Confirmed, not signed in. Clicking a link in an inbox proves someone
     * reads that inbox - it is not evidence they know the password.
     */
    public function testConfirmingDoesNotStartASession(): void
    {
        $this->makeController('post', $this->body())->signup();

        $link = (string) ($this->renderedWith('mail/signup-confirm')['link'] ?? '');
        preg_match('/token=([0-9a-f]{64})/', $link, $matches);

        // a mock here, and only here: the assertion is that a call never happens
        $spy = $this->createMock(User::class);
        $spy->expects($this->never())->method('change');

        $this->userDouble = $spy;

        $this->makeController('get', [], ['token' => $matches[1]])->confirm();
    }

    public function testAConfirmationLinkWorksOnlyOnce(): void
    {
        $this->makeController('post', $this->body())->signup();

        $link = (string) ($this->renderedWith('mail/signup-confirm')['link'] ?? '');
        preg_match('/token=([0-9a-f]{64})/', $link, $matches);

        $this->makeController('get', [], ['token' => $matches[1]])->confirm();
        $this->makeController('get', [], ['token' => $matches[1]])->confirm();

        $this->assertSame('signup/expired', $this->renderedView());
        $this->assertEquals(410, $this->output->getResponseCode());
    }

    public function testAForgedConfirmationTokenActivatesNothing(): void
    {
        $this->makeController('post', $this->body())->signup();

        $this->makeController('get', [], ['token' => str_repeat('a', 64)])->confirm();

        $this->assertSame('signup/expired', $this->renderedView());
        $this->assertFalse($this->accounts->findByEmail(self::NEW_EMAIL)['is_active']);
    }

    /**
     * A reset token is not a confirmation token, even though both live in one
     * table.
     */
    public function testAPasswordResetTokenCannotConfirmAnAccount(): void
    {
        $this->makeController('post', $this->body())->signup();

        $id = $this->accounts->findByEmail(self::NEW_EMAIL)['id'];
        $resetToken = $this->tokens->issue($id, UserTokenModel::PURPOSE_PASSWORD_RESET);

        $this->makeController('get', [], ['token' => $resetToken])->confirm();

        $this->assertSame('signup/expired', $this->renderedView());
        $this->assertFalse($this->accounts->findByEmail(self::NEW_EMAIL)['is_active']);
    }
}
