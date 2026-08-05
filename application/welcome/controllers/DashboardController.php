<?php

declare(strict_types=1);

namespace application\welcome\controllers;

use application\controllers\WebController;
use orange\acl\interfaces\UserEntityInterface;
use orange\framework\attributes\Route;

/**
 * The protected example page.
 *
 * Two different gates, on purpose, because they are two different questions and
 * the demo is worth nothing if it only shows one:
 *
 *   requireLogin()          are you anybody?      guest -> sent to the login form
 *   $entity->can(...)       may *you* do this?    -> decides what the page shows
 *
 * The permission checks are the same three the orders API guards its writes
 * with, asked the same way, of the same acl user entity. That is the point: the
 * page and the endpoint cannot disagree about what an account may do, because
 * neither of them decides it - orange/acl does, from the database, on every
 * request.
 *
 * Nothing here trusts anything the browser said. The page hides what an account
 * may not do, but hiding a button is courtesy, not security: the orders
 * endpoints ask again, on their own, every time.
 */
class DashboardController extends WebController
{
    /** The permissions this page reports on - the orders module's own. */
    protected const array PERMISSIONS = [
        'orders.create' => 'Create an order',
        'orders.update' => 'Edit an order',
        'orders.delete' => 'Delete an order',
    ];

    #[Route('get', '/dashboard', 'dashboard')]
    public function index(): string
    {
        // The whole gate: a guest never gets past this line, and is sent to the
        // login form with this page remembered.
        if (($denied = $this->requireLogin()) !== null) {
            return $denied;
        }

        // Non-null by the line above: requireLogin() returns a redirect for a
        // guest and for a request with no accounts behind it alike, so the only
        // way past it is a real user. Checked rather than asserted because the
        // type says it can be null and the type is right in general.
        $entity = $this->currentUser();

        if (!$entity instanceof UserEntityInterface) {
            return $this->redirect($this->router->getUrl('login'));
        }

        $this->chrome('Dashboard');

        $permissions = [];

        foreach (self::PERMISSIONS as $key => $description) {
            $permissions[$key] = ['description' => $description, 'granted' => $entity->can($key)];
        }

        $this->data->merge([
            'permissions' => $permissions,
            'roles' => $entity->hasRole('orders manager'),
            // Rendered only for an account that actually holds it. The check is
            // here, in PHP, rather than in the view deciding for itself - a view
            // that makes authorization decisions is a view that has to be
            // audited like a controller.
            'mayDelete' => $entity->can('orders.delete'),
        ]);

        return $this->renderView('dashboard/index');
    }
}
