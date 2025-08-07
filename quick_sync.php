#!/usr/bin/env php
<?php
/**
 * Quick Sync Script
 * Simple script for emergency blockchain synchronization
 */

require_once __DIR__ . '/network_sync.php';

function showUsage() {
    echo "Blockchain Quick Sync Tool\n";
    echo "==========================\n\n";
    echo "Usage: php quick_sync.php [command] [options]\n\n";
    echo "Commands:\n";
    echo "  sync     - Start full synchronization\n";
    echo "  status   - Show current database status\n";
    echo "  check    - Quick network connectivity check\n";
    echo "  repair   - Repair and re-sync missing data\n";
    echo "  help     - Show this help message\n\n";
    echo "Options:\n";
    echo "  --verbose  - Show detailed output\n";
    echo "  --quiet    - Minimal output\n";
    echo "  --force    - Force sync even if up-to-date\n\n";
    echo "Examples:\n";
    echo "  php quick_sync.php sync --verbose\n";
    echo "  php quick_sync.php status\n";
    echo "  php quick_sync.php repair --force\n\n";
}

function formatBytes($bytes, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    
    for ($i = 0; $bytes > 1024; $i++) {
        $bytes /= 1024;
    }
    
    return round($bytes, $precision) . ' ' . $units[$i];
}

function showStatus($syncManager, $verbose = false) {
    echo "📊 Blockchain Database Status\n";
    echo "============================\n\n";
    
    $status = $syncManager->getStatus();
    
    // Show table statistics
    $totalRecords = 0;
    foreach ($status['tables'] as $table => $count) {
        $totalRecords += $count;
        printf("%-20s: %s records\n", 
            ucfirst(str_replace('_', ' ', $table)), 
            number_format($count)
        );
    }
    
    echo "\n";
    printf("Total records: %s\n", number_format($totalRecords));
    printf("Latest block: #%s\n", $status['latest_block']);
    printf("Latest timestamp: %s\n", $status['latest_timestamp'] ?? 'Unknown');
    
    if ($verbose) {
        echo "\n📈 Additional Information\n";
        echo "========================\n";
        
        // Memory usage
        printf("Memory usage: %s\n", formatBytes(memory_get_usage(true)));
        printf("Peak memory: %s\n", formatBytes(memory_get_peak_usage(true)));
        
        // Log file size
        $logFile = 'logs/network_sync.log';
        if (file_exists($logFile)) {
            printf("Log file size: %s\n", formatBytes(filesize($logFile)));
        }
    }
}

function checkConnectivity($syncManager) {
    echo "🌐 Network Connectivity Check\n";
    echo "=============================\n\n";
    
    try {
        // This is a simplified check - we'll use the selectBestNode method
        $reflection = new ReflectionClass($syncManager);
        $method = $reflection->getMethod('selectBestNode');
        $method->setAccessible(true);
        
        $bestNode = $method->invoke($syncManager);
        echo "✅ Successfully connected to: $bestNode\n";
        echo "🔗 Network is accessible and responsive\n";
        
    } catch (Exception $e) {
        echo "❌ Network check failed: " . $e->getMessage() . "\n";
        echo "🔧 Try checking your internet connection or network configuration\n";
        return false;
    }
    
    return true;
}

function performSync($syncManager, $options = []) {
    $verbose = in_array('--verbose', $options);
    $quiet = in_array('--quiet', $options);
    $force = in_array('--force', $options);
    
    if (!$quiet) {
        echo "🚀 Starting Blockchain Synchronization\n";
        echo "======================================\n\n";
        
        if ($force) {
            echo "⚠️  Force mode enabled - will re-sync all data\n\n";
        }
    }
    
    try {
        $startTime = microtime(true);
        $result = $syncManager->syncAll();
        $duration = microtime(true) - $startTime;
        
        if (!$quiet) {
            echo "\n✅ Synchronization Completed Successfully!\n";
            echo "==========================================\n\n";
            printf("📊 Blocks synced: %s\n", number_format($result['blocks_synced']));
            printf("💰 Transactions synced: %s\n", number_format($result['transactions_synced']));
            printf("🌐 Source node: %s\n", $result['node']);
            printf("⏱️  Duration: %.2f seconds\n", $duration);
            printf("📅 Completed at: %s\n", $result['completion_time']);
        }
        
        return true;
        
    } catch (Exception $e) {
        echo "\n❌ Synchronization Failed!\n";
        echo "==========================\n";
        echo "Error: " . $e->getMessage() . "\n";
        
        if ($verbose) {
            echo "\nStack trace:\n";
            echo $e->getTraceAsString() . "\n";
        }
        
        echo "\n🔧 Troubleshooting tips:\n";
        echo "- Check your internet connection\n";
        echo "- Verify database credentials\n";
        echo "- Check if network nodes are accessible\n";
        echo "- Try running: php quick_sync.php check\n";
        
        return false;
    }
}

