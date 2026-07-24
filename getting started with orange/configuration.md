# Configuration & Cascading Config

Configuration in Orange is just **PHP files that return arrays**. There is no YAML,
no `.ini` parsing for app config, no special DSL. What makes it powerful is
*cascading*: the same-named config file can live in several directories, and the
framework merges them in a defined priority order. That's how you override kernel
defaults and how per-environment settings work.

---

## The two kinds of "config"

Orange has two distinct configuration inputs. Keep them straight:

| | `.env` | `config/*.php` |
| --- | -------- | ---------------- |
| **Format** | INI (key = value) | PHP returning an array |
| **In git?** | No (gitignored) | Yes |
| **Holds** | Secrets & environment values: DB credentials, `ENVIRONMENT`, `DEBUG` | Application/service settings |
| **Read via** | `env('KEY', 'default')` | `config('file', 'key')` or `$this->config['file']['key']` |

`.env` answers *"which machine/environment is this?"*; `config/*.php` answers
*"how should the app behave?"*. Config files may read `.env` values via `env()`
(see [config/services.php](../config/services.php), which pulls DB credentials
with `env('db')`).

---

## `.env`

INI format, seeded from [env.sample](../env.sample) on first Docker run,
gitignored. Loaded by the front controller:

```php
Application::make([__ROOT__ . '/.env'])->http();
```

Two keys drive framework behavior globally:

- `ENVIRONMENT` — e.g. `development` or `production`. Defaults to `production` if
  unset. **This value selects which override config folder is active** (below).
- `DEBUG` — becomes the `DEBUG` constant.

Read any value in app code:

```php
$apiKey = env('SERVICE_API_KEY', null);
$db     = env('db');   // an INI [db] section becomes a nested array
```

`.env` is parsed with `INI_SCANNER_TYPED`, so `true`/`false`/numbers come back as
real bools/ints, and `[section]` headers become nested arrays.

---

## The config directory cascade

At bootstrap, `Application` assembles an ordered list of directories to search for
config files. With the default setup (no directories passed to `make()`), the list
is:

```text
1. vendor/orange/framework/src/config     ← kernel defaults (always first)
2. config/                                 ← your app config
3. config/{ENVIRONMENT}/                   ← per-environment overrides
```

(The kernel folder is force-prepended; the other two are the defaults when the
caller supplies none. See `setConfigDirectories()` in
[Application.php](../vendor/orange/framework/src/Application.php).)

For a config file loaded across those directories, **files with the same basename
are merged with `array_replace_recursive()`, and later directories win.** So:

```text
kernel default  →  overridden by  config/foo.php  →  overridden by  config/{ENVIRONMENT}/foo.php
```

This is why:

- You can drop a `config/view.php` in your app to override the kernel's
  [default view config](../vendor/orange/framework/src/config/view.php) without
  copying the whole thing — you only specify the keys you want to change; the rest
  cascade through.
- `config/development/` and `config/production/` hold environment-specific
  overrides. Only the folder matching `ENVIRONMENT` is on the search path, so the
  same key can differ per environment.

### Worked example: routes

- The kernel ships
  [vendor/orange/framework/src/config/routes.php](../vendor/orange/framework/src/config/routes.php)
  (an empty route table).
- In **development**, [config/development/routes.php](../config/development/routes.php)
  overrides it by scanning your modules live with `RouterDetector`.
- In **production**, [config/production/routes.php](../config/production/routes.php)
  overrides it with a pre-generated static snapshot.

Same filename, three directories, environment picks the winner. Details in
[Routing](routing.md).

---

## Reading config at runtime

The `Config` service ([Config.php](../vendor/orange/framework/src/Config.php)) is
the runtime front door. A config file's *basename* (no `.php`) is its key.

```php
// in a controller (config is auto-attached by BaseController):
$h1 = $this->config['application']['h1'];        // whole file → array access
$position = $this->config['application']['position'];

// anywhere, via the global helper:
$h1   = config('application', 'h1');             // file + key
$all  = config('application');                   // whole file as array
$deep = config('application.h1');                // dotted single-string form
```

