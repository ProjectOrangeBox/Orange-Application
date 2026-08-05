<?php

declare(strict_types=1);

namespace application\login\controllers;

use application\models\LoginThrottleModel;
use application\controllers\WebController;
use application\login\models\ResetPasswordDto;
use application\login\models\SignupDto;
use application\login\models\UserAccountModel;
use application\login\models\UserTokenModel;
use orange\framework\attributes\AttachService;
use orange\framework\attributes\Route;
use orange\mail\exceptions\Mail;
use orange\mail\MailDto;
use orange\mail\MailerInterface;

/**
 * Forgetting a password, and choosing a new one.
 *
 *   GET  /password/forgot   the "what is your email" form
 *   POST /password/forgot   issue a token and mail the link - always
 *   GET  /password/reset    the form behind the link, if the token is live
 *   POST /password/reset    spend the token and set the password
 *
 * Two rules run through all of it.
 *
 * **The response never depends on whether the account exists.** A reset form
 * that says "no account with that address" is an account enumerator with a
 * friendly face: point it at a list of addresses and it tells you which of your
 * users have accounts here. So an unknown address gets the same page, after the
 * same work, and simply has no mail sent. The visitor who genuinely mistyped
 * their address is told what to do about it on that page instead.
 *
 * **A reset is a login.** Anything that can set a password can take an account,
 * so this flow answers to the same throttle the login form does, counted against
 * the same window - otherwise the cheapest way past a rate-limited login would
 * be to attack the thing next to it.
 */
class PasswordController extends WebController
{
    /** Said to everyone who submits the forgot form, whatever happened next. */
    protected const string SENT_MESSAGE = 'If that address has an account, a link to reset the password is on its way.';

    #[AttachService('mail')]
    protected MailerInterface $mail;

    #[AttachService('UserAccountModel')]
    protected UserAccountModel $accounts;

    #[AttachService('UserTokenModel')]
    protected UserTokenModel $tokens;

    #[AttachService('LoginThrottleModel')]
    protected LoginThrottleModel $throttle;

    #[Route('get', '/password/forgot', 'password_forgot')]
    public function forgotForm(): string
    {
        return $this->forgotPage();
    }

    #[Route('post', '/password/forgot', 'password_forgot_submit')]
    public function forgot(): string
    {
        if (!$this->csrfValid()) {
            return $this->forgotPage('That form was no longer valid. Please try again.');
        }

        $email = mb_strtolower(trim($this->requestString('email')));
        $ip = $this->clientIp();

        if (($retryAfter = $this->throttle->retryAfter($email, $ip)) > 0) {
            // asked before the account lookup, so a throttled request costs no
            // query - and, more to the point, cannot be used to time one
            logMsg('WARNING', 'password reset throttled', ['login' => $email, 'ip' => $ip, 'retry_after' => $retryAfter]);

            return $this->forgotPage('Too many attempts. Try again in ' . ceil($retryAfter / 60) . ' minute(s).');
        }

        if ($email === '') {
            return $this->forgotPage('Please enter your email address.');
        }

        // counted whether or not the address exists, because counting only the
        // misses would itself be the oracle this flow refuses to be
        $this->throttle->recordFailure($email, $ip);

        $account = $this->accounts->findByEmail($email);

        if ($account === null) {
            logMsg('NOTICE', 'password reset for unknown address', ['login' => $email, 'ip' => $ip]);
        } elseif (!$account['is_active']) {
            // an unconfirmed signup: resetting would activate an account whose
            // address was never proven, so the confirmation flow owns this case
            logMsg('NOTICE', 'password reset for inactive account', ['user_id' => $account['id'], 'ip' => $ip]);
        } else {
            $this->sendResetLink($account['id'], $account['username'], $email);
        }

        // one page, three paths - see the class docblock
        return $this->sentPage();
    }

    #[Route('get', '/password/reset', 'password_reset')]
    public function resetForm(): string
    {
        // the emailed link's query parameter. The POST below reads its own copy
        // from the form body, where resetPage() re-planted it as a hidden field.
        $token = $this->queryString('token');

        if (!$this->tokens->isUsable($token, UserTokenModel::PURPOSE_PASSWORD_RESET)) {
            return $this->expiredPage();
        }

        return $this->resetPage($token);
    }