function performRepair($syncManager, $options = []) {
    echo "🔧 Blockchain Data Repair\n";
    echo "=========================\n\n";
    
    $verbose = in_array('--verbose', $options);
    
    echo "🔍 Checking for missing or corrupted data...\n";
    
    try {
        // Force a complete re-sync
        echo "🔄 Performing complete data re-synchronization...\n";
        $result = performSync($syncManager, array_merge($options, ['--force']));
        
        if ($result) {
            echo "\n✅ Repair completed successfully!\n";
        } else {
            echo "\n❌ Repair failed - manual intervention may be required\n";
        }
        
    } catch (Exception $e) {
        echo "\n❌ Repair failed: " . $e->getMessage() . "\n";
    }
}

// Main execution
// Enable detailed error output when running via web (for debugging 500) and via CLI if requested
// Note: Keep verbose output minimal in production; disable after debugging.
if (php_sapi_name() !== 'cli') {
    // Force JSON for HTTP responses
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    // For CLI: allow enabling verbose errors by env var QUICK_SYNC_DEBUG=1
    if (getenv('QUICK_SYNC_DEBUG') === '1') {
        ini_set('display_errors', '1');
        ini_set('display_startup_errors', '1');
        error_reporting(E_ALL);
    }
}

// Detect execution mode and parse input
if (php_sapi_name() === 'cli') {
    $command = $argv[1] ?? 'help';
    $options = array_slice($argv, 2);
} else {
    // HTTP mode: support ?cmd=...&token=... and JSON body
    $input = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = file_get_contents('php://input');
        if ($raw) {
            $json = json_decode($raw, true);
            if (is_array($json)) {
                $input = $json;
            }
        }
    }
    $cmdParam = $_GET['cmd'] ?? $_GET['command'] ?? ($input['cmd'] ?? $input['command'] ?? 'help');
    $command = $cmdParam ?: 'help';
    // Build options array from flags if any (not strictly needed in HTTP)
    $options = [];
}

