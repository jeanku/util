<?php
namespace Jeanku\Util;

use Closure;
use ArrayAccess;
use ReflectionClass;
use ReflectionMethod;
use ReflectionFunction;
use ReflectionParameter;

class Container implements ArrayAccess
{
    /**
     * The current globally available container (if any).
     */
    protected static $instance;

    /**
     * An array of the types that have been resolved.
     */
    protected $resolved = [];

    /**
     * The container's bindings.
     */
    protected $bindings = [];

    /**
     * The container's shared instances.
     */
    protected $instances = [];

    /**
     * The registered type aliases.
     */
    protected $aliases = [];

    /**
     * The extension closures for services.
     */
    protected $extenders = [];

    /**
     * All of the registered tags.
     */
    protected $tags = [];

    /**
     * The stack of concretions currently being built.
     */
    protected $buildStack = [];

    /**
     * The contextual binding map.
     */
    public $contextual = [];

    /**
     * All of the registered rebound callbacks.
     */
    protected $reboundCallbacks = [];

    /**
     * All of the global resolving callbacks.
     */
    protected $globalResolvingCallbacks = [];

    /**
     * All of the global after resolving callbacks.
     */
    protected $globalAfterResolvingCallbacks = [];

    /**
     * All of the resolving callbacks by class type.
     */
    protected $resolvingCallbacks = [];

    /**
     * All of the after resolving callbacks by class type.
     */
    protected $afterResolvingCallbacks = [];

    public function __construct()
    {
        $this->setEnv();
    }

    /**
     * Determine if the given abstract type has been bound.
     * @param  string  $abstract
     * @return bool
     */
    public function bound($abstract)
    {
        $abstract = $this->normalize($abstract);
        return isset($this->bindings[$abstract]) || isset($this->instances[$abstract]) || $this->isAlias($abstract);
    }

    /**
     * Determine if the given abstract type has been resolved.
     * @param  string  $abstract
     * @return bool
     */
    public function resolved($abstract)
    {
        $abstract = $this->normalize($abstract);
        if ($this->isAlias($abstract)) {
            $abstract = $this->getAlias($abstract);
        }
        return isset($this->resolved[$abstract]) || isset($this->instances[$abstract]);
    }

    /**
     * Determine if a given string is an alias.
     * @param  string  $name
     * @return bool
     */
    public function isAlias($name)
    {
        return isset($this->aliases[$this->normalize($name)]);
    }

    /**
     * Register a binding with the container.
     * @param  string|array  $abstract
     * @param  \Closure|string|null  $concrete
     * @param  bool  $shared
     * @return void
     */
    public function bind($abstract, $concrete = null, $shared = false)
    {
        $abstract = $this->normalize($abstract);
        $concrete = $this->normalize($concrete);
        if (is_array($abstract)) {
            list($abstract, $alias) = $this->extractAlias($abstract);

            $this->alias($abstract, $alias);
        }
        $this->dropStaleInstances($abstract);

        if (is_null($concrete)) {
            $concrete = $abstract;
        }
        if (! $concrete instanceof Closure) {
            $concrete = $this->getClosure($abstract, $concrete);
        }
        $this->bindings[$abstract] = compact('concrete', 'shared');
        if ($this->resolved($abstract)) {
            $this->rebound($abstract);
        }
    }

    /**
     * Get the Closure to be used when building a type.
     * @param  string  $abstract
     * @param  string  $concrete
     * @return \Closure
     */
    protected function getClosure($abstract, $concrete)
    {
        return function ($c, $parameters = []) use ($abstract, $concrete) {
            $method = ($abstract == $concrete) ? 'build' : 'make';
            return $c->$method($concrete, $parameters);
        };
    }

    /**
     * Add a contextual binding to the container.
     * @param  string  $concrete
     * @param  string  $abstract
     * @param  \Closure|string  $implementation
     * @return void
     */
    public function addContextualBinding($concrete, $abstract, $implementation)
    {
        $this->contextual[$this->normalize($concrete)][$this->normalize($abstract)] = $this->normalize($implementation);
    }

