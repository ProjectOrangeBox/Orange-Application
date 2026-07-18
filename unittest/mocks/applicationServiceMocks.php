<?php

declare(strict_types=1);

use orange\framework\interfaces\ConfigInterface;
use orange\framework\interfaces\ViewInterface;
use orange\framework\interfaces\DirectorySearchInterface;

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

    public function __construct(private ?DirectorySearchInterface $search = null)
    {
        $this->search ??= new MockDirectorySearch();
    }

    public function render(string $view = '', array $data = [], array $options = []): string
    {
        $this->renderCalls[] = ['view' => $view, 'data' => $data];

        return 'rendered:' . $view;
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

    public function search(): DirectorySearchInterface
    {
        return $this->search;
    }
}

/**
 * DirectorySearch double. Every method is an inert no-op - BaseController's
 * constructor calls search()->addDirectory() to register a controller's local
 * view path, which is irrelevant when the view itself is a MockViewService.
 */
class MockDirectorySearch implements DirectorySearchInterface
{
    public function addDirectory(string $directory, ?int $pend = null): self
    {
        return $this;
    }

    public function addDirectories(array $directories, ?int $pend = null): self
    {
        return $this;
    }

    public function removeDirectory(string $directory, bool $removeFoundResources = false): self
    {
        return $this;
    }

    public function removeDirectories(array $directories, bool $removeFoundResources = false): self
    {
        return $this;
    }

    public function listDirectories(): array
    {
        return [];
    }

    public function directoryExists(string $directory): bool
    {
        return false;
    }

    public function replaceDirectories(array $directories, bool $removeFoundResources = false): self
    {
        return $this;
    }

    public function addResource(string $resource, string $absolutePath): self
    {
        return $this;
    }

    public function addResources(array $resources): self
    {
        return $this;
    }

    public function removeResource(string $resource): self
    {
        return $this;
    }

    public function removeResources(array $resources): self
    {
        return $this;
    }

    public function list(): array
    {
        return [];
    }

    public function exists(string $resource): bool
    {
        return false;
    }

    public function replaceResources(array $resources): self
    {
        return $this;
    }

    public function flushDirectories(bool $flushResources = true): self
    {
        return $this;
    }

    public function flushResources(): self
    {
        return $this;
    }

    public function find(string $resource): array
    {
        return [];
    }

    public function findFirst(string $resource): string
    {
        return '';
    }

    public function findLast(string $resource): string
    {
        return '';
    }

    public function findAll(): array
    {
        return [];
    }

    public function lock(): self
    {
        return $this;
    }

    public function unlock(): self
    {
        return $this;
    }

    public function isLocked(): bool
    {
        return false;
    }
}
