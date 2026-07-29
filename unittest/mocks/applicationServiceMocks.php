<?php

declare(strict_types=1);

use orange\framework\interfaces\ConfigInterface;
use orange\framework\interfaces\ViewInterface;
use orange\framework\interfaces\ViewFinderInterface;

/**
 * Lightweight test doubles for the framework services that application
 * controllers pull off the container. They implement just enough of each
 * interface to let a controller be constructed and driven in isolation, while
 * recording the interactions a test needs to assert on.
 *
 * Registered as container services in each controller test's setUp():
 *
 *   $container->set('config', new MockConfigService([...]));
 *   $container->set('view',   new MockViewService());
 */

/**
 * Config double backed by a plain array. Controllers read config as an
 * ArrayAccess (e.g. $this->config['application']['h1']), so both ConfigInterface
 * and ArrayAccess are implemented over the same store.
 */
class MockConfigService implements ConfigInterface, ArrayAccess
{
    public function __construct(private array $store = [])
    {
    }

    public function __get(string $filename): mixed
    {
        return $this->store[$filename] ?? null;
    }

    public function get(string $filenameKey, mixed $defaultValue = null): mixed
    {
        return $this->store[$filenameKey] ?? $defaultValue;
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->store[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->store[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->store[$offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->store[$offset]);
    }
}

/**
 * View double. Records every render()/renderString() call so a test can assert
 * which view was requested and with what data, and returns a deterministic
 * string so the controller's return value can be checked.
 */
class MockViewService implements ViewInterface
{
    /** @var array<int, array{view: string, data: array}> */
    public array $renderCalls = [];
    /** @var array<int, array{string: string, data: array}> */
    public array $renderStringCalls = [];
    /** @var array<int, array{name: string, value: mixed}> */
    public array $changeCalls = [];

    public function render(string $viewFile = '', array $data = [], array $options = []): string
    {
        $this->renderCalls[] = ['view' => $viewFile, 'data' => $data];

        return 'rendered:' . $viewFile;
    }

    public function renderString(string $string, array $data = [], array $options = []): string
    {
        $this->renderStringCalls[] = ['string' => $string, 'data' => $data];

        return $string;
    }

    public function change(string $name, mixed $value): self
    {
        $this->changeCalls[] = ['name' => $name, 'value' => $value];

        return $this;
    }
}


/**
 * ViewFinder double.
 *
 * BaseController::renderView() resolves a name to a path through this before
 * handing it to the view engine, so a controller test needs one on the
 * container. It echoes the name back rather than consulting a map, which keeps
 * the assertions about *what was asked for* - MockViewService returns
 * 'rendered:<path>', so a test still sees the view name it expected.
 */
class MockViewFinderService implements ViewFinderInterface
{
    /** @var array<int, array{view: string, namespace: string}> */
    public array $findCalls = [];

    public function find(string $view, string $namespace = ''): string
    {
        $this->findCalls[] = ['view' => $view, 'namespace' => $namespace];

        return $view;
    }

    public function exists(string $view, string $namespace = ''): bool
    {
        return true;
    }

    public function all(): array
    {
        return [];
    }
}
