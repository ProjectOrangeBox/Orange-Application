<?php

declare(strict_types=1);

namespace application\login\controllers;

use application\models\LoginThrottleModel;
use application\controllers\WebController;
use orange\acl\User;
use orange\acl\interfaces\UserEntityInterface;
use orange\auth\Auth;
use orange\auth\AuthError;
use orange\framework\attributes\AttachService;
use orange\framework\attributes\Route;

/**
 * The browser's way in and out.
 *
 *   GET  /login    the form
 *   POST /login    check the credentials, then redirect
 *   POST /logout   drop the session, then redirect
 *
 * Deliberately the same machinery as application\api\controllers\AuthController
 * rather than a second implementation of it: the same Auth, the same
 * LoginThrottleModel, the same acl User. A form login that skipped the throttle
 * would be a way around it - the endpoint an attacker scripts is whichever one
 * counts least, so both have to count the same failures in the same place.
 *
 * What differs is only what a browser needs: a CSRF token, and a redirect
 * instead of a status code.
 */
class SessionController extends WebController
{
    /**
     * One message for every credential failure - see AuthController, which
     * withholds the same distinction for the same reason. The inactive-account
     * message in particular is only reachable after the password has already
     * been verified.
     */
    protected const string LOGIN_FAILED_MESSAGE = 'Those credentials are not correct.';

    #[AttachService('auth')]
    protected Auth $auth;

    #[AttachService('LoginThrottleModel')]
    protected LoginThrottleModel $throttle;

    /**
     * Attached here rather than inherited from WebController, which resolves it
     * lazily so a page that never mentions a user survives having no accounts
     * database. This controller is not such a page: logging someone in and out
     * IS the accounts database, and it already attaches two other things backed
     * by the same connection. Declaring it says so.
     */
    #[AttachService('user')]
    protected User $user;

    #[Route('get', '/login', 'login')]
    public function form(): string
    {
        // Already logged in? The form has nothing to offer. No accounts at all
        // is not that case - it falls through and the form renders, which is
        // where the failure to reach them is the honest thing to report.
        $entity = $this->currentUser();

        if ($entity instanceof UserEntityInterface && !$entity->isGuest()) {
            return $this->redirect($this->router->getUrl('dashboard'));
        }

        return $this->loginPage();
    }

    #[Route('post', '/login', 'login_submit')]
    public function login(): string
    {
        if (!$this->csrfValid()) {
            // Not "your session expired" as an accusation - it is the usual
            // cause (a form left open until the session went away) and the
            // visitor can act on it. A genuine cross-site attempt lands here
            // too and is told nothing useful.
            return $this->loginPage('That form was no longer valid. Please try again.');
        }

        $request = (array) $this->input->request();
        $email = is_string($request['email'] ?? null) ? $request['email'] : '';
        $password = is_string($request['password'] ?? null) ? $request['password'] : '';

        // normalized the way auth looks accounts up, so one account cannot get
        // several allowances by being typed differently
        $login = mb_strtolower(trim($email));
        $ip = $this->clientIp();

        if (($retryAfter = $this->throttle->retryAfter($login, $ip)) > 0) {
            // asked before the password is checked, so a throttled attempt
            // costs neither a query nor a bcrypt verify
            logMsg('WARNING', 'form login throttled', ['login' => $login, 'ip' => $ip, 'retry_after' => $retryAfter]);

            return $this->loginPage(
                'Too many attempts. Try again in ' . ceil($retryAfter / 60) . ' minute(s).',
                $email
            );
        }

        if (!$this->auth->login($email, $password)) {
            $errorCode = $this->auth->errorCode();

            // an empty field never reached a hash, so it is not a guess - and
            // counting it would let anyone spend a victim's whole allowance
            // without knowing anything about their password
            if ($errorCode !== AuthError::EmptyFields) {
                $this->throttle->recordFailure($login, $ip);
            }

            logMsg('NOTICE', 'form login failed', ['login' => $login, 'ip' => $ip, 'reason' => $errorCode->name]);

            // the email is handed back so the visitor does not retype it; the
            // password never is
            return $this->loginPage($this->failureMessage($errorCode), $email);
        }

        // hands auth's proven id to the session-aware User, which regenerates
        // the session id as it stores it - a session fixed before login is
        // worthless after it
        $this->user->change($this->auth->userId());

        $this->throttle->clear($login);
        $this->rotateCsrfToken();

        logMsg('INFO', 'form login succeeded', ['login' => $login, 'ip' => $ip, 'user_id' => $this->auth->userId()]);

        return $this->redirect($this->takeReturnUrl());
    }

    /**
     * POST, never GET.
     *
     * A GET that logs you out can be fired by any <img src="/logout"> on any
     * page on the internet - harmless as attacks go, but it is a state change,
     * and state changes answer to POST and a token like every other.
     */
    #[Route('post', '/logout', 'logout')]
    public function logout(): string
    {
        if ($this->csrfValid()) {
            $this->user->logout();
            $this->rotateCsrfToken();
        }

        return $this->redirect($this->router->getUrl('home'));
    }

    /**
     * Render the form, optionally with something to say about the last attempt.
     */
    protected function loginPage(string $error = '', string $email = ''): string
    {
        $this->chrome('Log In');

        $this->data->merge([
            'error' => $error,
            'email' => $email,
            // shown on the form so the example is usable straight from a fresh
            // seed. This is public example data, not a secret - see AclSeeder.
            'demoEmail' => 'admin@example.com',
            'demoPassword' => 'orange123',
        ]);

        return $this->renderView('session/login');
    }

    /**
     * Everything about an account collapses to one message; only "you left a
     * field blank", which describes the form rather than the account, is
     * answered plainly.
     */
    protected function failureMessage(AuthError $errorCode): string
    {
        return $errorCode === AuthError::EmptyFields
            ? 'Please fill in both fields.'
            : self::LOGIN_FAILED_MESSAGE;
    }

    /**
     * Where to send someone who has just logged in: back to whatever they were
     * refused, or the dashboard.
     *
     * Consumed rather than read - it applies to this login only, and leaving it
     * behind would bounce the *next* login somewhere surprising.
     */
    protected function takeReturnUrl(): string
    {
        $returnTo = $this->session->get(self::RETURN_SESSION_KEY);

        $this->session->remove(self::RETURN_SESSION_KEY);

        // Only ever a path this application stored itself. The leading-slash
        // test is belt and braces - nothing user-supplied reaches this key -
        // and "//evil.com" is a host, not a path, which is why it is excluded
        // explicitly.
        if (is_string($returnTo) && str_starts_with($returnTo, '/') && !str_starts_with($returnTo, '//')) {
            return $returnTo;
        }

        return $this->router->getUrl('dashboard');
    }
}
