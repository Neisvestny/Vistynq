<?php

declare(strict_types=1);

namespace Vistynq\Support;

use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionNamedType;
use Throwable;

/**
 * Lightweight PSR-11 container: explicit bind/singleton with factories,
 * constructor autowiring for concrete classes. No method injection, no attributes.
 */
final class Container implements ContainerInterface
{
    /** @var array<string, callable(self): mixed> */
    private array $factories = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /** @var array<string, true> */
    private array $singletons = [];

    /**
     * Bind an id to a factory (new instance on every get) or a concrete class (autowired).
     *
     * @param callable(self): mixed|class-string $factory
     */
    public function bind(string $id, callable|string $factory): void
    {
        $this->factories[$id] = is_string($factory) && class_exists($factory)
            ? static fn (self $c): mixed => $c->make($factory)
            : $factory;
        unset($this->instances[$id], $this->singletons[$id]);
    }

    /**
     * Bind an id to a factory cached after the first resolution.
     *
     * @param callable(self): mixed|class-string $factory
     */
    public function singleton(string $id, callable|string $factory): void
    {
        $this->bind($id, $factory);
        $this->singletons[$id] = true;
    }

    /**
     * @param class-string $class
     */
    public function make(string $class): mixed
    {
        if (isset($this->instances[$class])) {
            return $this->instances[$class];
        }

        try {
            $reflection = new ReflectionClass($class);
        } catch (Throwable $e) {
            throw new ContainerResolutionException("Не удалось отразить класс {$class}: " . $e->getMessage(), 0, $e);
        }

        if (!$reflection->isInstantiable()) {
            throw new ContainerResolutionException("Класс {$class} не может быть создан контейнером");
        }

        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $args = [];
        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();
            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                throw new ContainerResolutionException(
                    "Не удалось разрезолвить параметр \${$param->getName()} в {$class}: скалярные параметры задавайте явным bind",
                );
            }

            $args[] = $this->get($type->getName());
        }

        $instance = $reflection->newInstanceArgs($args);

        if (isset($this->singletons[$class])) {
            $this->instances[$class] = $instance;
        }

        return $instance;
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (isset($this->factories[$id])) {
            $instance = ($this->factories[$id])($this);
            if (isset($this->singletons[$id])) {
                $this->instances[$id] = $instance;
            }

            return $instance;
        }

        if (class_exists($id)) {
            return $this->make($id);
        }

        throw new NotFoundException("Сервис {$id} не зарегистрирован в контейнере");
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id])
            || array_key_exists($id, $this->instances)
            || (class_exists($id) && (new ReflectionClass($id))->isInstantiable());
    }
}
