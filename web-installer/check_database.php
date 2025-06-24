<?php
// Disable any output buffering that might interfere
if (ob_get_level()) {
    ob_end_clean();
}

// Set headers first
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Turn off error reporting to prevent HTML error pages
error_reporting(0);
ini_set('display_errors', 0);

try {
    // Get database connection parameters from POST
    $rawInput = file_get_contents('php://input');
    
    // Debug: Check if we received data
    if (empty($rawInput)) {
        throw new Exception('No data received in request body');
    }
    
    $input = json_decode($rawInput, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON data: ' . json_last_error_msg());
    }
    
    if (!$input) {
        throw new Exception('No database configuration provided');
    }
    
    $host = $input['db_host'] ?? 'localhost';
    $port = $input['db_port'] ?? 3306;
    $username = $input['db_username'] ?? '';
    $password = $input['db_password'] ?? '';
    $database = $input['db_name'] ?? '';
    
    if (empty($username)) {
        throw new Exception('Database username is required');
    }
    
    if (empty($database)) {
        throw new Exception('Database name is required');
    }
    
    // Test database connection
    $dsn = "mysql:host=$host;port=$port;charset=utf8mb4";
    
    try {
        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5
        ]);
        
        // Check if database exists
        $stmt = $pdo->prepare("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?");
        $stmt->execute([$database]);
        $dbExists = $stmt->rowCount() > 0;
        
        if (!$dbExists) {
            // Try to create database
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $message = "Database created successfully";
        } else {
            $message = "Database connection successful";
        }
        
        // Test connection to the specific database
        $dsn = "mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4";
        $testPdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
        
        // Get MySQL version
        $version = $testPdo->query('SELECT VERSION()')->fetchColumn();
        
        echo json_encode([
            'status' => 'success',
            'message' => $message,
            'version' => $version,
            'database_exists' => $dbExists,
            'can_create_tables' => true
        ]);
        
    } catch (PDOException $e) {
        $errorCode = $e->getCode();
        $errorMessage = $e->getMessage();
        
        // Specific error handling
        if (strpos($errorMessage, 'Access denied') !== false) {
            throw new Exception('Access denied. Check username and password.');
        } elseif (strpos($errorMessage, 'Connection refused') !== false) {
            throw new Exception('Connection refused. Check if MySQL server is running.');
        } elseif (strpos($errorMessage, 'Unknown database') !== false) {
            throw new Exception('Database does not exist and cannot be created.');
        } elseif (strpos($errorMessage, "Can't connect to MySQL server") !== false) {
            throw new Exception('Cannot connect to MySQL server. Check host and port.');
        } else {
            throw new Exception("Database error: $errorMessage");
        }
    }
    
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
        'suggestion' => 'Please check your database credentials and ensure MySQL server is running.'
    ]);
} catch (Error $e) {
    // Handle fatal errors
    echo json_encode([
        'status' => 'error',
        'message' => 'Fatal error: ' . $e->getMessage(),
        'suggestion' => 'Please check your PHP configuration and MySQL extensions.'
    ]);
} catch (Throwable $e) {
    // Handle any other throwable
    echo json_encode([
        'status' => 'error',
        'message' => 'Unexpected error: ' . $e->getMessage(),
        'suggestion' => 'Please contact support.'
    ]);
}
?>
