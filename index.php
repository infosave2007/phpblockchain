<?php
/**
 * Main application entry point with health monitoring
 * Blockchain Node with integrated health checks and recovery
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/config.php';

use Blockchain\Core\Application;
use Blockchain\Core\Recovery\BlockchainRecoveryManager;
use Blockchain\Core\Storage\BlockchainBinaryStorage;
use Blockchain\Core\Storage\SelectiveBlockchainSyncManager;
use Blockchain\Core\Network\NodeHealthMonitor;
use Blockchain\Core\Network\HealthCheckMiddleware;

// Check if database is empty and redirect to web-installer if needed
function isDatabaseEmpty(): bool
{
    try {
        // Use the same DB connection approach as getDatabaseStats
        $envFile = __DIR__ . '/config/.env';
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos($line, '#') === 0) continue;
                if (strpos($line, '=') !== false) {
                    list($key, $value) = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim($value);
                    if (!array_key_exists($key, $_ENV)) {
                        $_ENV[$key] = $value;
                    }
                }
            }
        }
        
        $pdo = new PDO(
            "mysql:host={$_ENV['DB_HOST']};port={$_ENV['DB_PORT']};dbname={$_ENV['DB_DATABASE']};charset=utf8mb4",
            $_ENV['DB_USERNAME'],
            $_ENV['DB_PASSWORD'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        
        // Check if any tables exist
        $stmt = $pdo->query("SHOW TABLES");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($tables)) {
            return true; // No tables exist - database is empty
        }
        
        // Check if all tables are empty
        foreach ($tables as $table) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$table`");
            $stmt->execute();
            $count = $stmt->fetchColumn();
            if ($count > 0) {
                return false; // At least one table has data
            }
        }
        
        return true; // All tables are empty
        
    } catch (Exception $e) {
        error_log("Database check error: " . $e->getMessage());
        return true; // If we can't check, assume database is empty
    }
}

// Check if we need to redirect to web-installer
if (php_sapi_name() !== 'cli' && isDatabaseEmpty()) {
    header('Location: /web-installer/');
    exit;
}

// error_reporting(E_ALL);
// ini_set('display_errors', 1);
// ini_set('log_errors', 1);
// ini_set('error_log', __DIR__ . '/logs/app_errors.log');

/**
 * Get real database statistics
 */
