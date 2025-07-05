<?php
/**
 * Wallet Logger - единая система логирования для кошелька
 */

class WalletLogger
{
    private static $config = null;
    
    /**
     * Инициализация логгера с конфигурацией
     */
    public static function init($config = null)
    {
        self::$config = $config;
        
        // Log system information for debugging
        self::log("PHP Memory Limit: " . ini_get('memory_limit'), 'DEBUG');
        self::log("PHP Max Execution Time: " . ini_get('max_execution_time'), 'DEBUG');
        self::log("PHP Version: " . phpversion(), 'DEBUG');
    }
    
    /**
     * Функция для записи логов в файл wallet_api.log
     */
    public static function log($message, $level = 'INFO')
    {
        // Проверяем режим отладки
        $debugMode = self::$config['debug_mode'] ?? true; // По умолчанию включена отладка
        if (!$debugMode && $level === 'DEBUG') {
            return; // Не записываем DEBUG логи если отладка выключена
        }
        
        $baseDir = dirname(__DIR__);
        $logDir = $baseDir . '/logs';
        
        // Создаем папку logs если её нет
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        $logFile = $logDir . '/wallet_api.log';
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;
        
        file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Логирование ошибок
     */
    public static function error($message)
    {
        self::log($message, 'ERROR');
    }
    
    /**
     * Логирование предупреждений
     */
    public static function warning($message)
    {
        self::log($message, 'WARNING');
    }
    
    /**
     * Логирование информации
     */
    public static function info($message)
    {
        self::log($message, 'INFO');
    }
    
    /**
     * Логирование отладочной информации
     */
    public static function debug($message)
    {
        self::log($message, 'DEBUG');
    }
}
