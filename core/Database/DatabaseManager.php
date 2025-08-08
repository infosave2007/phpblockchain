<?php
declare(strict_types=1);

namespace Blockchain\Core\Database;

use PDO;
use PDOException;
use Exception;
use Blockchain\Core\Environment\EnvironmentLoader;

/**
 * Universal Database Manager
 * Provides standardized database connections across the project
 */
class DatabaseManager
{
    private static ?PDO $connection = null;
    private static ?array $config = null;
    
    /**
     * Get database connection
     * @param array|null $customConfig Custom configuration to override defaults
     * @return PDO
     * @throws Exception
     */
    public static function getConnection(?array $customConfig = null): PDO
    {
        if (self::$connection === null || $customConfig !== null) {
            self::$connection = self::createConnection($customConfig);
        }
        
        return self::$connection;
    }
    
    /**
     * Create new database connection
     * @param array|null $customConfig Custom configuration
     * @return PDO
     * @throws Exception
     */
    public static function createConnection(?array $customConfig = null): PDO
    {
        $config = $customConfig ?? self::getConfig();
        $dbConfig = $config['database'] ?? $config;
        
        // Validate required fields
        if (empty($dbConfig['host']) || empty($dbConfig['username'])) {
            throw new Exception('Database configuration incomplete: host and username are required');
        }
        
        // Set defaults
        $dbConfig = array_merge([
            'port' => 3306,
            'charset' => 'utf8mb4',
            'timeout' => 10,
            'options' => []
        ], $dbConfig);
        
        // Build DSN
        $dsn = "mysql:host={$dbConfig['host']};port={$dbConfig['port']};charset={$dbConfig['charset']}";
        
        // Add database name if specified
        if (!empty($dbConfig['database'])) {
            $dsn .= ";dbname={$dbConfig['database']}";
        }
        
        try {
            // Ensure PDO extension is loaded
            if (!extension_loaded('pdo') || !extension_loaded('pdo_mysql')) {
                throw new Exception('PDO MySQL extension is not available');
            }
            
            // Standard PDO options - use minimal safe options
            $options = [];
            
            // Only set safe options that don't cause issues on shared hosting
            $options[20] = false; // PDO::ATTR_EMULATE_PREPARES => false
            $options[2] = $dbConfig['timeout']; // PDO::ATTR_TIMEOUT
            
            // Don't set ERRMODE here - let PDO use default and handle via try/catch
            // Don't set DEFAULT_FETCH_MODE - will use default
            
            $options = array_merge($options, $dbConfig['options']);
            
            $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'] ?? '', $options);
            
            // Set error mode after PDO object creation to avoid constant issues
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            
            // Enable autocommit by default to ensure simple operations commit immediately
            $pdo->setAttribute(PDO::ATTR_AUTOCOMMIT, 1);
            
            return $pdo;
        } catch (PDOException $e) {
            throw new Exception("Database connection failed: " . $e->getMessage());
        }
    }
    
    /**
     * Get database configuration from multiple sources
     * @return array
     */
    public static function getConfig(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }
        
        $baseDir = dirname(__DIR__, 2);
        
        // Try to load from config.php first
        $configFile = $baseDir . '/config/config.php';
        if (file_exists($configFile)) {
            $config = require $configFile;
            if (isset($config['database']) && !empty($config['database']['host'])) {
                self::$config = $config;
                return self::$config;
            }
        }
        
        // Fallback to environment variables - try .env file first
        try {
            EnvironmentLoader::load($baseDir);
            
            self::$config = [
                'database' => [
                    'host' => EnvironmentLoader::get('DB_HOST', 'localhost'),
                    'port' => (int) EnvironmentLoader::get('DB_PORT', 3306),
                    'database' => EnvironmentLoader::get('DB_DATABASE', EnvironmentLoader::get('DB_NAME', 'blockchain')),
                    'username' => EnvironmentLoader::get('DB_USERNAME', EnvironmentLoader::get('DB_USER', 'root')),
                    'password' => EnvironmentLoader::get('DB_PASSWORD', EnvironmentLoader::get('DB_PASS', '')),
                    'charset' => 'utf8mb4',
                    'timeout' => 10,
                    'options' => []
                ]
            ];
        } catch (Exception $e) {
            // If EnvironmentLoader fails, use absolute defaults
            self::$config = [
                'database' => [
                    'host' => 'localhost',
                    'port' => 3306,
                    'database' => 'blockchain',
                    'username' => 'root',
                    'password' => '',
                    'charset' => 'utf8mb4',
                    'timeout' => 10,
                    'options' => []
                ]
            ];
        }
        
        return self::$config;
    }
    
    /**
     * Set custom configuration
     * @param array $config
     */
    public static function setConfig(array $config): void
    {
        self::$config = $config;
        self::$connection = null; // Reset connection to use new config
    }
    
    /**
     * Reset connection (force reconnect on next getConnection call)
     */
    public static function resetConnection(): void
    {
        self::$connection = null;
    }
    
    /**
     * Check if database exists and create if needed
     * @param string $databaseName
     * @param array|null $config
     * @return bool
     * @throws Exception
     */
    public static function createDatabaseIfNotExists(string $databaseName, ?array $config = null): bool
    {
        // Connect without specifying database
        $tempConfig = $config ?? self::getConfig();
        $tempConfig['database']['database'] = ''; // Remove database from DSN
        
        $pdo = self::createConnection($tempConfig);
        
        try {
            // Check if database exists
            $stmt = $pdo->prepare("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?");
            $stmt->execute([$databaseName]);
            
            if ($stmt->rowCount() === 0) {
                // Create database
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$databaseName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                return true; // Database was created
            }
            
            return false; // Database already existed
        } catch (PDOException $e) {
            throw new Exception("Failed to create database: " . $e->getMessage());
        }
    }
    
    /**
     * Get database connection for installer with custom parameters
     * @param array $params Database parameters from installer
     * @return PDO
     * @throws Exception
     */
    public static function getInstallerConnection(array $params): PDO
    {
        $config = [
            'database' => [
                'host' => $params['db_host'] ?? $params['host'] ?? 'localhost',
                'port' => (int) ($params['db_port'] ?? $params['port'] ?? 3306),
                'database' => $params['db_name'] ?? $params['database'] ?? '',
                'username' => $params['db_username'] ?? $params['username'] ?? '',
                'password' => $params['db_password'] ?? $params['password'] ?? '',
                'charset' => 'utf8mb4',
                'timeout' => 10
            ]
        ];
        
        return self::createConnection($config);
    }
    
    /**
     * Test database connection
     * @param array|null $config
     * @return array Connection test result
     */
    public static function testConnection(?array $config = null): array
    {
        try {
            $pdo = self::createConnection($config);
            $version = $pdo->query('SELECT VERSION()')->fetchColumn();
            
            return [
                'success' => true,
                'message' => 'Database connection successful',
                'version' => $version
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }
}
