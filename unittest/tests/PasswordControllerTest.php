<?php

declare(strict_types=1);

use application\models\LoginThrottleModel;
use application\login\controllers\PasswordController;
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
 * Forgetting a password without telling anybody who has an account.
 *
 * The assertion this file exists for is the dull-looking one: that a known
 * address and an unknown one produce the *same* page. It is dull because
 * nothing visible depends on it, which is exactly why it needs a test - the
 * natural, helpful-feeling change ("tell them we have no account with that
 * address") turns this form into an account enumerator, and would otherwise
 * break nothing that anyone would notice.
 *
 * TestSession and TestOutput come from SessionControllerTest, required
 * explicitly so this file also passes when run on its own.
 */
require_once __DIR__ . '/SessionControllerTest.php';

final class PasswordControllerTest extends unitTestHelper
{
    private const string ACTIVE_EMAIL = 'active@example.com';
    private const string INACTIVE_EMAIL = 'pending@example.com';
    private const string UNKNOWN_EMAIL = 'nobody@example.com';
    private const string NEW_PASSWORD = 'a-long-enough-passphrase';

    protected PDO $pdo;
    protected TestSession $session;
    protected TestOutput $output;
    protected Data $data;
    protected MockViewService $view;
    protected CollectorMailer $mailer;
    protected UserAccountModel $accounts;
    protected UserTokenModel $tokens;

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

        $pdo->exec(<<<'SQL'
            CREATE TABLE login_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                login TEXT NOT NULL,
                ip TEXT NOT NULL,
                attempted_at TEXT NOT NULL
            )
            SQL);

        $pdo->exec(
            "insert into orange_users (username, email, password, is_active) values"
            . " ('active', '" . self::ACTIVE_EMAIL . "', '', 1),"
            . " ('pending', '" . self::INACTIVE_EMAIL . "', '', 0)"
        );

        return $pdo;
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $query
     */
    protected function makeController(string $method = 'get', array $body = [], array $query = []): PasswordController
    {
        $input = Input::newInstance([
            'server' => [
                'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
                'REQUEST_METHOD' => strtoupper($method),
                'REMOTE_ADDR' => '203.0.113.5',
                'REQUEST_URI' => '/password/forgot',
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
        $entity->method('isGuest')->willReturn(true);

        $user = $this->createStub(User::class);
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
        $container->set('LoginThrottleModel', LoginThrottleModel::getInstance($this->pdo));

        return new PasswordController();
    }

    /** Render the form once and return the token it issued. */
    protected function issuedCsrfToken(): string
    {
        $this->makeController('get')->forgotForm();

        return (string) $this->data['csrfToken'];
    }

    /**
     * The page the visitor was shown.
     *
     * The last non-mail render: an action that sends mail renders the two email
     * bodies through the same view service first, so renderCalls[0] is a mail
     * body rather than the page whenever one went out.
     */
    protected function renderedView(): string
    {
        $pages = array_values(array_filter(
            $this->view->renderCalls,
            static fn(array $call): bool => !str_starts_with((string) $call['view'], 'mail/')
        ));

        return (string) ($pages === [] ? '' : $pages[array_key_last($pages)]['view']);
    }

    /**
     * The data one view was rendered with.
     *
     * MockViewService returns a marker string rather than real markup, so what
     * an email says is asserted on what was handed to the template - which is
     * where the controller's decisions actually are.
     *
     * @return array<string, mixed>
     */
    protected function renderedWith(string $view): array
    {
        foreach ($this->view->renderCalls as $call) {
            if ($call['view'] === $view) {
                return (array) $call['data'];
            }
        }

        return [];
    }

    /** The reset token out of the link the mail was built with. */
    protected function tokenFromMail(): string
    {
        $link = (string) ($this->renderedWith('mail/password-reset')['link'] ?? '');

        return preg_match('/token=([0-9a-f]{64})/', $link, $matches) === 1 ? $matches[1] : '';
    }

    /* the thing this file exists for */

    /**
     * A known address, an unknown one, and one whose account is not yet
     * confirmed all produce the same page carrying the same words. If this ever
     * fails, the form has become a way to ask which addresses have accounts.
     */
    public function testEveryAddressGetsTheSameAnswer(): void
    {
        $seen = [];

        foreach ([self::ACTIVE_EMAIL, self::INACTIVE_EMAIL, self::UNKNOWN_EMAIL] as $email) {
            $token = $this->issuedCsrfToken();

            $this->makeController('post', ['_token' => $token, 'email' => $email])->forgot();

            $seen[$email] = [$this->renderedView(), (string) $this->data['message']];
        }

        $this->assertSame($seen[self::ACTIVE_EMAIL], $seen[self::UNKNOWN_EMAIL]);
        $this->assertSame($seen[self::ACTIVE_EMAIL], $seen[self::INACTIVE_EMAIL]);
        $this->assertSame('password/sent', $seen[self::ACTIVE_EMAIL][0]);
    }

    public function testOnlyARealActiveAccountIsActuallyMailed(): void
    {
        foreach ([self::UNKNOWN_EMAIL, self::INACTIVE_EMAIL] as $email) {
            $token = $this->issuedCsrfToken();

            $this->makeController('post', ['_token' => $token, 'email' => $email])->forgot();

            // the page said the same thing; the difference is only ever here
            $this->assertCount(0, $this->mailer, $email . ' should not be mailed');
        }

        $token = $this->issuedCsrfToken();
        $this->makeController('post', ['_token' => $token, 'email' => self::ACTIVE_EMAIL])->forgot();

        $this->assertCount(1, $this->mailer->sentTo(self::ACTIVE_EMAIL));
    }

    public function testTheMailCarriesAWorkingLink(): void
    {
        $token = $this->issuedCsrfToken();

        $this->makeController('post', ['_token' => $token, 'email' => self::ACTIVE_EMAIL])->forgot();

        $this->assertSame('Reset your password', $this->mailer->last()?->subject);

        $link = (string) ($this->renderedWith('mail/password-reset')['link'] ?? '');

        // absolute, because a mail is read outside the session that caused it -
        // a relative path in one resolves against nothing
        $this->assertStringStartsWith('http://example.test/password_reset?token=', $link);
        // and the token in it is one the reset form will actually accept
        $this->assertTrue($this->tokens->isUsable($this->tokenFromMail(), UserTokenModel::PURPOSE_PASSWORD_RESET));
    }

    public function testBothBodiesAreSent(): void
    {
        $token = $this->issuedCsrfToken();

        $this->makeController('post', ['_token' => $token, 'email' => self::ACTIVE_EMAIL])->forgot();

        // a text part is what a plain-text client and a screen reader read
        $this->assertNotSame('', (string) $this->mailer->last()?->html);
        $this->assertNotSame('', (string) $this->mailer->last()?->text);
    }

    /* refusals on the way in */

    public function testAForgotRequestWithoutATokenIsRefusedAndMailsNothing(): void
    {
        $this->makeController('post', ['email' => self::ACTIVE_EMAIL])->forgot();

        $this->assertSame('password/forgot', $this->renderedView());
        $this->assertCount(0, $this->mailer);
    }

    public function testAnEmptyAddressIsAnsweredPlainly(): void
    {
        $token = $this->issuedCsrfToken();

        $this->makeController('post', ['_token' => $token, 'email' => ''])->forgot();

        // this one describes the form, not the account, so it can be specific
        $this->assertSame('password/forgot', $this->renderedView());
        $this->assertStringContainsString('enter your email', (string) $this->data['error']);
        $this->assertCount(0, $this->mailer);
    }

    /**
     * A reset can set a password, so it is a login by another name and answers
     * to the same counter.
     */
    public function testTheRequestIsThrottledLikeALogin(): void
    {
        for ($attempt = 0; $attempt < LoginThrottleModel::MAX_PER_LOGIN; $attempt++) {
            $token = $this->issuedCsrfToken();
            $this->makeController('post', ['_token' => $token, 'email' => self::ACTIVE_EMAIL])->forgot();
        }

        $token = $this->issuedCsrfToken();
        $this->makeController('post', ['_token' => $token, 'email' => self::ACTIVE_EMAIL])->forgot();

        $this->assertSame('password/forgot', $this->renderedView());
        $this->assertStringContainsString('Too many attempts', (string) $this->data['error']);
        $this->assertCount(0, $this->mailer);
    }

    /* the form behind the link */

    public function testTheResetFormRendersForALiveToken(): void
    {
        $token = $this->tokens->issue(1, UserTokenModel::PURPOSE_PASSWORD_RESET);

        $this->makeController('get', [], ['token' => $token])->resetForm();

        $this->assertSame('password/reset', $this->renderedView());
        // rendering the form must not spend the token the POST still needs
        $this->assertTrue($this->tokens->isUsable($token, UserTokenModel::PURPOSE_PASSWORD_RESET));
    }

    public function testAForgedTokenGetsTheExpiredPage(): void
    {
        $this->makeController('get', [], ['token' => str_repeat('a', 64)])->resetForm();

        $this->assertSame('password/expired', $this->renderedView());
        $this->assertEquals(410, $this->output->getResponseCode());
    }

    /**
     * The token survives a request that was never going to succeed, so a typo
     * does not cost the visitor their link.
     */
    public function testARejectedPasswordDoesNotSpendTheToken(): void
    {
        $resetToken = $this->tokens->issue(1, UserTokenModel::PURPOSE_PASSWORD_RESET);
        $csrf = $this->issuedCsrfToken();

        $this->makeController('post', [
            '_token' => $csrf,
            'token' => $resetToken,
            'password' => 'short',
            'passwordConfirm' => 'short',
        ])->reset();

        $this->assertSame('password/reset', $this->renderedView());
        $this->assertTrue($this->tokens->isUsable($resetToken, UserTokenModel::PURPOSE_PASSWORD_RESET));
    }

    public function testAMismatchedConfirmationIsRefused(): void
    {
        $resetToken = $this->tokens->issue(1, UserTokenModel::PURPOSE_PASSWORD_RESET);
        $csrf = $this->issuedCsrfToken();

        $this->makeController('post', [
            '_token' => $csrf,
            'token' => $resetToken,
            'password' => self::NEW_PASSWORD,
            'passwordConfirm' => 'something-else-entirely',
        ])->reset();

        $this->assertSame('password/reset', $this->renderedView());
        $this->assertTrue($this->tokens->isUsable($resetToken, UserTokenModel::PURPOSE_PASSWORD_RESET));
    }

    public function testAGoodResetChangesThePasswordAndSpendsTheToken(): void
    {
        $resetToken = $this->tokens->issue(1, UserTokenModel::PURPOSE_PASSWORD_RESET);
        $csrf = $this->issuedCsrfToken();

        $this->makeController('post', [
            '_token' => $csrf,
            'token' => $resetToken,
            'password' => self::NEW_PASSWORD,
            'passwordConfirm' => self::NEW_PASSWORD,
        ])->reset();

        $this->assertSame('password/done', $this->renderedView());

        $stored = (string) $this->pdo->query('select password from orange_users where id = 1')->fetchColumn();

        $this->assertTrue(password_verify(self::NEW_PASSWORD, $stored));
        $this->assertFalse($this->tokens->isUsable($resetToken, UserTokenModel::PURPOSE_PASSWORD_RESET));
    }

    /**
     * Someone has just taken their account back; any other live link into it is
     * a way for whoever caused this to follow them in.
     */
    public function testAGoodResetKillsEveryOtherOutstandingLink(): void
    {
        $this->tokens->issue(1, UserTokenModel::PURPOSE_PASSWORD_RESET);

        // a second request arrives; both links exist as far as an inbox knows
        $resetToken = $this->tokens->issue(1, UserTokenModel::PURPOSE_PASSWORD_RESET);
        $csrf = $this->issuedCsrfToken();

        $this->makeController('post', [
            '_token' => $csrf,
            'token' => $resetToken,
            'password' => self::NEW_PASSWORD,
            'passwordConfirm' => self::NEW_PASSWORD,
        ])->reset();

        $outstanding = (int) $this->pdo->query(
            "select count(*) from user_tokens where user_id = 1 and purpose = 'password_reset'"
        )->fetchColumn();

        $this->assertSame(0, $outstanding);
    }

    public function testAResetWithoutACsrfTokenChangesNothing(): void
    {
        $resetToken = $this->tokens->issue(1, UserTokenModel::PURPOSE_PASSWORD_RESET);
        $before = (string) $this->pdo->query('select password from orange_users where id = 1')->fetchColumn();

        $this->makeController('post', [
            'token' => $resetToken,
            'password' => self::NEW_PASSWORD,
            'passwordConfirm' => self::NEW_PASSWORD,
        ])->reset();

        $after = (string) $this->pdo->query('select password from orange_users where id = 1')->fetchColumn();

        $this->assertSame($before, $after);
        $this->assertTrue($this->tokens->isUsable($resetToken, UserTokenModel::PURPOSE_PASSWORD_RESET));
    }

    /**
     * A confirmation token is not a reset token, even though both live in one
     * table - the purpose is matched, not merely recorded.
     */
    public function testASignupTokenCannotResetAPassword(): void
    {
        $confirmToken = $this->tokens->issue(1, UserTokenModel::PURPOSE_SIGNUP_CONFIRM);

        $this->makeController('get', [], ['token' => $confirmToken])->resetForm();

        $this->assertSame('password/expired', $this->renderedView());
    }
}
