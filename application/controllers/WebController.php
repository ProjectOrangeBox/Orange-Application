<?php

declare(strict_types=1);

namespace application\controllers;

use PDOException;
use orange\acl\User;
use orange\acl\interfaces\UserEntityInterface;
use orange\framework\attributes\AttachService;
use orange\framework\controllers\BaseController;
use orange\framework\exceptions\MissingRequired;
use orange\framework\interfaces\DataInterface;
use orange\framework\interfaces\ViewInterface;
use orange\model\exceptions\Model as ModelException;
use orange\session\SessionInterface;

/**
 * What every browser-facing page needs: the chrome the partials render, the
 * current user, and the two guards below.
 *
 * It sits at the PSR-4 root rather than inside a module because more than one
 * module extends it - welcome renders the marketing page and the dashboard,
 * login renders the form - and a module reaching into a sibling's controllers
 * is the one dependency the HMVC layout is meant to rule out. Its partials live
 * alongside it in application/views/partials for the same reason.
 *
 * The API module answers "may this request do this" with a status code, because
 * its caller is a program. A browser is not a program: the same refusal has to
 * become a redirect to a login form or a rendered page, and a form has to carry
 * a CSRF token that a JSON endpoint does not. Those are the only differences -
 * the question is still asked of orange/acl, on the server, on every request.
 *
 * CSRF lives here for the same reason LoginThrottleModel lives in
 * application/models: neither orange/session nor the framework ships one, it is
 * shared by more than one module, and a token is meaningless without a form to
 * put it in. The session cookie is already
 * SameSite=Strict (see config/services.php), which stops a cross-site POST in
 * any current browser; the token is the second lock, for the browser that
 * doesn't, and for the day someone loosens that setting to make an inbound link
 * work.
 */
abstract class WebController extends BaseController
{
    /** Where the token lives between the form being rendered and submitted. */
    protected const string CSRF_SESSION_KEY = 'csrf_token';

    /** The form field carrying it back. */
    protected const string CSRF_FIELD = '_token';

    /**
     * Where a guard stashes the page the visitor was actually after.
     *
     * In the session rather than a ?return= parameter: a URL the browser can
     * edit is a URL an attacker can set, and "log in and you will be sent
     * wherever this link says" is an open redirect with a login form in front
     * of it. Nothing the client sends is involved, so there is nothing to
     * validate.
     */
    protected const string RETURN_SESSION_KEY = 'return_to';

    #[AttachService('data')]
    protected DataInterface $data;

    #[AttachService('view')]
    protected ViewInterface $view;

    #[AttachService('session')]
    protected SessionInterface $session;

    /**
     * The user service, resolved on first use - false once it has failed.
     *
     * NOT #[AttachService('user')], which is what it used to be. Attaching it
     * makes it a *construction* dependency: the container builds user -> acl ->
     * pdo before the controller body ever runs, so an unreachable or unseeded
     * accounts database took down every browser-facing page with a stack trace,
     * the marketing page that mentions no user at all included. That is what a
     * fresh clone saw before it had run the migrations.
     *
     * Kept per-request rather than re-asked, because a failure costs a TCP
     * connect that has to time out, and chrome() plus a guard ask twice on a
     * single page.
     */
    private User|false|null $userService = null;

    /**
     * The three partials every page wraps itself in, by view name.
     *
     * @var list<string>
     */
    protected const array CHROME_PARTIALS = ['header', 'nav', 'footer'];

    /**
     * The variables every browser-facing view expects, whoever is asking.
     *
     * Called first so a page can overwrite any of it afterwards.
     */
    protected function chrome(string $title): void
    {
        $entity = $this->currentUser();

        $this->data->merge([
            // Partials arrive as resolved paths for the view to include, rather
            // than the views reaching for __DIR__ . '/../partials/header.php'.
            // A relative path only ever finds the copy in its own module, which
            // is exactly what breaks the moment a second module needs the same
            // chrome. Asked for by name, each one goes through the view map:
            // the module's own copy first, the shared one otherwise - so a
            // module takes over its own nav by dropping views/partials/nav.php
            // in, and changes nothing anywhere else.
            ...$this->chromePartials(),
            // the partials echo these unconditionally
            'css' => '',
            'script' => '',
            'js' => '',
            'h1' => $title,
            'name' => $title,
            // nav state
            'currentUser' => $entity,
            'isLoggedIn' => $entity instanceof UserEntityInterface && !$entity->isGuest(),
            // Whether there is an accounts database behind this page at all.
            // The nav hides Log In, Sign Up, Dashboard and Log Out when there
            // is not - every one of them leads somewhere that cannot work, and
            // offering a link to a page that will only throw is worse than not
            // offering it.
            'accountsAvailable' => $entity instanceof UserEntityInterface,
            'loginUrl' => $this->router->getUrl('login'),
            'logoutUrl' => $this->router->getUrl('logout'),
            'dashboardUrl' => $this->router->getUrl('dashboard'),
            'homeUrl' => $this->router->getUrl('home'),
            'signupUrl' => $this->router->getUrl('signup'),
            'forgotUrl' => $this->router->getUrl('password_forgot'),
            'resetUrl' => $this->router->getUrl('password_reset'),
            'csrfToken' => $this->csrfToken(),
        ]);
    }

