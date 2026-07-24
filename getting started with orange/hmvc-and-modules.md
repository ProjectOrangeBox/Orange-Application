# HMVC & Modules

Orange organizes application code into **modules**. HMVC — Hierarchical
Model-View-Controller — means each module is a small, self-contained MVC
application, and modules can nest inside each other arbitrarily deep.

---

## What a module is

A module is a top-level PSR-4 root registered in
[composer.json](../composer.json). This repo has two:

```json
"autoload": {
    "psr-4": {
        "application\\": "application",
        "api\\":         "api"
    }
}
```

So `application/` (namespace `application\`) and `api/` (namespace `api\`) are two
independent modules. Each owns its own controllers, views, and — if it needs them
— libraries and sub-modules:

```text
application/
└── welcome/                     ← a module (here, a nested sub-module)
    ├── controllers/
    │   └── MainController.php    ← application\welcome\controllers\MainController
    └── views/
        ├── main/
        │   └── index.php         ← rendered by render('main/index')
        └── partials/
            ├── header.php
            ├── nav.php
            └── footer.php

api/
├── controllers/
│   ├── RestController.php        ← api\controllers\RestController
│   └── WelcomeController.php
└── models/
    ├── RecordModel.php
    └── RecordDto.php
```

Notice `application/welcome` is itself nested under `application/` — HMVC nesting.
A controller's namespace mirrors its folder path exactly (PSR-4), which is what
lets the framework locate the sibling `views/` directory automatically (see
[Controllers](controllers.md) and [Views](views.md)).

---

## The rules that keep modules independent

1. **Modules depend on shared services, never on each other.** A controller in
   `api/` never `use`s a class from `application/`. They communicate only through
   shared services from the [container](the-container.md) (`router`, `data`,
   `view`, `config`, a model registered in `services.php`, …).

2. **Each module carries its own views.** When a controller has a `$view`
   property, `BaseController` registers that controller's sibling `views/`
   directory at the top of the view search path — so `render('main/index')`
   resolves against *this* module's views first. Modules can't accidentally
   render each other's templates.

3. **Routes are discovered per module.** `#[Route]` attributes are scanned across
   every registered module path (see below), so a module declares its own URLs
   right next to the code that handles them.

---

## What lives in a module

| Folder / property | Purpose |
| ------------------- | --------- |
| `controllers/` | Request handlers. Extend `BaseController` (HTML) or `JsonController` (APIs). |
| `views/` | Plain-PHP templates for that module (auto-registered when a `$view` property exists). |
| `libraries/` | Optional helper/model `.php` files `include_once`'d before the controller runs, listed in the controller's `protected array $libraries`. |
| `models/` | Optional. Plain classes (see `api/models/`) usually wired as services in `config/services.php`. |
| sub-folders | Nested sub-modules (like `application/welcome`). |

There is no required file beyond a controller — a module is just a folder tree
under a PSR-4 root.

---

## Adding a new top-level module

Say you want a `blog/` module. Four steps:

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
    "api\\":         "api",
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
    [__ROOT__ . '/application', __ROOT__ . '/api', __ROOT__ . '/blog'],
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
        return $this->view->render('post/index');
    }
}
```

That's it. In development the route is discovered automatically on the next
request; for production you regenerate the route snapshot (see [Routing](routing.md)).

The full end-to-end version of this — with a view, config, and a service — is the
[build-a-module tutorial](tutorial-build-a-module.md).

Next: **[Configuration & cascading config →](configuration.md)**
