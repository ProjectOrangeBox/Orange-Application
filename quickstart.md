# Quickstart: Controllers, Routing, MVC & HMVC in this app

This is a practical tour of how this codebase is wired together, based on the actual
sample code in [`application/`](application/) and the
[`orange/framework`](vendor/orange/framework/) package it depends on. It assumes you're
comfortable with PHP and MVC in general — the focus here is what's specific to Orange.

## 1. The 30-second mental model

- **`vendor/orange/framework`** is the kernel: routing, dependency injection container,
  request/response, view rendering, config loading, logging. You don't edit it.
- **`application/`** is your code — one PSR-4 root, with each directory under it a
  self-contained module (more on this in [§7](#7-hmvc-multiple-mvc-modules-in-one-app)).
- **`config/`** wires the two together: it lists services (`config/services.php`) and
  routes (`config/development/routes.php`).
- **`htdocs/index.php`** is the single entry point for every HTTP request.

### Request lifecycle

Every request goes through [`htdocs/index.php`](htdocs/index.php), which does almost
nothing itself — it defines `__ROOT__`, loads the Composer autoloader, and hands off to
the framework:

```php
Application::make([__ROOT__ . '/.env'], [__ROOT__ . '/config'])->http();
```

`Application::http()` ([`src/Application.php`](vendor/orange/framework/src/Application.php))
then runs a fixed pipeline, firing an event before each stage so you can hook in:

1. `before.router` event
2. `router->match($uri, $method)` — find a route for the current request
3. `before.controller` event
4. `dispatcher->call(...)` — instantiate the matched controller and call the matched method
5. `before.output` event
6. `output->send()` — send headers + body
7. `before.shutdown` event

Everything below is about steps 2 and 4: getting a URL to call your controller method.

## 2. Routing

A route is just an array: `method`, `url`, `callback` (`[ControllerClass::class, 'method']`),
and an optional `name`. There are two ways to register them, and this app uses both at once.

### Option A — explicit array (`config/development/routes.php`)

```php
return [
    ['method' => '*', 'url' => '/', 'callback' => [\application\welcome\controllers\MainController::class, 'index'], 'name' => 'home'],
    ['method' => '*', 'url' => '/api/welcome', 'callback' => [\application\api\controllers\RestController::class, 'index'], 'name' => 'rest_home'],
];
```

- `method` is `'*'` (any of the configured "match all" verbs), a single verb (`'get'`),
  or an array (`['get', 'post']`).
- `url` is matched as a regex (`@^{url}$@D`). Capture groups become positional arguments
  passed to your controller method; named groups (`(?<id>\d+)`) are captured too but
  filtered out before being unpacked as arguments (see
  [`Dispatcher::call()`](vendor/orange/framework/src/Dispatcher.php)) so you don't hit a
  "positional argument after named argument" error.
- Entries with just `url` + `name` and no `callback` (see the `assets`/`javascript`/`css`/
  `images` entries in `config/development/routes.php`) aren't routable — they only exist so
  `router->getUrl('assets')` can resolve a path. Handy for centralizing asset URLs.

### Option B — `#[Route]` attribute on the controller method

Both sample controllers actually use this style instead of hand-writing array entries:

```php
// application/welcome/controllers/MainController.php
#[Route('*', '/', 'home')]
public function index(): string { ... }

// application/api/controllers/RestController.php
#[Route('*', '/api/welcome', 'rest_home')]
public function index(): string { ... }
```

In development, [`config/development/routes.php`](config/development/routes.php) calls
[`config/development/RouterDetector.php`](config/development/RouterDetector.php) to scan
`application/` recursively — one path, because every module lives under it now — reflect on
every public method, and build the routes array from any `#[Route]` attributes it finds. So
you never hand-write a route while developing. `RouterDetector::detect()` refuses to run
unless `ENVIRONMENT === 'development'` (it prints why and `exit(1)`s) because a recursive
filesystem+reflection scan on every request is too expensive for production.

For production the array is pre-generated into
[`config/production/routes.php`](config/production/routes.php). You do not normally generate
it by hand: `detect()` takes a **production write path as its third argument**, so every
development request rewrites that snapshot as a side effect and it stays in step while you
work — commit it. `ENVIRONMENT=production` adds `config/production` to the config search
path (see `Application::setConfigDirectories()`), so it is picked up with no scanning.

To regenerate deliberately, run `composer build:production` rather than calling the
detectors by hand — order matters there, since the config snapshot bakes routes and views in
as ordinary sections and would otherwise freeze stale copies of both.

### Named routes → URLs

Never hardcode a path you also route. Use the name:

```php
$this->router->getUrl('rest_home');           // -> /api/welcome
$this->router->getUrl('user.show', ['42']);   // fills in one placeholder group
```

If a route's URL ever changes, every `getUrl()` call picks up the new path automatically.

## 3. Controllers

Extend `orange\framework\controllers\BaseController` (or `JsonController` for APIs — see
below). You don't have to, but it gives you three things for free:

```php
namespace application\welcome\controllers;

use orange\framework\attributes\AttachService;
use orange\framework\attributes\Route;
use orange\framework\controllers\BaseController;
use orange\framework\interfaces\DataInterface;
use orange\framework\interfaces\ViewInterface;

class MainController extends BaseController
{
    #[AttachService('data')]
    protected DataInterface $data;

    #[AttachService('view')]
    protected ViewInterface $view;

    #[Route('*', '/', 'home')]
    public function index(): string
    {
        $this->data['h1'] = 'Hello World!';

        return $this->renderView('main/index');
    }
}
```

1. **`#[AttachService('name')]`** on a property pulls that service straight out of the DI
   container — no constructor boilerplate. `BaseController` itself already attaches
   `config`, `input`, and `output` this way; add your own (`data`, `view`, or anything
   registered in `config/services.php`) the same way. Note that attachment happens in the
   **constructor**, so an attached service is built on every request the controller serves,
   whether the method uses it or not — see
   [controllers.md](getting%20started%20with%20orange/controllers.md#attachment-is-eager).
2. **Views are found by name, through a generated map.** `renderView()` passes your
   controller's own namespace to `ViewFinder`, which keys `.../welcome/views/main/index.php`
   under it — so `$this->renderView('main/index')` gets *this* module's copy with no path
   configuration. Whose copy wins is decided by the name, not by a search order.
3. **`$libraries` autoloading.** List filenames (no `.php`) in `protected array $libraries`
   and `BaseController` will `include_once` `<module>/libraries/<name>.php` for you before
   your controller runs.

Route matching, dependency wiring, and view resolution all happen without you ever
constructing the controller yourself — `Dispatcher::call()` does `new $controllerClass()`
and calls the matched method with the route's captured arguments.

### `JsonController` — for APIs

`application/api/controllers/RestController.php` extends `JsonController` instead, which adds a
`data` property and a `response()` helper that sets the status code + `Content-Type: json`
and JSON-encodes `$this->data`:

```php
namespace application\api\controllers;

use orange\framework\attributes\Route;
use orange\framework\controllers\JsonController;

class RestController extends JsonController
{
    #[Route('*', '/api/welcome', 'rest_home')]
    public function index(): string
    {
        $this->data->merge(['msg' => 'Welcome to My Vue App']);

        return $this->response(); // 'ok' -> HTTP 200, JSON body
    }
}
```

`response(string $status = 'ok')` looks `$status` up in `$restSuccessMap` (`'create' =>
201`, `'update' => 202`, `'noAuth' => 401`, `'badMethod' => 405`, etc.) — call
`$this->response('create')` from a POST handler and get the right status code for free.

## 4. Views

The `view` service (`orange\framework\View`, backed by `ViewAbstract`) is a plain-PHP
template renderer:

```php
$this->renderView('main/index');             // locates main/index.php, then renders it
$this->view->renderString($someTemplateStr); // renders an ad-hoc string instead
```

- **Data** comes from the `data` service (`orange\framework\Data`) — an `ArrayObject`
  you can use as an array (`$this->data['name'] = 'x'`) or merge into in bulk
  (`$this->data->merge([...])`). Whatever's in it when you render becomes
  in-scope variables inside the view file (`<?= $name ?>`), via `extract()`.
- **Locating** is `ViewFinder`'s job, not the engine's. Views are keyed in a generated
  map: once under the owning PSR-4 namespace, and once under everything after their
  `views/` directory as a fallback. `renderView()` tries the namespaced key first, so a
  module always gets its own copy — decided by name, not by search order. Application
  roots are mapped before vendor ones, so dropping in a file of the same name overrides
  a package's view with no config change.
- **Partials** are plain `include`, but of a **path the controller resolved**, never a
  relative one — `__DIR__ . '/../partials/nav.php'` only ever finds the including
  module's own copy and bypasses the map. See
  [`application/welcome/views/main/index.php`](application/welcome/views/main/index.php):

  ```php
  <?php include $headerPartial ?>
  <?php include $navPartial ?>
  ...
  <?php include $footerPartial ?>
  ```

There's no separate templating language and no compile step for `render()` — it's just
`require`'d PHP with your data extracted into scope.

## 5. Models — the "M"

Orange doesn't ship a base `Model` class, and that's deliberate — a "model" here is just:
a plain PHP class, a controller-local file under `models/` which can be picked up via the
`$libraries` property (see §3), or a proper service registered in `config/services.php`
(like `files` is, in this repo — see [`config/services.php`](config/services.php)) and
pulled into a controller with `#[AttachService('files')]`. Use whichever fits: a tiny
value object doesn't need to be a registered service, but something stateful/shared
(a database connection, a repository) usually should be.

## 6. Putting it together: adding a new controller + route + view

Say you want a `/contact` page in the existing `welcome` sub-app.

1. **Controller** — `application/welcome/controllers/ContactController.php`:

   ```php
   <?php

   declare(strict_types=1);

   namespace application\welcome\controllers;

   use orange\framework\attributes\AttachService;
   use orange\framework\attributes\Route;
   use orange\framework\controllers\BaseController;
   use orange\framework\interfaces\DataInterface;
   use orange\framework\interfaces\ViewInterface;

   class ContactController extends BaseController
   {
       #[AttachService('data')]
       protected DataInterface $data;

       #[AttachService('view')]
       protected ViewInterface $view;

       #[Route('*', '/contact', 'contact')]
       public function index(): string
       {
           $this->data['h1'] = 'Contact Us';

           return $this->renderView('contact/index');
       }
   }
   ```

2. **View** — `application/welcome/views/contact/index.php` (reuse the existing
   partials the same way `main/index.php` does).

3. **Route** — nothing to do in development: `RouterDetector` picks up the new
   `#[Route]` attribute automatically on the next request. Before deploying, regenerate
   `config/production/routes.php` (see §2) so the new route is included there too.

4. Visit `http://<site>/contact` — or from anywhere in the app,
   `$this->router->getUrl('contact')`.

## 7. HMVC: multiple MVC modules in one app

"HMVC" (Hierarchical MVC) just means: instead of one giant Controllers/ + Views/ pair for
the whole app, you have several **independent, self-contained MVC units** — each with its
own controllers and views (and optionally its own libraries/models) — plugged into one
shared kernel (the router, DI container, view engine, data store).

There is **one PSR-4 root**, and each directory under it is an independent module:

| Module | Purpose |
| --- | --- |
| `application/welcome/` | the marketing page and the dashboard |
| `application/login/` | the browser sign-in, signup and password flows |
| `application/api/` | JSON endpoints |
| `application/orders/` | the nested-DTO example, JSON |

The mapping lives in [`composer.json`](composer.json) and is a single line:

```json
"autoload": {
    "psr-4": {
        "application\\": "application"
    }
}
```

...and [`config/development/routes.php`](config/development/routes.php) scans that one
root recursively:

```php
RouterDetector::detect([__ROOT__ . '/application'], [ /* name-only routes */ ])
```

The hierarchy can go as deep as you want, and nesting is what makes it hierarchical rather
than merely modular. Each module only depends on the shared services (`router`, `data`,
`view`, ...) — never on another module's controllers or views directly. That decoupling is
the whole point of HMVC: you can add, remove, or hand off a module without touching the
others.

**What sits at the root rather than in a module.** `application/controllers/WebController.php`
and `application/views/partials/` are shared by every browser-facing module — putting them in
either `welcome` or `login` would make the other depend on a sibling, which is the one thing
this layout rules out.

### Walkthrough: adding a brand-new `admin` module

1. **Create the folders** — under `application/`, which is the whole registration step:

   ```text
   application/admin/
     controllers/
     views/
   ```

   There is nothing to add to `composer.json`: `application\` is already mapped, so
   `application\admin\controllers\…` autoloads on its own. `RouterDetector` already scans
   `application/` recursively, and `ViewDetector` reads the PSR-4 roots straight from
   Composer. **Only a brand-new PSR-4 root** — a directory *outside* `application/` — needs
   a `composer.json` entry, a `composer dump-autoload`, and a path added to the `detect(...)`
   array in `config/development/routes.php`.

2. **Write a controller** — `application/admin/controllers/DashboardController.php`:

   ```php
   <?php

   declare(strict_types=1);

   namespace admin\controllers;

   use orange\framework\attributes\AttachService;
   use orange\framework\attributes\Route;
   use orange\framework\controllers\BaseController;
   use orange\framework\interfaces\DataInterface;
   use orange\framework\interfaces\ViewInterface;

   class DashboardController extends BaseController
   {
       #[AttachService('data')]
       protected DataInterface $data;

       #[AttachService('view')]
       protected ViewInterface $view;

       #[Route('*', '/admin', 'admin.dashboard')]
       public function index(): string
       {
           return $this->renderView('dashboard/index');
       }
   }
   ```

   `renderView()` passes this controller's namespace to `ViewFinder`, so the module's own
   `views/` is where `'dashboard/index'` resolves — same mechanism as every other module,
   no extra config.

3. **Add the view** — `application/admin/views/dashboard/index.php`.

   In development that is all that's needed: `RouterDetector` picks the new `#[Route]` up
   on the next request. Visit `/admin` and it works.

4. **Before shipping**, run `composer build:production` so the new `#[Route]` is baked into
   the static production route list — production doesn't run the live filesystem scan. (In
   practice the routes snapshot is already current, since every development request rewrites
   it; the command also refreshes the view and config snapshots, in the order they depend on
   each other.)

That's the whole recipe: a folder, a controller, a view. No `composer.json` entry, no route
registration, and nothing about the existing modules changes — that's the "independent
modules" part of HMVC paying off.

## 8. Where to look next

- [`vendor/orange/framework/readme.md`](vendor/orange/framework/readme.md) — a class-by-class
  tour of the framework internals.
- [`vendor/orange/framework/src/interfaces/`](vendor/orange/framework/src/interfaces) — the
  contracts (`ViewInterface`, `RouterInterface`, `DataInterface`, ...) if you ever need to
  swap an implementation.
- [`unittest/`](unittest/) — real usage examples exercised by the test suite; run them with
  `composer test`.
- [`config/services.php`](config/services.php) and
  [`vendor/orange/framework/src/config/services.php`](vendor/orange/framework/src/config/services.php) —
  the full list of what's available to `#[AttachService(...)]`.
