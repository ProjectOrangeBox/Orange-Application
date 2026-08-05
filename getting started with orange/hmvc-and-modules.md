# HMVC & Modules

Orange organizes application code into **modules**. HMVC — Hierarchical
Model-View-Controller — means each module is a small, self-contained MVC
application, and modules can nest inside each other arbitrarily deep.

---

## What a module is

A module is a self-contained directory of MVC code. It does **not** need a PSR-4
root of its own — this repo registers exactly one in
[composer.json](../composer.json):

```json
"autoload": {
    "psr-4": {
        "application\\": "application"
    }
}
```

and every directory under `application/` is an independent module. Each owns its
own controllers, views, and — if it needs them — libraries, models and
sub-modules:

```text
application/
├── controllers/
│   └── WebController.php         ← shared by every browser-facing module
├── views/
│   └── partials/                 ← and the chrome they all render
│       ├── header.php
│       ├── nav.php
│       └── footer.php
├── welcome/                      ← a module
│   ├── controllers/
│   │   └── MainController.php    ← application\welcome\controllers\MainController
│   └── views/
│       └── main/
│           └── index.php         ← rendered by renderView('main/index')
├── login/                        ← another: sign-in, signup, password reset
└── api/
    ├── controllers/
    │   ├── RestController.php    ← application\api\controllers\RestController
    │   └── WelcomeController.php
    └── models/
        ├── RecordModel.php
        └── RecordDto.php
```

Notice `application/welcome` is itself nested under `application/` — HMVC nesting.
A controller's namespace mirrors its folder path exactly (PSR-4), which is what
lets the framework key that module's views under it and hand the controller its
own copy (see [Controllers](controllers.md) and [Views](views.md)).

---

## The rules that keep modules independent

1. **Modules depend on shared services, never on each other.** A controller in
   `application/api/` never `use`s a class from `application/orders/`. They
   communicate only through
   shared services from the [container](the-container.md) (`router`, `data`,
   `view`, `config`, a model registered in `services.php`, …).

2. **Each module carries its own views.** `renderView()` passes the controller's
   own PSR-4 namespace to `ViewFinder`, and the generated view map keys a module's
   templates under it — so `renderView('main/index')` finds *this* module's copy.
   That is decided by name, not by search order, so modules can't accidentally
   render each other's templates. See [Views](views.md).

3. **Routes are discovered per module.** `#[Route]` attributes are scanned across
   every registered module path (see below), so a module declares its own URLs
   right next to the code that handles them.

---

## What lives in a module

| Folder / property | Purpose |
| ------------------- | --------- |
| `controllers/` | Request handlers. Extend `BaseController` (HTML) or `JsonController` (APIs). |
| `views/` | Plain-PHP templates for that module (keyed in the view map under this module's namespace). |
| `libraries/` | Optional helper/model `.php` files `include_once`'d before the controller runs, listed in the controller's `protected array $libraries`. |
| `models/` | Optional. Plain classes (see `application/api/models/`) usually wired as services in `config/services.php`. |
| sub-folders | Nested sub-modules (like `application/welcome`). |

There is no required file beyond a controller — a module is just a folder tree
under a PSR-4 root.

---

## Adding a new top-level module

> **Most modules don't need this.** A directory under `application/` is already a
> module — Composer maps it, `RouterDetector` scans it, `ViewDetector` finds its
> views, and there is nothing to register. The steps below are for a genuinely
> new **PSR-4 root**, which is the only case that costs configuration.

Say you want a `blog/` root of its own. Four steps:

**1. Create the folder tree.**

```text
blog/
├── controllers/
│   └── PostController.php
└── views/
    └── post/
        └── index.php
```

**2. Register the PSR-4 root** in [composer.json](../composer.json):

```json
"psr-4": {
    "application\\": "application",
    "blog\\":        "blog"
}
```

Then regenerate the autoloader:

```bash
composer dump-autoload
```

**3. Tell the route scanner about the module.** In
[config/development/routes.php](../config/development/routes.php), add the module
path to the `RouterDetector::detect([...])` array (and to the matching
`export(...)` path list you use to build the production route file):

```php
'routes' => RouterDetector::detect(
    [__ROOT__ . '/application', __ROOT__ . '/blog'],
    [ /* getUrl-only entries */ ]
),
```

**4. Write the controller** with a `#[Route]` attribute:

```php
namespace blog\controllers;

use blog\controllers; // (illustrative)
use orange\framework\attributes\Route;
use orange\framework\controllers\BaseController;

class PostController extends BaseController
{
    #[Route('get', '/blog', 'blog_index')]
    public function index(): string
    {
        return $this->renderView('post/index');
    }
}
```

That's it. In development the route is discovered automatically on the next
request; for production you regenerate the route snapshot (see [Routing](routing.md)).

The full end-to-end version of this — with a view, config, and a service — is the
[build-a-module tutorial](tutorial-build-a-module.md).

Next: **[Configuration & cascading config →](configuration.md)**
