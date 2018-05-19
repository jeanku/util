<?php

namespace Jeanku\Facades;

/**
 * @see \Illuminate\Foundation\Application
 */
class Container extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'app';
    }
}
