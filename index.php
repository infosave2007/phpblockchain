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

// error_reporting(E_ALL);
// ini_set('display_errors', 1);
// ini_set('log_errors', 1);
// ini_set('error_log', __DIR__ . '/logs/app_errors.log');

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
        
        $response = $middleware->handle(function() use ($route, $method, $app, $context) {
            return routeRequest($route, $method, $app, $context);
        });
        
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
    $health = $healthMonitor->quickHealthCheck();
    $networkStats = $healthMonitor->getNetworkStats();
    
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
    
    $statusColor = $health['healthy'] ? '#4CAF50' : '#f44336';
    $statusText = $health['healthy'] ? 'Healthy' : 'Needs Attention';
    
    return "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='utf-8'>
    <title>Blockchain Node</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; }
        .card { background: white; padding: 20px; margin: 10px 0; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .status { color: {$statusColor}; font-weight: bold; }
        .healthy { color: #4CAF50; }
        .error { color: #f44336; }
        .warning { color: #ff9800; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; }
        .metric { display: flex; justify-content: space-between; margin: 10px 0; }
        .refresh { background: #2196F3; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; }
        .refresh:hover { background: #1976D2; }
    </style>
    <script>
        function refreshStatus() {
            location.reload();
        }
        
        // Auto-refresh every 30 seconds
        setInterval(refreshStatus, 30000);
    </script>
</head>
<body>
    <div class='container'>
        <h1>🔗 Blockchain Node Dashboard</h1>
        
        <div class='card'>
            <h2>Node Status</h2>
            <div class='metric'>
                <span>State:</span>
                <span class='status'>{$statusText}</span>
            </div>
            <div class='metric'>
                <span>Node ID:</span>
                <span>{$health['node_id']}</span>
            </div>
            <div class='metric'>
                <span>Check Time:</span>
                <span>{$health['check_time']} ms</span>
            </div>
            <div class='metric'>
                <span>Last Check:</span>
                <span>" . date('H:i:s', $health['timestamp']) . "</span>
            </div>
            <button class='refresh' onclick='refreshStatus()'>Refresh Status</button>
        </div>
        
        <div class='grid'>
            <div class='card'>
                <h3>Component Checks</h3>";
                
    foreach ($health['checks'] as $check => $result) {
        $class = $result ? 'healthy' : 'error';
        $icon = $result ? '✅' : '❌';
        $text = $result ? 'OK' : 'ERROR';
        echo "<div class='metric'><span>{$icon} " . ucfirst($check) . ":</span><span class='{$class}'>{$text}</span></div>";
    }
    
    echo "</div>
            
            <div class='card'>
                <h3>Network Statistics</h3>";
                
    if (!empty($networkStats)) {
        foreach ($networkStats as $stat) {
            echo "<div class='metric'><span>" . ucfirst($stat['status']) . ":</span><span>{$stat['count']} nodes</span></div>";
        }
    } else {
        echo "<div class='metric'><span>No network data available</span></div>";
    }
    
    echo "</div>
        </div>
        
        <div class='card'>
            <h3>Quick Actions</h3>
            <div style='display: flex; gap: 10px; flex-wrap: wrap;'>
                <button class='refresh' onclick=\"window.open('/api/health', '_blank')\">API Health Check</button>
                <button class='refresh' onclick=\"window.open('/api/status', '_blank')\">Full Status</button>
                <button class='refresh' onclick=\"window.open('/status', '_self')\">Detailed Page</button>
            </div>
        </div>
        
        <div class='card'>
            <h3>Information</h3>
            <p>The node automatically checks its health on every request.</p>
            <p>When issues are detected, the system automatically notifies other nodes and starts the recovery process.</p>
            <p>This page refreshes automatically every 30 seconds.</p>
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
