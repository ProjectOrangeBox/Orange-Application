<?php

declare(strict_types=1);

namespace application\api\controllers;

use application\api\models\LoginThrottleModel;
use orange\acl\User;
use orange\auth\Auth;
use orange\auth\AuthError;
use orange\framework\attributes\AttachService;
use orange\framework\attributes\Route;
use orange\framework\controllers\JsonController;
use orange\framework\interfaces\OutputInterface;

/**
 * Session endpoints.
 *
 *   POST /api/login   {email, password} -> 200 {id, username, permissions}
 *                                          401 {"msg": ...}
 *                                          415 {"msg": ...} not sent as JSON
 *                                          429 {"msg": ...} + Retry-After
 *   POST /api/logout                    -> 204
 *   GET  /api/me                        -> 200 {id, username, permissions}
 *
 * Lives here rather than in the orders module even though orders is what
 * currently guards anything: authentication is the application's, not one
 * module's. The orders module needs no reference to this controller - it reads
 * the same 'user' service, which is how HMVC modules are meant to meet.
 *
 * Two packages, deliberately separate. orange/auth answers "is this the right
 * password", and knows nothing about sessions or permissions. orange/acl
 * answers "what may user N do", and never sees a password. The glue is the one
 * line in login() that hands auth's user id to the session-aware User.
 *
 * What this controller adds on top of those two is everything neither of them
 * can see, because it is a property of the *request* rather than the
 * credential: how often this address has been guessing, whether the request
 * looks like it came from this application at all, and what belongs in the log
 * afterwards. orange/auth says as much in its own docblock - rate limiting
 * needs state shared across requests and is the application layer's job.
 */
class AuthController extends JsonController
{
    /**
     * The one message every credential failure gets.
     *
     * orange/auth already returns the same string for an unknown account and a
     * wrong password, but not for an inactive one ("Your user is not active."),
     * and that message is only ever reached *after* password_verify() succeeds -
     * so quoting it back would confirm both that the address exists and that the
     * password submitted was the right one. The distinction is real and worth
     * having; it belongs in the log, not the response. Kept identical to auth's
     * own 'general error' so the two paths cannot be told apart.
     */
    protected const string LOGIN_FAILED_MESSAGE = 'Login Error.';

    #[AttachService('auth')]
    protected Auth $auth;

    #[AttachService('user')]
    protected User $user;

    #[AttachService('LoginThrottleModel')]
    protected LoginThrottleModel $throttle;

    #[Route('post', '/api/login', 'auth_login')]
    public function login(): string
    {
        $this->noStore();

        // A browser will send a cross-origin form or fetch() with a simple
        // content type without asking anyone's permission; application/json is
        // not simple, so requiring it forces a preflight, which the CORS config
        // then answers for exactly the origins it lists. That is what stands
        // between this endpoint and login CSRF - an attacker silently logging a
        // visitor into an account the attacker controls, so that whatever they
        // do next is recorded against it. Cheap here because every caller of
        // this API already speaks JSON.
        if (!$this->isJsonRequest()) {
            return $this->failure(415, 'Expected Content-Type: application/json.');
        }

        $request = (array) $this->input->request();
        $email = is_string($request['email'] ?? null) ? $request['email'] : '';
        $password = is_string($request['password'] ?? null) ? $request['password'] : '';

        // Counted by the same normalized form auth looks the account up by, or
        // ' Bob@Example.com ' and 'bob@example.com' would be two counters
        // against one account and the limit would be worth twice what it says.
        $login = mb_strtolower(trim($email));
        $ip = $this->clientIp();

        if (($retryAfter = $this->throttle->retryAfter($login, $ip)) > 0) {
            // Refused before the password is ever checked, so a throttled
            // attempt costs neither a query nor a bcrypt verify.
            $this->output->header('Retry-After: ' . $retryAfter, OutputInterface::REPLACEALL);

            $this->log('WARNING', 'login throttled', $login, $ip, ['retry_after' => $retryAfter]);

            return $this->failure(429, 'Too many login attempts. Try again later.');
        }

        if (!$this->auth->login($email, $password)) {
            $errorCode = $this->auth->errorCode();

            // An empty field never reached the database or a hash, so it is not
            // a guess and must not count toward the limit - otherwise anyone
            // could spend a victim's whole allowance on blank passwords.
            if ($errorCode !== AuthError::EmptyFields) {
                $this->throttle->recordFailure($login, $ip);
            }

            // The reason, which the response deliberately withholds, is exactly
            // what makes the log worth keeping: UnknownUser repeating across
            // many logins from one address is credential stuffing, BadPassword
            // against one login is a guess at that account.
            $this->log('NOTICE', 'login failed', $login, $ip, ['reason' => $errorCode->name]);

            return $this->failure(401, $this->loginFailureMessage($errorCode));
        }

        // The handoff between the two packages: auth has proved who they are,
        // User puts that id in the session so later requests are authorized
        // without re-checking a password. change() regenerates the session id
        // as it does so, which is what stops a fixed pre-login id from being
        // usable afterwards.
        $this->user->change($this->auth->userId());

        // Only now, so a successful login clears its own failures but a
        // throttled one can never clear them by succeeding later in the window.
        $this->throttle->clear($login);

        $this->log('INFO', 'login succeeded', $login, $ip, ['user_id' => $this->auth->userId()]);

        $this->data->merge($this->currentUser());

        return $this->response(200);
    }

