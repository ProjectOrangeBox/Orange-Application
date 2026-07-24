# Tutorial: Build a Module From Scratch

This walkthrough ties the whole guide together. You'll add a brand-new **`quotes`**
module with:

- an HTML page at `/quotes` listing quotes,
- a JSON endpoint at `/quotes/api` returning the same data,
- its own config file (demonstrating the [config cascade](configuration.md)),
- its own view (demonstrating [view resolution](views.md)),
- routes via `#[Route]` attributes (demonstrating [routing](routing.md)),
- services pulled in via `#[AttachService]` (demonstrating [the container](the-container.md)).

No database is needed — the data comes from a config file — so you can run it
immediately. Everything happens in **your** code; you won't touch the kernel.

Assumes the app is running locally (`http://localhost:8080` or
`http://127.0.0.1:8000`). See the repo [readme.md](../readme.md).

---

## Step 1 — Create the module folders

From the repo root:

```bash
mkdir -p quotes/controllers quotes/views/quotes
```

Target layout:

```text
quotes/
├── controllers/
│   ├── QuotesController.php     ← the HTML page
│   └── QuotesApiController.php  ← the JSON endpoint
└── views/
    └── quotes/
        └── index.php            ← the page template
```

---

## Step 2 — Register the PSR-4 namespace

Add the `quotes\` root to [composer.json](../composer.json)'s `autoload.psr-4`:

```json
"autoload": {
    "psr-4": {
        "application\\": "application",
        "api\\":         "api",
        "quotes\\":      "quotes"
    }
}
```

Regenerate the autoloader so `quotes\...` classes resolve:

```bash
composer dump-autoload
```

---

## Step 3 — Add a config file (and see the cascade)

Create `config/quotes.php`. Config files just return an array; the basename
(`quotes`) is the key you'll read it by.

```php
<?php

declare(strict_types=1);

return [
    'heading' => 'Words to Code By',
    'list'    => [
        ['text' => 'Make it work, make it right, make it fast.', 'who' => 'Kent Beck'],
        ['text' => 'Premature optimization is the root of all evil.', 'who' => 'Donald Knuth'],
        ['text' => 'Programs must be written for people to read.', 'who' => 'Harold Abelson'],
    ],
];
```

Because config [cascades by environment](configuration.md), you could later drop a
`config/production/quotes.php` that overrides just `heading` — without repeating
the list. That's the cascade in action; for now one file is enough.

---

## Step 4 — Write the HTML controller

Create `quotes/controllers/QuotesController.php`:

```php
<?php

declare(strict_types=1);

namespace quotes\controllers;

use orange\framework\attributes\AttachService;
use orange\framework\attributes\Route;
use orange\framework\controllers\BaseController;
use orange\framework\interfaces\DataInterface;
use orange\framework\interfaces\ViewInterface;

class QuotesController extends BaseController
{
    // pulled from the container by BaseController before index() runs.
    // Declaring $view also makes BaseController auto-register this module's
    // views/ directory at the top of the view search path.
    #[AttachService('data')]
    protected DataInterface $data;

    #[AttachService('view')]
    protected ViewInterface $view;

    #[Route('get', '/quotes', 'quotes_index')]
    public function index(): string
    {
        // config['quotes'] is config/quotes.php, cascaded and merged for us
        $this->data->merge([
            'heading' => $this->config['quotes']['heading'],
            'quotes'  => $this->config['quotes']['list'],
        ]);

        // resolves to quotes/views/quotes/index.php; returns a string
        return $this->view->render('quotes/index');
    }
}
```

What's happening, mapped to the guide:

- `extends BaseController` → gets `config`, `input`, `output` for free
  ([Controllers](controllers.md)).
- `#[AttachService('data')]` / `#[AttachService('view')]` → those services are
  injected into the properties ([the container](the-container.md)).
- The `$view` property → `BaseController` adds `quotes/views/` to the front of the
  search path, so `render('quotes/index')` finds this module's template
  ([Views](views.md)).
- `#[Route('get', '/quotes', 'quotes_index')]` → the URL, discovered by
  `RouterDetector` ([Routing](routing.md)).
- The method **returns a string** → the dispatcher sends it.

---

## Step 5 — Write the view

Create `quotes/views/quotes/index.php`. It's plain PHP; data keys are in scope as
variables. Note the `htmlspecialchars()` — application views escape their own
output.

