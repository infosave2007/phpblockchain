<?php
/**
 * Global compatibility functions for legacy code
 * This file provides backward compatibility for functions that may be called
 * from various parts of the application
 */

if (!function_exists('getpdofromconfig')) {
    /**
     * Get PDO connection from configuration
     * Compatibility function for legacy code
     */
    function getpdofromconfig(array $config = null): PDO {
        // Try to load configuration if not provided
        if ($config === null) {
            $configPaths = [
                __DIR__ . '/config/install_config.json',
                __DIR__ . '/config/config.json'
            ];
            
            foreach ($configPaths as $configPath) {
                if (file_exists($configPath)) {
                    $config = json_decode(file_get_contents($configPath), true);
                    if ($config) {
                        break;
                    }
                }
            }
            
            if (!$config) {
                throw new Exception('No configuration found for database connection');
            }
        }
        
        // Extract database settings from various config formats
        $host = $config['db_host'] ?? $config['database']['host'] ?? 'localhost';
        $port = $config['db_port'] ?? $config['database']['port'] ?? 3306;
        $username = $config['db_username'] ?? $config['database']['username'] ?? '';
        $password = $config['db_password'] ?? $config['database']['password'] ?? '';
        $database = $config['db_name'] ?? $config['database']['database'] ?? '';
        
        if (empty($username)) {
            throw new Exception('Database username is required');
        }
        
        if (empty($database)) {
            throw new Exception('Database name is required');
        }
        
        // Try to use DatabaseManager first if available
        if (class_exists('\\Blockchain\\Core\\Database\\DatabaseManager')) {
            try {
                return \Blockchain\Core\Database\DatabaseManager::getInstallerConnection([
                    'db_host' => $host,
                    'db_port' => $port,
                    'db_name' => $database,
                    'db_username' => $username,
                    'db_password' => $password
                ]);
            } catch (Exception $e) {
                // Fall back to manual connection
            }
        }
        
        // Create PDO connection manually
        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
        
        try {
            $pdo = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 10,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
            
            return $pdo;
        } catch (PDOException $e) {
            throw new Exception('Database connection failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('getPdoFromConfig')) {
    /**
     * Alternative naming for getpdofromconfig
     */
    function getPdoFromConfig(array $config = null): PDO {
        return getpdofromconfig($config);
    }
}
