# Getting Started with Orange

A practical guide to building applications on the **Orange Framework**
(`vendor/orange/framework`) — the lightweight PHP 8.4 MVC kernel that powers
this repository.

This guide is **usage-first**: it explains how you, as an application developer,
add routes, controllers, views, config, and services — and it opens the hood on
the kernel only where doing so makes you more effective. Every example is real
code from this repository (`application/` and `config/`), not invented
snippets.

> **Framework vs. application.** `vendor/orange/framework` is the kernel and is
> installed as a Composer dependency. `application/` and `config/` are
> *your* code — that's where almost everything in this guide happens. You rarely
> touch the kernel; you build on top of it.

---

## The 60-second mental model

A request enters through one front controller and flows through a fixed pipeline:

```text
htdocs/index.php
   │  define __ROOT__, load autoloader
   ▼
Application::make(['.env'])->http()
   │  load env → cascade config → build DI container
   ▼
before.router  ──▶  Router::match(uri, method)
   │                       finds the route → [Controller, 'method'] + captured args
   ▼
before.controller ─▶ Dispatcher::call(...)
   │                       new Controller() → attaches #[AttachService] props
   │                       → runs the method → returns a string
   ▼
before.output  ──▶  Output::send()
   │                       writes body + headers + status code
   ▼
before.shutdown
```

If anything throws along the way, the `Error` service takes over and renders an
error page appropriate to the request type (HTML page, JSON body, or CLI text).

Everything the framework offers is reached through the **DI container**: services
like `router`, `input`, `output`, `view`, `data`, and `config` are registered
once and pulled out wherever you need them — usually via the `#[AttachService]`
attribute on a controller property.

---

## How to read this guide

Read these roughly in order the first time; after that, treat each as a reference.

| # | Page | What it covers |
| --- | ------ | ---------------- |
| 1 | [The request lifecycle](request-lifecycle.md) | The front controller, the pipeline, and the four events you can hook |
| 2 | [HMVC & modules](hmvc-and-modules.md) | How each directory under `application/` is a self-contained module, and how to add one |
| 3 | [Configuration & cascading config](configuration.md) | How `config/*.php` files merge across directories and environments; `.env`; `ConfigurationTrait` |
| 4 | [The DI container & the 3 attributes](the-container.md) | Registering/resolving services, and how `#[AttachService]`, `#[AutoWire]`, and `#[Route]` work |
| 5 | [Routing](routing.md) | The two routing mechanisms, `#[Route]`, `RouterDetector`, named URLs, production route export |
| 6 | [Controllers](controllers.md) | `BaseController`, `JsonController`, attaching services (and why attachment is eager), local libraries |
| 7 | [Views](views.md) | How a view name becomes a file, the generated view map, `render()` vs `renderString()`, passing data, partials |
| 8 | [Input & Output](input-and-output.md) | Reading the request; building and sending the response |
| 9 | [Error handling & error views](error-handling.md) | How `Error` selects an error view from `errors/{env}/{type}/{code}.php` |
| 10 | [Global helper functions](global-helpers.md) | The wrappers in `wrappers.php`: `container()`, `config()`, `getUrl()`, `input()`, `output()`, `env()`, `logMsg()` |
| 11 | [Tutorial: build a module from scratch](tutorial-build-a-module.md) | A hands-on walkthrough that ties everything together |

---

## Conventions used in this guide

- **File references** point at real files, e.g.
  [application/welcome/controllers/MainController.php](../application/welcome/controllers/MainController.php).
- **"Kernel"** means `vendor/orange/framework/src`. When a path starts with
  `orange\framework\…` it's a kernel class.
- Code blocks are copied from the repo where possible; where a block is
  illustrative it's noted.

Prerequisites and how to run the app locally live in the repo
[readme.md](../readme.md). This guide assumes you already have it running at
`http://localhost:8080` (Docker) or `http://127.0.0.1:8000` (`php -S`).

Next: **[The request lifecycle →](request-lifecycle.md)**
