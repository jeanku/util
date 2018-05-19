<?php
namespace Jeanku\Util;

/**
 * Config类
 * @date 2016-05-18
 */
class Config
{

    protected static $config = [];

    /**
     * get config
     * @param string $name require config name
     * @param string $key sometime key
     * @return array
     */
    public static function get($name, $key = null)
    {
        if (!isset(self::$config[$name])) {
            $path = WEBPATH .'/config/' . $name . '.php';
            if (is_file($path)) {
                self::$config[$name] = require($path);
            }
        }
        if ($key) {
            return isset(self::$config[$name][$key]) ? self::$config[$name][$key] : null;
        } else {
            return isset(self::$config[$name]) ? self::$config[$name] : null;
        }
    }
}
