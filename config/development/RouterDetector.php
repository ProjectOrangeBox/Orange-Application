<?php

declare(strict_types=1);

namespace config\development;

// This file is namespaced, so framework classes and PHP reflection classes need
// explicit imports rather than relying on global-namespace resolution.
use orange\framework\attributes\Route;
use orange\framework\exceptions\filesystem\DirectoryNotFound;
use orange\framework\exceptions\filesystem\DirectoryNotWritable;
use ReflectionClass;
use ReflectionMethod;

/**
 * Development-only route scanner.
 *
 * Production should consume a pre-generated plain PHP route array instead of
 * reflecting over every controller file on each request.
 */
class RouterDetector
{
    /**
     * Scan controller paths for Route attributes and merge them with any
     * manually supplied routes used by Router::getUrl().
     *
     * Passing $productionPathWrite also refreshes the production route snapshot
     * while running in development.
     */
    public static function detect(array $paths, array $routes = [], ?string $productionPathWrite = null): array
    {
        if (ENVIRONMENT != 'development') {
            echo 'The ' . self::class . ' should only be used in development.' . PHP_EOL;
            echo 'For production export a static array for /config/production/routes.php' . PHP_EOL;
            echo 'or add the production write path as the 3rd argument and detect will auto write it for you.' . PHP_EOL;
            echo 'This can then be committed and picked up automatically in production.' . PHP_EOL;

            exit(1);
        }

        $routes = static::findRoutesInProvidedPaths($paths, $routes);

        if ($productionPathWrite) {
            if (!is_dir($productionPathWrite)) {
                throw new DirectoryNotFound($productionPathWrite);
            }

            if (!is_writable($productionPathWrite)) {
                throw new DirectoryNotWritable($productionPathWrite);
            }

            file_put_contents($productionPathWrite . '/routes.php', static::formatForProduction($routes));
        }

        return $routes;
    }

    public static function export(array $paths, array $routes = []): string
    {
        echo static::formatForProduction(static::findRoutesInProvidedPaths($paths, $routes));

        exit(0);
    }

    protected static function findRoutesInProvidedPaths(array $paths, array $routes): array
    {
        foreach ($paths as $path) {
            // Each configured module root is scanned recursively so new
            // controller files are picked up without touching this config.
            foreach (static::rglob($path, '*.php') as $file) {
                static::scanPath($routes, $file);
            }
        }

        return $routes;
    }

    protected static function scanPath(array &$routes, string $file): void
    {
        $fullyQualifiedClass = static::getFullyQualifiedClass($file);

        if ($fullyQualifiedClass !== '' && $fullyQualifiedClass !== '0') {
            // Reflection depends on Composer autoloading for the application/api
            // namespaces configured in composer.json.
            $reflectionClass = new ReflectionClass($fullyQualifiedClass);

            foreach ($reflectionClass->getMethods(ReflectionMethod::IS_PUBLIC) as $reflectionMethod) {
                $attributes = $reflectionMethod->getAttributes(Route::class);

                if (!empty($attributes)) {
                    $routeInstance = $attributes[0]->newInstance();

                    $route['url'] = $routeInstance->url;
                    $route['name'] = $routeInstance->name;
                    $route['method'] = $routeInstance->method;

                    // Route fields are optional except for the callback that is
                    // derived from the reflected class and method below.
                    $route = array_filter($route);

                    if ($route !== []) {
                        $route['callback'] = [$fullyQualifiedClass, $reflectionMethod->getName()];

                        $routes[] = $route;
                    }
                }
            }
        }
    }

    /**
     * Format the route table as a complete PHP config file for production.
     */
    protected static function formatForProduction(array $routes): string
    {
        $q = chr(39);
        $ts = str_repeat(' ', 8);

        $php[] = '<?php';
        $php[] = '';
        $php[] = 'declare(strict_types=1);';
        $php[] = '';
        $php[] = 'return [';
        $php[] = '    ' . $q . 'routes' . $q . ' => [';

        // ['method' => '*', 'url' => '/', 'callback' => [\orange\framework\controllers\HomeController::class, 'index'], 'name' => 'home'],
        foreach ($routes as $route) {
            $line = '';

            if (isset($route['method'])) {
                $line .= $q . 'method' . $q . ' => ';

                if (is_array($route['method'])) {
                    $line .= '[';

                    foreach ($route['method'] as $m) {
                        $line .= $q . $m . $q . ',';
                    }

                    $line = rtrim($line, ',');

                    $line .= ']';
                } else {
                    $line .= $q . $route['method'] . $q;
                }

                $line .= ', ';
            }

            if (isset($route['url'])) {
                $line .= $q . 'url' . $q . ' => ' . $q . $route['url'] . $q . ', ';
            }

            if (isset($route['callback'])) {
                $line .= $q . 'callback' . $q . ' => [';

                $line .= $route['callback'][0] . '::class, ' . $q . $route['callback'][1] . $q;

                $line .= '], ';
            }

            if (isset($route['name'])) {
                $line .= $q . 'name' . $q . ' => ' . $q . $route['name'] . $q;
            }

            $line = trim($line, ', ');

            $php[] = $ts . '[' . $line . '],';
        }

        $php[] = '    ]';
        $php[] = '];';

        return implode(PHP_EOL, $php) . PHP_EOL;
    }

    protected static function getFullyQualifiedClass(string $file): string
    {
        $fullyQualifiedClass = '';
        $namespace = '';

        // Lightweight parser for PSR-4 controller files. This intentionally only
        // needs namespace + class name, because Reflection handles attributes.
        foreach (file($file) as $line) {
            $line = trim($line);

            if ($line !== '' && $line !== '0') {
                if (preg_match('/namespace\s+(.*)\s*;/', $line, $matches, PREG_OFFSET_CAPTURE, 0)) {
                    $namespace = $matches[1][0];
                }

                if (preg_match('/class\s*([^ ]*).*/', $line, $matches, PREG_OFFSET_CAPTURE, 0)) {
                    if ($namespace !== '' && $namespace !== '0') {
                        $fullyQualifiedClass = chr(92) . $namespace . chr(92) . $matches[1][0];
                    }
                    break;
                }
            }
        }

        return $fullyQualifiedClass;
    }

    protected static function rglob(string $path, string $pattern): array
    {
        $paths = glob($path . '/*', GLOB_MARK | GLOB_ONLYDIR | GLOB_NOSORT);
        $files = glob($path . '/' . $pattern);

        if (!is_array($files)) {
            $files = [];
        }

        foreach ($paths as $subpath) {
            $files = array_merge($files, static::rglob($subpath, $pattern));
        }

        return $files;
    }
}
