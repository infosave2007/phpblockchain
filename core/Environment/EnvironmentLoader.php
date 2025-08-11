<?php
declare(strict_types=1);

namespace Blockchain\Core\Environment;

/**
 * Environment Variables Loader
 * Loads .env files from multiple possible locations
 */
class EnvironmentLoader
{
    private static ?array $variables = null;
    
    /**
     * Load environment variables from .env file
     */
    public static function load(?string $basePath = null): array
    {
        if (self::$variables !== null) {
            return self::$variables;
        }
        
        $basePath = $basePath ?? dirname(__DIR__, 2);
        
        // Priority order for .env file locations
        $envPaths = [
            $basePath . '/config/.env',  // New location
            $basePath . '/.env'          // Legacy location
        ];
        
        $envFile = null;
        foreach ($envPaths as $path) {
            if (file_exists($path)) {
                $envFile = $path;
                break;
            }
        }
        
        if (!$envFile) {
            self::$variables = [];
            return self::$variables;
        }
        
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $variables = [];
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            // Skip comments and empty lines
            if (empty($line) || $line[0] === '#') {
                continue;
            }
            
            // Parse KEY=VALUE format
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                
                // Remove quotes if present
                if (strlen($value) >= 2 && 
                    (($value[0] === '"' && $value[strlen($value)-1] === '"') || 
                     ($value[0] === "'" && $value[strlen($value)-1] === "'"))) {
                    $value = substr($value, 1, -1);
                }
                
                $variables[$key] = $value;
                
                // Also set as $_ENV variable
                $_ENV[$key] = $value;
            }
        }
        
        self::$variables = $variables;
        return $variables;
    }
    
    /**
     * Get environment variable with default value
     */
    public static function get(string $key, $default = null)
    {
        if (self::$variables === null) {
            self::load();
        }
        
        return self::$variables[$key] ?? $_ENV[$key] ?? $default;
    }
    
    /**
     * Check if environment variable exists
     */
    public static function has(string $key): bool
    {
        if (self::$variables === null) {
            self::load();
        }
        
        return isset(self::$variables[$key]) || isset($_ENV[$key]);
    }
    
    /**
     * Get all loaded variables
     */
    public static function all(): array
    {
        if (self::$variables === null) {
            self::load();
        }
        
        return self::$variables;
    }
    
    /**
     * Clear loaded variables (for testing)
     */
    public static function clear(): void
    {
        self::$variables = null;
    }
}
