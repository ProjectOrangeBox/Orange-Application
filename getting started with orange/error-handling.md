# Error Handling & Error Views

When any uncaught `Throwable` reaches the top of the request, the **`Error`**
service takes over. Its job is to turn that exception into a clean response whose
*format matches the request type* — an HTML page for a browser, a JSON body for an
AJAX/API call, plain text for the CLI — with the correct HTTP status code, then
exit.

Source: [Error.php](../vendor/orange/framework/src/Error.php), error views in
[vendor/orange/framework/src/views/errors/](../vendor/orange/framework/src/views/errors/).

---

## What happens on an exception

1. The exception's details are loaded into the `data` service: `message`, `code`,
   `file`, `line`, and the stack trace as `options`.
2. The error **code** is taken from the exception's code (if `> 0`), else defaults
   to `500`.
3. The **request type** (`html` / `ajax` / `cli`) is read from `Input::requestType()`
   — this decides which family of error views to look in.
4. An error **view** is resolved by code + type (the search order is below).
5. If a view is found it's rendered; if not, a safe raw fallback is emitted.
6. The HTTP status code and content type are sent, and the script exits.

You can also trigger this deliberately. The `showNNN()` helper family (in the
kernel's `helpers/errors.php`) throws the matching `HttpNNN` exception, which the
framework's `exceptionHandler` then routes through the `Error` service exactly as
above:

```php
show404();              // throws Http404  → 404 error page
show403('Forbidden');   // throws Http403  → 403 with a message
show500($msg);          // throws Http500
// also: show422(), show429(), show503(), redirect301($url), …
```

These `HttpNNN` exceptions carry their own `getHttpCode()`, so the correct HTTP
status is sent automatically (see below). There is no `error` container service to
call directly — you signal an error by throwing, and the handler renders it.

### Custom exceptions can steer the response

Before falling back to view lookup, `Error` checks the thrown exception for three
optional methods and defers to them if present:

- `getHttpCode()` → overrides the HTTP status sent.
- `getOutput()` → supplies the response body directly (skips view rendering).
- `decorate($error)` → hands the exception the `Error` object to customize freely.

This lets a domain exception carry its own presentation without the global handler
knowing about it.

---

## How an error view is picked

This is the part worth understanding precisely. The error views directory is
`errors` (from
[config/error.php](../vendor/orange/framework/src/config/error.php)'s
`'error view directory' => 'errors'`), resolved against the View service's search
path — so it finds the kernel's
[views/errors/](../vendor/orange/framework/src/views/errors/) unless your app
provides its own `errors/` directory earlier in the path.

Three values combine to locate a file:

- **`{errors}`** — the error view directory (`errors`).
- **`{env}`** — the current `ENVIRONMENT`, lowercased (`development`, `production`).
- **`{type}`** — the request type: `html`, `ajax`, or `cli`.
- **`{code}`** — the HTTP status if set, otherwise the error code (e.g. `404`, `500`).

`Error::findView()` tries these paths **in order** and uses the first that exists:

```text
1.  errors/{env}/{type}/{code}     e.g. errors/development/html/404
2.  errors/{type}/{env}/{code}     e.g. errors/html/development/404
3.  errors/{type}/{code}           e.g. errors/html/404          ← the common hit
4.  errors/{code}                  e.g. errors/404
5.  errors                         a single catch-all errors view
6.  {code}                         a bare 404 view anywhere on the path
```

So the resolution goes **most specific → most general**: an
environment-and-type-specific override first, then type-specific, then a plain
code view, then catch-alls. If none of the six exist, the raw fallback runs.

### What ships in the kernel

```text
vendor/orange/framework/src/views/errors/
├── html/          ← full styled HTML pages
│   ├── 401.php  ├── 403.php  ├── 404.php  └── 500.php
├── ajax/          ← JSON bodies
│   ├── 401.php  ├── 403.php  ├── 404.php  └── 500.php
└── cli/
    └── 500.php
```

These live at level **3** of the search order (`errors/{type}/{code}`), which is
why they're the default match. The `html/404.php` is a self-contained styled page
that echoes `$code` and `$message`; the `ajax/*` views `json_encode` the error
fields (in fact `ajax/404.php` just `require`s `ajax/500.php`, since the JSON shape
is identical). Error views receive the same `data` keys set in step 1
(`$code`, `$message`, `$file`, `$line`, `$options`).

### The raw fallback (when no view exists)

If `findView()` finds nothing, `viewRaw()` produces a format-appropriate response
without any template:

- **ajax/json** → `json_encode()` of the error fields.
- **html** → a `<pre>` block — and every field is `htmlspecialchars()`-escaped,
  because messages/paths/traces can echo back attacker-controlled input.
- **cli / other** → `print_r()` text.

---

## Overriding error views in your app

To customize an error page, **write a file with the same name** as the kernel's.
Application roots are scanned into the view map before vendor ones and the first
writer of a key keeps it, so your copy simply owns the key — the file existing is
the override, with nothing to register:

```text
application/<module>/views/errors/html/404.php
```

and it will win over the kernel's `404.php`. To differ by environment (say, a verbose 500 in
development but a terse one in production), use the environment-specific slots:

```text
errors/development/html/500.php     ← detailed, dev only
errors/production/html/500.php      ← friendly, prod only
```

Same file name, environment picks the winner — the same cascading idea as
[configuration](configuration.md), applied to views.

---

## Status code vs. error code

`Error` distinguishes the **HTTP status** actually sent from the internal **error
code**:

- If the exception (or `getHttpCode()`) provides an HTTP code, that's what's sent
  and what's used for view lookup.
- Otherwise the error code is used (defaulting to `500`).
- For CLI requests no HTTP status is emitted (there's no HTTP response to code).

Next: **[Global helper functions →](global-helpers.md)**
