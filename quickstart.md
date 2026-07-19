# Quickstart: Controllers, Routing, MVC & HMVC in this app

This is a practical tour of how this codebase is wired together, based on the actual
sample code in [`application/`](application/), [`api/`](api/), and the
[`orange/framework`](vendor/orange/framework/) package it depends on. It assumes you're
comfortable with PHP and MVC in general — the focus here is what's specific to Orange.

## 1. The 30-second mental model

- **`vendor/orange/framework`** is the kernel: routing, dependency injection container,
  request/response, view rendering, config loading, logging. You don't edit it.
- **`application/`** and **`api/`** are your code — each is a self-contained module
  (more on this in [§7](#7-hmvc-multiple-mvc-modules-in-one-app)).
- **`config/`** wires the two together: it lists services (`config/services.php`) and
  routes (`config/routes.php`).
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

### Option A — explicit array (`config/routes.php`)

```php
return [
    ['method' => '*', 'url' => '/', 'callback' => [\application\welcome\controllers\MainController::class, 'index'], 'name' => 'home'],
    ['method' => '*', 'url' => '/api/welcome', 'callback' => [\api\controllers\RestController::class, 'index'], 'name' => 'rest_home'],
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
  `images` entries in `config/routes.php`) aren't routable — they only exist so
  `router->getUrl('assets')` can resolve a path. Handy for centralizing asset URLs.

### Option B — `#[Route]` attribute on the controller method

Both sample controllers actually use this style instead of hand-writing array entries:

```php
// application/welcome/controllers/MainController.php
#[Route('*', '/', 'home')]
public function index(): string { ... }

// api/controllers/RestController.php
#[Route('*', '/api/welcome', 'rest_home')]
public function index(): string { ... }
```

In development, `config/routes.php` uses
[`config/RouterDetector.php`](config/RouterDetector.php) to scan `application/` and
`api/` recursively, reflect on every public method, and build the routes array from any
`#[Route]` attributes it finds — so you never have to touch `config/routes.php` by hand
while developing. `RouterDetector::detect()` deliberately refuses to run unless
`ENVIRONMENT === 'development'` (it `die()`s otherwise) because a recursive
filesystem+reflection scan on every request is too expensive for production.

For production, pre-compute the array once with `RouterDetector::export($paths, $extraRoutes)`
(run it from a CLI script or a throwaway dev route) — it echoes a ready-to-save PHP file.
Save that output as `config/production/routes.php` — since `ENVIRONMENT=production` adds
`config/production` to the config search path (see `Application::setConfigDirectories()`),
it's picked up automatically. **Make sure the exported array ends up assigned to a `'routes'`
key** (`return ['routes' => [...]];`), matching the shape `config/routes.php` returns — that's
the key `Router` actually reads.

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

        return $this->view->render('main/index');
    }
}
```

1. **`#[AttachService('name')]`** on a property pulls that service straight out of the DI
   container — no constructor boilerplate. `BaseController` itself already attaches
   `config`, `input`, and `output` this way; add your own (`data`, `view`, or anything
   registered in `config/services.php`) the same way.
2. **A sibling `views/` directory is auto-registered.** If your controller has a `$view`
   property, `BaseController`'s constructor finds the directory two levels up from the
   controller's own file (`.../controllers/Foo.php` → `.../views`) and adds it to the
   view search path with top priority — so `$this->view->render('main/index')` finds
   `.../welcome/views/main/index.php` without any manual path configuration.
3. **`$libraries` autoloading.** List filenames (no `.php`) in `protected array $libraries`
   and `BaseController` will `include_once` `<module>/libraries/<name>.php` for you before
   your controller runs.

Route matching, dependency wiring, and view resolution all happen without you ever
constructing the controller yourself — `Dispatcher::call()` does `new $controllerClass()`
and calls the matched method with the route's captured arguments.

### `JsonController` — for APIs

`api/controllers/RestController.php` extends `JsonController` instead, which adds a
`data` property and a `response()` helper that sets the status code + `Content-Type: json`
and JSON-encodes `$this->data`:

