<?php

namespace Ignite\Config;

use BadMethodCallException;
use Ignite\Support\Facades\Config;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use TypeError;

class ConfigHandler
{
    /**
     * Loaded config attributes.
     *
     * @var array
     */
    protected $attributes = [];

    /**
     * Instance of config class.
     *
     * @var mixed
     */
    protected $config;

    /**
     * Config factory instances.
     *
     * @var array
     */
    protected $factories = [];

    /**
     * Config factory alias.
     *
     * @var array
     */
    protected $alias = [];

    /**
     * Methods with their associated factories.
     *
     * @var array
     */
    protected $methodFactories = [];

    /**
     * Create new ConfigHandler instance.
     *
     * @param  mixed $config
     * @return void
     */
    public function __construct($config)
    {
        $this->config = $config;

        $this->findConfigFactories();
    }

    /**
     * Set a config attribute.
     *
     * @param  string $attribute
     * @param  mixed  $value
     * @return void
     */
    public function set($attribute, $value)
    {
        $this->attributes[$attribute] = $value;
    }

    /**
     * Get namespace of the config.
     *
     * @return string|null
     */
    public function getNamespace()
    {
        return is_null($this->config) ? null : get_class($this->config);
    }

    /**
     * Determines if config is an instance of the given abstract.
     *
     * @param  string $abstract
     * @return bool
     */
    public function instanceOf($abstract)
    {
        return $this->config instanceof $abstract;
    }

    /**
     * Find factories by config depenecies.
     *
     * @return void
     */
    public function findConfigFactories()
    {
        $reflector = new ReflectionClass($this->config);
        $parent = $reflector->getParentClass();
        $uses = class_uses_recursive($this->config);

        foreach (Config::factories() as $dependency => $factory) {
            // Matching parent class.
            if ($parent) {
                if ($this->config instanceof $dependency) {
                    $this->registerFactory($factory);
                }
            }

            if (in_array($dependency, $uses)) {
                $this->registerFactory($factory);
            }
        }
    }

    /**
     * Get config key from config class.
     *
     * @return string
     */
    public function getKey()
    {
        return Config::getKey(get_class($this->config));
    }

    /**
     * Register config factory.
     *
     * @param  string $factory
     * @return void
     *
     * @throws \TypeError
     */
    public function registerFactory($factory)
    {
        $instance = new $factory($this);

        if (! is_subclass_of($factory, ConfigFactory::class)) {
            throw new TypeError("Config factory {$factory} must extend ".ConfigFactory::class.'.');
        }

        $this->factories[] = $instance;

        $reflector = new ReflectionClass($factory);
        foreach ($reflector->getMethods() as $method) {
            if ($factory != $method->class) {
                continue;
            }

            if ($method->getModifiers() != ReflectionMethod::IS_PUBLIC) {
                continue;
            }

            $this->methodFactories[$method->name] = $instance;
        }

        $reflector = new ReflectionClass($this->config);
        foreach ($reflector->getMethods() as $method) {
            if (! $alias = $instance->getAliasFor($method)) {
                continue;
            }

            if ($alias == $method->getName()) {
                continue;
            }

            $this->alias[$method->getName()] = $alias;
        }
    }

    /**
     * Load select config attributes.
     *
     * @param  string|array ...$keys
     * @return array        $attributes
     */
    public function get(...$keys)
    {
        if (count($keys) == 1 && trait_exists($keys[0])) {
            $trait = new ReflectionClass($keys[0]);
            $keys = collect(array_merge($trait->getProperties(), $trait->getMethods()))->map(function ($reflect) {
                // Looking for abstract public or public methods or properties.
                if (
                    $reflect->getModifiers() != ReflectionMethod::IS_PUBLIC
                    && $reflect->getModifiers() != ReflectionMethod::IS_PUBLIC + ReflectionMethod::IS_ABSTRACT
                ) {
                    return;
                }

                return $reflect->name;
            })->filter()->toArray();
        }
        $attributes = [];

        foreach ($keys as $key) {
            $attributes[$key] = $this->getAttribute($key);
        }

        return collect($attributes);
    }

    /**
     * Check if config has method.
     *
     * @param  string $method
     * @return bool
     */
    public function hasMethod(string $method)
    {
        return method_exists($this->config, $method);
    }

    /**
     * Config has attribute.
     *
     * @return bool
     */
    public function has(string $attribute)
    {
        if ($this->hasMethod($attribute)) {
            return true;
        }

        return property_exists($this->config, $attribute);
    }

    /**
     * Get config attribute from loaded stack or new.
     *
     * @param  string $name
     * @return mixed
     */
    public function getAttribute($name)
    {
        if (array_key_exists($name, $this->attributes)) {
            return $this->attributes[$name];
        }

        // Check for existing method.
        $method = $this->getMethodName($name);

        if ($this->hasMethod($method)) {
            return $this->resolveMethod($method);
        }

        return $this->config->$name;
    }

    /**
     * Call config method and store attributes.
     *
     * @param  string $method
     * @param  array  ...$parameters
     * @return mixed
     */
    public function callMethod($method, $parameters)
    {
        $attributes = $this->config->$method(...$parameters);

        $this->setAttribute($method, $attributes);

        return $attributes;
    }

    /**
     * Set attribute.
     *
     * @param  string $name
     * @param  mixed  $value
     * @return void
     */
    public function setAttribute(string $name, $value)
    {
        $this->attributes[Str::snake($name)] = $value;
    }

    /**
     * Check if a method has a factory.
     *
     * @param  string $method
     * @return bool
     */
    public function methodHasFactory(string $method)
    {
        return ! is_null($this->getMethodFactory($method));
    }

    /**
     * Get factory for method.
     *
     * @param  string $method
     * @return mixed
     */
    public function getMethodFactory(string $method)
    {
        if (array_key_exists($method, $this->alias)) {
            $method = $this->alias[$method];
        }

        return $this->methodFactories[$method] ?? null;
    }

    /**
     * Resolve config method.
     *
     * @param  string $name
     * @param  array  $parameters
     * @return array
     */
    protected function resolveMethod($method, $parameters = [])
    {
        if (! $this->methodHasFactory($method)) {
            return $this->callMethod($method, $parameters);
        }

        $factory = $this->getMethodFactory($method);

        return $factory->handle(
            $method, $parameters, $this->alias[$method] ?? null
        );
    }

    /**
     * Get config.
     *
     * @return mixed
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * Get method name.
     *
     * @param  string $method
     * @return string
     */
    public function getMethodName(string $method)
    {
        return Str::camel($method);
    }

    /**
     * Call config class method.
     *
     * @param  string $method
     * @param  array  $parameters
     * @return mixed
     *
     * @throws BadMethodCallException
     */
    public function __call($method, $parameters)
    {
        $method = $this->getMethodName($method);

        if ($this->hasMethod($method)) {
            return $this->resolveMethod($method, $parameters);
        }

        throw new BadMethodCallException(sprintf(
            'Method %s::%s does not exist.',
            static::class,
            $method
        ));
    }

    /**
     * Get config attribute.
     *
     * @param  string $name
     * @return mixed
     */
    public function __get(string $name)
    {
        return $this->getAttribute($name);
    }
}
