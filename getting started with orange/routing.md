# Routing

Routing maps an incoming **URL + HTTP method** to a **controller method**, and in
reverse lets you generate a URL from a route's name. Orange offers two ways to
define routes, and they're used together.

Source: [Router.php](../vendor/orange/framework/src/Router.php),
[config/development/RouterDetector.php](../config/development/RouterDetector.php).

---

## A route, defined

Whichever mechanism you use, every route boils down to the same array shape:

```php
[
    'method'   => 'get',                                   // verb(s), or '*'
    'url'      => '/api/read/(\d+)',                       // path (matched as regex)
    'callback' => [\application\api\controllers\RestController::class, 'read'],
    'name'     => 'rest_read',                             // for getUrl()
]
```

- **`method`** — a verb (`'get'`), an array of verbs (`['get','post']`), or `'*'`.
  `'*'` expands to the configured "match all" set, default
  `GET, POST, PUT, DELETE, PATCH`.
- **`url`** — matched against the request path as `@^{url}$@D`. If it contains no
  regex metacharacters it's compared as a plain string (faster); otherwise it's a
  regex and **each capture group becomes a positional argument** passed to the
  controller method (URL-decoded).
- **`callback`** — `[ControllerClass, 'methodName']`.
- **`name`** — optional; the key you pass to `getUrl()` / `getUrl` helper to
  build this URL.

---

## Mechanism 1: `#[Route]` attributes (what this app uses)

Declare the route right on the method that handles it:

```php
// application/welcome/controllers/MainController.php
#[Route('*', '/', 'home')]
public function index(): string { /* … */ }
```

```php
// application/api/controllers/RestController.php
#[Route('get',    '/api/index',       'rest_index')]
public function index(): string { /* … */ }

#[Route('get',    '/api/read/(\d+)',  'rest_read')]
public function read(string $id): string { /* … */ }

#[Route('post',   '/api/create',      'rest_create')]
public function create(): string { /* … */ }

#[Route('put',    '/api/update/(\d+)', 'rest_update')]
public function update(string $id): string { /* … */ }

#[Route('delete', '/api/delete/(\d+)', 'rest_delete')]
public function delete(string $id): string { /* … */ }
```

The `(\d+)` capture in `/api/read/(\d+)` becomes the `$id` argument of `read()`.
Routes live next to the code they run, which is the whole appeal.

### How attributes become routes: `RouterDetector`

In **development**, [config/development/routes.php](../config/development/routes.php)
builds the route table by scanning your module folders for `#[Route]` attributes:

```php
return [
    'routes' => RouterDetector::detect(
        [__ROOT__ . '/application'],   // module paths to scan
        [
            // "getUrl-only" entries — no callback, not routable (see below)
            ['url' => '/assets',     'name' => 'assets'],
            ['url' => '/assets/js',  'name' => 'javascript'],
            ['url' => '/assets/css', 'name' => 'css'],
            ['url' => '/images',     'name' => 'images'],
        ]
    ),
];
```

`RouterDetector::detect()` recursively globs each path for `.php` files, reflects
on each class, reads every method's `#[Route]`, and assembles the route array. It
**refuses to run outside `ENVIRONMENT === 'development'`** — a filesystem +
reflection scan on every request is too expensive for production.

---

## Mechanism 2: a plain routes array

You can also list routes literally. `config/development/routes.php` (or another per-environment
override) just needs to return `['routes' => [ … ]]` with entries in the shape
shown above. The production route file does exactly this — see below.

Both mechanisms produce the same route table; `RouterDetector` is only a
convenience that generates the array for you from attributes.

---

## `getUrl`-only entries (never hardcode a path)

Notice the `assets` / `javascript` / `css` / `images` entries above have a `url`
and `name` but **no `callback`**. They aren't routable — they exist purely so you
can resolve a path centrally:

```php
$router->getUrl('css');        // → '/assets/css'
getUrl('images');              // same, via the global helper
```

The rule: **never hardcode a routed path in a view or controller** — resolve it by
name with `getUrl()`. Change the path in one place (the route definition) and every
caller updates automatically.

`getUrl()` also fills in arguments for parameterized routes:

```php
getUrl('rest_read', [42]);     // → '/api/read/42'
```

It validates the argument against the route's capture pattern (so a non-numeric id
for `(\d+)` throws), and it checks the argument count matches the number of
capture groups.

---

## How matching works at request time

`Router::match($uri, $method)` (called in the [pipeline](request-lifecycle.md)):

1. Routes are stored keyed by uppercase HTTP method; only routes for the request's
   method are considered.
2. The URI is normalized (`/foo/` → `/foo`). Literal routes match by string
   equality; regex routes by `preg_match`. The **first** match wins.
3. Capture groups are URL-decoded and become the matched route's `argv`.
4. If nothing matches, `RouteNotFound` is thrown → a 404 via the
   [Error](error-handling.md) service.

The matched callback is then handed to the [Dispatcher](controllers.md), which
instantiates the controller and calls the method with `argv` as arguments.

> **Route precedence:** routes are added as a stack (last-added is tried first
> within a method). With attribute discovery you generally give each URL a
> distinct pattern, so precedence rarely bites — but if two patterns can match the
> same URL, order matters.

---

## Production: export a static route table

`RouterDetector` won't scan in production, so you pre-generate the route list once
and commit it. The generator is `RouterDetector::export($paths, $extraRoutes)`,
which echoes PHP source for a `config/production/routes.php` file. The result
([config/production/routes.php](../config/production/routes.php)) is a plain
snapshot:

```php
return [
    'routes' => [
        ['url' => '/assets',     'name' => 'assets'],   // getUrl-only entries
        // …
        ['method' => '*',   'url' => '/',              'callback' => [\application\welcome\controllers\MainController::class, 'index'], 'name' => 'home'],
        ['method' => 'get', 'url' => '/api/read/(\d+)', 'callback' => [\application\api\controllers\RestController::class, 'read'],  'name' => 'rest_read'],
        // …
    ],
];
```

Because `ENVIRONMENT=production` puts `config/production/` on the config search
path, this file overrides the kernel's empty route table with no live scanning
(see [Configuration](configuration.md)).

> **Regenerate this file whenever a `#[Route]` attribute changes** — a route
> added, removed, or renamed in development won't exist in production until you
> re-export. This is the one manual step the attribute convenience costs you.

---

## Adding a route: the short version

1. Write a public controller method that returns a `string`.
2. Put a `#[Route(verb, '/path', 'name')]` attribute on it.
3. Development: done — it's discovered on the next request.
4. Production: re-run the `export(...)` step and commit `config/production/routes.php`.

Next: **[Controllers →](controllers.md)**
