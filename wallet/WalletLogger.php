<?php
declare(strict_types=1);

namespace Blockchain\Wallet;

/**
 * Wallet Logger - unified logging system for the wallet API
 */

class WalletLogger
{
    private static $config = null;
    private static $enabled = true; // Always enable logging for debugging
    
    /**
     * Initialize logger with configuration
     */
    public static function init($config = null)
    {
        self::$config = $config;
        // Infer logging toggle from config or environment; default is disabled to preserve disk space
        $flag = null;
        if (is_array(self::$config)) {
            $flag = self::$config['wallet_logging_enabled']
                ?? self::$config['logging_enabled']
                ?? self::$config['api_logging_enabled']
                ?? null;
        }
        if ($flag === null) {
            $flag = getenv('WALLET_LOGGING')
                ?: getenv('WALLET_LOGGING_ENABLED')
                ?: getenv('API_LOGGING')
                ?: getenv('LOGGING_ENABLED');
        }
        
        // If still not specified, enable logging by default in debug mode
        $defaultEnabled = true; // Always enabled for debugging
        if (is_array(self::$config)) {
            $defaultEnabled = (bool) (self::$config['debug_mode'] ?? true);
        }
        self::$enabled = self::toBool($flag, $defaultEnabled);
        
        // Log system information for debugging
        self::log("PHP Memory Limit: " . ini_get('memory_limit'), 'DEBUG');
        self::log("PHP Max Execution Time: " . ini_get('max_execution_time'), 'DEBUG');
        self::log("PHP Version: " . phpversion(), 'DEBUG');
    }
    
    /**
     * Write log entry to logs/wallet_api.log when logging is enabled
     */
    public static function log($message, $level = 'INFO')
    {
        // Honor debug mode for DEBUG verbosity
        $debugMode = self::$config['debug_mode'] ?? true; // Default: debug enabled for development
        if (!$debugMode && $level === 'DEBUG') {
            return; // Skip DEBUG logs if debug mode is off
        }
        // Skip all disk writes unless explicitly enabled
        if (!self::$enabled) {
            return;
        }
        
        $baseDir = dirname(__DIR__);
        $logDir = $baseDir . '/logs';
        
        // Create logs directory if it does not exist
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        $logFile = $logDir . '/wallet_api.log';
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;
        
        file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Error logging helper
     */
    public static function error($message)
    {
        self::log($message, 'ERROR');
    }
    
    /**
     * Warning logging helper
     */
    public static function warning($message)
    {
        self::log($message, 'WARNING');
    }
    
    /**
     * Info logging helper
     */
    public static function info($message)
    {
        self::log($message, 'INFO');
    }
    
    /**
     * Debug logging helper
     */
    public static function debug($message)
    {
        self::log($message, 'DEBUG');
    }

    /**
     * Convert common truthy/falsy string/env values to boolean
     */
    private static function toBool($value, $default = false)
    {
        if ($value === null) return $default;
        if (is_bool($value)) return $value;
        $v = strtolower(trim((string)$value));
        if ($v === '') return $default;
        return in_array($v, ['1','true','yes','on','y','enabled'], true);
    }
}

// Backward compatibility: provide global alias if not already defined
if (!class_exists('WalletLogger')) {
    class_alias('Blockchain\\Wallet\\WalletLogger', 'WalletLogger');
}
// Note: prefer fully-qualified \\Blockchain\\Wallet\\WalletLogger going forward
