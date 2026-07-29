<?php

declare(strict_types=1);

namespace config\development;

// This file is namespaced, so framework classes and PHP reflection classes need
// explicit imports rather than relying on global-namespace resolution.
use orange\framework\exceptions\filesystem\DirectoryNotFound;
use orange\framework\exceptions\filesystem\DirectoryNotWritable;
use InvalidArgumentException;

/**
 * Development-only view scanner.
 *
 * The counterpart to RouterDetector: it walks the filesystem once in development
 * and exports a plain PHP array for production, so a request never has to search
 * directories to find a view file.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Two maps, because a view is addressed two ways
 *
 * 'views' - namespaced and unique. An application module's views are keyed by
 * the PSR-4 namespace that owns them, so nothing can collide:
 *
 *     application/welcome/views/main/index.php
 *         -> 'application/welcome/main/index'
 *     application/welcome/views/partials/header.php
 *         -> 'application/welcome/partials/header'
 *
 * 'view fallbacks' - the same views keyed by everything after their views/
 * directory, with no namespace at all. This is the map a *vendor* package's
 * views live in, and it is what makes overriding them work:
 *
 *     vendor/acme/blog/src/views/blog/post/show.php
 *         -> 'blog/post/show'
 *
 * Add application/welcome/views/blog/post/show.php to your own module and it
 * claims that same fallback key. Application roots are scanned before vendor
 * ones and the first writer of a key keeps it, so yours wins - no config edit,
 * no priority list, the file existing *is* the override.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * How a name resolves (BaseController::renderView(), step three)
 *
 * A controller renders 'blog/post/show'. renderView() prefixes its own
 * namespace and the view layer tries the namespaced map first, then the
 * fallback map:
 *
 *     1. 'application/welcome/blog/post/show'  -> your override, if you wrote one
 *     2. 'blog/post/show'                      -> the package's own copy
 *
 * So a module always finds its own views first, and silently inherits the
 * package's until the day someone drops a file in to take over. Vendor packages
 * therefore want their views under a distinct sub-directory
 * (views/<package>/...) rather than at the top - two packages both shipping
 * views/main/index.php would be competing for one fallback key.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Scan order comes from Composer
 *
 * Application roots are read from the root composer.json's autoload.psr-4 in
 * declaration order; vendor roots come from the generated autoload map and go
 * last. Nothing is hardcoded here - add a PSR-4 entry, run dump-autoload, and
 * its views are picked up.
 */
class ViewDetector
{
    /** directory name that marks the root of a module's views */
    private const string VIEW_DIRECTORY = 'views';

    /** view file extension, including the dot */
    private const string EXTENSION = '.php';

    /** never descended into while looking for views/ directories */
    private const array PRUNE = ['.git', '.svn', '.hg', '.idea', '.vscode', 'node_modules', 'vendor', 'unittest', 'tests'];

