<?php
namespace Jeanku\Facades;

/**
 * @see \Illuminate\Foundation\Application
 */
class Route extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'route';
    }
}