try {
    // Basic environment diagnostics to help trace 500 errors
    if (getenv('QUICK_SYNC_DEBUG') === '1' && php_sapi_name() === 'cli') {
        echo "[diag] PHP version: " . PHP_VERSION . "\n";
        echo "[diag] SAPI: " . php_sapi_name() . "\n";
        echo "[diag] Working dir: " . getcwd() . "\n";
        echo "[diag] Script: " . __FILE__ . "\n";
    }

    $syncManager = new NetworkSyncManager(false);

    // HTTP auth helper: verify admin users.api_key
    $httpAuthCheck = function (): array {
        // return [ok(bool), message(string)]
        $token = $_GET['token'] ?? null;
        if (!$token && isset($_SERVER['HTTP_AUTHORIZATION'])) {
            if (preg_match('/Bearer\\s+(.*)$/i', $_SERVER['HTTP_AUTHORIZATION'], $m)) {
                $token = trim($m[1]);
            }
        }
        if (!$token) {
            return [false, 'Missing token'];
        }

        // minimal DB bootstrap using .env (same approach as network_sync.php and sync-service)
        $envPath = __DIR__ . '/config/.env';
        $env = [];
        if (file_exists($envPath)) {
            $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if ($line === '' || $line[0] === '#') continue;
                if (strpos($line, '=') !== false) {
                    list($k, $v) = explode('=', $line, 2);
                    $env[trim($k)] = trim($v);
                }
            }
        }
        $dbHost = $env['DB_HOST'] ?? ($_ENV['DB_HOST'] ?? 'localhost');
        $dbPort = $env['DB_PORT'] ?? ($_ENV['DB_PORT'] ?? '3306');
        $dbName = $env['DB_DATABASE'] ?? ($_ENV['DB_DATABASE'] ?? '');
        $dbUser = $env['DB_USERNAME'] ?? ($_ENV['DB_USERNAME'] ?? '');
        $dbPass = $env['DB_PASSWORD'] ?? ($_ENV['DB_PASSWORD'] ?? '');
        $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
        try {
            $pdo = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (Throwable $e) {
            return [false, 'DB connect failed'];
        }

        try {
            $stmt = $pdo->query("SELECT api_key FROM users WHERE role='admin' ORDER BY id ASC LIMIT 1");
            $row = $stmt->fetch();
            if (!$row || empty($row['api_key'])) {
                return [false, 'Admin API key not found'];
            }
            if (!hash_equals($row['api_key'], $token)) {
                return [false, 'Invalid token'];
            }
        } catch (Throwable $e) {
            return [false, 'DB query failed'];
        }

        return [true, 'OK'];
    };

    // HTTP mode handling
    if (php_sapi_name() !== 'cli') {
        // Only allow selected commands via HTTP
        if (!in_array($command, ['sync', 'status', 'check'], true)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Route not found', 'cmd' => $command], JSON_UNESCAPED_UNICODE);
            exit;
        }
        // Auth check
        [$ok, $msg] = $httpAuthCheck();
        if (!$ok) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Unauthorized: ' . $msg], JSON_UNESCAPED_UNICODE);
            exit;
        }
        // Concurrency guard
        $lockFile = '/tmp/phpbc_sync.lock';
        $fp = fopen($lockFile, 'c');
        if ($fp === false) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Cannot open lock file'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $locked = flock($fp, LOCK_EX | LOCK_NB);
        if (!$locked) {
            http_response_code(429);
            echo json_encode(['success' => false, 'error' => 'Sync already running'], JSON_UNESCAPED_UNICODE);
            fclose($fp);
            exit;
        }

        // Execute command and respond JSON
        if ($command === 'sync') {
            $ok = performSync($syncManager, $options);
            $resp = ['success' => (bool)$ok, 'command' => 'sync'];
            if (!$ok) {
                http_response_code(500);
            }
            echo json_encode($resp, JSON_UNESCAPED_UNICODE);
            flock($fp, LOCK_UN);
            fclose($fp);
            exit;
        } elseif ($command === 'status') {
            // Collect status
            $status = $syncManager->getStatus();
            echo json_encode(['success' => true, 'command' => 'status', 'status' => $status], JSON_UNESCAPED_UNICODE);
            flock($fp, LOCK_UN);
            fclose($fp);
            exit;
        } elseif ($command === 'check') {
            $ok = checkConnectivity($syncManager);
            echo json_encode(['success' => (bool)$ok, 'command' => 'check'], JSON_UNESCAPED_UNICODE);
            flock($fp, LOCK_UN);
            fclose($fp);
            exit;
        }
    }
    
    switch ($command) {
        case 'sync':
            performSync($syncManager, $options);
            break;
            
        case 'status':
            $verbose = in_array('--verbose', $options);
            showStatus($syncManager, $verbose);
            break;
            
        case 'check':
            checkConnectivity($syncManager);
            break;
            
        case 'repair':
            performRepair($syncManager, $options);
            break;

        case 'auto-start':
            // Автозапуск синхронизации при наличии флага от инсталлятора
            $flag = __DIR__ . '/storage/sync_autostart.flag';
            if (file_exists($flag)) {
                echo "Auto-start flag found. Starting synchronization...\n";
                @unlink($flag);
                performSync($syncManager, $options);
            } else {
                echo "No auto-start flag present. Nothing to do.\n";
            }
            break;
            
        case 'help':
        case '--help':
        case '-h':
        default:
            showUsage();
            break;
    }

    if (php_sapi_name() !== 'cli') {
        // If HTTP reached here without exiting, send generic response
        echo json_encode(['success' => true, 'message' => 'OK'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
} catch (Throwable $e) {
    // Catch Throwable to include TypeError/FatalError on PHP 7+
    $msg = "💥 Fatal Error: " . $e->getMessage() . "\n";
    $msg .= "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    if (getenv('QUICK_SYNC_DEBUG') === '1') {
        $msg .= "\nStack trace:\n" . $e->getTraceAsString() . "\n";
    }
    echo $msg;

    echo "\nThis usually indicates a configuration or database issue.\n";
    echo "Please check:\n";
    echo "- Database connection settings\n";
    echo "- File permissions\n";
    echo "- PHP requirements\n";

    // Also log to PHP error_log for web runs
    error_log("[quick_sync] " . str_replace("\n", " | ", $msg));

    exit(1);
}
?>
