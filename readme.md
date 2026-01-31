# Orange Framework (Orange-Application)

A lightweight PHP MVC framework + example application.

> **Project note:** This framework has powered real sites, but today it’s primarily maintained as a portfolio-quality codebase and a reference implementation.

---

## Table of contents

- [Orange Framework (Orange-Application)](#orange-framework-orange-application)
  - [Table of contents](#table-of-contents)
  - [Features](#features)
  - [Requirements](#requirements)
  - [Quick start](#quick-start)
  - [Manual installation](#manual-installation)
  - [Configuration (.env)](#configuration-env)
  - [Run locally](#run-locally)
    - [Option A: PHP built-in server (fastest)](#option-a-php-built-in-server-fastest)
    - [Option B: Apache/Nginx](#option-b-apachenginx)
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

- PHP **8.2** (tested; older versions may work but are not verified)
- Composer
- Git
- A web server (Apache/Nginx) **or** PHP’s built-in dev server

---

## Quick start

Clone the repository:

```bash
git clone git@github.com:ProjectOrangeBox/Orange-Application.git webapp
cd webapp
```

Run the installer script:

```bash
cd install
./install.sh
```

Then install PHP dependencies:

```bash
cd ..
composer install
```

Now point your web server document root to:

```text
./htdocs
```

---

## Manual installation

If you prefer not to use `install.sh`, the script is essentially doing this:

```bash
mkdir -p packages
cd packages

git clone git@github.com:ProjectOrangeBox/OrangePackage.git Orange
git clone https://github.com/ProjectOrangeBox/Peels Peels

cd ..
cp ./support/samples/sample.env .env

composer install
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

### Option A: PHP built-in server (fastest)

From the repo root:

```bash
php -S 127.0.0.1:8000 -t htdocs
```

Then open:

```text
http://127.0.0.1:8000
```

### Option B: Apache/Nginx

Configure your vhost/site to use `htdocs/` as the document root.

---

## Project structure

```text
application/   # HMVC modules (examples: people, rest, shared, welcome)
config/        # configuration
htdocs/        # public web root (index.php entry point)
install/       # install script
packages/      # external packages cloned here (OrangePackage, Peels)
support/       # samples and helpers (sample.env, etc.)
bin/           # scripts/utilities
```

---

## Testing

Framework unit tests live in:

```text
/packages/orange/bin/tests/runUnitTests.sh
```

Run them (after installation):

```bash
bash ./packages/orange/bin/tests/runUnitTests.sh
```

If core framework tests fail, higher-level features may fail as well.

---

## Architecture overview

High-level request flow:

```text
index.php → Application → Container → Input → Router → Dispatcher
        → Controller → View → Output
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