    /**
     * Register a binding if it hasn't already been registered.
     * @param  string  $abstract
     * @param  \Closure|string|null  $concrete
     * @param  bool  $shared
     * @return void
     */
    public function bindIf($abstract, $concrete = null, $shared = false)
    {
        if (! $this->bound($abstract)) {
            $this->bind($abstract, $concrete, $shared);
        }
    }

    /**
     * Register a shared binding in the container.
     * @param  string|array  $abstract
     * @param  \Closure|string|null  $concrete
     * @return void
     */
    public function singleton($abstract, $concrete = null)
    {
        $this->bind($abstract, $concrete, true);
    }

    /**
     * Wrap a Closure such that it is shared.
     * @param  \Closure  $closure
     * @return \Closure
     */
    public function share(Closure $closure)
    {
        return function ($container) use ($closure) {
            static $object;
            if (is_null($object)) {
                $object = $closure($container);
            }
            return $object;
        };
    }

    /**
     * "Extend" an abstract type in the container.
     * @param  string    $abstract
     * @param  \Closure  $closure
     * @return void
     * @throws \Exception
     */
    public function extend($abstract, Closure $closure)
    {
        $abstract = $this->normalize($abstract);
        if (isset($this->instances[$abstract])) {
            $this->instances[$abstract] = $closure($this->instances[$abstract], $this);
            $this->rebound($abstract);
        } else {
            $this->extenders[$abstract][] = $closure;
        }
    }

    /**
     * Register an existing instance as shared in the container.
     * @param  string  $abstract
     * @param  mixed   $instance
     * @return void
     */
    public function instance($abstract, $instance)
    {
        $abstract = $this->normalize($abstract);
        if (is_array($abstract)) {
            list($abstract, $alias) = $this->extractAlias($abstract);
            $this->alias($abstract, $alias);
        }
        unset($this->aliases[$abstract]);
        $bound = $this->bound($abstract);
        $this->instances[$abstract] = $instance;
        if ($bound) {
            $this->rebound($abstract);
        }
    }

    /**
     * Assign a set of tags to a given binding.
     * @param  array|string  $abstracts
     * @param  array|mixed   ...$tags
     * @return void
     */
    public function tag($abstracts, $tags)
    {
        $tags = is_array($tags) ? $tags : array_slice(func_get_args(), 1);
        foreach ($tags as $tag) {
            if (! isset($this->tags[$tag])) {
                $this->tags[$tag] = [];
            }
            foreach ((array) $abstracts as $abstract) {
                $this->tags[$tag][] = $this->normalize($abstract);
            }
        }
    }

    /**
     * Resolve all of the bindings for a given tag.
     * @param  string  $tag
     * @return array
     */
    public function tagged($tag)
    {
        $results = [];
        if (isset($this->tags[$tag])) {
            foreach ($this->tags[$tag] as $abstract) {
                $results[] = $this->make($abstract);
            }
        }
        return $results;
    }

    /**
     * Alias a type to a different name.
     * @param  string  $abstract
     * @param  string  $alias
     * @return void
     */
    public function alias($abstract, $alias)
    {
        $this->aliases[$alias] = $this->normalize($abstract);
    }

    /**
     * Extract the type and alias from a given definition.
     * @param  array  $definition
     * @return array
     */
    protected function extractAlias(array $definition)
    {
        return [key($definition), current($definition)];
    }

    /**
     * Bind a new callback to an abstract's rebind event.
     * @param  string    $abstract
     * @param  \Closure  $callback
     * @return mixed
     */
    public function rebinding($abstract, Closure $callback)
    {
        $this->reboundCallbacks[$this->normalize($abstract)][] = $callback;
        if ($this->bound($abstract)) {
            return $this->make($abstract);
        }
    }

    /**
     * Refresh an instance on the given target and method.
     * @param  string  $abstract
     * @param  mixed   $target
     * @param  string  $method
     * @return mixed
     */
    public function refresh($abstract, $target, $method)
    {
        return $this->rebinding($this->normalize($abstract), function ($app, $instance) use ($target, $method) {
            $target->{$method}($instance);
        });
    }

    /**
     * Fire the "rebound" callbacks for the given abstract type.
     * @param  string  $abstract
     * @return void
     */
    protected function rebound($abstract)
    {
        $instance = $this->make($abstract);
        foreach ($this->getReboundCallbacks($abstract) as $callback) {
            call_user_func($callback, $this, $instance);
        }
    }

