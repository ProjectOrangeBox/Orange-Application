<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Shared PHPUnit base class for orange/* package test suites.
 *
 * This is the single canonical source for what used to be a per-package
 * unitTestHelper.php copy (22 copies had drifted into 6 versions). It is the
 * superset of every variant: the four reflection helpers every package used,
 * plus ve() (var_export debug helper) and makeTempDir()/removeTempDir()
 * (filesystem test helpers) that individual packages had added locally.
 *
 * Kept in the global namespace with the historical lowercase name so existing
 * test classes keep working unchanged (`class FooTest extends unitTestHelper`).
 */
class unitTestHelper extends TestCase
{
    protected $instance;

    /* --- private / protected property + method access ----------------------- */

    protected function getPrivatePublic($attribute, $instance = null)
    {
        $instance ??= $this->instance;

        $getter = (fn() => $this->$attribute);

        $closure = \Closure::bind($getter, $instance, $instance::class);

        return $closure();
    }

    protected function setPrivatePublic($attribute, $value, $instance = null)
    {
        $instance ??= $this->instance;

        $setter = function ($value) use ($attribute) {
            $this->$attribute = $value;
        };

        $closure = \Closure::bind($setter, $instance, $instance::class);

        $closure($value);
    }

    protected function callMethod(string $method, ?array $args = null, $instance = null)
    {
        $instance ??= $this->instance;

        $reflectionMethod = new ReflectionMethod($instance, $method);

        return (is_array($args)) ? $reflectionMethod->invokeArgs($instance, $args) : $reflectionMethod->invoke($instance);
    }

    protected function stripInvisible(string $string): string
    {
        return preg_replace('/[\x00-\x1F\x7F]/u', '', $string);
    }

    /* --- var_export debug helper (dump a value as an assertEquals stub) ------ */

    protected function ve($expression): void
    {
        if (!is_array($expression)) {
            $export = var_export($expression, true);
        } else {
            $export = var_export($expression, true);
            $export = preg_replace("/^([ ]*)(.*)/m", '$1$1$2', $export);
            $array = preg_split("/\r\n|\n|\r/", (string) $export);
            $array = preg_replace(["/\s*array\s\($/", "/\)(,)?$/", "/\s=>\s$/"], [null, ']$1', ' => ['], $array);
            $export = implode(PHP_EOL, array_filter(["["] + $array));
        }

        echo $export . PHP_EOL;

        echo '$this->assertEquals(' . $export . ',' . PHP_EOL;
    }

    /* --- temporary directory helpers ---------------------------------------- */

    /* create a fresh temporary directory and return its absolute path */
    protected function makeTempDir(string $prefix = 'orange-'): string
    {
        $dir = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(6));

        mkdir($dir, 0777, true);

        return $dir;
    }

    /* recursively remove a directory created by makeTempDir() */
    protected function removeTempDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;

            is_dir($path) ? $this->removeTempDir($path) : unlink($path);
        }

        rmdir($dir);
    }
}
