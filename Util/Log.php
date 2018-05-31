<?php
namespace Jeanku\Util;

/**
 * 日志类
 * @desc log
 * @package \Supercoach\Util
 * @date 2017-09-13
 */
class Log
{
    //error level
    protected static $levels = [
        'debug',
        'info',
        'notice',
        'warning',
        'error',
        'critical',
        'alert',
        'emergency',
    ];

    const FILE_PATH = WEBPATH . '/storage/logs';

    /**
     * 添加debug日志
     * @date 2017-09-13
     * @param string $title required 日志title
     * @param array $context option 日志内容
     * @demo Log::debug('debug info', ['errorCode' => 'error code', 'errorMsg' => 'error message']);
     * @return array
     */
    public static function debug($title, array $context = array())
    {
        return self::_addLog(__FUNCTION__, $title, $context);
    }



    /**
     * 添加info日志
     * @date 2017-09-13
     * @param string $title required 日志title
     * @param array $context option 日志内容
     * @demo Log::info('debug info', ['errorCode' => 'error code', 'errorMsg' => 'error message']);
     * @return array
     */
    public static function info($title, array $context = array())
    {
        return self::_addLog(__FUNCTION__, $title, $context);
    }


    /**
     * 添加notice日志
     * @date 2017-09-13
     * @param string $title required 日志title
     * @param array $context option 日志内容
     * @demo Log::notice('debug info', ['errorCode' => 'error code', 'errorMsg' => 'error message']);
     * @return array
     */
    public static function notice($title, array $context = array())
    {
        return self::_addLog(__FUNCTION__, $title, $context);
    }


    /**
     * 添加warning日志
     * @date 2017-09-13
     * @param string $title required 日志title
     * @param array $context option 日志内容
     * @demo Log::warning('debug info', ['errorCode' => 'error code', 'errorMsg' => 'error message']);
     * @return array
     */
    public static function warning($title, array $context = array())
    {
        return self::_addLog(__FUNCTION__, $title, $context);
    }


    /**
     * 添加error日志
     * @date 2017-09-13
     * @param string $title required 日志title
     * @param array $context option 日志内容
     * @demo Log::error('debug info', ['errorCode' => 'error code', 'errorMsg' => 'error message']);
     * @return array
     */
    public static function error($title, array $context = array())
    {
        return self::_addLog(__FUNCTION__, $title, $context);
    }


    /**
     * 添加error日志
     * @date 2017-09-13
     * @param string $title required 日志title
     * @param array $context option 日志内容
     * @demo Log::critical('debug info', ['errorCode' => 'error code', 'errorMsg' => 'error message']);
     * @return array
     */
    public static function critical($title, array $context = array())
    {
        return self::_addLog(__FUNCTION__, $title, $context);
    }


    /**
     * 添加alert日志
     * @date 2017-09-13
     * @param string $title required 日志title
     * @param array $context option 日志内容
     * @demo Log::alert('debug info', ['errorCode' => 'error code', 'errorMsg' => 'error message']);
     * @return array
     */
    public static function alert($title, array $context = array())
    {
        return self::_addLog(__FUNCTION__, $title, $context);
    }


    /**
     * 添加alert日志
     * @date 2017-09-13
     * @param string $title required 日志title
     * @param array $context option 日志内容
     * @demo Log::emergency('debug info', ['errorCode' => 'error code', 'errorMsg' => 'error message']);
     * @return array
     */
    public static function emergency($title, array $context = array())
    {
        return self::_addLog(__FUNCTION__, $title, $context);
    }


    /**
     * 添加日志具体方法
     * @date 2017-09-13
     * @param string $level required 错误级别
     * @param string $title required 日志title
     * @param array $context option 日志内容
     * @return array
     */
    private static function _addLog($level, $title, $context)
    {
        $file = sprintf(self::FILE_PATH . '/%s.log', date('Ymd'));
        $msg = sprintf("[%s]   %s   %s " . json_encode($context, JSON_UNESCAPED_UNICODE) . PHP_EOL, date('Y-m-d H:i:s'), strtoupper($level), $title);
        file_put_contents($file, $msg, FILE_APPEND);
    }

} 


