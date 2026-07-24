# Global Helper Functions

Orange exposes a handful of **global functions** so you can reach core services
from anywhere — a view, a library, a plain class — without pulling them out of the
container by hand. The ones covered here live in
[vendor/orange/framework/src/helpers/wrappers.php](../vendor/orange/framework/src/helpers/wrappers.php):
each is a thin *wrapper* around a container service, which is why the file is named
`wrappers.php`.

---

## How they're loaded (not via Composer)

These functions are **not** autoloaded by Composer. They're `include_once`'d at
runtime during `Application::preContainer()`, alongside the other helper files
(`helpers.php`, `errors.php`). Every wrapper is guarded with
`if (!function_exists(...))`, so you can **override any of them** by defining your
own version earlier (e.g. in a helper listed in your app config) — the framework
won't redefine it.

> **Tooling implication.** Because these are runtime includes, any static-analysis
> or test entry point must bootstrap them explicitly — this is already wired up in
> `phpstan.neon`'s `bootstrapFiles` and `unittest/bootstrap.php`. If you add a new
> analysis/test harness, include these files first or the functions will look
> undefined.

---

## The wrappers

### `container(): ContainerInterface`

Returns the one DI container instance. The root from which everything else is
reachable.

```php
$router = container()->router;
$mine   = container()->get('RecordModel');
```

### `config(?string $filename = null, ?string $key = null, mixed $default = null): mixed`

Read configuration. Three call shapes:

```php
config();                          // the Config service itself (chain your own calls)
config('application');             // the whole application.php file as an array
config('application', 'h1');       // a single key, with optional default
config('application', 'x', 'def'); // → 'def' if application.x is missing
```

Internally it caches the `Config` service on first use and swallows errors to the
default if config isn't set up yet. See [Configuration](configuration.md).

### `getUrl(string $name = '', array $arguments = [], ?bool $skipCheckingType = null): string`

Reverse-route: build a URL from a route **name**. Always prefer this to hardcoding
a path.

```php
getUrl('home');                 // → '/'
getUrl('css');                  // → '/assets/css'   (a getUrl-only route)
getUrl('rest_read', [42]);      // → '/api/read/42'
```

Arguments fill the route's capture groups and are validated against them (pass
`skipCheckingType: true` to bypass). Wraps `container()->router->getUrl(...)`. See
[Routing](routing.md).

### `input(): InputInterface`

The request service.

```php
$page = input()->query('page', 1);
$body = input()->request();
```

Same object controllers get as `$this->input`. See [Input & Output](input-and-output.md).

### `output(): OutputInterface`

The response service.

```php
output()->responseCode(204)->send();
output()->redirect(getUrl('home'));
```

Same object controllers get as `$this->output`.

### `env(string $key, mixed $default = null): mixed`

Read a value from the loaded `.env` (INI) environment. This is the **only** way to
read `.env` in app code — `$_ENV` is unset after load.

```php
$creds = env('db');                    // a whole [db] section as an array
$debug = env('DEBUG', false);
```

Wraps `Application::get()->env(...)`. See [Configuration](configuration.md).

### `logMsg(mixed $level, string $msg, array $context = []): void`

Write a log entry through the `log` service, if one is configured. Safe to call
before the container/log exists — it silently no-ops rather than throwing (useful
during very early bootstrap).

```php
logMsg('DEBUG', 'processing record', ['id' => $id]);
logMsg('ERROR', 'payment failed', ['order' => $orderId]);
```

### `isLogEnabled(string|int $level): bool`

Ask whether a log level would actually be written **before** you spend effort
building the message. Pair it with `logMsg()` whenever the message is expensive to
construct (concatenation, `var_export`, array literals):

```php
if (isLogEnabled('DEBUG')) {
    logMsg('DEBUG', __METHOD__, ['payload' => $bigArray]);   // built only if it'll be logged
}
```

The kernel uses exactly this pattern throughout. `Log::isLevelEnabled()` memoizes
per level, so repeated checks are cheap.

---

## Quick reference

| Function | Returns / does | Wraps |
| ---------- | ---------------- | ------- |
| `container()` | the DI container | `Container::getInstance()` |
| `config($file, $key, $default)` | a config value/array | `container()->config` |
| `getUrl($name, $args)` | a URL from a route name | `router->getUrl()` |
| `input()` | the request service | `container()->input` |
| `output()` | the response service | `container()->output` |
| `env($key, $default)` | a `.env` value | `Application::get()->env()` |
| `logMsg($level, $msg, $ctx)` | write a log entry (no-op if unavailable) | `container()->log->log()` |
| `isLogEnabled($level)` | would this level log? | `container()->log->isLevelEnabled()` |

> There are more helpers in the sibling files `helpers.php` (e.g. `container()`
> companions, array/dot utilities) and `errors.php` (e.g. `show404()`). This page
> covers `wrappers.php` — the service accessors you'll use most from application
> code.

Next: **[Tutorial: build a module from scratch →](tutorial-build-a-module.md)**