    #[Route('post', '/password/reset', 'password_reset_submit')]
    public function reset(): string
    {
        $token = $this->requestString('token');

        if (!$this->csrfValid()) {
            return $this->resetPage($token, ['That form was no longer valid. Please try again.']);
        }

        $request = new ResetPasswordDto((array) $this->input->request());

        // shape first, so a token is not spent on a request that was never
        // going to succeed - the visitor gets the form back with their link
        // still working
        if (!$request->isValid()) {
            return $this->resetPage($token, $this->flattenErrors($request->errors()));
        }

        $userId = $this->tokens->consume($token, UserTokenModel::PURPOSE_PASSWORD_RESET);

        if ($userId === null) {
            return $this->expiredPage();
        }

        $this->accounts->updatePassword($userId, $request->password);

        // any other outstanding reset link is now a way back into an account
        // whose owner has just taken it back
        $this->tokens->invalidateFor($userId, UserTokenModel::PURPOSE_PASSWORD_RESET);

        // and the throttle counter goes with it: the person has proved they
        // hold the address, so the failures spent getting here are not theirs
        // to keep paying for
        $this->throttle->clear($this->accounts->emailFor($userId));

        $this->rotateCsrfToken();

        logMsg('INFO', 'password reset completed', ['user_id' => $userId, 'ip' => $this->clientIp()]);

        return $this->donePage();
    }

    /**
     * Issue a token and mail the link.
     *
     * A mail failure is logged and swallowed rather than shown. The visitor has
     * already been told the same thing everyone is told, and changing that now
     * would leak - "the mail server broke" is only ever said to someone whose
     * address really does have an account.
     */
    protected function sendResetLink(int $userId, string $username, string $email): void
    {
        $token = $this->tokens->issue($userId, UserTokenModel::PURPOSE_PASSWORD_RESET);

        $link = $this->absoluteUrl($this->router->getUrl('password_reset') . '?token=' . urlencode($token));

        $data = [
            'username' => $username,
            'link' => $link,
            'minutes' => UserTokenModel::PASSWORD_RESET_TTL / 60,
        ];

        try {
            $this->mail->send(new MailDto([
                'to' => $email,
                'subject' => 'Reset your password',
                // ordinary views, so an email template overrides per module
                // exactly like a page does
                'html' => $this->renderView('mail/password-reset', $data),
                'text' => $this->renderView('mail/password-reset-text', $data),
            ]));
        } catch (Mail $e) {
            logMsg('ERROR', 'password reset mail failed', ['user_id' => $userId, 'reason' => $e->getMessage()]);
        }
    }

    /* pages */

    protected function forgotPage(string $error = ''): string
    {
        $this->chrome('Forgot Password');

        $this->data->merge(['error' => $error]);

        return $this->renderView('password/forgot');
    }

    protected function sentPage(): string
    {
        $this->chrome('Check Your Email');

        $this->data->merge(['message' => self::SENT_MESSAGE]);

        return $this->renderView('password/sent');
    }

    /**
     * @param list<string> $errors
     */
    protected function resetPage(string $token, array $errors = []): string
    {
        $this->chrome('Choose a New Password');

        $this->data->merge([
            'token' => $token,
            'errors' => $errors,
            'minLength' => SignupDto::MIN_PASSWORD_LENGTH,
        ]);

        return $this->renderView('password/reset');
    }

    protected function expiredPage(): string
    {
        $this->chrome('Link No Longer Valid');

        // 410 rather than 404: the link was real, and is not any more. A
        // forged token lands here too and is told the same thing.
        $this->output->responseCode(410);

        $this->data->merge(['forgotUrl' => $this->router->getUrl('password_forgot')]);

        return $this->renderView('password/expired');
    }

    protected function donePage(): string
    {
        $this->chrome('Password Changed');

        $this->data->merge(['loginUrl' => $this->router->getUrl('login')]);

        return $this->renderView('password/done');
    }
}
