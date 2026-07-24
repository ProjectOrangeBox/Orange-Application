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

        return $this->view->render('main/index');    // returns a string
    }
}
```

Three things are happening, all covered below: **service attachment**, the
**automatic view directory**, and the method **returning a string**.

### What `BaseController` gives you for free

`BaseController`'s constructor runs four steps before your method:

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

3. **Registers the module's view directory.** *If the controller has a `$view`
   property*, `BaseController` finds the sibling `views/` folder two levels up from
   the controller file (`.../controllers/Main.php` → `.../views`) and adds it to
   the **top** of the view search path. That's why `render('main/index')` in the
   `welcome` module resolves to `application/welcome/views/main/index.php`
   automatically. See [Views](views.md).

4. **Calls `beforeMethodCalled()` if you defined one.** Override this method to run
   setup common to every action in the controller (it runs after services are
   attached, before the routed method):

   ```php
   protected function beforeMethodCalled(): void
   {
       // e.g. require auth, set a layout, prime $this->data
   }
   ```

### Returning output

The routed method must **return a string**. The dispatcher takes that return value
and writes it to the `output` service; you do **not** `echo`. Rendering a view
returns a string, which is why `return $this->view->render(...)` is the norm.

---

## `JsonController` (APIs)

Source: [JsonController.php](../vendor/orange/framework/src/controllers/JsonController.php).

Extends `BaseController` and adds a `data` service plus response helpers that set
the HTTP status + JSON content type and encode `$this->data`. From this repo's
REST controller:

```php
// api/controllers/RestController.php
class RestController extends JsonController
{
    #[AttachService('RecordModel')]
    protected RecordModel $recordModel;

    #[Route('get', '/api/read/(\d+)', 'rest_read')]
    public function read(string $id): string
    {
        $record = $this->recordModel->read((int)$id);

        if (!$record instanceof \api\models\RecordDto) {
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
