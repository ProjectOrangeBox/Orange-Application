# orange/testkit

Shared PHPUnit base class (`unitTestHelper`) for the `orange/*` package test
suites.

## Why

The same `unittest/unitTestHelper.php` was copy-pasted into every package. Over
time 22 copies drifted into **6 different versions** — including some still
carrying a PHP 8.4+ `implicitly nullable parameter` deprecation, and others with
extra helpers (`ve()`, `makeTempDir()`/`removeTempDir()`) that never made it back
to the rest. `src/unitTestHelper.php` here is the **superset** of every variant,
deprecation-clean, in one place.

It keeps the historical global namespace and lowercase name so existing tests
(`class FooTest extends unitTestHelper`) work unchanged.

## Publishing (one-time)

This directory is a ready-to-publish package. To make it consumable by the other
packages:

1. Create the `ProjectOrangeBox/testkit` repo and push these files to it.
2. It is picked up by the OrangePackages composer repository the same way every
   other `orange/*` package is.

## Migrating a package to it

For each `orange/*` package (do the webapp itself once, too, so the standalone
`runUnitTests.sh` runners resolve the class):

1. Add to the package's `composer.json`:
   ```json
   "require-dev": {
       "orange/testkit": "dev-master"
   }
   ```
2. Delete the package's `unittest/unitTestHelper.php`.
3. In `unittest/bootstrap.php`, remove the line
   `require __DIR__ . '/unitTestHelper.php';` — the class now autoloads via
   Composer's classmap.

No test files change: they still `extends unitTestHelper`.

## Note

Because the `orange/*` packages are installed as `dev-master` git clones, the
`require-dev` edits above only take effect once each package's change is pushed
and re-installed (a local edit to a vendored package's `composer.json` is not
seen by Composer's resolver). Publish this package first, then migrate.
