<?php

declare(strict_types=1);

namespace application\login\controllers;

use application\controllers\WebController;
use application\login\models\SignupDto;
use application\login\models\UserAccountModel;
use application\login\models\UserTokenModel;
use orange\acl\interfaces\UserEntityInterface;
use orange\framework\attributes\AttachService;
use orange\framework\attributes\Route;
use orange\mail\exceptions\Mail;
use orange\mail\MailDto;
use orange\mail\MailerInterface;
use PDOException;

/**
 * Making an account, and proving the address on it.
 *
 *   GET  /signup           the form
 *   POST /signup           create the account, inactive, and mail a link
 *   GET  /signup/confirm   spend the link's token and turn the account on
 *
 * **An account starts unusable.** It is created with is_active = 0 and only
 * confirming the address turns it on. That is the whole reason the mail is
 * worth sending: if a new account could log in before confirming, anyone could
 * sign up as anyone, and the address on the account would be decoration.
 * orange/acl already refuses an inactive account, so this needs no second gate.
 *
 * **The address is not answered as a yes or no.** A signup form that says "that
 * email is already registered" is the same account enumerator the reset form
 * refuses to be, reached from the other side. So a taken address gets the same
 * "check your email" page as a new one - and the mail that goes out says
 * somebody tried to sign up with an address that already has an account, which
 * is genuinely useful to the person who owns it and useless to whoever probed.
 *
 * The username is different and *is* answered plainly - see
 * UserAccountModel::usernameTaken().
 *
 * A new account gets no role at all, which is deliberate rather than an
 * omission: the dashboard then shows three permission checks all answering No,
 * next to the seeded admin's three Yeses. That contrast is the demonstration.
 */
class SignupController extends WebController
{
    /** Said whether the address was free, taken, or malformed at the database level. */
    protected const string CHECK_EMAIL_MESSAGE = 'Check your email - if we could use that address, a confirmation link is on its way.';

    #[AttachService('mail')]
    protected MailerInterface $mail;

    #[AttachService('UserAccountModel')]
    protected UserAccountModel $accounts;

    #[AttachService('UserTokenModel')]
    protected UserTokenModel $tokens;

    #[Route('get', '/signup', 'signup')]
    public function form(): string
    {
        // already signed in? there is nothing here for you. No accounts to ask
        // is not that - see SessionController::form(), same reasoning.
        $entity = $this->currentUser();

        if ($entity instanceof UserEntityInterface && !$entity->isGuest()) {
            return $this->redirect($this->router->getUrl('dashboard'));
        }

        return $this->signupPage();
    }

    #[Route('post', '/signup', 'signup_submit')]
    public function signup(): string
    {
        if (!$this->csrfValid()) {
            return $this->signupPage(['That form was no longer valid. Please try again.']);
        }

        $request = new SignupDto((array) $this->input->request());

        if (!$request->isValid()) {
            return $this->signupPage($this->flattenErrors($request->errors()), $request->username, $request->email);
        }

        // The one thing answered plainly, because a username is public anyway -
        // it shows in the navbar of whoever holds it.
        if ($this->accounts->usernameTaken($request->username)) {
            return $this->signupPage(['That username is taken.'], '', $request->email);
        }

        $email = mb_strtolower(trim($request->email));

        if ($this->accounts->emailTaken($email)) {
            // Same page as success, and a mail that tells the real owner what
            // happened. Whoever submitted this learns nothing either way.
            $this->sendAddressInUse($email);

            logMsg('NOTICE', 'signup for an address already in use', ['login' => $email, 'ip' => $this->clientIp()]);

            return $this->checkEmailPage();
        }

        try {
            $userId = $this->accounts->createPending($request->username, $email, $request->password);
        } catch (PDOException $e) {
            // The unique index, not this code, is what actually guarantees one
            // account per address - emailTaken() above can lose the race with a
            // simultaneous signup. Landing here means it did, which for the
            // visitor is the same situation as the branch above.
            logMsg('NOTICE', 'signup lost the race for an address', ['login' => $email, 'reason' => $e->getMessage()]);

            return $this->checkEmailPage();
        }

        $this->sendConfirmation($userId, $request->username, $email);

        logMsg('INFO', 'signup created a pending account', ['user_id' => $userId, 'login' => $email]);

        return $this->checkEmailPage();
    }

