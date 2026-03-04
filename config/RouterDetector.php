<?php

declare(strict_types=1);

/**
 * ONLY USE IN DEVELOPMENT
 */

class RouterDetector
{
    /**
     * we will use this class to scan the application for route attributes
     * and build the routes array that is used in the Router class
     *
     * @param array $paths
     * @return array
     */
    static public function detect(array $paths): array
    {
        if (ENVIRONMENT != 'development') {
            die('The ' . __CLASS__ . ' should only be used in development. You can use the static method export to get the current array');
        }

        $routes = [];

        foreach ($paths as $path) {
            // we need to recursively scan the directory for php files
            foreach (new RegexIterator(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path)), '/^.+\.' . preg_quote('php') . '$/i', RegexIterator::GET_MATCH) as $file) {
                // we need to scan the file for route attributes
                static::scan($routes, $file[0]);
            }
        }

        return $routes;
    }

    /**
     * echo the formatted routes array
     *
     * @param array $paths
     * @return void
     */
    static public function export(array $paths): void
    {
        // we will just echo the formatted routes array
        echo static::format(static::detect($paths));
    }

    /**
     * read the file and look for the namespace, class and route attributes
     * use regular expressions to find the namespace, class and route attributes
     * build the routes array as we go and add it to the main routes array
     * only look for public functions that have a route attribute
     * use the line number to look for the public function after we find a route attribute
     * use str_getcsv to parse the route attribute parameters, this will handle quoted strings and commas correctly
     * need to add two empty values to the end of the array to prevent undefined offset errors when we try to access the parameters
     * need to convert the http methods to uppercase and split them into an array if they contain a pipe character
     *
     * @param array &$routes
     * @param string $file
     * @return void
     */
    static protected function scan(array &$routes, string $file): void
    {
        // we need to read the file into an array of lines
        $source = file($file);

        // we need to keep track of the current namespace and class so we can build the callback
        $namespace = '';
        $class = '';

        foreach ($source as $lineNumber => $line) {
            // we need to trim the line to remove any leading or trailing whitespace
            $line = trim($line);

            if (!empty($line)) {
                // we are looking for the namespace, class and route attributes
                if (preg_match('/namespace\s+(.*);/', $line, $matches, PREG_OFFSET_CAPTURE, 0)) {
                    $namespace = $matches[1][0];
                }
                // we are only interested in public functions, so we will only look for the route attribute if we find a public function
                if (preg_match('/class\s+([^ ]*)\s+(.*)/', $line, $matches, PREG_OFFSET_CAPTURE, 0)) {
                    $class = $matches[1][0];
                }
                // #[Route('get|post', '/errors')]
                if (preg_match('/#\[Route\((.*)\)\]/', $line, $matches, PREG_OFFSET_CAPTURE, 0)) {
                    // we have a route attribute, so we need to parse the parameters and build the route array
                    $route = [];

                    // we need to add two empty values to the end of the array to prevent undefined offset errors
                    list($httpMethods, $route['url'], $route['name']) = str_getcsv(str_replace('"', "'", $matches[1][0]) . ',,', ',', chr(39));

                    // if we have a http method, we need to add it to the route array
                    if (!empty($httpMethods)) {
                        // we need to convert to uppercase
                        $route['method'] = strtoupper($httpMethods);

                        // do we need to convert to an array?
                        if (strpos($route['method'], '|') > 0) {
                            // we need to split the string into an array
                            $route['method'] = explode('|', $route['method']);
                        }

                        // ['method' => '*', 'url' => '/', 'callback' => [\orange\framework\controllers\HomeController::class, 'index'], 'name' => 'home'],
                        if (preg_match('/public\s+function\s+([^\(]*)(.*)/', $source[$lineNumber + 1], $matches, PREG_OFFSET_CAPTURE, 0)) {
                            // we have a valid route, so we can add it to the routes array
                            $route['callback'] = [chr(92) . $namespace . chr(92) . $class, $matches[1][0]];
                        } else {
                            // we don't have a valid route, so we can skip it
                            unset($route['name']);
                            unset($route['url']);
                            unset($route['method']);
                        }
                    }

                    // remove empty values
                    $route = array_filter($route);

                    // only add if we have a valid route
                    if (!empty($route)) {
                        $routes[] = $route;
                    }
                }
            }
        }
    }

    /**
     * we need to format the routes array into a string that can be used in the export method
     *
     * @param array $routes
     * @return string
     */
    static protected function format(array $routes): string
    {
        $output = '';
        $t = chr(39);

        // ['method' => '*', 'url' => '/', 'callback' => [\orange\framework\controllers\HomeController::class, 'index'], 'name' => 'home'],
        foreach ($routes as $route) {
            $line = '';

            if (isset($route['method'])) {
                $line .= $t . 'method' . $t . ' => ';

                if (is_array($route['method'])) {
                    $line .= '[';

                    foreach ($route['method'] as $m) {
                        $line .= $t . $m . $t . ',';
                    }

                    $line = rtrim($line, ',');

                    $line .= ']';
                } else {
                    $line .= $t . $route['method'] . $t;
                }

                $line .= ', ';
            }

            if (isset($route['url'])) {
                $line .= $t . 'url' . $t . ' => ' . $t . $route['url'] . $t . ', ';
            }

            if (isset($route['callback'])) {
                $line .= $t . 'callback' . $t . ' => [';

                $line .= $route['callback'][0] . '::class, ' . $t . $route['callback'][1] . $t;

                $line .= '], ';
            }

            if (isset($route['name'])) {
                $line .= $t . 'name' . $t . ' => ' . $t . $route['name'] . $t;
            }

            $line = trim($line, ', ');

            $output .= '[' . $line . '],' . PHP_EOL;
        }

        return '[' . PHP_EOL . $output . '],' . PHP_EOL;
    }
}