    /**
     * Get the rebound callbacks for a given type.
     * @param  string  $abstract
     * @return array
     */
    protected function getReboundCallbacks($abstract)
    {
        if (isset($this->reboundCallbacks[$abstract])) {
            return $this->reboundCallbacks[$abstract];
        }
        return [];
    }

    /**
     * Wrap the given closure such that its dependencies will be injected when executed.
     * @param  \Closure  $callback
     * @param  array  $parameters
     * @return \Closure
     */
    public function wrap(Closure $callback, array $parameters = [])
    {
        return function () use ($callback, $parameters) {
            return $this->call($callback, $parameters);
        };
    }

    /**
     * Call the given Closure / class@method and inject its dependencies.
     * @param  callable|string  $callback
     * @param  array  $parameters
     * @param  string|null  $defaultMethod
     * @return mixed
     */
    public function call($callback, array $parameters = [], $defaultMethod = null)
    {
        if ($this->isCallableWithAtSign($callback) || $defaultMethod) {
            return $this->callClass($callback, $parameters, $defaultMethod);
        }
        $dependencies = $this->getMethodDependencies($callback, $parameters);
        return call_user_func_array($callback, $dependencies);
    }

    /**
     * Determine if the given string is in Class@method syntax.
     * @param  mixed  $callback
     * @return bool
     */
    protected function isCallableWithAtSign($callback)
    {
        return is_string($callback) && strpos($callback, '@') !== false;
    }

    /**
     * Get all dependencies for a given method.
     * @param  callable|string  $callback
     * @param  array  $parameters
     * @return array
     */
    protected function getMethodDependencies($callback, array $parameters = [])
    {
        $dependencies = [];
        foreach ($this->getCallReflector($callback)->getParameters() as $parameter) {
            $this->addDependencyForCallParameter($parameter, $parameters, $dependencies);
        }
        return array_merge($dependencies, $parameters);
    }

    /**
     * Get the proper reflection instance for the given callback.
     * @param  callable|string  $callback
     * @return \ReflectionFunctionAbstract
     */
    protected function getCallReflector($callback)
    {
        if (is_string($callback) && strpos($callback, '::') !== false) {
            $callback = explode('::', $callback);
        }
        if (is_array($callback)) {
            return new ReflectionMethod($callback[0], $callback[1]);
        }
        return new ReflectionFunction($callback);
    }

    /**
     * Get the dependency for the given call parameter.
     * @param  \ReflectionParameter  $parameter
     * @param  array  $parameters
     * @param  array  $dependencies
     * @return mixed
     */
    protected function addDependencyForCallParameter(ReflectionParameter $parameter, array &$parameters, &$dependencies)
    {
        if (array_key_exists($parameter->name, $parameters)) {
            $dependencies[] = $parameters[$parameter->name];
            unset($parameters[$parameter->name]);
        } elseif ($parameter->getClass()) {
            $dependencies[] = $this->make($parameter->getClass()->name);
        } elseif ($parameter->isDefaultValueAvailable()) {
            $dependencies[] = $parameter->getDefaultValue();
        }
    }

    /**
     * Call a string reference to a class using Class@method syntax.
     * @param  string  $target
     * @param  array  $parameters
     * @param  string|null  $defaultMethod
     * @return mixed
     * @throws \Exception
     */
    protected function callClass($target, array $parameters = [], $defaultMethod = null)
    {
        $segments = explode('@', $target);
        $method = count($segments) == 2 ? $segments[1] : $defaultMethod;
        if (is_null($method)) {
            throw new \Exception('Method not provided.', -1);
        }
        return $this->call([$this->make($segments[0]), $method], $parameters);
    }