    /**
     * Resolve each chrome partial to the file a view can include.
     *
     * Keyed 'headerPartial', 'navPartial', 'footerPartial' - named for what the
     * view does with them, since what lands in scope there is a path and not
     * markup.
     *
     * @return array<string, string>
     */
    protected function chromePartials(): array
    {
        $partials = [];

        foreach (self::CHROME_PARTIALS as $partial) {
            // the controller's own namespace, so a module's copy wins over the
            // shared one - the same lookup renderView() makes for a page view
            $partials[$partial . 'Partial'] = $this->viewFinder->find('partials/' . $partial, $this->viewNamespace());
        }

        return $partials;
    }

    /**
     * The current user - the guest entity for an anonymous visitor, and null
     * only when there are no accounts to ask.
     *
     * Guest and null are different answers and the callers treat them so. Guest
     * means "nobody is logged in", which logging in would change. Null means the
     * question could not be put at all - see userService() for the three ways
     * that happens, all of them some form of "there are no accounts to ask".
     */
    protected function currentUser(): ?UserEntityInterface
    {
        $service = $this->userService();

        if (!$service instanceof User) {
            return null;
        }

        try {
            return $service->load();
        } catch (PDOException | ModelException | MissingRequired $e) {
            return $this->accountsUnavailable($e);
        }
    }

    /**
     * The user service, or null when it cannot be built.
     *
     * The three exceptions caught here are one fact reported by three layers,
     * which is why they are caught together rather than told apart:
     *
     *   PDOException      no database - the connection itself failed
     *   ModelException    a database that cannot answer, orange/model having
     *                     wrapped the driver's error. A checkout that has not
     *                     been migrated arrives here: the server is up, the
     *                     schema is not, and orange_users does not exist
     *   MissingRequired   a schema with no data in it - orange/acl reporting
     *                     that the guest row its config names is absent
     *
     * None is recoverable inside a request, and none should cost the visitor
     * the page they asked for.
     */
    protected function userService(): ?User
    {
        if ($this->userService === null) {
            try {
                $service = container()->get('user');

                $this->userService = $service instanceof User ? $service : false;
            } catch (PDOException | ModelException | MissingRequired $e) {
                $this->accountsUnavailable($e);
            }
        }

        return $this->userService === false ? null : $this->userService;
    }

    /**
     * Record that there is nothing to authenticate against, and say so as null.
     *
     * Logged rather than swallowed: a page that renders logged-out because the
     * database is down looks exactly like a page that renders logged-out because
     * nobody is logged in, and the difference has to be visible somewhere.
     */
    private function accountsUnavailable(\Throwable $e): null
    {
        $this->userService = false;

        logMsg('warning', 'accounts unavailable, serving this request as an anonymous visitor: ' . $e->getMessage());

        return null;
    }

    /**
     * Send anyone not logged in to the login form, remembering where they were
     * going. Returns null when the request may carry on.
     *
     * No accounts means no login, so it is treated as not-logged-in rather than
     * given a path of its own. The visitor lands on the login form, which is
     * where being unable to reach the accounts database is a truthful thing to
     * report - and it is a page, not a guarded one, so it says so itself.
     */
    protected function requireLogin(): ?string
    {
        $entity = $this->currentUser();

        if ($entity instanceof UserEntityInterface && !$entity->isGuest()) {
            return null;
        }

        $this->session->set(self::RETURN_SESSION_KEY, $this->input->requestUri());

        return $this->redirect($this->router->getUrl('login'));
    }

    /**
     * Authentication first, then authorization - the two refusals are different
     * answers and must not be collapsed.
     *
     * A guest is redirected, because logging in would genuinely change the
     * answer. Someone already logged in gets 403 and a page saying so, because
     * it would not: sending them to a login form they have already been through
     * is a loop, and the honest answer is that this account may not do this.
     * Same reasoning as OrderController::denyUnless(), which returns 401 for the
     * first and 403 for the second.
     */
    protected function requirePermission(string $permission): ?string
    {
        if (($denied = $this->requireLogin()) !== null) {
            return $denied;
        }

        // requireLogin() sends both a guest and a request with no accounts
        // behind it to the login form, so getting here means a real user - the
        // null arm satisfies the type checker rather than a reachable case.
        $entity = $this->currentUser();

        if ($entity instanceof UserEntityInterface && $entity->can($permission)) {
            return null;
        }

        return $this->forbidden($permission);
    }