```php
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= htmlspecialchars($heading) ?></title>
</head>
<body>
    <h1><?= htmlspecialchars($heading) ?></h1>
    <ul>
        <?php foreach ($quotes as $quote): ?>
            <li>
                &ldquo;<?= htmlspecialchars($quote['text']) ?>&rdquo;
                &mdash; <?= htmlspecialchars($quote['who']) ?>
            </li>
        <?php endforeach; ?>
    </ul>

    <!-- never hardcode a routed path: resolve it by name -->
    <p><a href="<?= getUrl('quotes_api') ?>">View as JSON</a></p>
</body>
</html>
```

`$heading` and `$quotes` are the keys you `merge()`d onto `data`. `getUrl('quotes_api')`
reverse-resolves the JSON route you're about to define ([global helpers](global-helpers.md)).

---

## Step 6 — Write the JSON controller

Create `quotes/controllers/QuotesApiController.php`. Extending `JsonController`
gives you the response helpers and a `data` service ([Controllers](controllers.md)):

```php
<?php

declare(strict_types=1);

namespace quotes\controllers;

use orange\framework\attributes\Route;
use orange\framework\controllers\JsonController;

class QuotesApiController extends JsonController
{
    #[Route('get', '/quotes/api', 'quotes_api')]
    public function index(): string
    {
        // listResponse encodes a top-level JSON array: [{"text":…,"who":…}, …]
        return $this->listResponse($this->config['quotes']['list']);
    }
}
```

`listResponse()` sets the `json` content type + `200` status and returns the
encoded array as the body ([Input & Output](input-and-output.md)).

---

## Step 7 — Tell the route scanner about the module

`RouterDetector` only scans the paths it's given. Add `quotes/` to the list in
[config/development/routes.php](../config/development/routes.php):

```php
'routes' => RouterDetector::detect(
    [__ROOT__ . '/application', __ROOT__ . '/api', __ROOT__ . '/quotes'],
    [
        ['url' => '/assets',     'name' => 'assets'],
        ['url' => '/assets/js',  'name' => 'javascript'],
        ['url' => '/assets/css', 'name' => 'css'],
        ['url' => '/images',     'name' => 'images'],
    ]
),
```

In development, routes are re-scanned each request, so there's nothing to rebuild.

---

## Step 8 — Try it

With `ENVIRONMENT=development` in `.env`:

```bash
# the HTML page
curl http://localhost:8080/quotes

# the JSON endpoint
curl http://localhost:8080/quotes/api
# → [{"text":"Make it work, make it right, make it fast.","who":"Kent Beck"}, …]
```

Or open `http://localhost:8080/quotes` in a browser and click **View as JSON**.

If you get a 404, check: the PSR-4 entry + `composer dump-autoload` (Step 2), the
`quotes/` path in `RouterDetector::detect()` (Step 7), and that
`ENVIRONMENT=development` (attribute scanning is dev-only).

---

## Step 9 — Ready it for production

`RouterDetector` won't scan in production, so regenerate the static route snapshot
whenever routes change ([Routing](routing.md)). Produce the source for
`config/production/routes.php` with `RouterDetector::export()` over the same paths,
then commit the result. Your two new routes (`quotes_index`, `quotes_api`) will
appear as literal entries alongside the existing ones.

---

## What you exercised

| Guide topic | Where it showed up |
| ------------- | -------------------- |
| [HMVC & modules](hmvc-and-modules.md) | new `quotes/` PSR-4 module, self-contained |
| [Configuration](configuration.md) | `config/quotes.php`, read via `$this->config['quotes']` |
| [The container + attributes](the-container.md) | `#[AttachService]` on `data`/`view` |
| [Routing](routing.md) | `#[Route]`, `RouterDetector` path, `getUrl()`, prod export |
| [Controllers](controllers.md) | `BaseController` + `JsonController`, string returns |
| [Views](views.md) | module `views/` auto-registered, data → template variables |
| [Input & Output](input-and-output.md) | `listResponse()` status + JSON content type |
| [Global helpers](global-helpers.md) | `getUrl('quotes_api')` in the view |

That's a complete feature, top to bottom, without editing a single kernel file —
which is exactly the point of the architecture.

← Back to the **[guide index](README.md)**
