<?php

declare(strict_types=1);

namespace application\api\controllers;

use orange\acl\User;
use orange\auth\Auth;
use orange\framework\attributes\AttachService;
use orange\framework\attributes\Route;
use orange\framework\controllers\JsonController;

/**
 * Session endpoints.
 *
 *   POST /api/login   {email, password} -> 200 {id, username, permissions} | 401 {"msg": ...}
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
 */
class AuthController extends JsonController
{
    #[AttachService('auth')]
    protected Auth $auth;

    #[AttachService('user')]
    protected User $user;

    #[Route('post', '/api/login', 'auth_login')]
    public function login(): string
    {
        $request = (array) $this->input->request();
        $email = is_string($request['email'] ?? null) ? $request['email'] : '';
        $password = is_string($request['password'] ?? null) ? $request['password'] : '';

        if (!$this->auth->login($email, $password)) {
            // auth's own message is deliberately vague - the same 'Login Error.'
            // whether the account is unknown, wrong-password or inactive, so the
            // response cannot be used to discover which addresses exist.
            return $this->response(401, json_encode(['msg' => $this->auth->error()]) ?: '');
        }

        // The handoff between the two packages: auth has proved who they are,
        // User puts that id in the session so later requests are authorized
        // without re-checking a password.
        $this->user->change($this->auth->userId());

        $this->data->merge($this->currentUser());

        return $this->response(200);
    }

    #[Route('post', '/api/logout', 'auth_logout')]
    public function logout(): string
    {
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
                'orders.delete' => $entity->can('orders.delete'),
            ],
        ];
    }
}
