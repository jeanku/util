<?php
namespace Jeanku\Util;

/**
 * Redis class
 * @desc Redis
 * @package \Jeanku\Util
 */
class Redis
{

    protected static $instance = [];

    protected function __construct() {}

    /**
     * get redis config
     * @param string $database option redis database
     * @return array
     * @throws \Exception
     */
    public static function getConfig($database)
    {
        $config = app('config')->get('database', 'redis');
        if (isset($config[$database])) {
            return $config[$database];
        }
        throw new \Exception("databse $database not config!");
    }


    /**
     * get redis instance
     * @param string $database option redis database
     * @return array
     */
    public static function getInstance($database = 'default')
    {
        if (!isset(self::$instance[$database])) {
            $config = self::getConfig($database);
            $redis = new \Redis();
            $redis->pconnect($config['host'], $config['port']);
            $redis->select($config['database']);
            self::$instance[$database] = $redis;
        }
        return self::$instance[$database];
    }
}


