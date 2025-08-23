<?php
declare(strict_types=1);

namespace Blockchain\Wallet;

/**
 * Wallet Logger - unified logging system for the wallet API
 */

class WalletLogger
{
    private static $config = null;
    private static $enabled = true;
    private static $debugLevel = null; // null = not initialized, 0 = no logs, 1 = verbose logs
    
    /**
     * Get debug level from various sources
     */
    private static function getDebugLevel(): int
    {
        if (self::$debugLevel !== null) {
            return self::$debugLevel;
        }
        
        // Check for debug variable in various places
        $debugValue = null;
        
        // 1. Check environment variables
        $debugValue = $debugValue ?? getenv('DEBUG');
        $debugValue = $debugValue ?? getenv('WALLET_DEBUG');
        $debugValue = $debugValue ?? getenv('API_DEBUG');
        
        // 2. Check config array
        if (is_array(self::$config)) {
            $debugValue = $debugValue ?? self::$config['debug'];
            $debugValue = $debugValue ?? self::$config['debug_mode'];
            $debugValue = $debugValue ?? self::$config['wallet_debug'];
        }
        
        // 3. Check global variables
        $debugValue = $debugValue ?? ($GLOBALS['debug'] ?? null);
        
        // 4. Check $_GET parameter for testing
        $debugValue = $debugValue ?? ($_GET['debug'] ?? null);
        
        // Convert to integer (0 or 1)
        // Default to verbose logging (1) unless explicitly disabled (0)
        if ($debugValue === '0' || $debugValue === 'false' || $debugValue === 'off' || $debugValue === 'no') {
            self::$debugLevel = 0; // No logging - explicitly disabled
        } else {
            self::$debugLevel = 1; // Verbose logging - default behavior
        }
        
        return self::$debugLevel;
    }
    
    /**
     * Initialize logger with configuration
     */
    public static function init($config = null)
    {
        self::$config = $config;
        
        // Set enabled based on debug level
        $debugLevel = self::getDebugLevel();
        self::$enabled = ($debugLevel > 0);
        
        // Log system information only if debug=1
        if ($debugLevel > 0) {
            self::log("Debug logging enabled (level: {$debugLevel})", 'DEBUG');
            self::log("PHP Memory Limit: " . ini_get('memory_limit'), 'DEBUG');
            self::log("PHP Max Execution Time: " . ini_get('max_execution_time'), 'DEBUG');
            self::log("PHP Version: " . phpversion(), 'DEBUG');
        }
    }
    
    /**
     * Write log entry to logs/wallet_api.log based on debug level
     * debug=0: No logs at all
     * debug=1: All logs (verbose mode)
     */
    public static function log($message, $level = 'INFO')
    {
        $debugLevel = self::getDebugLevel();
        
        // If debug=0, don't log anything
        if ($debugLevel === 0) {
            return;
        }
        
        // If debug=1, log everything
        if ($debugLevel >= 1) {
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
