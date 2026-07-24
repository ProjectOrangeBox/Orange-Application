# Views

A view is a **plain PHP template** that produces a string. There is no templating
language, no compile step, no `{{ }}` syntax — just PHP files with data variables
in scope. The View service's job is to turn a short view *name* into a *file path*,
run that file with your data available, and capture its output as a string.

Source: [ViewAbstract.php](../vendor/orange/framework/src/abstract/ViewAbstract.php)
(the concrete `View` class is a thin subclass).

---

## Rendering a view

You call `render()` and return its result:

```php
return $this->view->render('main/index');
```

- `'main/index'` is a **view name**, not a path — no `.php`, no directory prefix.
- The View service searches its registered directories for a file matching
  `main/index.php` and renders the first one it finds.
- The return value is the rendered **string** (the controller returns it to the
  dispatcher).

To pass data specific to this render, give `render()` a second argument; it's
merged over the shared `data` service for this call only:

```php
return $this->view->render('main/index', ['title' => 'Home']);
```

---

## How a view name becomes a file: the search path

The View service holds an ordered list of directories (a `DirectorySearch`) and
finds the **first** directory containing the named file. The path is built from
two sources, in priority order:

1. **The controller's module `views/` directory (highest priority).** If a
   controller has a `$view` property, `BaseController` adds its sibling `views/`
   folder to the **front** of the search path (see [Controllers](controllers.md)).
   So inside the `welcome` module, `render('main/index')` resolves to:

   ```
   application/welcome/views/main/index.php
   ```

2. **The configured view paths**, then the kernel's default views directory
   (lowest priority), from
   [config/view.php](../vendor/orange/framework/src/config/view.php):

   ```php
   'view paths'         => [],                    // add your own shared paths here
   'default view paths' => [__DIR__ . '/../views'],
   'extension'          => '.php',
   ```

Because the module directory is searched first, each module renders its own
templates, and shared/fallback templates live in the configured paths. If no
directory contains the file, `render()` throws `ViewNotFound`.

> **You can register more directories at runtime** via
> `$this->view->search()->addDirectory($path, DirectorySearch::FIRST|LAST)` — this
> is exactly what `BaseController` does for the module views folder.

### Aliases

`config/view.php`'s `view aliases` map one view name to another, and
`$this->view->addAlias('short', 'some/long/path')` does it at runtime. Rendering
`short` then renders `some/long/path`.

---

## The `data` service

Data reaches a view through the shared **`data`** service
([Data.php](../vendor/orange/framework/src/Data.php)) — an array-like object you
can access by property *or* array syntax. In a controller:

```php
$this->data['name'] = 'Johnny Appleseed';       // array style
$this->data->name   = 'Johnny Appleseed';       // property style
$this->data->merge([                             // many at once
    'h1'       => 'Hello World!',
    'position' => 'Head Bottle Washer',
]);
```

At render time, the View service `extract()`s the data into the template's scope,
so each key becomes a local variable:

```php
<!-- application/welcome/views/main/index.php -->
<h1 class="masthead-heading"><?= $h1 ?></h1>
<p class="masthead-subheading"><?= $position ?></p>
<p class="lead">Ipsum dolor sit <?= $cash ?> amet…</p>
```

`$h1`, `$position`, `$cash` are the data keys you set. That's the entire data-flow
contract: **set keys on `data` in the controller → use them as `$variables` in the
view.**

> **Escaping is your job.** Because templates are raw PHP, output is not
> auto-escaped. Use `htmlspecialchars()` / `<?= e($x) ?>`-style escaping for any
> value that could contain user input. (The framework escapes its own raw error
> output, but application views are yours to secure.)

> **Static-analysis note.** Because view variables are injected at render time,
> PHPStan and Rector can't see them — so `phpstan.neon` and `rector.php` both
> **exclude `application/*/views/*`**. Don't be surprised that view files aren't
> linted the way the rest of the app is.

---

## Partials

A partial is just a plain `include` of another template file — no special API.
The index view composes the page from partials:

```php
<!-- application/welcome/views/main/index.php -->
<?php include __DIR__ . '/../partials/header.php' ?>
<?php include __DIR__ . '/../partials/nav.php' ?>
<!-- … page body … -->
<?php include __DIR__ . '/../partials/footer.php' ?>
```

Included partials share the same variable scope, so `header.php` can use `$h1`,
`$css`, etc. directly:

```php
<!-- application/welcome/views/partials/header.php -->
<title><?= $h1 ?></title>
<?= $css ?>
```

---

## `render()` vs `renderString()`

| | `render($name, $data)` | `renderString($template, $data)` |
| --- | --- | --- |
| Input | a view **name** resolved on the search path | a raw template **string** |
| Use when | rendering a file you wrote | the template comes from a DB row, config, etc. |
| Under the hood | finds and `require`s the file | writes the string to a hashed temp file, then `require`s it |

`renderString()` compiles the string to a file under the configured `temp
directory` (default the system temp dir) so it can be `require`d like any other
template. It's the same rendering engine; only the source differs.

---

## Dynamic views (advanced, off by default)

When `allow dynamic views` is enabled in `config/view.php` **and** the View
service has the router, view names may contain placeholders resolved from the
matched route: `$c` (controller), `$m` (method), `$1`/`$2` (namespace segments).
For example an empty name resolves to `"$c/$m"` — the current controller/method.
This is off by default (`'allow dynamic views' => false`); most apps name their
views explicitly as shown above.

Next: **[Input & Output →](input-and-output.md)**