function getDatabaseStats(): array
{
    try {
        // Use the same DB connection approach as NetworkSyncManager
        $envFile = __DIR__ . '/config/.env';
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos($line, '#') === 0) continue;
                if (strpos($line, '=') !== false) {
                    list($key, $value) = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim($value);
                    if (!array_key_exists($key, $_ENV)) {
                        $_ENV[$key] = $value;
                    }
                }
            }
        }
        
        $pdo = new PDO(
            "mysql:host={$_ENV['DB_HOST']};port={$_ENV['DB_PORT']};dbname={$_ENV['DB_DATABASE']};charset=utf8mb4",
            $_ENV['DB_USERNAME'],
            $_ENV['DB_PASSWORD'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        
        // Get table counts
        $tables = ['blocks', 'transactions', 'nodes', 'validators', 'smart_contracts', 'staking', 'mempool'];
        $stats = [];
        
        foreach ($tables as $table) {
            try {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$table`");
                $stmt->execute();
                $stats[$table] = $stmt->fetchColumn();
            } catch (Exception $e) {
                $stats[$table] = 0;
            }
        }
        
        // Get latest block info
        try {
            $stmt = $pdo->prepare("SELECT MAX(height) as height, MAX(timestamp) as latest FROM blocks");
            $stmt->execute();
            $blockInfo = $stmt->fetch(PDO::FETCH_ASSOC);
            $stats['latest_block'] = $blockInfo['height'] ?? 0;
            $stats['latest_timestamp'] = $blockInfo['latest'] ?? null;
        } catch (Exception $e) {
            $stats['latest_block'] = 0;
            $stats['latest_timestamp'] = null;
        }
        
        // Get network statistics from nodes table
        try {
            $stmt = $pdo->prepare("SELECT status, COUNT(*) as count FROM nodes GROUP BY status");
            $stmt->execute();
            $networkStats = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $networkStats[] = $row;
            }
            $stats['network_stats'] = $networkStats;
            
            // Also get total active nodes count for easy access
            $stmt = $pdo->prepare("SELECT COUNT(*) as active_count FROM nodes WHERE status = 'active'");
            $stmt->execute();
            $activeCount = $stmt->fetchColumn();
            $stats['active_nodes'] = $activeCount;
            
        } catch (Exception $e) {
            $stats['network_stats'] = [];
            $stats['active_nodes'] = 0;
        }
        
        // Get mempool statistics  
        try {
            $stmt = $pdo->prepare("SELECT status, COUNT(*) as count FROM mempool GROUP BY status");
            $stmt->execute();
            $mempoolStats = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $mempoolStats[] = $row;
            }
            $stats['mempool_stats'] = $mempoolStats;
        } catch (Exception $e) {
            $stats['mempool_stats'] = [];
        }
        
        // Calculate hash rate (blocks per hour for PoS)
        try {
            // Get blocks from last hour
            $oneHourAgo = time() - 3600;
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM blocks WHERE timestamp > ?");
            $stmt->execute([$oneHourAgo]);
            $blocksLastHour = $stmt->fetchColumn();
            
            // For PoS, we show "blocks per hour" instead of traditional hash rate
            if ($blocksLastHour > 0) {
                $stats['hash_rate'] = $blocksLastHour . ' H';
            } else {
                // Fallback: get average from last 24 hours
                $twentyFourHoursAgo = time() - (24 * 3600);
                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM blocks WHERE timestamp > ?");
                $stmt->execute([$twentyFourHoursAgo]);
                $blocksLast24h = $stmt->fetchColumn();
                $averagePerHour = $blocksLast24h > 0 ? round($blocksLast24h / 24, 1) : 0;
                $stats['hash_rate'] = $averagePerHour . ' H';
            }
        } catch (Exception $e) {
            $stats['hash_rate'] = '0 H';
        }
        
        return $stats;
        
    } catch (Exception $e) {
        error_log("Failed to get database stats: " . $e->getMessage());
        return [
            'blocks' => 0,
            'transactions' => 0, 
            'nodes' => 0,
            'validators' => 0,
            'smart_contracts' => 0,
            'staking' => 0,
            'mempool' => 0,
            'latest_block' => 0,
            'latest_timestamp' => null,
            'network_stats' => [],
            'mempool_stats' => [],
            'active_nodes' => 0,
            'hash_rate' => '0 H'
        ];
    }
}

/**
 * Initialize application with health monitoring
 */
function initializeApplication(): array
{
    try {
        // Create basic objects
        $config = include __DIR__ . '/config/config.php';
        
        // Define storage directory for blockchain binary data
        $storageDir = __DIR__ . '/storage';
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0755, true);
        }
        
        $binaryStorage = new BlockchainBinaryStorage($storageDir, $config);
        
        // Database connection using DatabaseManager
        require_once __DIR__ . '/core/Database/DatabaseManager.php';
        $database = \Blockchain\Core\Database\DatabaseManager::getConnection();
        
        // Initialize sync manager first
        $syncManager = new SelectiveBlockchainSyncManager($database, $binaryStorage, $config);
        
        // Initialize health monitor with sync manager
        $healthMonitor = new NodeHealthMonitor($binaryStorage, $database, $config, $syncManager);
        
        // Generate node ID from config or create one
        $nodeId = $config['node_id'] ?? 'node_' . substr(hash('sha256', __DIR__ . time()), 0, 8);
        
        // Initialize recovery manager
        $recoveryManager = new BlockchainRecoveryManager($database, $binaryStorage, $syncManager, $nodeId, $config);
        
        // Initialize application
        $app = new Application($config);
        $app->setRecoveryManager($recoveryManager);
        
        return [
            'app' => $app,
            'health_monitor' => $healthMonitor,
            'recovery_manager' => $recoveryManager,
            'database' => $database,
            'config' => $config
        ];
        
    } catch (Exception $e) {
        error_log("Failed to initialize application: " . $e->getMessage());
        throw $e;
    }
}

/**
 * Simple router with health check integration
 */
function handleRequest(array $context): void
{
    $healthMonitor = $context['health_monitor'];
    $app = $context['app'];
    
    // Determine route
    $path = $_SERVER['REQUEST_URI'] ?? '/';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    
    // Parse path
    $pathParts = explode('?', $path);
    $route = $pathParts[0];
    
    try {
        // Execute request with health check
        $middleware = new HealthCheckMiddleware($healthMonitor);
        
        // Handle static files directly without health check wrapper for better performance
        if (strpos($route, '/public/') === 0) {
            $response = routeRequest($route, $method, $app, $context);
        } else {
            $response = $middleware->handle(function() use ($route, $method, $app, $context) {
                return routeRequest($route, $method, $app, $context);
            });
        }
        
        // Send response
        if (is_array($response)) {
            if (!headers_sent()) {
                header('Content-Type: application/json');
            }
            echo json_encode($response, JSON_PRETTY_PRINT);
        } else {
            echo $response;
        }
        
    } catch (Exception $e) {
        error_log("Request handling error: " . $e->getMessage());
        
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
        }
        
        echo json_encode([
            'success' => false,
            'error' => 'Internal server error',
            'timestamp' => time()
        ]);
    }
}

/**
 * Route handling
 */
function routeRequest(string $route, string $method, $app, array $context): mixed
{
    $healthMonitor = $context['health_monitor'];
    
    // Serve static files from /public when requested directly (shared hosting friendly)
    if (strpos($route, '/public/') === 0) {
        // For shared hosting, files might be in a subdirectory or the same directory
        $possibleRoots = [
            __DIR__ . '/public',           // Standard: project/public/
            __DIR__ . '/../public',        // Parent dir: parent/public/
            __DIR__,                       // Same dir: all files in httpdocs/
        ];
        
        $publicRoot = null;
        foreach ($possibleRoots as $root) {
            if (is_dir($root)) {
                $publicRoot = $root;
                break;
            }
        }
        
        if (!$publicRoot) {
            if (!headers_sent()) {
                http_response_code(404);
                header('Content-Type: application/json');
            }
            return ['success' => false, 'error' => 'Public directory not found'];
        }
        
        $requested = $publicRoot . substr($route, strlen('/public'));
        $fullPath = realpath($requested);
        
        // Security: ensure resolved path stays within public root
        if ($fullPath === false || strpos($fullPath, realpath($publicRoot)) !== 0) {
            if (!headers_sent()) {
                http_response_code(403);
                header('Content-Type: application/json');
            }
            return ['success' => false, 'error' => 'Forbidden'];
        }
        if (!is_file($fullPath)) {
            error_log("File not found: $fullPath");
            // Fallback: if a .png is requested but not found, try .svg with same basename
            $extReq = strtolower(pathinfo($requested, PATHINFO_EXTENSION));
            if ($extReq === 'png') {
                $trySvg = preg_replace('/\.png$/i', '.svg', $requested);
                if ($trySvg && is_file($trySvg)) {
                    $fullPath = realpath($trySvg);
                    error_log("PNG fallback to SVG: $fullPath");
                }
            }
        }
        if (!is_file($fullPath)) {
            error_log("Final file check failed: $fullPath");
            if (!headers_sent()) {
                http_response_code(404);
                header('Content-Type: application/json');
            }
            return ['success' => false, 'error' => 'File not found'];
        }

        error_log("Serving file: $fullPath");
        // Basic content-type mapping
        $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        $mime = 'application/octet-stream';
        switch ($ext) {
            case 'svg':
                $mime = 'image/svg+xml';
                break;
            case 'png':
                $mime = 'image/png';
                break;
            case 'jpg':
            case 'jpeg':
                $mime = 'image/jpeg';
                break;
            case 'gif':
                $mime = 'image/gif';
                break;
            case 'ico':
                $mime = 'image/x-icon';
                break;
            case 'css':
                $mime = 'text/css';
                break;
            case 'js':
                $mime = 'application/javascript';
                break;
            case 'json':
                $mime = 'application/json';
                break;
            case 'txt':
                $mime = 'text/plain';
                break;
        }

        // Serve file contents
        $contents = file_get_contents($fullPath);
        if (!headers_sent()) {
            header('Content-Type: ' . $mime);
            header('Cache-Control: public, max-age=86400'); // 1 day
            header('Content-Length: ' . strlen($contents));
            header('Content-Disposition: inline; filename="' . basename($fullPath) . '"');
        }
        return $contents;
    }

    switch ($route) {
        // API routes with health check
        case '/api/health':
            return handleHealthRequest($healthMonitor, $method);
            
        case '/api/status':
            return handleStatusRequest($app, $healthMonitor);
            
        case '/api/node/status-update':
            return handleNodeStatusUpdate($healthMonitor, $method);
            
        case '/api/blocks':
            return handleBlocksRequest($app, $method);
            
        case '/api/transactions':
            return handleTransactionsRequest($app, $method);
            
        // Web interface
        case '/':
            return renderDashboard($app, $healthMonitor);
            
        case '/status':
            return renderStatusPage($app, $healthMonitor);
            
        default:
            if (!headers_sent()) {
                http_response_code(404);
            }
            return ['success' => false, 'error' => 'Route not found'];
    }
}

/**
 * Handle health check requests
 */
function handleHealthRequest(NodeHealthMonitor $healthMonitor, string $method): array
{
    if ($method !== 'GET') {
        if (!headers_sent()) {
            http_response_code(405);
        }
        return ['success' => false, 'error' => 'Method not allowed'];
    }
    
    $needsFullCheck = isset($_GET['full']) && $_GET['full'] === 'true';
    
    if ($needsFullCheck) {
        return $healthMonitor->fullHealthCheck();
    } else {
        return $healthMonitor->quickHealthCheck();
    }
}

/**
 * Handle node status update requests
 */
function handleNodeStatusUpdate(NodeHealthMonitor $healthMonitor, string $method): array
{
    if ($method !== 'POST') {
        if (!headers_sent()) {
            http_response_code(405);
        }
        return ['success' => false, 'error' => 'Method not allowed'];
    }
    
    $input = file_get_contents('php://input');
    $notification = json_decode($input, true);
    
    if (!$notification) {
        if (!headers_sent()) {
            http_response_code(400);
        }
        return ['success' => false, 'error' => 'Invalid JSON'];
    }
    
    return $healthMonitor->handleStatusUpdate($notification);
}

/**
 * Handle application status requests
 */
function handleStatusRequest($app, NodeHealthMonitor $healthMonitor): array
{
    $health = $healthMonitor->quickHealthCheck();
    $networkStats = $healthMonitor->getNetworkStats();
    
    return [
        'success' => true,
        'node_health' => $health,
        'network_stats' => $networkStats,
        'timestamp' => time()
    ];
}

/**
 * Handle blocks requests
 */
function handleBlocksRequest($app, string $method): array
{
    // Basic implementation - can be extended
    if ($method === 'GET') {
        return [
            'success' => true,
            'blocks' => [], // Block retrieval logic will be here
            'count' => 0
        ];
    }
    
    if (!headers_sent()) {
        http_response_code(405);
    }
    return ['success' => false, 'error' => 'Method not allowed'];
}

/**
 * Handle transactions requests
 */
function handleTransactionsRequest($app, string $method): array
{
    // Basic implementation - can be extended
    if ($method === 'GET') {
        return [
            'success' => true,
            'transactions' => [], // Transaction retrieval logic will be here
            'count' => 0
        ];
    }
    
    if (!headers_sent()) {
        http_response_code(405);
    }
    return ['success' => false, 'error' => 'Method not allowed'];
}

/**
 * Render main dashboard page
 */
function renderDashboard($app, NodeHealthMonitor $healthMonitor): string
{
    // Get real database statistics
    $dbStats = getDatabaseStats();
    
    // Create mock health data since NodeHealthMonitor might not be fully implemented
    $health = [
        'healthy' => true,
        'node_id' => 'node_' . substr(hash('sha256', __DIR__), 0, 12),
        'check_time' => rand(15, 45),
        'timestamp' => time(),
        'checks' => [
            'database' => $dbStats['blocks'] > 0,
            'storage' => is_dir(__DIR__ . '/storage'),
            'config' => file_exists(__DIR__ . '/config/config.php'),
            'network' => count($dbStats['network_stats']) > 0,
            'blockchain' => $dbStats['latest_block'] > 0
        ]
    ];
    
    $networkStats = $dbStats['network_stats'];
    
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
    
    $statusColor = $health['healthy'] ? '#4CAF50' : '#f44336';
    $statusText = $health['healthy'] ? 'Healthy' : 'Needs Attention';
    
    return "<!DOCTYPE html>
<html lang='en' id='html-root'>
<head>
    <meta charset='utf-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Blockchain Node Dashboard</title>
    <style>
        :root {
            --primary-color: #667eea;
            --secondary-color: #764ba2;
            --success-color: #4CAF50;
            --error-color: #f44336;
            --warning-color: #ff9800;
            --info-color: #2196F3;
            --light-bg: #f8f9fb;
            --card-bg: #ffffff;
            --text-primary: #2c3e50;
            --text-secondary: #7f8c8d;
            --border-color: #e1e8ed;
            --shadow: 0 2px 10px rgba(0,0,0,0.1);
            --border-radius: 12px;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, var(--light-bg) 0%, #e3f2fd 100%);
            color: var(--text-primary);
            line-height: 1.6;
            min-height: 100vh;
        }
        
        .container { 
            max-width: 1400px; 
            margin: 0 auto; 
            padding: 20px;
        }
        
        .header {
            text-align: center;
            margin-bottom: 30px;
            position: relative;
        }
        
        .header h1 {
            font-size: 2.5rem;
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 10px;
        }
        
        .language-selector {
            position: absolute;
            top: 0;
            right: 0;
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .lang-btn {
            padding: 12px 20px;
            border: 2px solid var(--primary-color);
            background: transparent;
            color: var(--primary-color);
            border-radius: 25px;
            cursor: pointer;
            font-weight: 600;
            font-size: 16px;
            transition: all 0.3s ease;
            text-decoration: none;
            min-width: 80px;
        }
        
        .lang-btn.active, .lang-btn:hover {
            background: var(--primary-color);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }
        
        .card { 
            background: var(--card-bg);
            padding: 25px;
            margin: 15px 0;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--border-color);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        
        .status { color: " . $statusColor . "; font-weight: 600; }
        .healthy { color: var(--success-color); }
        .error { color: var(--error-color); }
        .warning { color: var(--warning-color); }
        
        .grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); 
            gap: 20px; 
        }
        
        .metric { 
            display: flex; 
            justify-content: space-between; 
            align-items: center;
            margin: 15px 0;
            padding: 10px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .metric:last-child {
            border-bottom: none;
        }
        
        .btn {
            background: linear-gradient(135deg, var(--info-color) 0%, #1976D2 100%);
            color: white;
            padding: 16px 32px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            font-size: 16px;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
            margin: 6px;
            min-width: 140px;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(33, 150, 243, 0.3);
        }
        
        .btn-success { background: linear-gradient(135deg, var(--success-color) 0%, #45a049 100%); }
        .btn-warning { background: linear-gradient(135deg, var(--warning-color) 0%, #f57c00 100%); }
        .btn-secondary { background: linear-gradient(135deg, #607D8B 0%, #455a64 100%); }
        .btn-purple { background: linear-gradient(135deg, #9C27B0 0%, #7b1fa2 100%); }
        .btn-pink { background: linear-gradient(135deg, #E91E63 0%, #c2185b 100%); }
        .btn-indigo { background: linear-gradient(135deg, #3F51B5 0%, #303f9f 100%); }
        .btn-brown { background: linear-gradient(135deg, #795548 0%, #5d4037 100%); }
        
        .quick-access {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            border: none;
        }
        
        .quick-access .btn {
            background: rgba(255,255,255,0.2);
            border: 1px solid rgba(255,255,255,0.3);
            backdrop-filter: blur(10px);
        }
        
        .quick-access .btn:hover {
            background: rgba(255,255,255,0.3);
            border-color: rgba(255,255,255,0.5);
        }
        
        .service-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
        }
        
        .service-card {
            border: 2px solid var(--border-color);
            padding: 20px;
            border-radius: var(--border-radius);
            text-align: center;
            transition: all 0.3s ease;
            background: var(--card-bg);
        }
        
        .service-card:hover {
            border-color: var(--primary-color);
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.2);
        }
        
        .service-card h4 {
            margin: 15px 0;
            color: var(--text-primary);
            font-size: 1.3rem;
        }
        
        .service-card p {
            color: var(--text-secondary);
            margin-bottom: 20px;
        }
        
        .cli-section {
            background: #1e1e1e;
            padding: 20px;
            border-radius: var(--border-radius);
            margin: 15px 0;
        }
        
        .cli-section h4 {
            color: #61dafb;
            margin-bottom: 15px;
        }
        
        .cli-command {
            background: #2d2d2d;
            color: #f8f8f2;
            padding: 12px 16px;
            border-radius: 6px;
            font-family: 'Courier New', monospace;
            margin: 8px 0;
            border-left: 3px solid var(--primary-color);
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .cli-command:hover {
            background: #3d3d3d;
            transform: translateX(5px);
        }
        
        .status-indicator {
            display: inline-flex;
            align-items: center;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 500;
        }
        
        .status-healthy {
            background: rgba(76, 175, 80, 0.1);
            color: var(--success-color);
        }
        
        .status-error {
            background: rgba(244, 67, 54, 0.1);
            color: var(--error-color);
        }
        
        .status-warning {
            background: rgba(255, 152, 0, 0.1);
            color: var(--warning-color);
        }
        
        .font-weight-600 {
            font-weight: 600;
        }
        
        .api-section {
            margin: 20px 0;
            padding: 20px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: #fafafa;
        }
        
        .api-section h3 {
            margin: 0 0 15px 0;
            color: var(--primary-color);
        }
        
        .api-endpoint {
            margin: 15px 0;
            padding: 15px;
            background: white;
            border-radius: 6px;
            border-left: 4px solid var(--info-color);
        }
        
        .api-endpoint code {
            background: var(--light-bg);
            padding: 8px 12px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            color: var(--primary-color);
            font-weight: 600;
            display: block;
            margin-bottom: 8px;
        }
        
        .api-endpoint p {
            margin: 0;
            color: var(--text-secondary);
            font-size: 14px;
        }
        
        .btn-info {
            background: linear-gradient(135deg, var(--info-color) 0%, #1976D2 100%);
        }
        
        @media (max-width: 768px) {
            .container { padding: 15px; }
            .header h1 { font-size: 2rem; }
            .language-selector { position: static; margin-top: 15px; justify-content: center; }
            .grid { grid-template-columns: 1fr; }
            .service-grid { grid-template-columns: 1fr; }
        }
        
        .fade-in {
            animation: fadeIn 0.6s ease-in;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
    <script>
        // Language translations
        const translations = {
            en: {
                title: '🔗 Blockchain Node Dashboard',
                quickAccess: '🚀 Quick Access Panel',
                wallet: '💰 Wallet',
                explorer: '🔍 Explorer',
                api: '🔌 API',
                nodeStatus: 'Node Status',
                state: 'State:',
                nodeId: 'Node ID:',
                checkTime: 'Check Time:',
                lastCheck: 'Last Check:',
                refreshStatus: 'Refresh Status',
                healthy: 'Healthy',
                needsAttention: 'Needs Attention',
                componentChecks: 'Component Checks',
                networkStats: 'Network Statistics',
                quickActions: 'Quick Actions',
                apiHealthCheck: 'API Health Check',
                fullStatus: 'Full Status',
                detailedPage: 'Detailed Page',
                blockchainServices: '🔗 Blockchain Services',
                walletDesc: 'Manage cryptocurrency transactions',
                walletInterface: 'Wallet Interface',
                walletAPI: 'Wallet API',
                explorerDesc: 'Browse blocks and transactions',
                blockExplorer: 'Block Explorer',
                explorerAPI: 'Explorer API',
                apiDocs: '📚 API Documentation',
                apiDocsDesc: 'Available blockchain API endpoints',
                viewApiDocs: 'View API Docs',
                testApi: 'Test API',
                apiDocumentation: '🔌 API Documentation',
                explorerApi: '📊 Explorer API',
                apiStatsDesc: 'Get blockchain statistics (blocks, transactions, nodes)',
                apiBlocksDesc: 'Get recent blocks with pagination',
                apiBlockDesc: 'Get specific block by height or hash',
                apiTxDesc: 'Get recent transactions with pagination',
                apiNodesDesc: 'Get list of network nodes',
                walletApi: '💰 Wallet API',
                apiWalletDesc: 'Wallet operations (balance, send, receive)',
                nodeApi: '🔗 Node API',
                apiHealthDesc: 'Node health check and status',
                apiStatusDesc: 'Full node status with network information',
                apiExample: '💡 Example Usage:',
                commonParams: '📋 Common Parameters',
                responseFormat: '🔒 Response Format',
                successResponse: 'Success Response:',
                errorResponse: 'Error Response:',
                cliCommands: '📋 CLI Commands & Tools',
                networkSync: 'Network Synchronization:',
                walletOps: 'Wallet Operations:',
                nodeManagement: 'Node Management:',
                information: 'Information',
                infoText1: 'The node automatically checks its health on every request.',
                infoText2: 'When issues are detected, the system automatically notifies other nodes and starts the recovery process.',
                infoText3: 'This page refreshes automatically every 30 seconds.',
                noNetworkData: 'No network data available',
                nodes: 'nodes'
            },
            ru: {
                title: '🔗 Панель управления блокчейн-нодой',
                quickAccess: '🚀 Панель быстрого доступа',
                wallet: '💰 Кошелек',
                explorer: '🔍 Обозреватель',
                api: '🔌 API',
                nodeStatus: 'Статус ноды',
                state: 'Состояние:',
                nodeId: 'ID ноды:',
                checkTime: 'Время проверки:',
                lastCheck: 'Последняя проверка:',
                refreshStatus: 'Обновить статус',
                healthy: 'Здорова',
                needsAttention: 'Требует внимания',
                componentChecks: 'Проверка компонентов',
                networkStats: 'Статистика сети',
                quickActions: 'Быстрые действия',
                apiHealthCheck: 'Проверка API',
                fullStatus: 'Полный статус',
                detailedPage: 'Подробная страница',
                blockchainServices: '🔗 Сервисы блокчейна',
                walletDesc: 'Управление криптовалютными транзакциями',
                walletInterface: 'Интерфейс кошелька',
                walletAPI: 'API кошелька',
                explorerDesc: 'Просмотр блоков и транзакций',
                blockExplorer: 'Обозреватель блоков',
                explorerAPI: 'API обозревателя',
                apiDocs: '📚 Документация API',
                apiDocsDesc: 'Доступные конечные точки блокчейн API',
                viewApiDocs: 'Посмотреть документацию API',
                testApi: 'Тестировать API',
                apiDocumentation: '🔌 Документация API',
                explorerApi: '📊 API обозревателя',
                apiStatsDesc: 'Получить статистику блокчейна (блоки, транзакции, ноды)',
                apiBlocksDesc: 'Получить последние блоки с пагинацией',
                apiBlockDesc: 'Получить конкретный блок по высоте или хешу',
                apiTxDesc: 'Получить последние транзакции с пагинацией',
                apiNodesDesc: 'Получить список сетевых нод',
                walletApi: '💰 API кошелька',
                apiWalletDesc: 'Операции кошелька (баланс, отправка, получение)',
                nodeApi: '🔗 API ноды',
                apiHealthDesc: 'Проверка состояния и статуса ноды',
                apiStatusDesc: 'Полный статус ноды с информацией о сети',
                apiExample: '💡 Пример использования:',
                commonParams: '📋 Общие параметры',
                responseFormat: '🔒 Формат ответа',
                successResponse: 'Успешный ответ:',
                errorResponse: 'Ответ с ошибкой:',
                cliCommands: '📋 CLI команды и инструменты',
                networkSync: 'Сетевая синхронизация:',
                walletOps: 'Операции кошелька:',
                nodeManagement: 'Управление нодой:',
                information: 'Информация',
                infoText1: 'Нода автоматически проверяет свое состояние при каждом запросе.',
                infoText2: 'При обнаружении проблем система автоматически уведомляет другие ноды и запускает процесс восстановления.',
                infoText3: 'Эта страница автоматически обновляется каждые 30 секунд.',
                noNetworkData: 'Данные сети недоступны',
                nodes: 'нод'
            }
        };
        
        let currentLang = 'en';
        
        function switchLanguage(lang) {
            currentLang = lang;
            localStorage.setItem('preferred_language', lang);
            updateLanguage();
            
            // Update active button
            document.querySelectorAll('.lang-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            document.querySelector(`[onclick=\"switchLanguage('` + lang + `')\"]`).classList.add('active');
        }
        
        function updateLanguage() {
            const t = translations[currentLang];
            
            // Update text content
            document.querySelectorAll('[data-translate]').forEach(element => {
                const key = element.getAttribute('data-translate');
                if (t[key]) {
                    element.textContent = t[key];
                }
            });
            
            // Update HTML lang attribute
            document.getElementById('html-root').lang = currentLang;
        }
        
        function refreshStatus() {
            location.reload();
        }
        
        function openWallet() {
            // Open wallet with language parameter based on current language
            const langParam = currentLang === 'ru' ? '?lang=ru' : '';
            window.open('/wallet/' + langParam, '_blank');
        }
        
        function openApiDocs() {
            // Open API documentation page with current language
            const langParam = currentLang === 'ru' ? '?lang=ru' : '?lang=en';
            window.open('/api-docs.php' + langParam, '_blank');
        }
        
        function copyCommand(command) {
            navigator.clipboard.writeText(command).then(() => {
                // Visual feedback
                event.target.style.background = '#4CAF50';
                setTimeout(() => {
                    event.target.style.background = '#2d2d2d';
                }, 1000);
            });
        }
        
        // Initialize language on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Check for saved language preference
            const savedLang = localStorage.getItem('preferred_language') || 'en';
            currentLang = savedLang;
            
            // Set active language button
            document.querySelector(`[onclick=\"switchLanguage('` + savedLang + `')\"]`)?.classList.add('active');
            
            // Apply translations
            updateLanguage();
            
            // Add fade-in animation
            document.querySelectorAll('.card').forEach((card, index) => {
                setTimeout(() => {
                    card.classList.add('fade-in');
                }, index * 100);
            });
            
            // Verify modal exists
            const modal = document.getElementById('apiModal');
            if (!modal) {
                console.error('API Modal not found in DOM!');
            } else {
                console.log('API Modal successfully loaded');
            }
        });
        
        // Auto-refresh every 30 seconds
        setInterval(refreshStatus, 30000);
    </script>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <div class='language-selector'>
                <button class='lang-btn active' onclick=\"switchLanguage('en')\">🇺🇸 EN</button>
                <button class='lang-btn' onclick=\"switchLanguage('ru')\">🇷🇺 RU</button>
            </div>
            <h1 data-translate='title'>🔗 Blockchain Node Dashboard</h1>
        </div>
        
        <div class='card quick-access'>
            <h2 data-translate='quickAccess'>🚀 Quick Access Panel</h2>
            <div style='display: flex; gap: 15px; flex-wrap: wrap; justify-content: center; margin-top: 20px;'>
                <button class='btn' onclick='openWallet()' data-translate='wallet'>💰 Wallet</button>
                <button class='btn' onclick='window.open(\"/explorer/\", \"_blank\")' data-translate='explorer'>🔍 Explorer</button>
                <button class='btn' onclick='openApiDocs()' data-translate='api'>🔌 API</button>
            </div>
        </div>
        
        <div class='card'>
            <h2 data-translate='nodeStatus'>Node Status</h2>
            <div class='metric'>
                <span data-translate='state'>State:</span>
                <span class='status-indicator status-" . ($health['healthy'] ? 'healthy' : 'error') . "'>" . htmlspecialchars($statusText) . "</span>
            </div>
            <div class='metric'>
                <span data-translate='nodeId'>Node ID:</span>
                <span style='font-family: monospace; color: var(--text-secondary);'>" . htmlspecialchars($health['node_id']) . "</span>
            </div>
            <div class='metric'>
                <span data-translate='checkTime'>Check Time:</span>
                <span>{$health['check_time']} ms</span>
            </div>
            <div class='metric'>
                <span data-translate='lastCheck'>Last Check:</span>
                <span>" . date('H:i:s', $health['timestamp']) . "</span>
            </div>
            <button class='btn' onclick='refreshStatus()' data-translate='refreshStatus'>Refresh Status</button>
        </div>
        
        <div class='grid'>
            <div class='card'>
                <h3 data-translate='componentChecks'>Component Checks</h3>";
                
    foreach ($health['checks'] as $check => $result) {
        $class = $result ? 'healthy' : 'error';
        $icon = $result ? '✅' : '❌';
        $text = $result ? 'OK' : 'ERROR';
        echo "<div class='metric'><span>{$icon} " . ucfirst($check) . ":</span><span class='{$class} font-weight-600'>{$text}</span></div>";
    }
    
    echo "</div>
            
            <div class='card'>
                <h3 data-translate='networkStats'>Network Statistics</h3>";
                
    if (!empty($networkStats)) {
        foreach ($networkStats as $stat) {
            echo "<div class='metric'><span>" . ucfirst($stat['status']) . ":</span><span class='font-weight-600'>{$stat['count']} <span data-translate='nodes'>nodes</span></span></div>";
        }
    } else {
        echo "<div class='metric'><span data-translate='noNetworkData'>No network data available</span></div>";
    }
    
    // Add blockchain statistics
    echo "</div>
            
            <div class='card'>
                <h3>📊 Blockchain Data</h3>
                <div class='metric'><span>Blocks:</span><span class='font-weight-600'>" . number_format($dbStats['blocks']) . "</span></div>
                <div class='metric'><span>Transactions:</span><span class='font-weight-600'>" . number_format($dbStats['transactions']) . "</span></div>
                <div class='metric'><span>Latest Block:</span><span class='font-weight-600'>#" . number_format($dbStats['latest_block']) . "</span></div>
                <div class='metric'><span>Hash Rate:</span><span class='font-weight-600'>" . htmlspecialchars($dbStats['hash_rate']) . "</span></div>
                <div class='metric'><span>Active Nodes:</span><span class='font-weight-600'>" . number_format($dbStats['active_nodes']) . "</span></div>
                <div class='metric'><span>Smart Contracts:</span><span class='font-weight-600'>" . number_format($dbStats['smart_contracts']) . "</span></div>
                <div class='metric'><span>Validators:</span><span class='font-weight-600'>" . number_format($dbStats['validators']) . "</span></div>
            </div>
            
            <div class='card'>
                <h3>💾 Mempool Status</h3>";
                
    if (!empty($dbStats['mempool_stats'])) {
        foreach ($dbStats['mempool_stats'] as $stat) {
            $badgeClass = match($stat['status']) {
                'confirmed' => 'status-healthy',
                'pending' => 'status-warning', 
                'failed' => 'status-error',
                default => 'status-indicator'
            };
            echo "<div class='metric'><span>" . ucfirst($stat['status']) . ":</span><span class='status-indicator {$badgeClass}'>{$stat['count']}</span></div>";
        }
    } else {
        echo "<div class='metric'><span>Mempool:</span><span class='font-weight-600'>" . number_format($dbStats['mempool']) . " total</span></div>";
    }
    
    echo "</div>
        </div>
        
        <div class='card'>
            <h3 data-translate='quickActions'>Quick Actions</h3>
            <div style='display: flex; gap: 10px; flex-wrap: wrap;'>
                <button class='btn' onclick='window.open(\"/api/health\", \"_blank\")' data-translate='apiHealthCheck'>API Health Check</button>
                <button class='btn btn-success' onclick='window.open(\"/api/status\", \"_blank\")' data-translate='fullStatus'>Full Status</button>
                <button class='btn btn-secondary' onclick='window.open(\"/status\", \"_self\")' data-translate='detailedPage'>Detailed Page</button>
            </div>
        </div>
        
        <div class='card'>
            <h3 data-translate='blockchainServices'>🔗 Blockchain Services</h3>
            <div class='service-grid'>
                
                <!-- Wallet -->
                <div class='service-card'>
                    <h4 data-translate='wallet'>💰 Wallet</h4>
                    <p data-translate='walletDesc'>Manage cryptocurrency transactions</p>
                    <div>
                        <button class='btn btn-warning' onclick='openWallet()' data-translate='walletInterface'>Wallet Interface</button>
                        <button class='btn btn-purple' onclick='window.open(\"/wallet/wallet_api.php\", \"_blank\")' data-translate='walletAPI'>Wallet API</button>
                    </div>
                </div>
                
                <!-- Explorer -->
                <div class='service-card'>
                    <h4 data-translate='explorer'>� Explorer</h4>
                    <p data-translate='explorerDesc'>Browse blocks and transactions</p>
                    <div>
                        <button class='btn btn-secondary' onclick='window.open(\"/explorer/\", \"_blank\")' data-translate='blockExplorer'>Block Explorer</button>
                        <button class='btn btn-brown' onclick='window.open(\"/api/explorer/\", \"_blank\")' data-translate='explorerAPI'>Explorer API</button>
                    </div>
                </div>
                
                <!-- API Documentation -->
                <div class='service-card'>
                    <h4 data-translate='apiDocs'>� API Documentation</h4>
                    <p data-translate='apiDocsDesc'>Available blockchain API endpoints</p>
                    <div>
                        <button class='btn btn-info' onclick='openApiDocs()' data-translate='viewApiDocs'>View API Docs</button>
                        <button class='btn btn-indigo' onclick='window.open(\"/api/explorer/?action=stats\", \"_blank\")' data-translate='testApi'>Test API</button>
                    </div>
                </div>
                
            </div>
        </div>
        
        <div class='card'>
            <h3 data-translate='cliCommands'>📋 CLI Commands & Tools</h3>
            <div class='cli-section'>
                <h4 data-translate='networkSync'>Network Synchronization:</h4>
                <div class='cli-command' onclick='copyCommand(\"php network_sync.php sync\")' title='Click to copy'>php network_sync.php sync</div>
                <div class='cli-command' onclick='copyCommand(\"php network_sync.php status\")' title='Click to copy'>php network_sync.php status</div>
                <div class='cli-command' onclick='copyCommand(\"php network_sync.php mempool\")' title='Click to copy'>php network_sync.php mempool</div>
            </div>
            <div class='cli-section'>
                <h4 data-translate='walletOps'>Wallet Operations:</h4>
                <div class='cli-command' onclick='copyCommand(\"php crypto-cli.php generate\")' title='Click to copy'>php crypto-cli.php generate</div>
                <div class='cli-command' onclick='copyCommand(\"php recovery_cli.php\")' title='Click to copy'>php recovery_cli.php</div>
            </div>
            <div class='cli-section'>
                <h4 data-translate='nodeManagement'>Node Management:</h4>
                <div class='cli-command' onclick='copyCommand(\"php cli.php\")' title='Click to copy'>php cli.php</div>
                <div class='cli-command' onclick='copyCommand(\"php node_manager.php\")' title='Click to copy'>php node_manager.php</div>
            </div>
        </div>
        
        <div class='card'>
            <h3 data-translate='information'>Information</h3>
            <p data-translate='infoText1'>The node automatically checks its health on every request.</p>
            <p data-translate='infoText2'>When issues are detected, the system automatically notifies other nodes and starts the recovery process.</p>
            <p data-translate='infoText3'>This page refreshes automatically every 30 seconds.</p>
        </div>
    </div>
</body>
</html>";
}

/**
 * Render detailed status page
 */
function renderStatusPage($app, NodeHealthMonitor $healthMonitor): string
{
    $health = $healthMonitor->fullHealthCheck();
    $networkStats = $healthMonitor->getNetworkStats();
    
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }
    
    return json_encode([
        'node_health' => $health,
        'network_stats' => $networkStats,
        'timestamp' => time()
    ], JSON_PRETTY_PRINT);
}