    /**
     * 403 with a page rather than a redirect - see requirePermission().
     */
    protected function forbidden(string $permission): string
    {
        $this->chrome('Not Allowed');

        $this->data['permission'] = $permission;

        $this->output->responseCode(403);

        return $this->renderView('session/forbidden');
    }

    /**
     * A redirect, as 303 See Other.
     *
     * Explicit because the framework's configured default is 301: a permanent
     * redirect is *cached*, so a browser told once that /login is permanently
     * /dashboard would stop asking for the login form at all - including after
     * logging out. 303 also states the thing PRG depends on, that the follow-up
     * is a GET regardless of what this request was.
     */
    protected function redirect(string $url): string
    {
        $this->output->redirect($url, 303);

        // redirect() exits, so this is only reached by tests (which stub the
        // exit) - but the method still has to satisfy its return type.
        return '';
    }

    /**
     * The CSRF token for this session, minted on first use.
     *
     * One per session rather than one per form: a per-form token breaks the
     * back button and two tabs, and buys nothing here, since anything able to
     * read one token can read them all.
     */
    protected function csrfToken(): string
    {
        $token = $this->session->get(self::CSRF_SESSION_KEY);

        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));

            $this->session->set(self::CSRF_SESSION_KEY, $token);
        }

        return $token;
    }

    /**
     * Whether this request carried the session's token back.
     */
    protected function csrfValid(): bool
    {
        $submitted = $this->input->request(self::CSRF_FIELD, '');
        $stored = $this->session->get(self::CSRF_SESSION_KEY);

        if (!is_string($submitted) || !is_string($stored) || $stored === '') {
            return false;
        }

        // hash_equals, not ===: a comparison that returns early on the first
        // wrong byte tells an attacker how much of a guess was right
        return hash_equals($stored, $submitted);
    }

    /**
     * One request field as a string - '' when it is absent, or is an array
     * because someone renamed a form field to `email[]` to see what happened.
     */
    protected function requestString(string $key): string
    {
        $value = $this->input->request($key, '');

        return is_string($value) ? $value : '';
    }

    /**
     * One query-string parameter as a string.
     *
     * Separate from requestString() because Input keeps them apart: request()
     * is the decoded body, query() is what came after the `?`. A token arriving
     * by emailed link is in the URL and is invisible to request(), which is a
     * silent failure - the link simply never works - rather than an error.
     */
    protected function queryString(string $key): string
    {
        $value = $this->input->query($key, '');

        return is_string($value) ? $value : '';
    }

    /**
     * The client address, for anything counted per-address.
     *
     * REMOTE_ADDR only. X-Forwarded-For is written by the client, so trusting
     * it would hand anyone an unlimited supply of fresh throttle counters. A
     * deployment genuinely behind a proxy has to resolve that here, from a
     * trusted-proxy list - not by believing a header.
     */
    protected function clientIp(): string
    {
        $ip = $this->input->server('remote_addr', '');

        return is_string($ip) ? $ip : '';
    }

    /**
     * Turn a path into a URL that survives being clicked out of a mail client.
     *
     * A mail is read outside the session that caused it, so a relative path in
     * one resolves against nothing. Scheme and host come from the request
     * rather than config, which is what makes the same link work unchanged on
     * localhost:8080 and on a real hostname.
     */
    protected function absoluteUrl(string $path): string
    {
        $host = $this->input->server('http_host', '');

        if (!is_string($host) || $host === '') {
            return $path;
        }

        return $this->input->isHttpsRequest(true) . '://' . $host . $path;
    }

    /**
     * Flatten a Dto's errors into the flat list a form renders above itself.
     *
     * errors() is keyed by field because a caller may want to put each message
     * beside its input. These forms show them together at the top instead, so
     * the keys are dropped here rather than in every view.
     *
     * @param array<string, list<string>> $errors
     * @return list<string>
     */
    protected function flattenErrors(array $errors): array
    {
        $flat = [];

        foreach ($errors as $messages) {
            foreach ($messages as $message) {
                $flat[] = $message;
            }
        }

        return $flat;
    }

    /**
     * Throw the token away so the next render mints a new one.
     *
     * Done on every privilege change. The session id is regenerated at those
     * moments too (orange/acl's User::change()), but that keeps the session's
     * *contents* - so without this the token issued to the anonymous visitor
     * would carry on into their logged-in session.
     */
    protected function rotateCsrfToken(): void
    {
        $this->session->remove(self::CSRF_SESSION_KEY);
    }
}
