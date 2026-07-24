# Orange Framework (Orange-Application)

A lightweight PHP MVC framework + example application.

**Project note:** This framework has powered real sites, but today it’s primarily maintained as a portfolio-quality codebase and a reference implementation.

---

## Table of contents

- [Orange Framework (Orange-Application)](#orange-framework-orange-application)
  - [Table of contents](#table-of-contents)
  - [Features](#features)
  - [Requirements](#requirements)
  - [Quick start](#quick-start)
  - [Configuration (.env)](#configuration-env)
  - [Run locally](#run-locally)
    - [Option A: PHP built-in server (fastest to start)](#option-a-php-built-in-server-fastest-to-start)
    - [Option B: FrankenPHP (what Docker uses)](#option-b-frankenphp-what-docker-uses)
    - [Option C: Apache](#option-c-apache)
    - [Option D: Nginx](#option-d-nginx)
  - [Project structure](#project-structure)
  - [Testing](#testing)
  - [Architecture overview](#architecture-overview)
  - [Recommended libraries (optional)](#recommended-libraries-optional)
  - [License](#license)

---

## Features

- Classic MVC request lifecycle (Front Controller → Router → Dispatcher → Controller → View → Output)
- Dependency Injection container for services
- Environment-based configuration via `.env`
- Centralized error handling and logging
- HMVC-style application layout included under `/application`

---

## Requirements

**Docker (recommended):**

- Docker with Compose v2

**Or a local PHP toolchain:**

- PHP **8.2+** (the Docker image uses 8.4)
- Composer
- Git
- A web server (FrankenPHP, Apache, or Nginx) **or** PHP’s built-in dev server

---

## Quick start

Clone the repository:

```bash
git clone https://github.com/ProjectOrangeBox/Orange-Application.git webapp
cd webapp
```

### With Docker (recommended)

Build and start the container:

```bash
docker compose up -d --build
```

That's it — the container's entrypoint prepares the writable `var/` directories,
seeds `.env` from `env.sample` (only if one doesn't already exist), and runs
`composer install` on first start. The app is then served at:

```text
http://localhost:8080
https://localhost:8443
```

The container runs [FrankenPHP](https://frankenphp.dev/) (PHP 8.4 with an
embedded Caddy web server), which terminates TLS itself — `localhost` gets a
self-signed certificate, so expect a browser warning on the HTTPS port.

Common commands:

```bash
docker compose logs -f          # follow logs (incl. the startup/composer output)
docker compose exec web bash    # shell into the container
docker compose down             # stop and remove the container
```

The source directory is mounted into the container, so code edits are picked up
live — no rebuild needed. Dependencies (`vendor/`) install into your working copy
on first run; delete `vendor/` and restart to reinstall.

#### Serving modes

FrankenPHP serves the app in one of two modes, selected by the `ENVIRONMENT`
value in `.env`. The entrypoint reads it once at startup, so **switching modes
requires a container restart** (`docker compose restart web`):

| `ENVIRONMENT` | Mode | Behavior |
| --- | --- | --- |
| anything but `production` (e.g. `development`) | **Classic** | PHP is re-read from disk on every request. Edits appear immediately on reload — best for local development. |
| `production` | **Worker** | The app boots once and stays resident in memory between requests, so it is much faster. Code is held in memory — restart the container to pick up a deploy. |

**How to set the mode:** edit `ENVIRONMENT` in `.env`, then restart:

```bash
# Classic mode (live-reload development)
ENVIRONMENT=development

# Worker mode (fast, production)
ENVIRONMENT=production
```

```bash
docker compose restart web   # apply the change
```

Worker mode runs [worker.php](worker.php), which sits outside `htdocs/` and so
is never reachable over HTTP. It builds a fresh DI container per request, so no
request state leaks between requests. `SERVER_NAME` and `MAX_REQUESTS` in `.env`
tune the hostname/TLS certificate and worker recycling — see [env.sample](env.sample)
for the full documentation.

### Without Docker

Install PHP dependencies:

```bash
composer install
```

Copy the sample environment file and create the writable directories:

```bash
cp env.sample .env
mkdir -p var/logs var/cache var/uploads var/downloads var/temp var/working
chmod -R 777 var
```

Now point your web server document root to:

```text
./htdocs
```

---

## Configuration (.env)

This project uses a `.env` file (INI format) to keep environment-specific configuration out of source control.

Typical values include:

- Database credentials
- API keys
- Environment toggles (dev/test/prod)

**Important:** `.env` should never be committed. This repo’s `.gitignore` already excludes it.

In application code, you can read values via the global helper:

```php
env('KEY_NAME', 'default-value');
```

---

## Run locally

The Docker setup above (FrankenPHP) is the recommended way to run this project,
but the app is a plain PHP front-controller and will run under any web server.
Two rules apply to all of them:

- The public **document root is `htdocs/`** — everything above it (`.env`,
  `config/`, `vendor/`, `worker.php`) must stay unreachable over HTTP.
- Every request that is **not a real file must be routed to `htdocs/index.php`**,
  the single front controller. The framework reads the original `REQUEST_URI`,
  so no special path rewriting is needed beyond that.

### Option A: PHP built-in server (fastest to start)

Great for quick local work (not for production). From the repo root:

```bash
php -S 127.0.0.1:8000 -t htdocs
```

Then open:

```text
http://127.0.0.1:8000
```

### Option B: FrankenPHP (what Docker uses)

[FrankenPHP](https://frankenphp.dev/) is a single binary that bundles PHP with
the Caddy web server, and is what the Docker container runs. The included
[Caddyfile](Caddyfile) is the reference configuration — its `php_server`
directive serves static files from `htdocs/` and routes everything else to
`index.php`. It also drives the two [serving modes](#serving-modes) (classic vs.
worker) described above.

To run FrankenPHP directly (outside Docker), [install it](https://frankenphp.dev/docs/#installation)
and serve `htdocs/`:

```bash
frankenphp php-server -r htdocs/
```

### Option C: Apache

Point a virtual host's `DocumentRoot` at `htdocs/` and allow `.htaccess`
overrides (`AllowOverride All`) with `mod_rewrite` enabled. The included
[htdocs/.htaccess](htdocs/.htaccess) already contains the front-controller
rewrite:

```apache
RewriteEngine On
RewriteBase /
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php/$1 [L]
```

### Option D: Nginx

Nginx doesn't read `.htaccess`, so add the equivalent front-controller routing
to your server block (PHP served by php-fpm):

```nginx
server {
    listen 80;
    server_name localhost;
    root /path/to/webapp/htdocs;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php-fpm.sock;   # or 127.0.0.1:9000
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

---

## Project structure

```text
application/   # HMVC modules (examples: people, rest, shared, welcome)
config/        # configuration
htdocs/        # public web root (index.php entry point)
install/       # install script
bin/           # scripts/utilities
vendor/        # Composer dependencies
Caddyfile      # FrankenPHP/Caddy web server config (baked into the image)
worker.php     # FrankenPHP worker entry point (production mode only)
env.sample     # documented .env template
```

---

## Testing

Framework unit tests live in:

```text
/vendor/orange/framework/bin/tests/runUnitTests.sh
```

Run them (after installation):

```bash
bash ./vendor/orange/framework/bin/tests/runUnitTests.sh
```

If core framework tests fail, higher-level features may fail as well.

---

## Architecture overview

High-level request flow:

```text
index.php → Application → Container → Input → Router → Dispatcher → Controller → View → Output
          → Error (on exception)
```

For the deeper walkthrough and lifecycle notes, see:

- [overview.md](https://github.com/ProjectOrangeBox/Orange-Application/blob/main/overview.md)
- [lifecycle.png](https://github.com/ProjectOrangeBox/Orange-Application/blob/main/lifecycle.png)

---

## Recommended libraries (optional)

- **Carbon** — Date/time utilities: https://carbon.nesbot.com/
- **Phinx** — Database migrations: https://phinx.org/

---

## License

MIT (see `LICENSE`)