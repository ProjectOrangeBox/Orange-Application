# Controllers

A controller is the class whose method a route points at. It reads input, does
work (usually via a model/service), and **returns a string** — the response body.
The [Dispatcher](../vendor/orange/framework/src/Dispatcher.php) instantiates the
controller and calls the matched method; you never construct a controller
yourself.

Two base classes are provided (extending them is optional but conventional):

- `orange\framework\controllers\BaseController` — for HTML pages.
- `orange\framework\controllers\JsonController` — for JSON/REST APIs.

---

## `BaseController` (HTML)

Source: [BaseController.php](../vendor/orange/framework/src/controllers/BaseController.php).

A minimal HTML controller from this repo:

```php
// application/welcome/controllers/MainController.php
class MainController extends BaseController
{
    #[AttachService('data')]
    protected DataInterface $data;

    #[AttachService('view')]
    protected ViewInterface $view;

    #[Route('*', '/', 'home')]
    public function index(): string
    {
        $this->data->merge([
            'h1'       => $this->config['application']['h1'],
            'position' => $this->config['application']['position'],
            'cash'     => '19.95',
        ]);

        $this->data['name'] = 'Johnny Appleseed';   // one at a time

        return $this->renderView('main/index');    // returns a string
    }
}
```

Three things are happening, all covered below: **service attachment**, **locating
the view by name**, and the method **returning a string**.

### What `BaseController` gives you for free

`BaseController`'s constructor runs three steps before your method:

1. **Attaches `#[AttachService]` properties.** It reflects over its own properties
   and populates each one marked with the attribute from the container. It always
   attaches `config`, `input`, and `output`:

   ```php
   #[AttachService('config')] protected ConfigInterface $config;
   #[AttachService('input')]  protected InputInterface  $input;
   #[AttachService('output')] protected OutputInterface $output;
   ```

   You add more by declaring more `#[AttachService]` properties (like `data` and
   `view` above). See [the container](the-container.md) for how the attribute
   works.

2. **Loads local libraries.** Any filenames listed in
   `protected array $libraries` are `include_once`'d from
   `<module>/libraries/<name>.php` before your method runs — the framework's
   answer to helper/model include files:

   ```php
   protected array $libraries = ['helpers', 'formatting'];
   // → includes <module>/libraries/helpers.php and formatting.php
   ```

   A missing library file throws `FileNotFound`.

3. **Calls `beforeMethodCalled()` if you defined one.** Override this method to run
   setup common to every action in the controller (it runs after services are
   attached, before the routed method):

   ```php
   protected function beforeMethodCalled(): void
   {
       // e.g. require auth, set a layout, prime $this->data
   }
   ```

### Attachment is eager

Step 1 happens in the **constructor**, before your method is entered and before it
can decide whether it needs the service at all. Every `#[AttachService]` property
is therefore built on every request the controller serves, including the requests
that never touch it — and a service that cannot be built takes the whole page with
it, from a line of code your method never reached.

That is harmless for `config`, `input` and `view`, which cannot fail. It is not
harmless for anything standing in front of a network:

```php
#[AttachService('user')]        // -> acl -> pdo, built before index() starts
protected User $user;
```

This repo learned it the expensive way. `WebController` attached the `orange/acl`
user service so the shared navbar could say who was signed in. The container
resolves that as `user → acl → pdo`, so a checkout whose database was not yet
migrated answered **every** browser page with a stack trace — including the
marketing page, which mentions no user at all and needs no database to render.

The rule that follows: attach what the controller always needs, and resolve what
it only sometimes needs where it is needed, so failing to get it is a decision the
method makes rather than one the constructor makes for it.

```php
// application/controllers/WebController.php - resolved on first use,
// and a failure degrades the visitor to anonymous instead of to a 500
protected function userService(): ?User
{
    if ($this->userService === null) {
        try {
            $service = container()->get('user');

            $this->userService = $service instanceof User ? $service : false;
        } catch (PDOException | ModelException | MissingRequired $e) {
            $this->accountsUnavailable($e);
        }
    }

    return $this->userService === false ? null : $this->userService;
}
```

