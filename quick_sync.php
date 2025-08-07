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
    echo "  sync           - Start full synchronization\n";
    echo "  enhanced-sync  - Enhanced sync with mempool processing and recovery\n";
    echo "  status         - Show current database status\n";
    echo "  check          - Quick network connectivity check\n";
    echo "  repair         - Repair and re-sync missing data\n";
    echo "  mempool-status - Show detailed mempool status\n";
    echo "  help           - Show this help message\n\n";
    echo "Options:\n";
    echo "  --verbose  - Show detailed output\n";
    echo "  --quiet    - Minimal output\n";
    echo "  --force    - Force sync even if up-to-date\n\n";
    echo "Examples:\n";
    echo "  php quick_sync.php sync --verbose\n";
    echo "  php quick_sync.php enhanced-sync\n";
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

function showMempoolStatus($syncManager, $verbose = false) {
    echo "🧹 Mempool Status\n";
    echo "=================\n\n";
    
    try {
        // Get pdo safely
        $reflection = new ReflectionClass($syncManager);
        if ($reflection->hasProperty('pdo')) {
            $pdoProperty = $reflection->getProperty('pdo');
            $pdoProperty->setAccessible(true);
            $pdo = $pdoProperty->getValue($syncManager);
            
            // Local mempool status
            $stmt = $pdo->prepare("
                SELECT status, COUNT(*) as count 
                FROM mempool 
                GROUP BY status
            ");
            $stmt->execute();
            $mempoolStats = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $mempoolStats[$row['status']] = $row['count'];
            }
            
            echo "📊 Local Mempool:\n";
            if (empty($mempoolStats)) {
                echo "  No transactions in mempool\n";
            } else {
                foreach ($mempoolStats as $status => $count) {
                    printf("  %-10s: %s transactions\n", ucfirst($status), number_format($count));
                }
            }
            
            // Network mempool comparison
            if ($verbose) {
                echo "\n🌐 Network Mempool Comparison:\n";
                
                // Hardcoded nodes for compatibility
                $nodes = [
                    'https://wallet.coursefactory.pro',
                    'https://node1.coursefactory.pro', 
                    'https://node2.globhouse.com'
                ];
                
                foreach ($nodes as $node) {
                    try {
                        $url = rtrim($node, '/') . '/api/explorer/index.php?action=get_mempool';
                        $response = $syncManager->makeApiCall($url);
                        
                        if ($response && isset($response['total'])) {
                            printf("  %-30s: %s pending\n", $node, $response['total']);
                        } else {
                            printf("  %-30s: Unable to fetch\n", $node);
                        }
                    } catch (Exception $e) {
                        printf("  %-30s: Error - %s\n", $node, $e->getMessage());
                    }
                }
            }
            
            // Recent mempool activity
            $stmt = $pdo->prepare("
                SELECT 
                    status,
                    COUNT(*) as count,
                    MIN(created_at) as earliest,
                    MAX(created_at) as latest
                FROM mempool 
                WHERE created_at > NOW() - INTERVAL 1 HOUR
                GROUP BY status
            ");
            $stmt->execute();
            $recentActivity = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($recentActivity)) {
                echo "\n📈 Recent Activity (Last Hour):\n";
                foreach ($recentActivity as $activity) {
                    printf("  %s: %s transactions (from %s to %s)\n", 
                        ucfirst($activity['status']), 
                        $activity['count'],
                        $activity['earliest'],
                        $activity['latest']
                    );
                }
            }
        }
        
    } catch (Exception $e) {
        echo "❌ Error retrieving mempool status: " . $e->getMessage() . "\n";
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
        echo "🚀 Starting Enhanced Blockchain Synchronization\n";
        echo "================================================\n\n";
        
        if ($force) {
            echo "⚠️  Force mode enabled - will re-sync all data\n\n";
        }
    }
    
    try {
        $startTime = microtime(true);
        
        // Step 1: Check current status before sync
        if ($verbose) {
            echo "📊 Pre-sync status check...\n";
            $preStatus = $syncManager->getStatus();
            echo "Current blocks: " . $preStatus['latest_block'] . "\n";
            echo "Current transactions: " . $preStatus['tables']['transactions'] . "\n";
        }
        
        // Step 2: Perform main synchronization
        if (!$quiet) echo "🔄 Performing blockchain synchronization...\n";
        $result = $syncManager->syncAll();
        
        // Step 3: Enhanced mempool processing and recovery
        if (!$quiet) echo "🧹 Processing mempool and pending transactions...\n";
        
        try {
            // Check for pending transactions in mempool - use reflection to access private methods safely
            $reflection = new ReflectionClass($syncManager);
            
            // Get pdo instance safely
            if ($reflection->hasProperty('pdo')) {
                $pdoProperty = $reflection->getProperty('pdo');
                $pdoProperty->setAccessible(true);
                $pdo = $pdoProperty->getValue($syncManager);
                
                $stmt = $pdo->query("SELECT COUNT(*) FROM mempool WHERE status = 'pending'");
                $pendingCount = $stmt->fetchColumn();
                
                if ($pendingCount > 0) {
                    if ($verbose) echo "Found {$pendingCount} pending transactions in mempool\n";
                    
                    // Try to mine pending transactions if this node should be mining
                    if ($reflection->hasMethod('shouldThisNodeMine')) {
                        $shouldMineMethod = $reflection->getMethod('shouldThisNodeMine');
                        $shouldMineMethod->setAccessible(true);
                        $shouldMine = $shouldMineMethod->invoke($syncManager);
                        
                        if ($shouldMine && $reflection->hasMethod('mineNewBlock')) {
                            if ($verbose) echo "This node is designated for mining, processing pending transactions...\n";
                            $mineMethod = $reflection->getMethod('mineNewBlock');
                            $mineMethod->setAccessible(true);
                            $miningResult = $mineMethod->invoke($syncManager, min($pendingCount, 100));
                            
                            if ($miningResult['success']) {
                                if (!$quiet) echo "✅ Mined block #{$miningResult['block_height']} with {$miningResult['transactions_count']} transactions\n";
                                
                                // Broadcast the new block - check if block data exists
                                if (isset($miningResult['block']) && $reflection->hasMethod('enhancedBlockBroadcast')) {
                                    $broadcastMethod = $reflection->getMethod('enhancedBlockBroadcast');
                                    $broadcastMethod->setAccessible(true);
                                    $broadcastMethod->invoke($syncManager, $miningResult['block']);
                                } else if ($verbose) {
                                    echo "Block data not available for broadcast\n";
                                }
                            }
                        } else if ($verbose) {
                            echo "This node is not designated for mining\n";
                        }
                    }
                    
                    // Clean up mempool
                    if ($reflection->hasMethod('cleanupMempool')) {
                        $cleanupMethod = $reflection->getMethod('cleanupMempool');
                        $cleanupMethod->setAccessible(true);
                        $cleanupMethod->invoke($syncManager);
                    }
                }
            }
        } catch (Exception $e) {
            if ($verbose) echo "Mempool processing warning: " . $e->getMessage() . "\n";
        }
        
        // Step 3.5: Fix pending transactions
        if (!$quiet) echo "🔧 Fixing pending transactions...\n";
        
        try {
            $fixResult = fixPendingTransactions($syncManager, $quiet, $verbose);
            if (isset($fixResult['fixed']) && $fixResult['fixed'] > 0) {
                if (!$quiet) echo "✅ Fixed {$fixResult['fixed']} pending transactions\n";
            }
        } catch (Exception $e) {
            if ($verbose) echo "Pending transactions fix warning: " . $e->getMessage() . "\n";
        }
        
        // Step 4: Data consistency check and recovery
        if (!$quiet) echo "🔍 Checking data consistency and performing recovery...\n";
        
        try {
            // Simplified recovery check using available methods
            $currentStatus = $syncManager->getStatus();
            
            if ($verbose) {
                printf("Current status: %d blocks, %d transactions\n", 
                    $currentStatus['latest_block'], 
                    $currentStatus['tables']['transactions'] ?? 0
                );
            }
            
            // Force one more sync to ensure consistency
            if ($force || $verbose) {
                if (!$quiet) echo "🔄 Performing final consistency sync...\n";
                $finalSync = $syncManager->syncAll();
                if (!$quiet) echo "✅ Final sync completed\n";
            }
            
        } catch (Exception $e) {
            if ($verbose) echo "Recovery check warning: " . $e->getMessage() . "\n";
        }
        
        $duration = microtime(true) - $startTime;
        
        // Final status check
        $postStatus = $syncManager->getStatus();
        
        if (!$quiet) {
            echo "\n✅ Enhanced Synchronization Completed Successfully!\n";
            echo "==================================================\n\n";
            printf("📊 Blocks synced: %s\n", number_format($result['blocks_synced']));
            printf("💰 Transactions synced: %s\n", number_format($result['transactions_synced']));
            printf("🌐 Source node: %s\n", $result['node']);
            printf("📈 Final block height: %s\n", $postStatus['latest_block']);
            printf("📈 Final transaction count: %s\n", number_format($postStatus['tables']['transactions']));
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
        if (!in_array($command, ['sync', 'enhanced-sync', 'status', 'check', 'mempool-status'], true)) {
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
            
        case 'enhanced-sync':
            // Enhanced sync is the same as regular sync now (enhanced version is default)
            performSync($syncManager, array_merge($options, ['--verbose']));
            break;
            
        case 'status':
            $verbose = in_array('--verbose', $options);
            showStatus($syncManager, $verbose);
            break;
            
        case 'mempool-status':
            $verbose = in_array('--verbose', $options);
            showMempoolStatus($syncManager, $verbose);
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

/**
 * Fix pending transactions that should be confirmed
 * Updates status from 'pending' to 'confirmed' for transactions that have block_hash but missing block_height
 */
function fixPendingTransactions($syncManager, $quiet = false, $verbose = false) {
    try {
        if (!$quiet) echo "🔧 Fixing pending transactions with missing block heights...\n";
        
        // Get PDO connection via reflection (safe access)
        $reflection = new ReflectionClass($syncManager);
        if (!$reflection->hasProperty('pdo')) {
            throw new Exception("PDO property not accessible in NetworkSyncManager");
        }
        
        $property = $reflection->getProperty('pdo');
        $property->setAccessible(true);
        $pdo = $property->getValue($syncManager);
        
        if (!$pdo) {
            throw new Exception("PDO connection not available");
        }
        
        // Find pending transactions that have block_hash but missing block_height
        $stmt = $pdo->prepare("
            SELECT t.*, b.height as actual_block_height, b.hash as actual_block_hash
            FROM transactions t
            LEFT JOIN blocks b ON t.block_hash = b.hash
            WHERE t.status = 'pending' 
            AND t.block_hash IS NOT NULL 
            AND t.block_hash != ''
            AND b.hash IS NOT NULL
        ");
        $stmt->execute();
        $pendingTxs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($pendingTxs)) {
            if (!$quiet) echo "✅ No pending transactions to fix\n";
            return ['fixed' => 0, 'message' => 'No pending transactions found'];
        }
        
        if ($verbose) {
            echo "Found " . count($pendingTxs) . " pending transactions to fix:\n";
            foreach ($pendingTxs as $tx) {
                echo "  - {$tx['hash']} -> block {$tx['actual_block_height']} ({$tx['actual_block_hash']})\n";
            }
        }
        
        $pdo->beginTransaction();
        $fixedCount = 0;
        
        // Update each pending transaction
        foreach ($pendingTxs as $tx) {
            $updateStmt = $pdo->prepare("
                UPDATE transactions 
                SET status = 'confirmed', 
                    block_height = ?
                WHERE hash = ? 
                AND status = 'pending'
            ");
            
            $result = $updateStmt->execute([
                $tx['actual_block_height'],
                $tx['hash']
            ]);
            
            if ($result && $updateStmt->rowCount() > 0) {
                $fixedCount++;
                if ($verbose) {
                    echo "  ✅ Fixed: {$tx['hash']} -> confirmed at height {$tx['actual_block_height']}\n";
                }
            }
        }
        
        $pdo->commit();
        
        if (!$quiet) {
            echo "✅ Fixed $fixedCount pending transactions\n";
        }
        
        return [
            'fixed' => $fixedCount,
            'total_found' => count($pendingTxs),
            'message' => "Successfully fixed $fixedCount pending transactions"
        ];
        
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        
        $error = "Failed to fix pending transactions: " . $e->getMessage();
        if (!$quiet) echo "❌ $error\n";
        error_log("[quick_sync] fixPendingTransactions error: " . $error);
        
        return [
            'fixed' => 0,
            'error' => $error
        ];
    }
}
?>
