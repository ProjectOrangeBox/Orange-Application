# The DI Container & the 3 Attributes

The **container** is the framework's service registry and dependency-injection
engine. Everything the framework exposes — `router`, `input`, `output`, `view`,
`data`, `config`, `events`, plus every service you register — lives in one
container, and you pull services out of it wherever you need them.

Source: [Container.php](../vendor/orange/framework/src/Container.php).

---

## Getting the container

The container is a singleton. Three ways to reach it:

```php
container();                       // the global helper (most common)
\orange\framework\Container::getInstance();
$container->container;             // it registers itself under the name 'container'
```

Inside a controller you rarely call `container()` directly — you declare the
services you want as properties and let `#[AttachService]` fill them in (below).

---

## Retrieving a service

Two equivalent forms:

```php
$router = container()->router;         // magic property access
$router = container()->get('router');  // explicit
```

Service names are **normalized (lowercased)**, so `Router`, `router`, and
`ROUTER` refer to the same service. Ask for a name that isn't registered and you
get a `ServiceNotFound` exception.

---

## Registering a service

You register services declaratively in a `services.php` file (yours is
[config/services.php](../config/services.php); the kernel's is
[here](../vendor/orange/framework/src/config/services.php)). The container
understands four registration shapes, distinguished by the value's type and a
one-character name prefix:

### 1. Value / object

```php
'siteName' => 'Orange Portfolio',      // scalar
'clock'    => new SystemClock(),        // pre-built object
```

Stored as-is and returned directly. Use this for constants and already-constructed
objects.

### 2. Closure (lazy factory) — the workhorse

```php
'pdo' => function ($container) {
    return new \PDO(/* … */);           // runs only on first request for 'pdo'
},
```

A closure is **lazy**: it doesn't run until the service is first requested, and
the container passes **itself in as the only argument** so the closure can resolve
its own dependencies:

```php
'RecordModel' => fn($container) => RecordModel::getInstance($container->pdo),
```

If a closure returns an **Orange singleton** (a class extending `Singleton` or
`SingletonArrayObject`), the container automatically stores that single instance
and hands the same one back on every future call — so you get singleton behavior
for free without writing caching logic.

### 3. Alias — `@`

A name starting with `@` registers an alternate name for an existing service:

```php
'@db' => 'pdo',     // container()->db  →  resolves to the 'pdo' service
```

Alias chains resolve up to 16 deep (loop protection).

### 4. Auto-wired class — `^`

A name starting with `^` maps to a **fully-qualified class name** the container
should build for you via reflection and the `#[AutoWire]` attribute:

```php
'^mailer' => \app\services\Mailer::class,
```

When `mailer` is first requested, the container reflects on the class, reads the
`#[AutoWire]` attributes on its constructor (or its public static `getInstance()`),
resolves each named service from the container, and passes them in. Details in the
**`#[AutoWire]`** section below.

---

## Lifecycle summary