Note what is caught: three exceptions from three layers saying one thing. No
server (`PDOException`), a server with no schema (`ModelException`, `orange/model`
having wrapped the driver's error), and a schema with no seed data
(`MissingRequired`, `orange/acl` reporting its guest row missing). Catching only
the first is a fix that still breaks on a fresh clone, which is the case that
matters most.

A controller that genuinely cannot work without the service should still attach
it, and say so — `SessionController` attaches `user` precisely because logging
someone in *is* the accounts database.

### Returning output

The routed method must **return a string**. The dispatcher takes that return value
and writes it to the `output` service; you do **not** `echo`. Rendering a view
returns a string, which is why `return $this->renderView(...)` is the norm.

---

## `JsonController` (APIs)

Source: [JsonController.php](../vendor/orange/framework/src/controllers/JsonController.php).

Extends `BaseController` and adds a `data` service plus response helpers that set
the HTTP status + JSON content type and encode `$this->data`. From this repo's
REST controller:

```php
// application/api/controllers/RestController.php
class RestController extends JsonController
{
    #[AttachService('RecordModel')]
    protected RecordModel $recordModel;

    #[Route('get', '/api/read/(\d+)', 'rest_read')]
    public function read(string $id): string
    {
        $record = $this->recordModel->read((int)$id);

        if (!$record instanceof \application\api\models\RecordDto) {
            return $this->notFoundResponse('Record not found');   // 404 {"msg": …}
        }

        return $this->response(200, json_encode($record, $this->jsonFlags));
    }

    #[Route('post', '/api/create', 'rest_create')]
    public function create(): string
    {
        $record = new RecordDto($this->input->request());          // JSON body → DTO

        if (!$record->isValid()) {
            return $this->errorsResponse($record->allErrors());    // 422 {"errors": …}
        }

        $this->data->id = $this->recordModel->create($record);
        return $this->response(201);                               // 201 {"id": n}
    }
}
```

### The response helpers

| Method | Status | Body |
| -------- | -------- | ------ |
| `response(int $status = 200, ?string $raw = null)` | `$status` | `$raw` if given, else `$this->data` JSON-encoded |
| `listResponse(array $list, int $status = 200)` | `$status` | a top-level JSON **array** (not an object) |
| `errorsResponse(array $errors, int $status = 422)` | 422 | `{"errors": {field: [messages]}}` |
| `notFoundResponse(string $msg = 'Not Found')` | 404 | `{"msg": "…"}` |
| `noContentResponse()` | 204 | empty (the one success reply with no body) |

`response()` sets both the status code and the `json` content type on the `output`
service, then returns the body string — which your method returns to the
dispatcher. `$this->data` is the shared [data](views.md#the-data-service) service;
set fields on it (`$this->data->id = …`) and they become the JSON response.

`$jsonFlags` hardens the encoding (`JSON_HEX_*` to prevent breaking out of HTML
contexts, `JSON_UNESCAPED_UNICODE`, and `JSON_THROW_ON_ERROR` so an encode failure
throws at the source rather than silently returning `false`).

---

## Reaching services inside a controller

- **`config`, `input`, `output`** — always attached by `BaseController`.
- **`data`** — attached by `JsonController`; add `#[AttachService('data')]`
  yourself in an HTML controller (as `MainController` does).
- **Anything in the container** — declare a typed property with
  `#[AttachService('serviceName')]`. `RestController` pulls its model this way:
  `#[AttachService('RecordModel')]` (the service registered in
  [config/services.php](../config/services.php)).
- **Globally** — the [helper functions](global-helpers.md) `input()`, `output()`,
  `config()`, `getUrl()`, `container()` reach the same services without a property.

Next: **[Views →](views.md)**