```php
namespace api\controllers;

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
$this->view->render('main/index');           // finds main/index.php on the search path
$this->view->renderString($someTemplateStr); // renders an ad-hoc string instead
```

- **Data** comes from the `data` service (`orange\framework\Data`) — an `ArrayObject`
  you can use as an array (`$this->data['name'] = 'x'`) or merge into in bulk
  (`$this->data->merge([...])`). Whatever's in it when you call `render()` becomes
  in-scope variables inside the view file (`<?= $name ?>`), via `extract()`.
- **Search path** is a stack: your controller's own `views/` directory (highest priority,
  see §3) plus anything in `config/view.php`'s `view paths` / `default view paths`. First
  match wins, so a module-local view always shadows a global one of the same name.
- **Partials** are just plain `include`, e.g.
  [`application/welcome/views/main/index.php`](application/welcome/views/main/index.php):

  ```php
  <?php include __DIR__ . '/../partials/header.php' ?>
  <?php include __DIR__ . '/../partials/nav.php' ?>
  ...
  <?php include __DIR__ . '/../partials/footer.php' ?>
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

           return $this->view->render('contact/index');
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
shared kernel (the router, DI container, view engine, data store). This repo already has
two:

| Module | PSR-4 root | Purpose |
|---|---|---|
| `application/` | `application\` → `application/` | HTML pages (contains a `welcome` sub-app) |
| `api/` | `api\` → `api/` | JSON endpoints |

That mapping lives in [`composer.json`](composer.json):

```json
"autoload": {
    "psr-4": {
        "application\\": "application",
        "api\\": "api"
    }
}
```

...and `config/routes.php` scans both roots for routable controllers:

```php
RouterDetector::detect([__ROOT__ . '/application', __ROOT__ . '/api'], [ /* name-only routes */ ])
```

Notice `application/welcome/` is itself a nested sub-module — the hierarchy can go as
deep as you want (`application/welcome/controllers` + `application/welcome/views`,
totally independent of `api/controllers`). Each module only depends on the shared
services (`router`, `data`, `view`, ...) — never on another module's controllers or
views directly. That decoupling is the whole point of HMVC: you can add, remove, or
hand off a module without touching the others.

### Walkthrough: adding a brand-new `admin` module

1. **Create the folders:**

   ```
   admin/
     controllers/
     views/
   ```

2. **Register the PSR-4 namespace** in `composer.json`:

   ```json
   "autoload": {
       "psr-4": {
           "application\\": "application",
           "api\\": "api",
           "admin\\": "admin"
       }
   }
   ```

   then regenerate the autoloader:

   ```sh
   composer dump-autoload
   ```

3. **Write a controller** — `admin/controllers/DashboardController.php`:

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
           return $this->view->render('dashboard/index');
       }
   }
   ```

   Because `admin/controllers/DashboardController.php` sits two levels below `admin/`,
   `BaseController` auto-registers `admin/views` as this controller's view path — same
   mechanism as every other module, no extra config.

4. **Add the view** — `admin/views/dashboard/index.php`.

5. **Tell `RouterDetector` about the new module** — edit `config/routes.php` and add
   `__ROOT__ . '/admin'` to **both** path arrays it currently hardcodes (the `export(...)`
   call and the `detect(...)` call):

   ```php
   RouterDetector::detect([__ROOT__ . '/application', __ROOT__ . '/api', __ROOT__ . '/admin'], [...])
   ```

   In development this is all that's needed — visit `/admin` and it works.

6. **Before shipping**, regenerate `config/production/routes.php` (§2) so the new
   `#[Route]` is baked into the static production route list — production doesn't run
   the live filesystem scan.

That's the whole recipe: a folder, a PSR-4 entry, a controller, and (if you want it
discovered in production) one line added to the route-detection path list. Nothing about
`application/` or `api/` needs to change — that's the "independent modules" part of HMVC
paying off.

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