- **Value/object** → returned every time as the same reference.
- **Closure** → executed on first access; if it returns an Orange singleton, the
  instance is cached and reused. (A closure returning a *non*-singleton object runs
  each time it's requested unless you cache it yourself.)
- **Auto-wired class** → constructed on first access; cached if it's an Orange
  singleton.

You can inspect what's registered with `container()->getServices()` (names) or
`var_dump(container())` (which triggers `__debugInfo()` showing each service's
type).

---

## The 3 attributes

Orange ships exactly three PHP attributes, all in
[vendor/orange/framework/src/attributes/](../vendor/orange/framework/src/attributes/).
Two of them (`#[AttachService]`, `#[AutoWire]`) are about pulling services *out*
of the container; the third (`#[Route]`) is about routing but is included here for
completeness. Each is a tiny class — the behavior lives in whatever *reads* the
attribute.

### `#[AttachService('name')]` — inject a service into a property

```php
use orange\framework\attributes\AttachService;

class MainController extends BaseController
{
    #[AttachService('data')]
    protected DataInterface $data;

    #[AttachService('view')]
    protected ViewInterface $view;
}
```

- **Target:** a class **property** (`Attribute::TARGET_PROPERTY`).
- **Who reads it:** `BaseController` — **not** the container. In its constructor,
  `BaseController::autoAttachService()` reflects over its own properties, finds
  each `#[AttachService]`, and does `$this->{prop} = container()->get('name')`.
- **Net effect:** declarative, constructor-free dependency injection for
  controllers. You list the services you want as typed properties; they're
  populated before your method runs.

`BaseController` itself attaches `config`, `input`, and `output` this way, so
those three are always available. `JsonController` adds `data`. You add whatever
else you need (`view`, a model service, etc.). See [Controllers](controllers.md).

> Because `#[AttachService]` is read by `BaseController`, it works out of the box
> in classes that extend it. In your own non-controller classes you'd either
> extend a base that scans for it, or use `#[AutoWire]` (below) instead.

### `#[AutoWire('name')]` — inject services into a constructor/factory

```php
use orange\framework\attributes\AutoWire;

class ReportBuilder
{
    #[AutoWire('pdo')]
    #[AutoWire('log')]
    public function __construct(protected \PDO $db, protected LogInterface $log)
    {
        // $db  ← container's 'pdo' service
        // $log ← container's 'log' service
    }
}
```

- **Target:** a **method** (`Attribute::TARGET_METHOD`) — in practice the
  constructor or a public static `getInstance()`.
- **Who reads it:** the **container**, during auto-wiring. When resolving a `^`
  class it calls `resolveAutoWireArgs()`, which reads the `#[AutoWire]` attributes
  **in declaration order**, resolves each named service, and passes them as
  positional arguments.
- **Which method is used:** the container prefers a **public constructor**; if the
  constructor isn't public it falls back to a **public static `getInstance()`**.
  The `#[AutoWire]` attributes are read off whichever method it actually calls.

Register the class with the `^` prefix so the container knows to auto-wire it:

```php
// config/services.php
'^reportBuilder' => \app\services\ReportBuilder::class,
```

Then `container()->reportBuilder` builds it with `pdo` and `log` injected.

> **`#[AttachService]` vs `#[AutoWire]`:** both inject container services, but at
> different points. `#[AttachService]` fills a *property* and is read by
> `BaseController` after construction. `#[AutoWire]` fills a *constructor
> argument* and is read by the container during construction. Use `#[AttachService]`
> in controllers; use `#[AutoWire]` for standalone services you register with `^`.
> Most app services are simpler to register as a **closure** in `services.php`
> (like `RecordModel`), which is why you'll see `#[AutoWire]` less often — it's
> there for when reflection-based wiring is cleaner than a hand-written closure.

### `#[Route($method, $url, $name)]` — declare a route on a controller method

```php
use orange\framework\attributes\Route;

#[Route('get', '/api/read/(\d+)', 'rest_read')]
public function read(string $id): string { /* … */ }
```

- **Target:** a **method** (`Attribute::TARGET_METHOD`).
- **Who reads it:** `RouterDetector` (in development) or the export step (for
  production), which scans your module files, reads each `#[Route]`, and builds
  the route table.
- **Arguments:** `$method` (string or array of HTTP verbs, or `'*'`), `$url` (a
  path, matched as a regex — capture groups become method arguments), `$name` (for
  `getUrl()` reverse lookups).

`#[Route]` is covered in full in **[Routing](routing.md)** — it's placed here only
so all three attributes are described in one place.

---

## Why this design

- **Declarative** — dependencies are visible as attributes/typed properties, not
  buried in `new` statements.
- **Lazy** — closures mean a service like `pdo` never connects to the database
  unless a request actually needs it.
- **Testable** — tests register fresh service instances on the container in
  `setUp()`, so each test gets isolated services (see the repo's `unittest/`).

Next: **[Routing →](routing.md)**