    /**
     * Resolve the given type from the container.
     * @param  string  $abstract
     * @param  array   $parameters
     * @return mixed
     */
    public function make($abstract, array $parameters = [])
    {
        $abstract = $this->getAlias($this->normalize($abstract));
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }
        $concrete = $this->getConcrete($abstract);
        if ($this->isBuildable($concrete, $abstract)) {
            $object = $this->build($concrete, $parameters);
        } else {
            $object = $this->make($concrete, $parameters);
        }
        foreach ($this->getExtenders($abstract) as $extender) {
            $object = $extender($object, $this);
        }
        if ($this->isShared($abstract)) {
            $this->instances[$abstract] = $object;
        }
        $this->fireResolvingCallbacks($abstract, $object);
        $this->resolved[$abstract] = true;
        return $object;
    }

    /**
     * Get the concrete type for a given abstract.
     * @param  string  $abstract
     * @return mixed   $concrete
     */
    protected function getConcrete($abstract)
    {
        if (! is_null($concrete = $this->getContextualConcrete($abstract))) {
            return $concrete;
        }
        if (! isset($this->bindings[$abstract])) {
            return $abstract;
        }
        return $this->bindings[$abstract]['concrete'];
    }

    /**
     * Get the contextual concrete binding for the given abstract.
     * @param  string  $abstract
     * @return string|null
     */
    protected function getContextualConcrete($abstract)
    {
        if (isset($this->contextual[end($this->buildStack)][$abstract])) {
            return $this->contextual[end($this->buildStack)][$abstract];
        }
    }

    /**
     * Normalize the given class name by removing leading slashes.
     * @param  mixed  $service
     * @return mixed
     */
    protected function normalize($service)
    {
        return is_string($service) ? ltrim($service, '\\') : $service;
    }

    /**
     * Get the extender callbacks for a given type.
     * @param  string  $abstract
     * @return array
     */
    protected function getExtenders($abstract)
    {
        if (isset($this->extenders[$abstract])) {
            return $this->extenders[$abstract];
        }
        return [];
    }

    /**
     * Instantiate a concrete instance of the given type.
     * @param  string  $concrete
     * @param  array   $parameters
     * @return mixed
     * @throws \Exception
     */
    public function build($concrete, array $parameters = [])
    {
        if ($concrete instanceof Closure) {
            return $concrete($this, $parameters);
        }
        $reflector = new ReflectionClass($concrete);
        if (! $reflector->isInstantiable()) {
            if (! empty($this->buildStack)) {
                $previous = implode(', ', $this->buildStack);
                $message = "Target [$concrete] is not instantiable while building [$previous].";
            } else {
                $message = "Target [$concrete] is not instantiable.";
            }
            throw new \Exception($message);
        }
        $this->buildStack[] = $concrete;
        $constructor = $reflector->getConstructor();
        if (is_null($constructor)) {
            array_pop($this->buildStack);

            return new $concrete;
        }
        $dependencies = $constructor->getParameters();
        $parameters = $this->keyParametersByArgument(
            $dependencies, $parameters
        );
        $instances = $this->getDependencies(
            $dependencies, $parameters
        );
        array_pop($this->buildStack);
        return $reflector->newInstanceArgs($instances);
    }

    /**
     * Resolve all of the dependencies from the ReflectionParameters.
     * @param  array  $parameters
     * @param  array  $primitives
     * @return array
     */
    protected function getDependencies(array $parameters, array $primitives = [])
    {
        $dependencies = [];
        foreach ($parameters as $parameter) {
            $dependency = $parameter->getClass();
            if (array_key_exists($parameter->name, $primitives)) {
                $dependencies[] = $primitives[$parameter->name];
            } elseif (is_null($dependency)) {
                $dependencies[] = $this->resolveNonClass($parameter);
            } else {
                $dependencies[] = $this->resolveClass($parameter);
            }
        }
        return $dependencies;
    }

    /**
     * Resolve a non-class hinted dependency.
     * @param  \ReflectionParameter  $parameter
     * @return mixed
     * @throws \Exception
     */
    protected function resolveNonClass(ReflectionParameter $parameter)
    {
        if (! is_null($concrete = $this->getContextualConcrete('$'.$parameter->name))) {
            if ($concrete instanceof Closure) {
                return call_user_func($concrete, $this);
            } else {
                return $concrete;
            }
        }
        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }
        $message = "Unresolvable dependency resolving [$parameter] in class {$parameter->getDeclaringClass()->getName()}";
        throw new \Exception($message);
    }

    /**
     * Resolve a class based dependency from the container.
     * @param  \ReflectionParameter  $parameter
     * @return mixed
     * @throws \Exception
     */
    protected function resolveClass(ReflectionParameter $parameter)
    {
        try {
            return $this->make($parameter->getClass()->name);
        }
        catch (\Exception $e) {
            if ($parameter->isOptional()) {
                return $parameter->getDefaultValue();
            }
            throw $e;
        }
    }

    /**
     * If extra parameters are passed by numeric ID, rekey them by argument name.
     * @param  array  $dependencies
     * @param  array  $parameters
     * @return array
     */
    protected function keyParametersByArgument(array $dependencies, array $parameters)
    {
        foreach ($parameters as $key => $value) {
            if (is_numeric($key)) {
                unset($parameters[$key]);
                $parameters[$dependencies[$key]->name] = $value;
            }
        }

        return $parameters;
    }

    /**
     * Register a new resolving callback.
     * @param  string    $abstract
     * @param  \Closure|null  $callback
     * @return void
     */
    public function resolving($abstract, Closure $callback = null)
    {
        if ($callback === null && $abstract instanceof Closure) {
            $this->resolvingCallback($abstract);
        } else {
            $this->resolvingCallbacks[$this->normalize($abstract)][] = $callback;
        }
    }

    /**
     * Register a new after resolving callback for all types.
     * @param  string   $abstract
     * @param  \Closure|null $callback
     * @return void
     */
    public function afterResolving($abstract, Closure $callback = null)
    {
        if ($abstract instanceof Closure && $callback === null) {
            $this->afterResolvingCallback($abstract);
        } else {
            $this->afterResolvingCallbacks[$this->normalize($abstract)][] = $callback;
        }
    }

    /**
     * Register a new resolving callback by type of its first argument.
     * @param  \Closure  $callback
     * @return void
     */
    protected function resolvingCallback(Closure $callback)
    {
        $abstract = $this->getFunctionHint($callback);
        if ($abstract) {
            $this->resolvingCallbacks[$abstract][] = $callback;
        } else {
            $this->globalResolvingCallbacks[] = $callback;
        }
    }

    /**
     * Register a new after resolving callback by type of its first argument.
     * @param  \Closure  $callback
     * @return void
     */
    protected function afterResolvingCallback(Closure $callback)
    {
        $abstract = $this->getFunctionHint($callback);
        if ($abstract) {
            $this->afterResolvingCallbacks[$abstract][] = $callback;
        } else {
            $this->globalAfterResolvingCallbacks[] = $callback;
        }
    }

    /**
     * Get the type hint for this closure's first argument.
     * @param  \Closure  $callback
     * @return mixed
     */
    protected function getFunctionHint(Closure $callback)
    {
        $function = new ReflectionFunction($callback);
        if ($function->getNumberOfParameters() == 0) {
            return;
        }
        $expected = $function->getParameters()[0];
        if (! $expected->getClass()) {
            return;
        }
        return $expected->getClass()->name;
    }

    /**
     * Fire all of the resolving callbacks.
     * @param  string  $abstract
     * @param  mixed   $object
     * @return void
     */
    protected function fireResolvingCallbacks($abstract, $object)
    {
        $this->fireCallbackArray($object, $this->globalResolvingCallbacks);
        $this->fireCallbackArray(
            $object, $this->getCallbacksForType(
                $abstract, $object, $this->resolvingCallbacks
            )
        );
        $this->fireCallbackArray($object, $this->globalAfterResolvingCallbacks);
        $this->fireCallbackArray(
            $object, $this->getCallbacksForType(
                $abstract, $object, $this->afterResolvingCallbacks
            )
        );
    }

    /**
     * Get all callbacks for a given type.
     * @param  string  $abstract
     * @param  object  $object
     * @param  array   $callbacksPerType
     * @return array
     */
    protected function getCallbacksForType($abstract, $object, array $callbacksPerType)
    {
        $results = [];
        foreach ($callbacksPerType as $type => $callbacks) {
            if ($type === $abstract || $object instanceof $type) {
                $results = array_merge($results, $callbacks);
            }
        }
        return $results;
    }

    /**
     * Fire an array of callbacks with an object.
     * @param  mixed  $object
     * @param  array  $callbacks
     * @return void
     */
    protected function fireCallbackArray($object, array $callbacks)
    {
        foreach ($callbacks as $callback) {
            $callback($object, $this);
        }
    }

    /**
     * Determine if a given type is shared.
     * @param  string  $abstract
     * @return bool
     */
    public function isShared($abstract)
    {
        $abstract = $this->normalize($abstract);
        if (isset($this->instances[$abstract])) {
            return true;
        }
        if (! isset($this->bindings[$abstract]['shared'])) {
            return false;
        }
        return $this->bindings[$abstract]['shared'] === true;
    }

    /**
     * Determine if the given concrete is buildable.
     * @param  mixed   $concrete
     * @param  string  $abstract
     * @return bool
     */
    protected function isBuildable($concrete, $abstract)
    {
        return $concrete === $abstract || $concrete instanceof Closure;
    }

    /**
     * Get the alias for an abstract if available.
     * @param  string  $abstract
     * @return string
     */
    protected function getAlias($abstract)
    {
        if (! isset($this->aliases[$abstract])) {
            return $abstract;
        }

        return $this->getAlias($this->aliases[$abstract]);
    }

    /**
     * Get the container's bindings.
     * @return array
     */
    public function getBindings()
    {
        return $this->bindings;
    }

    /**
     * Drop all of the stale instances and aliases.
     * @param  string  $abstract
     * @return void
     */
    protected function dropStaleInstances($abstract)
    {
        unset($this->instances[$abstract], $this->aliases[$abstract]);
    }

    /**
     * Remove a resolved instance from the instance cache.
     * @param  string  $abstract
     * @return void
     */
    public function forgetInstance($abstract)
    {
        unset($this->instances[$this->normalize($abstract)]);
    }

    /**
     * Clear all of the instances from the container.
     * @return void
     */
    public function forgetInstances()
    {
        $this->instances = [];
    }

    /**
     * Flush the container of all bindings and resolved instances.
     * @return void
     */
    public function flush()
    {
        $this->aliases = [];
        $this->resolved = [];
        $this->bindings = [];
        $this->instances = [];
    }

    /**
     * Set the globally available instance of the container.
     * @return static
     */
    public static function getInstance()
    {
        if (is_null(static::$instance)) {
            static::$instance = new static;
        }
        return static::$instance;
    }


    /**
     * Set the shared instance of the container.
     * @param  \Illuminate\Contracts\Container\Container  $container
     * @return void
     */
    public static function setInstance($container)
    {
        static::$instance = $container;
    }

    /**
     * Set env.
     * @return void
     */
    public function setEnv()
    {
        $path = WEBPATH .  DIRECTORY_SEPARATOR  . '.env';
        if (is_file($path) && is_readable($path)) {
            $file = file_get_contents($path);
            preg_match_all('/[A-Z|_]+=[\w\.-_]+/', $file, $column);
            foreach ($column[0] as $enval) {
                putenv($enval);
            }
        }
    }

    /**
     * Determine if a given offset exists.
     * @param  string  $key
     * @return bool
     */
    public function offsetExists($key)
    {
        return $this->bound($key);
    }

    /**
     * Get the value at a given offset.
     *
     * @param  string  $key
     * @return mixed
     */
    public function offsetGet($key)
    {
        return $this->make($key);
    }

    /**
     * Set the value at a given offset.
     * @param  string  $key
     * @param  mixed   $value
     * @return void
     */
    public function offsetSet($key, $value)
    {
        if (! $value instanceof Closure) {
            $value = function () use ($value) {
                return $value;
            };
        }
        $this->bind($key, $value);
    }

    /**
     * Unset the value at a given offset.
     * @param  string  $key
     * @return void
     */
    public function offsetUnset($key)
    {
        $key = $this->normalize($key);
        unset($this->bindings[$key], $this->instances[$key], $this->resolved[$key]);
    }

    /**
     * Dynamically access container services.
     * @param  string  $key
     * @return mixed
     */
    public function __get($key)
    {
        return $this[$key];
    }

    /**
     * Dynamically set container services.
     * @param  string  $key
     * @param  mixed   $value
     * @return void
     */
    public function __set($key, $value)
    {
        $this[$key] = $value;
    }
}
