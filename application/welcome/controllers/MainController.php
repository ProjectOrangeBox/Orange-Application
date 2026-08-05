<?php

declare(strict_types=1);

namespace application\welcome\controllers;

use application\controllers\WebController;
use orange\framework\attributes\Route;

/**
 * The home page.
 *
 * Extends WebController rather than BaseController only so the shared navbar can
 * show who is signed in - the page itself is public and guards nothing. The one
 * consequence worth knowing: reading the current user reads the session, so this
 * page now starts one for anonymous visitors too. That is the price of a nav
 * that tells the truth on every page; a site that wants a cookie-free landing
 * page should drop the auth block from the nav partial instead of guessing here.
 */
class MainController extends WebController
{
    #[Route('*', '/', 'home')]
    public function index(): string
    {
        // the navbar's variables (and the empty css/script/js the partials
        // echo). Called first so everything below can overwrite it.
        $this->chrome('Home');

        // many at once
        $this->data->merge([
            'css' => '',
            'script' => '',
            'js' => '',
            'address' => '123 South Main Street<br />Somewhere, AZ 12345',
            'about' => '',
            'aboutText' => '',
            'position' => $this->config['application']['position'],
            'h1' => $this->config['application']['h1'],
            'file' => $this->config['application']['this file'],
            'cash' => '19.95',
        ]);

        // or 1 at a time
        $this->data['around'] = 'AROUND THE WEB';
        $this->data['name'] = 'Johnny Appleseed';

        // render it!
        // renderView() resolves the name against this controller's own module
        // first - 'application/welcome/main/index' - so a view of the same name
        // shipped by a package is only used when this module has none
        return $this->renderView('main/index');
    }
}
