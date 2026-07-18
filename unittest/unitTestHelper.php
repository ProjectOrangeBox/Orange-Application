<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class UnitTestHelper extends TestCase
{
    protected $instance;

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
}