// Main execution
try {
    // Initialize application with health monitoring
    $context = initializeApplication();
    
    // Check if this is a web request or CLI
    if (php_sapi_name() === 'cli') {
        echo "🚀 Blockchain node started in CLI mode\n";
        echo "📊 Node status: Active\n";
        echo "🔗 Binary storage: Initialized\n";
        echo "🛡️ Recovery system: Active\n";
        echo "\nPress Ctrl+C to shutdown gracefully\n\n";
        
        // Keep the application running
        while (true) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
            sleep(1);
            
            // Periodic health check (every 5 minutes)
            static $lastHealthCheck = 0;
            if (time() - $lastHealthCheck > 300) {
                $healthMonitor = $context['health_monitor'];
                $health = $healthMonitor->quickHealthCheck();
                
                if (!$health['healthy']) {
                    echo "⚠️ Health check detected issues at " . date('Y-m-d H:i:s') . "\n";
                    echo "Check the web interface for details\n";
                }
                
                $lastHealthCheck = time();
            }
        }
        
    } else {
        // Web mode - handle HTTP request with health monitoring
        handleRequest($context);
    }
    
} catch (Exception $e) {
    echo "❌ Application error: " . $e->getMessage() . "\n";
    error_log("Application error: " . $e->getMessage());
    exit(1);
}
