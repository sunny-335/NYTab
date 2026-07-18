<?php
declare(strict_types=1);

namespace Nytab\Core;

/**
 * Minimal dependency injection container with lazy factory resolution
 * and instance caching.
 */
final class Container
{
    /** @var array<string, callable> */
    private array $factories = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    public function set(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }
        if (!array_key_exists($id, $this->factories)) {
            throw new \RuntimeException("Container entry not found: {$id}");
        }
        $instance = ($this->factories[$id])($this);
        $this->instances[$id] = $instance;
        return $instance;
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->factories) || array_key_exists($id, $this->instances);
    }

    public function singleton(string $id, callable $factory): void
    {
        $this->set($id, $factory);
    }
}
