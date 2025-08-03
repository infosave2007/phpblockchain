<?php
declare(strict_types=1);

/**
 * Common installation functions
 */

/**
 * Get PDO connection from configuration
 * This function provides backward compatibility for older installation code
 */
function getpdofromconfig(array $config = null): PDO {
    if ($config === null) {
        // Load configuration from install_config.json
        $configFile = __DIR__ . '/../config/install_config.json';
        if (!file_exists($configFile)) {
            throw new Exception('Installation configuration not found');
        }
        
        $config = json_decode(file_get_contents($configFile), true);
        if (!$config) {
            throw new Exception('Failed to parse installation configuration');
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
    
    // Create PDO connection
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

/**
 * Get database connection using DatabaseManager if available, fallback to installer connection
 */
function getInstallationDatabaseConnection(?array $config = null): PDO {
    // Try using DatabaseManager first
    if (file_exists(__DIR__ . '/../core/Database/DatabaseManager.php')) {
        try {
            require_once __DIR__ . '/../core/Database/DatabaseManager.php';
            
            if ($config) {
                return \Blockchain\Core\Database\DatabaseManager::getInstallerConnection($config);
            } else {
                return \Blockchain\Core\Database\DatabaseManager::getConnection();
            }
        } catch (Exception $e) {
            // Fallback to manual connection
        }
    }
    
    // Fallback to manual PDO connection
    return getpdofromconfig($config);
}

/**
 * Test database connection with configuration
 */
function testDatabaseConnection(array $config): array {
    try {
        $pdo = getpdofromconfig($config);
        $version = $pdo->query('SELECT VERSION()')->fetchColumn();
        
        return [
            'success' => true,
            'message' => 'Database connection successful',
            'version' => $version
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Database connection failed: ' . $e->getMessage(),
            'error' => $e->getMessage()
        ];
    }
}