`config('application', 'h1')` and `config('application.h1')` are equivalent. A
missing key returns the default you pass (or `null`).

> **Config is read-only.** The `Config` service is an immutable, load-once
> snapshot for the life of the request — writing to it (`$config['x'] = …`) throws
> `ImmutableAccess`. Results are memoized, and same-named files are only merged
> the first time they're read. If you need mutable per-request state, use the
> `data` service, not config.

---

## `config/services.php` — registering your own services

This file returns an array of service definitions merged into the container. It's
where you wire app-specific services. From [config/services.php](../config/services.php):

```php
return [
    'pdo' => function () {
        $env = env('db');
        $dsn = "mysql:host={$env['host']};port={$env['port']};dbname={$env['database']};charset={$env['charset']}";
        return new \PDO($dsn, $env['username'], $env['password'], [/* options */]);
    },
    'RecordModel' => fn(ContainerInterface $container): RecordModel
        => RecordModel::getInstance($container->pdo),
];
```

A closure is a **lazy** service: it isn't run until something first asks for that
service, and the container passes itself in as the argument. `RecordModel` above
pulls `pdo` out of the container on demand. See [the container](the-container.md)
for the full registration/resolution story. The kernel's own services are defined
separately in
[vendor/orange/framework/src/config/services.php](../vendor/orange/framework/src/config/services.php).

---

## `ConfigurationTrait` — how services consume their own config

Kernel services (Container, View, Error, Router, …) share
[ConfigurationTrait](../vendor/orange/framework/src/traits/ConfigurationTrait.php).
You'll see it used inside the kernel; you may also `use` it in your own service
classes. It provides the plumbing that turns a config array into object state.

The methods you'll actually care about:

### `mergeConfigWith(array $config, $path = null, bool $recursive = true): array`

Merges the caller's `$config` over the **defaults returned by a config file**. If
`$path` is null, it auto-locates the default file next to the class (a class
`Foo` in `.../src/Foo.php` looks for `.../src/config/foo.php`, then
`.../config/foo.php`). This is how, e.g., the View service starts from the
kernel's `config/view.php` defaults and lets you override individual keys:

```php
// inside a service constructor:
$this->config = $this->mergeConfigWith($config);   // your $config over the file defaults
```

Included default files are cached per path, so the merge is cheap on repeat.

### `setFromConfig(array $config)` and `assignFromConfig(array $config)`

Both walk a config array and push each entry into the object:

- `setFromConfig()` — for a key `default merge data` it calls
  `$this->setDefaultMergeData($value)` (camelized key → `set…` method).
- `assignFromConfig()` — for a key `foo bar` it assigns
  `$this->fooBar = $value` (camelized key → matching property).

So a service can be configured entirely from a flat array without hand-writing a
constructor that unpacks each option.

### `changeOption(string $name, mixed $value): self`

Runtime, type-checked mutation of a whitelisted property. A service declares
`protected array $changeableTypeCheck = ['debug' => 'is_bool', …]`; `changeOption`
(or `change()` on the View) validates the value against that check before setting
it, throwing `InvalidValue` on a type mismatch. This is the safe, public way to
flip something like a view's `debug` flag after construction.

### `validateConfig(array $config, array $rules): void`

A tiny built-in validator services can use to fail fast on bad config. Rules are a
comma list per key: `'string'`, `'integer'`, `'array'`, `'min[5]'`, `'max[10]'`,
`'count[3]'`, `'size[8]'`, `'class[SomeClass]'`. It throws `InvalidValue` listing
every failure.

### The string helpers

`camelize()`, `underscore()`, `humanize()`, `normalize()` are the case converters
that make the "human-readable config key → method/property name" mapping above
work (`'default merge data'` → `setDefaultMergeData` / `defaultMergeData`).

> You don't need `ConfigurationTrait` to *use* the framework — controllers get
> `config` handed to them. It matters when you **write a configurable service**
> and want the same "merge over file defaults, assign into the object, validate"
> ergonomics the kernel services have.

Next: **[The DI container & the 3 attributes →](the-container.md)**