    private const string VIEW_KEY_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9\/._-]*\z/';
    private const string VIEW_PATH_PATTERN = '/\A\/[A-Za-z0-9][A-Za-z0-9\/._-]*\z/';

    /**
     * Scan every PSR-4 root for views and merge in any manually supplied ones.
     *
     * Passing $productionPathWrite also refreshes the production snapshot while
     * running in development, exactly as RouterDetector::detect() does.
     *
     * @param array $extraViews same three-section shape as the return value;
     *        these are applied before anything is scanned, so they win a
     *        fallback key. This is where hand-written 'view aliases' go.
     * @param string|null $productionPathWrite directory to write views.php into
     * @return array{views: array<string, string>, 'view fallbacks': array<string, string>, 'view aliases': array<string, string>}
     */
    public static function detect(array $extraViews = [], ?string $productionPathWrite = null): array
    {
        if (ENVIRONMENT != 'development') {
            echo 'The ' . self::class . ' should only be used in development.' . PHP_EOL;
            echo 'For production export a static array for /config/production/views.php' . PHP_EOL;
            echo 'or add the production write path as the 2nd argument and detect will auto write it for you.' . PHP_EOL;
            echo 'This can then be committed and picked up automatically in production.' . PHP_EOL;

            exit(1);
        }

        [$views, $fallbacks, $aliases, $shadowed] = static::findViewsInPsr4Roots($extraViews);

        if ($productionPathWrite) {
            if (!is_dir($productionPathWrite)) {
                throw new DirectoryNotFound($productionPathWrite);
            }

            if (!is_writable($productionPathWrite)) {
                throw new DirectoryNotWritable($productionPathWrite);
            }

            file_put_contents($productionPathWrite . '/views.php', static::formatForProduction($views, $fallbacks, $aliases, $shadowed));
        }

        return ['views' => $views, 'view fallbacks' => $fallbacks, 'view aliases' => $aliases];
    }

    /**
     * Print the production file to stdout instead of writing it.
     */
    public static function export(array $extraViews = []): string
    {
        [$views, $fallbacks, $aliases, $shadowed] = static::findViewsInPsr4Roots($extraViews);

        echo static::formatForProduction($views, $fallbacks, $aliases, $shadowed);

        exit(0);
    }

    /**
     * Walk every root, in priority order, collecting all three sections.
     *
     * @return array{0: array<string, string>, 1: array<string, string>, 2: array<string, string>, 3: array<string, array<string, list<string>>>}
     */
    protected static function findViewsInPsr4Roots(array $extraViews): array
    {
        // hand-written entries go in first so they hold their fallback keys
        // against anything discovered on disk
        $views = static::normalizeKeys($extraViews['views'] ?? []);
        $fallbacks = static::normalizeKeys($extraViews['view fallbacks'] ?? []);

        // aliases are name -> name, so both halves get folded: the key because
        // that is what a lookup is matched against, the target because it is
        // itself looked up in the maps above
        $aliases = array_map(static::normalizeKey(...), static::normalizeKeys($extraViews['view aliases'] ?? []));

        // per-section record of what lost a key, so a silent overwrite still
        // shows up in the generated file
        $shadowed = ['views' => [], 'view fallbacks' => []];

        foreach (static::psr4Roots() as [$namespace, $root, $isVendor]) {
            foreach (static::findViewDirectories($root) as $viewDirectory) {
                // the segments between the PSR-4 root and the views directory are
                // the module - 'welcome' in application/welcome/views. A views
                // directory sitting straight on the root contributes nothing
                $module = trim(substr($viewDirectory, strlen($root), -strlen(self::VIEW_DIRECTORY)), '/');

                // vendor views are deliberately un-namespaced: their only key is
                // the fallback one, which is what an application module can claim
                // for itself to override them
                $prefix = $isVendor ? '' : trim($namespace . '/' . $module, '/');

                foreach (static::findViewFiles($viewDirectory) as $file) {
                    // 'main/index' - everything after the views directory, minus the extension
                    $rawName = substr($file, strlen($viewDirectory) + 1, -strlen(self::EXTENSION));

                    static::assertMatches(self::VIEW_KEY_PATTERN, $rawName, 'view name');
                    // the relative form is what gets written to the production
                    // file, so it is what has to be safe to embed there
                    static::assertMatches(self::VIEW_PATH_PATTERN, static::relativeToRoot($file), 'view path');

                    // matching is case insensitive, and it is cheaper to fold
                    // here once than on every lookup for the life of the app -
                    // so the generated file holds keys already folded and the
                    // finder only has to fold the name being asked for
                    $name = static::normalizeKey($rawName);

                    // absolute, because this array is consumed directly in
                    // development. formatForProduction() strips __ROOT__ back off
                    // and re-prepends it as an expression, so the production file
                    // rebuilds the same absolute path on whatever machine loads it
                    if ($prefix !== '') {
                        static::claim($views, $shadowed['views'], static::normalizeKey($prefix) . '/' . $name, $file);
                    }

                    // first writer keeps the key: application roots are walked
                    // before vendor ones, so an application copy of a package's
                    // view shadows it just by existing
                    static::claim($fallbacks, $shadowed['view fallbacks'], $name, $file);
                }
            }
        }

        ksort($views);
        ksort($fallbacks);
        ksort($aliases);
        ksort($shadowed['views']);
        ksort($shadowed['view fallbacks']);

        return [$views, $fallbacks, $aliases, $shadowed];
    }

    /**
     * Give a key to the first file that asks for it, recording any later
     * claimant instead of letting it overwrite silently.
     *
     * Two different things land here. The intended one is an application module
     * shadowing a package's view - that is the override mechanism working. The
     * accidental one is two files whose names differ only in case, which are
     * distinct on a case-sensitive filesystem but fold to one key: they cannot
     * both be addressed, so the second is dropped and recorded.
     *
     * @param array<string, string> $map
     * @param array<string, list<string>> $shadowed
     */
    protected static function claim(array &$map, array &$shadowed, string $key, string $file): void
    {
        if (isset($map[$key])) {
            $shadowed[$key][] = $file;
        } else {
            $map[$key] = $file;
        }
    }

    /**
     * Fold one view name for matching.
     *
     * View names are file paths, so they are ASCII in every realistic case, but
     * mb_convert_case is used rather than strtolower for the same reason the
     * rest of the framework does: a non-ASCII filename should fold predictably
     * rather than by the current locale.
     */
    protected static function normalizeKey(string $key): string
    {
        return mb_convert_case(trim($key, '/'), MB_CASE_LOWER, 'UTF-8');
    }

    /**
     * @param array<string, string> $map
     * @return array<string, string>
     */
    protected static function normalizeKeys(array $map): array
    {
        $normalized = [];

        foreach ($map as $key => $value) {
            $normalized[static::normalizeKey((string)$key)] = $value;
        }

        return $normalized;
    }

    /**
     * Every PSR-4 root, application ones first in the order the root
     * composer.json declares them, then vendor packages.
     *
     * @return list<array{0: string, 1: string, 2: bool}> namespace, directory, is-vendor
     */
    protected static function psr4Roots(): array
    {
        $vendorDirectory = __ROOT__ . '/vendor';
        $application = [];
        $vendor = [];
        $claimed = [];

        // the root composer.json, not the generated map: the generated one is
        // sorted by namespace length for prefix matching, which is not the
        // priority order anybody wrote down
        $composer = json_decode((string)file_get_contents(__ROOT__ . '/composer.json'), true);

        foreach ($composer['autoload']['psr-4'] ?? [] as $namespace => $directory) {
            foreach ((array)$directory as $one) {
                $resolved = realpath(__ROOT__ . '/' . ltrim((string) $one, '/'));

                if ($resolved) {
                    $claimed[$resolved] = true;
                    $application[] = [static::namespaceToPath($namespace), $resolved, false];
                }
            }
        }

        foreach (static::installedPsr4() as $namespace => $directories) {
            foreach ((array)$directories as $one) {
                $resolved = realpath($one);

                // skip anything the root composer.json already claimed, so an
                // application root is never also treated as a vendor one
                if ($resolved && !isset($claimed[$resolved]) && str_starts_with($resolved, $vendorDirectory)) {
                    $vendor[] = [static::namespaceToPath($namespace), $resolved, true];
                }
            }
        }

        // application first so it wins fallback keys, vendor last
        return array_merge($application, $vendor);
    }

    /**
     * Composer's generated PSR-4 map.
     *
     * @return array<string, array<string>>
     */
    protected static function installedPsr4(): array
    {
        $file = __ROOT__ . '/vendor/composer/autoload_psr4.php';

        return is_file($file) ? (array)include $file : [];
    }

    /**
     * 'application\' -> 'application', 'orange\framework\' -> 'orange/framework'
     */
    protected static function namespaceToPath(string $namespace): string
    {
        return trim(str_replace('\\', '/', $namespace), '/');
    }

    /**
     * Find every views directory under a root.
     *
     * Once one is found the walk stops descending into it - everything below is
     * view content, not another module.
     *
     * Deliberately an explicit stack rather than RecursiveIteratorIterator.
     * Three things went wrong with the iterator version, and all three are
     * easier to simply not have:
     *
     *  - It matched a *constructed* path ($parent . '/views') rather than the
     *    directory the listing actually reported. aplus/debug ships its views in
     *    'Views', which a case-insensitive filesystem resolves and a case
     *    sensitive one does not - so a Mac and a Linux box generated different
     *    view maps from identical source. Using the real name from the listing
     *    is the fix; matching it case-insensitively is what keeps both platforms
     *    agreeing on the answer.
     *
     *  - Rejecting a directory in the filter both stops the descent and hides it
     *    from the iteration, so the views directory had to be inferred from its
     *    parent. Here it is recorded and simply not pushed back on the stack.
     *
     *  - getChildren() on an unreadable or vanished directory throws
     *    UnexpectedValueException from inside the iteration, taking down the
     *    whole request. A directory that cannot be opened holds no views worth
     *    failing a page over.
     *
     * @return list<string>
     */
    protected static function findViewDirectories(string $root): array
    {
        if (!is_dir($root)) {
            return [];
        }

        $found = [];
        $stack = [$root];

        while ($stack !== []) {
            $directory = array_pop($stack);

            // unreadable, a dangling symlink, or removed since the parent was
            // listed - skip it rather than fail the scan
            if (!is_readable($directory) || ($entries = @scandir($directory)) === false) {
                continue;
            }

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $path = $directory . DIRECTORY_SEPARATOR . $entry;

                if (!is_dir($path)) {
                    continue;
                }

                // the real name from the listing, folded - so 'Views' and
                // 'views' are the same directory on every filesystem
                if (mb_convert_case($entry, MB_CASE_LOWER, 'UTF-8') === self::VIEW_DIRECTORY) {
                    $found[] = $path;

                    // everything below is view content, not another module
                    continue;
                }

                if (in_array($entry, self::PRUNE, true)) {
                    continue;
                }

                $stack[] = $path;
            }
        }

        // stable output so the generated production file does not churn between
        // machines with different directory-entry order
        sort($found);

        return $found;
    }

    /**
     * Every view file under one views directory, at any depth.
     *
     * Same explicit walk as findViewDirectories() and for the same reason: a
     * directory that cannot be opened should cost the views inside it, not the
     * whole request.
     *
     * @return list<string>
     */
    protected static function findViewFiles(string $viewDirectory): array
    {
        $files = [];
        $stack = [$viewDirectory];

        while ($stack !== []) {
            $directory = array_pop($stack);

            if (!is_readable($directory)) {
                continue;
            }

            $entries = @scandir($directory);

            if ($entries === false) {
                continue;
            }

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $path = $directory . DIRECTORY_SEPARATOR . $entry;

                if (is_dir($path)) {
                    $stack[] = $path;

                    continue;
                }

                if (str_ends_with($entry, self::EXTENSION)) {
                    $files[] = $path;
                }
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Absolute path -> the part after __ROOT__, so the generated file can rebuild
     * it with __ROOT__ . '...' and stay portable between a working copy and a
     * container that mounts the project somewhere else.
     */
    protected static function relativeToRoot(string $path): string
    {
        return str_starts_with($path, __ROOT__) ? substr($path, strlen(__ROOT__)) : $path;
    }

    /**
     * Format all three sections as a complete PHP config file for production.
     *
     * @param array<string, string> $views
     * @param array<string, string> $fallbacks
     * @param array<string, string> $aliases
     * @param array<string, array<string, list<string>>> $shadowed
     */
    protected static function formatForProduction(array $views, array $fallbacks, array $aliases, array $shadowed): string
    {
        $q = chr(39);

        $php[] = '<?php';
        $php[] = '';
        $php[] = 'declare(strict_types=1);';
        $php[] = '';
        $php[] = '// Generated by ' . self::class . ' - do not edit by hand.';
        $php[] = '// Regenerate whenever a view file is added, removed or renamed.';
        $php[] = '// Keys are lower cased: view matching is case insensitive, and folding';
        $php[] = '// once here is cheaper than folding the map on every request.';
        $php[] = '';
        $php[] = 'return [';

        $php[] = '    // namespaced and unique - what BaseController::renderView() asks for first';
        $php[] = '    ' . $q . 'views' . $q . ' => [';

        foreach ($views as $name => $path) {
            $php = [...$php, ...static::formatEntry($name, $path, $shadowed['views'][$name] ?? [])];
        }

        $php[] = '    ],';

        $php[] = '    // un-namespaced - a package ships its views here and an application';
        $php[] = '    // module overrides one just by holding the same key';
        $php[] = '    ' . $q . 'view fallbacks' . $q . ' => [';

        foreach ($fallbacks as $name => $path) {
            $php = [...$php, ...static::formatEntry($name, $path, $shadowed['view fallbacks'][$name] ?? [])];
        }

        $php[] = '    ],';

        $php[] = '    // name -> name, applied before either map is consulted';
        $php[] = '    ' . $q . 'view aliases' . $q . ' => [';

        foreach ($aliases as $name => $target) {
            $php[] = '        ' . $q . $name . $q . ' => ' . $q . $target . $q . ',';
        }

        $php[] = '    ],';
        $php[] = '];';

        return implode(PHP_EOL, $php) . PHP_EOL;
    }

    /**
     * One map entry, preceded by a comment for anything that lost the key.
     *
     * Losing a key is normal when an application module overrides a package's
     * view and a mistake when two filenames differ only in case, so the comment
     * names the file rather than guessing which happened.
     *
     * @param list<string> $shadowed
     * @return list<string>
     */
    protected static function formatEntry(string $name, string $path, array $shadowed): array
    {
        $q = chr(39);
        $lines = [];

        foreach ($shadowed as $lost) {
            $lines[] = '        // shadows ' . static::relativeToRoot($lost);
        }

        $lines[] = '        ' . $q . $name . $q . ' => __ROOT__ . ' . $q . static::relativeToRoot($path) . $q . ',';

        return $lines;
    }

    protected static function assertMatches(string $pattern, string $value, string $label): void
    {
        if (preg_match($pattern, $value) !== 1) {
            throw new InvalidArgumentException('Invalid ' . $label . ': ' . $value);
        }
    }
}
