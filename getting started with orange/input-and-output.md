# Input & Output

These two services are the request and the response. **`input`** reads everything
that came in; **`output`** builds and sends everything that goes back. Both are
attached to `BaseController` (`input` and `output` are always available in a
controller), and both have global helper shortcuts — `input()` and `output()`.

Sources: [Input.php](../vendor/orange/framework/src/Input.php),
[Output.php](../vendor/orange/framework/src/Output.php).

---

## Input — reading the request

`Input` normalizes the various PHP superglobals and request metadata behind a
uniform API. Every accessor follows the same convention: **call it with no
argument to get the whole collection, or with a key (and optional default) to get
one value.**

```php
$this->input->request('email', null);   // one POST/PUT/body field, or null
$this->input->request();                 // the whole request body/params array
$this->input->query('page', 1);          // ?page= from the query string
$this->input->cookie('session');         // a cookie
$this->input->server('HTTP_HOST');       // a $_SERVER value
$this->input->header('Authorization');   // a request header
$this->input->file('upload');            // an uploaded file
$this->input->inputStream();             // the raw request body (e.g. JSON)
```

`RestController::create()` uses `request()` to pull the parsed JSON body straight
into a DTO:

```php
$record = new RecordDto($this->input->request());
```

### Request metadata

```php
$this->input->requestUri();       // the path that gets routed, e.g. '/api/read/42'
$this->input->requestMethod();    // 'get', 'post', … (lowercase by default)
$this->input->requestType();      // 'html' | 'ajax' | 'cli'  (see below)
$this->input->uriSegment(2);      // the Nth path segment
$this->input->contentType();      // request Content-Type
$this->input->isAjaxRequest();    // bool
$this->input->isCliRequest();     // bool
$this->input->isHttpsRequest();   // bool (or 'https'/'http' string)
```

### Method override

`requestMethod()` honors override conventions so HTML forms (which can only send
GET/POST) can stand in for PUT/DELETE/PATCH. It checks, in order: the
`X-HTTP-Method-Override` header, a `_method` query param, a `_method` body field,
then the real `REQUEST_METHOD`. If none exist it's a CLI invocation.

### Request type (drives content negotiation)

`requestType()` returns one of three values, and it's what the framework uses to
decide *how* to talk back — an HTML page, a JSON body, or plain CLI text:

| Returns | When |
| --------- | ------ |
| `'ajax'` | `X-Requested-With: XMLHttpRequest`, **or** an `Accept` header containing `application/json` |
| `'cli'`  | running under the CLI SAPI / stdin |
| `'html'` | everything else |

This same value selects which error template renders — see
[Error handling](error-handling.md).

> **Input is read-only.** Like `config`, the `Input` service rejects writes
> (`$input['x'] = …` throws). It represents the request as it arrived.

---

## Output — building the response

`Output` is a **buffer**: the dispatcher writes your controller's returned string
into it, then the pipeline calls `send()`. You shape the response by chaining
methods on it (most return `self`).

```php
$this->output
    ->responseCode(201)          // HTTP status
    ->contentType('json');       // maps a short name → a full MIME type
```

That two-line chain is exactly what `JsonController::response()` does under the
hood.

### The methods you'll use

| Method | Effect |
| -------- | -------- |
| `write(string $s, bool $append = true)` | Append (or replace) the response body. The dispatcher calls this with your controller's return value. |
| `responseCode(int|string $code)` | Set the HTTP status code. |
| `contentType(string $type, string $fallback = '')` | Set the content type. `'json'`, `'html'`, etc. are resolved to real MIME types via the `mimes` config. |
| `charSet(string $cs)` | Set the response charset. |
| `header(string $value, …)` | Add a raw response header. |
| `redirect(string $url, int $code = 0, bool $exit = true)` | Send a redirect (and exit by default). |
| `send(bool|int $exit = false)` | Emit status + headers + body. Called for you by the pipeline. |
| `flush()` / `flushAll()` / `flushHeaders()` | Clear buffered body/headers. |
| `get()` | Return the currently buffered body string. |

### You return a string; you don't `echo`

The normal flow is: **your controller returns a string → the dispatcher
`write()`s it → the pipeline `send()`s it.** You rarely call `write()` or `send()`
yourself. What you *do* reach for is `responseCode()`, `contentType()`,
`header()`, and `redirect()` to shape the response around that body.

```php
// a controller that redirects instead of returning HTML
public function save(): string
{
    // … persist …
    $this->output->redirect(getUrl('home'));   // exits; never returns
    return '';                                   // satisfies the string return type
}
```

### CORS & HTTPS

`Output` reads [config/output.php](../config/output.php) for CORS settings:

```php
return [
    'enable cors'  => true,
    'allowed cors' => ['http://localhost:3000'],
];
```

`handleCors()` applies those, and `forceHttps()` can upgrade a request to HTTPS.
These are wired through config rather than called by hand in typical use.

---

## Global shortcuts

When you're outside a controller (a library, a helper), reach the same services
with the [helper functions](global-helpers.md):

```php
$id = input()->request('id');
output()->responseCode(204)->send();
```

Next: **[Error handling & error views →](error-handling.md)**