    #[Route('post', '/api/logout', 'auth_logout')]
    public function logout(): string
    {
        $this->noStore();

        $this->user->logout();

        return $this->noContentResponse();
    }

    /**
     * Who the current request is, logged in or not.
     *
     * Always 200: "nobody" is a real answer and the front end needs it to
     * decide what to render. The guest user is what User falls back to.
     */
    #[Route('get', '/api/me', 'auth_me')]
    public function me(): string
    {
        $this->noStore();

        $this->data->merge($this->currentUser());

        return $this->response(200);
    }

    /**
     * @return array<string, mixed>
     */
    protected function currentUser(): array
    {
        $entity = $this->user->load();

        return [
            'id' => $entity->id,
            'username' => $entity->username,
            // Sent so the client can hide what it must not offer. This is a
            // convenience, never the check - every guarded endpoint asks the
            // server again, because anything the browser knows it can lie about.
            'permissions' => [
                'orders.create' => $entity->can('orders.create'),
                'orders.update' => $entity->can('orders.update'),
                'orders.delete' => $entity->can('orders.delete'),
            ],
        ];
    }

    /**
     * The message a failed login is allowed to see.
     *
     * Everything about an account collapses to one string; only "you left a
     * field blank", which describes the request rather than the account, is
     * answered honestly - it tells an attacker nothing they did not just type.
     */
    protected function loginFailureMessage(AuthError $errorCode): string
    {
        return $errorCode === AuthError::EmptyFields ? $this->auth->error() : self::LOGIN_FAILED_MESSAGE;
    }

    /**
     * A refusal carrying a display message, in the same shape as the rest of
     * the API's errors: {"msg": "..."}.
     */
    protected function failure(int $status, string $message): string
    {
        $this->data['msg'] = $message;

        return $this->response($status);
    }

    /**
     * Whether the request body arrived as JSON.
     *
     * Compared on the media type alone because the header legitimately carries
     * parameters - 'application/json; charset=utf-8' is still JSON.
     */
    protected function isJsonRequest(): bool
    {
        $contentType = trim(explode(';', $this->input->contentType())[0]);

        return $contentType === 'application/json';
    }

    /**
     * The address to count attempts against.
     *
     * REMOTE_ADDR only. X-Forwarded-For is written by whoever spoke to the
     * proxy last and is trivially forged, so honoring it would hand every
     * attacker an unlimited supply of fresh counters - the exact opposite of
     * what a per-address limit is for. A deployment behind a trusted proxy
     * should have the proxy overwrite REMOTE_ADDR, or teach this method which
     * proxies it is allowed to believe.
     */
    protected function clientIp(): string
    {
        $ip = $this->input->server('remote_addr', '');

        return is_string($ip) ? $ip : '';
    }

    /**
     * Keep an identity response out of every cache between here and the
     * browser. Without it a shared proxy is free to hand one user's /api/me -
     * their name and their permissions - to the next person to ask for it.
     */
    protected function noStore(): void
    {
        $this->output->header('Cache-Control: no-store', OutputInterface::REPLACEALL);
        $this->output->header('Pragma: no-cache', OutputInterface::REPLACEALL);
    }

    /**
     * One line per attempt, for whoever reads the logs afterwards.
     *
     * The login is recorded because an audit trail that cannot say *which*
     * account was attacked is not one; the password never appears, in any
     * form, on any path.
     *
     * @param array<string, mixed> $context
     */
    protected function log(string $level, string $message, string $login, string $ip, array $context = []): void
    {
        logMsg($level, $message, ['login' => $login, 'ip' => $ip] + $context);
    }
}
