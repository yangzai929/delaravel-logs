<?php

namespace DelaravelLog\Dlog;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;

class Logs
{
    /**
     * 创建日志文件夹
     * @param  string  $dir
     * @param  int  $mode
     * @return bool
     */
    public static function mkDirs($dir, $mode = 0777)
    {
        if (is_dir($dir) || @mkdir($dir, $mode, true)) {
            return true;
        }

        return false;
    }

    private static function generateLogPath($filename, $isDate, $dir)
    {
        // 定义一个 `storage_path` 的兼容函数
        if (!function_exists('storage_path')) {
            function storage_path($path = '')
            {
                // 默认使用当前目录的 `storage` 文件夹或其他指定路径
                $basePath = defined('STORAGE_PATH') ? constant('STORAGE_PATH') : __DIR__ . '/storage';
                return rtrim($basePath, '/').($path ? '/'.ltrim($path, '/') : '');
            }
        }
        $filename .= PHP_SAPI == 'cli' ? '_cli' : '';
        $filename .= '.log';

        $path = storage_path(sprintf('logs/%s/%s', $isDate ? date('Ymd') : '', $dir ?: ''));
        self::mkDirs($path);

        return $path . '/' . $filename;
    }

    /**
     * @param  string  $message
     * @param  array  $data
     * @param  string  $filename
     * @param  bool  $isDate
     * @param  string  $dir
     * @param  int  $level
     */
    private static function _save($message, $data, $filename = 'log', $isDate = false, $dir = '', $level = Logger::INFO)
    {
        $data = (array) $data;

        $log = new Logger('mylog');
        $path = self::generateLogPath($filename, $isDate, $dir);
        //自定义日志的格式，使其更加易读
        $formatter = new LineFormatter(
            "[%datetime%] %channel%.%level_name%: %message% %context%\n",
            "Y-m-d H:i:s",
            true,
            true
        );
        $streamHandler = new RotatingFileHandler($path, 30, $level);  // 30 表示保留的最大文件数
        $streamHandler->setFormatter($formatter);
        $streamHandler->setFilenameFormat('{filename}-{date}', 'Ymd');

        $log->pushHandler($streamHandler);
        $log->log($level, $message, $data);
    }

    public static function logInfo($message, $data, $filename = 'info', $isDate = false, $dir = '')
    {
        self::_save($message, $data, $filename, $isDate, $dir, Logger::INFO);
    }

    public static function logError($message, $data, $filename = 'error')
    {
        self::_save($message, $data, $filename, false, '', Logger::ERROR);
    }

    protected static function getformattedTrace(\Exception $exception)
    {
        // 提取前 10 行的异常堆栈跟踪
        $traceDetails = array_map(function ($trace) {
            return sprintf(
                "File: %s, Line: %s, Class: %s, Function: %s",
                $trace['file'] ?? 'N/A',
                $trace['line'] ?? 'N/A',
                $trace['class'] ?? 'N/A',
                $trace['function'] ?? 'N/A'
            );
        }, array_slice($exception->getTrace(), 0, 10));

        // 将堆栈跟踪信息合并为一个字符串
        $formattedTrace = implode("\n", $traceDetails);

        return "\n".$formattedTrace."\n";
    }

    protected static function logException(\Exception $exception, $class, $method, $file_name, $context = null)
    {
        // 记录日志
        self::logError('mic-time: '.microtime(true).' An error occurred ', [
            'class' => $class,
            'method' => $method,
            'exception_message' => $exception->getMessage(),
            'exception_trace' => self::getformattedTrace($exception), // 使用格式化的堆栈跟踪
            'context' => json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        ], $file_name);

    }

    public static function logApiException(\Exception $exception, $class, $method)
    {
        $context = [
            'methods' => request()->route()->methods(),
            'route' => request()->route()->uri(),
            'request_id' => request()->header('X-Request-ID'),
            'request_params' => request()->all(),
            'user_id' => auth()->id() ?? 'guest',
        ];
        self::logException($exception, $class, $method, file_name: 'api_error', context: $context);
    }

    public static function logExternalServiceException(\Exception $exception, $class, $method, $context = null)
    {
        self::logException($exception, $class, $method, 'external_srv_error', $context);
    }


    public static function sql()
    {
        \DB::listen(function ($sql) {
            // 格式化绑定参数
            foreach ($sql->bindings as $i => $binding) {
                if ($binding instanceof \DateTime) {
                    $sql->bindings[$i] = $binding->format('\'Y-m-d H:i:s\'');
                } else {
                    if (is_string($binding)) {
                        $sql->bindings[$i] = "'$binding'";
                    }
                }
            }

            // 构造完整的 SQL 查询
            $query = str_replace(['%', '?'], ['%%', '%s'], $sql->sql);
            $query = vsprintf($query, $sql->bindings);

            // 增加 SQL 执行时间记录
            $executionTime = $sql->time; // 单位为毫秒
            $logMessage = sprintf(
                "SQL: %s | Execution Time: %.2f ms",
                $query,
                $executionTime
            );

            // 记录到日志
            Logs::logInfo('sql:', $logMessage, 'sql', false);
        });
    }

}