    #[Route('get', '/signup/confirm', 'signup_confirm')]
    public function confirm(): string
    {
        // a query parameter, not a body field - the token arrived in a URL
        $token = $this->queryString('token');

        $userId = $this->tokens->consume($token, UserTokenModel::PURPOSE_SIGNUP_CONFIRM);

        if ($userId === null) {
            return $this->expiredPage();
        }

        $this->accounts->activate($userId);

        logMsg('INFO', 'signup confirmed', ['user_id' => $userId, 'ip' => $this->clientIp()]);

        // Confirmed, not logged in. Clicking a link in an email is evidence
        // that someone reads that inbox, which is what was being proved - it is
        // not evidence they know the password, so it does not buy a session.
        return $this->confirmedPage();
    }

    /**
     * Mail the confirmation link.
     */
    protected function sendConfirmation(int $userId, string $username, string $email): void
    {
        $token = $this->tokens->issue($userId, UserTokenModel::PURPOSE_SIGNUP_CONFIRM);

        $data = [
            'username' => $username,
            'link' => $this->absoluteUrl($this->router->getUrl('signup_confirm') . '?token=' . urlencode($token)),
            'hours' => UserTokenModel::SIGNUP_CONFIRM_TTL / 3600,
        ];

        $this->trySend($email, 'Confirm your account', 'mail/signup-confirm', $data, $userId);
    }

    /**
     * Tell the address's real owner that someone tried to sign up with it.
     *
     * No token and no link: there is nothing for them to do, and a link in this
     * message would be a way to act on an account at the request of whoever
     * probed the address. It exists so the person who owns the account hears
     * about the attempt.
     */
    protected function sendAddressInUse(string $email): void
    {
        $data = ['loginUrl' => $this->absoluteUrl($this->router->getUrl('login'))];

        $this->trySend($email, 'Someone tried to sign up with your address', 'mail/signup-in-use', $data);
    }

    /**
     * Render both bodies and send, swallowing a transport failure.
     *
     * Swallowed for the reason the whole flow is uniform: the visitor has
     * already been told what everyone is told, and "the mail server broke"
     * would only ever be said to someone whose address really was usable.
     *
     * @param array<string, mixed> $data
     */
    protected function trySend(string $email, string $subject, string $view, array $data, ?int $userId = null): void
    {
        try {
            $this->mail->send(new MailDto([
                'to' => $email,
                'subject' => $subject,
                'html' => $this->renderView($view, $data),
                'text' => $this->renderView($view . '-text', $data),
            ]));
        } catch (Mail $e) {
            logMsg('ERROR', 'signup mail failed', ['user_id' => $userId, 'view' => $view, 'reason' => $e->getMessage()]);
        }
    }

    /* pages */

    /**
     * @param list<string> $errors
     */
    protected function signupPage(array $errors = [], string $username = '', string $email = ''): string
    {
        $this->chrome('Sign Up');

        $this->data->merge([
            'errors' => $errors,
            // handed back so nothing has to be retyped; the passwords never are
            'username' => $username,
            'email' => $email,
            'minLength' => SignupDto::MIN_PASSWORD_LENGTH,
        ]);

        return $this->renderView('signup/index');
    }

    protected function checkEmailPage(): string
    {
        $this->chrome('Check Your Email');

        $this->data->merge(['message' => self::CHECK_EMAIL_MESSAGE]);

        return $this->renderView('signup/check-email');
    }

    protected function confirmedPage(): string
    {
        $this->chrome('Account Confirmed');

        $this->data->merge(['loginUrl' => $this->router->getUrl('login')]);

        return $this->renderView('signup/confirmed');
    }

    protected function expiredPage(): string
    {
        $this->chrome('Link No Longer Valid');

        $this->output->responseCode(410);

        $this->data->merge(['signupUrl' => $this->router->getUrl('signup')]);

        return $this->renderView('signup/expired');
    }
}
