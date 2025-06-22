<?php
declare(strict_types=1);

/**
 * Modern Blockchain Platform
 * 
 * Entry point for the Modern Blockchain Platform
 * Customizable blockchain with PoS consensus and smart contracts
 * 
 * @author Blockchain Platform Team
 * @version 2.0.0
 */

//  PHP version
if (version_compare(PHP_VERSION, '8.0.0', '<')) {
    die('PHP 8.0 or higher is required');
}

// Define constants
define('BLOCKCHAIN_VERSION', '2.0.0');
define('BLOCKCHAIN_ROOT', __DIR__);
define('BLOCKCHAIN_START_TIME', microtime(true));

// Load Composer autoloader
if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    // If Composer is not installed, redirect to installer
    if (file_exists(__DIR__ . '/web-installer/index.html')) {
        header('Location: web-installer/');
        exit;
    } else {
        die('Composer autoloader not found. Please run: composer install');
    }
}

require_once __DIR__ . '/vendor/autoload.php';

// Load environment variables
if (class_exists('\\Dotenv\\Dotenv')) {
    try {
        $dotenv = \Dotenv\Dotenv::createImmutable(__DIR__);
        $dotenv->load();
    } catch (Exception $e) {
        // .env file is not required in production
    }
}

// Load configuration
if (!file_exists(__DIR__ . '/config/config.php')) {
    // If no config, redirect to installer
    if (file_exists(__DIR__ . '/web-installer/index.html')) {
        header('Location: web-installer/');
        exit;
    } else {
        die('Configuration file not found. Please run the installer first.');
    }
}

$config = require __DIR__ . '/config/config.php';

try {
    // Main application initialization
    $app = new Blockchain\Core\Application($config);
    
    // Determine request type
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $method = $_SERVER['REQUEST_METHOD'];
    
    // Routing
    switch (true) {
        case $path === '/':
        case $path === '/dashboard':
            $app->renderDashboard();
            break;
            
        case $path === '/wallet':
            $app->renderWallet();
            break;
            
        case $path === '/explorer':
            $app->renderExplorer();
            break;
            
        case $path === '/admin':
            $app->renderAdmin();
            break;
            
        case strpos($path, '/api/') === 0:
            $app->handleApiRequest($path, $method);
            break;
            
        default:
            http_response_code(404);
            echo json_encode(['error' => 'Not Found']);
            break;
    }
    
} catch (Exception $e) {
    error_log("Blockchain Platform Error: " . $e->getMessage());
    
    if (isset($config['development']['debug']) && $config['development']['debug']) {
        echo "<h1>Error</h1>";
        echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
        echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Internal Server Error']);
    }
}
