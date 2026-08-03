# The Request Lifecycle

Every HTTP request follows the same fixed path. Understanding it once makes the
rest of the framework obvious, because every other feature plugs into a specific
point in this pipeline.

---

## 1. The front controller

There is exactly one public PHP entry point:
[htdocs/index.php](../htdocs/index.php). The web server is configured so that any
URL that is not a real static file is routed to it (see the server configs in the
repo [readme.md](../readme.md)). Everything above `htdocs/` — `.env`, `config/`,
`vendor/` — stays unreachable over HTTP.

The front controller does almost nothing itself:

```php
// htdocs/index.php
define('__ROOT__', realpath(__DIR__ . '/../'));   // project root
define('__WWW__', __ROOT__ . '/htdocs');          // public web root

if (file_exists(__ROOT__ . '/bootstrap.php')) {
    require_once __ROOT__ . '/bootstrap.php';      // optional early hook
}

require_once __ROOT__ . '/vendor/autoload.php';    // Composer autoloader

Application::make([__ROOT__ . '/.env'])->http();   // build + run
```

`__ROOT__` is the anchor for every path the framework computes. `Application::make()`
is a singleton factory: the first argument is the list of `.env` files to load.

> **Why no config directory is passed here.** `Application` only appends
> `config/{ENVIRONMENT}` to the config search path *when the caller supplies no
> directories*. Passing `config/` explicitly would silently disable the
> per-environment override folder — so `index.php` deliberately passes none. See
> [Configuration](configuration.md).

---

## 2. Bootstrap

`Application::http()` first calls `bootstrap('http', …)`
([Application.php](../vendor/orange/framework/src/Application.php)), which:

1. Defines core constants: `UNDEFINED`, `RUN_MODE`, and (via the environment
   load) `ENVIRONMENT`, `DEBUG`, `CHARSET`.
2. Loads the `.env` file(s) into an internal array (read later with the `env()`
   helper). `$_ENV` is unset afterward so nothing else can read it directly.
3. Builds the **cascading config directory list** and loads the `application`
   config to set PHP runtime state (error reporting, timezone, encoding, umask).
4. `preContainer()` — includes global helper files (this is how the
   [global helpers](global-helpers.md) become available), registers optional
   `errorHandler`/`exceptionHandler`, and defines config-driven constants.
5. `bootstrapContainer()` — runs the `container` closure from
   [config/services.php](../vendor/orange/framework/src/config/services.php) to
   build the [DI container](the-container.md).
6. `postContainer()` — exposes the application config inside the container as the
   `$application` service.

After bootstrap, the container exists and every service is reachable.

---

## 3. The pipeline (and its four events)

The heart of `http()` is short enough to read in full:

```php
// vendor/orange/framework/src/Application.php  (Application::http)
$this->container->events->trigger('before.router', $this->container->input);

$this->container->router->match(
    $this->container->input->requestUri(),
    $this->container->input->requestMethod()
);

$this->container->events->trigger('before.controller', $this->container->router, $this->container->input);

$this->container->output->write(
    $this->container->dispatcher->call($this->container->router->getRouterCallback())
);

$this->container->events->trigger('before.output', $this->container->router, $this->container->input, $this->container->output);

$this->container->output->send();

$this->container->events->trigger('before.shutdown', $this->container->router, $this->container->input, $this->container->output);
```

Step by step:

| Stage | What happens |
| ------- | -------------- |
| **`before.router`** | Fired before routing. Receives `input`. |
| **`Router::match()`** | Matches the request URI + method against the route table. On no match it throws `RouteNotFound` (→ a 404 via the [Error](error-handling.md) service). See [Routing](routing.md). |
| **`before.controller`** | Fired after a route is matched, before the controller runs. Receives `router` and `input`. |
| **`Dispatcher::call()`** | Instantiates the matched controller, attaches its `#[AttachService]` properties, invokes the matched method with the URL's captured arguments, and returns the method's **string** return value. See [Controllers](controllers.md). |
| **`Output::write()`** | Stores that string as the response body. |
| **`before.output`** | Fired after the body is built, before it's sent. Receives `router`, `input`, `output` — the last chance to alter the response. |
| **`Output::send()`** | Emits status code, headers, and body. See [Input & Output](input-and-output.md). |
| **`before.shutdown`** | Fired after the response is sent. For cleanup/metrics. |

---

## 4. Hooking the events

Events are registered in [config/event.php](../config/event.php) (currently empty
in this repo). An entry maps a trigger name to a listener:

```php
// config/event.php  (illustrative)
return [
    'before.output' => [
        // listener receives the same args the trigger passes
        [function ($router, $input, $output) {
            $output->header('X-Powered-By: Orange');
        }],
    ],
];
```

This is the framework's extension seam: you change behavior around each stage
without editing kernel code. The webapp uses `before.output`, for example, to
merge flash messages into JSON responses.

> **Internally**, the event system (`orange\framework\Event`) supports
> priority-ordered listeners; the array shape above is the common case. You
> mostly just need to know the four trigger names and their arguments.

---

## 5. When something throws

Any uncaught `Throwable` is caught by the `Error` service
([Error.php](../vendor/orange/framework/src/Error.php)). It inspects the request
type (HTML / AJAX / CLI) and the error code, renders the best-matching error
view, sends the correct HTTP status, and exits. The full resolution order is in
[Error handling & error views](error-handling.md).

---

Next: **[HMVC & modules →](hmvc-and-modules.md)**
