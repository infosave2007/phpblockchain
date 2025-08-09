#!/usr/bin/env php
<?php
declare(strict_types=1);
namespace Blockchain\SyncService;
/**
 * Blockchain Sync Service - Console Version
 * Quick command-line tool for blockchain synchronization
 */

require_once __DIR__ . '/SyncManager.php';

// Console colors (local utility class, stays un-namespaced within current namespace)
class Console {
    const COLOR_GREEN = "\033[32m";
    const COLOR_RED = "\033[31m";
    const COLOR_YELLOW = "\033[33m";
    const COLOR_BLUE = "\033[34m";
    const COLOR_CYAN = "\033[36m";
    const COLOR_RESET = "\033[0m";
    const COLOR_BOLD = "\033[1m";
    
    public static function log($message, $color = self::COLOR_RESET) {
        echo $color . $message . self::COLOR_RESET . "\n";
    }
    
    public static function success($message) {
        self::log("✅ " . $message, self::COLOR_GREEN);
    }
    
    public static function error($message) {
        self::log("❌ " . $message, self::COLOR_RED);
    }
    
    public static function warning($message) {
        self::log("⚠️  " . $message, self::COLOR_YELLOW);
    }
    
    public static function info($message) {
        self::log("ℹ️  " . $message, self::COLOR_BLUE);
    }
    
    public static function header($message) {
        echo "\n" . self::COLOR_BOLD . self::COLOR_CYAN . "🔗 " . $message . self::COLOR_RESET . "\n";
        echo str_repeat("=", strlen($message) + 3) . "\n\n";
    }
}

function showHelp() {
    Console::header("Blockchain Sync Service - Console Tool");
    echo "Usage: php console.php [command]\n\n";
    echo "Commands:\n";
    echo "  sync     - Start full blockchain synchronization\n";
    echo "  status   - Show current database status\n";
    echo "  logs     - Show recent sync logs\n";
    echo "  help     - Show this help message\n\n";
    echo "Examples:\n";
    echo "  php console.php sync\n";
    echo "  php console.php status\n\n";
}

function formatNumber($number) {
    return number_format($number);
}

function formatBytes($size) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $unit = 0;
    while ($size >= 1024 && $unit < count($units) - 1) {
        $size /= 1024;
        $unit++;
    }
    return round($size, 2) . ' ' . $units[$unit];
}

// Parse command line arguments
$command = $argv[1] ?? 'help';

try {
    Console::header("Blockchain Synchronization Service");
    
    switch ($command) {
        case 'sync':
            Console::info("Initializing synchronization manager...");
            $syncManager = new SyncManager(false); // Console mode
            
            Console::info("Starting blockchain synchronization...");
            $startTime = microtime(true);
            
            $result = $syncManager->syncAll();
            
            $duration = round(microtime(true) - $startTime, 2);
            
            Console::success("Synchronization completed successfully!");
            echo "\n📊 Sync Results:\n";
            echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            echo "🌐 Node: " . $result['node'] . "\n";
            echo "🧱 New blocks: " . formatNumber($result['new_blocks']) . "\n";
            echo "💳 New transactions: " . formatNumber($result['new_transactions']) . "\n";
            echo "🖥️  New nodes: " . formatNumber($result['new_nodes']) . "\n";
            echo "📄 New contracts: " . formatNumber($result['new_contracts']) . "\n";
            echo "🔒 New staking records: " . formatNumber($result['new_staking']) . "\n";
            echo "📈 Total new records: " . formatNumber($result['total_new_records']) . "\n";
            echo "⏱️  Sync time: " . $duration . " seconds\n";
            echo "🕐 Completed at: " . $result['completion_time'] . "\n";
            echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            break;
            
        case 'status':
            Console::info("Checking database status...");
            $syncManager = new SyncManager(false);
            $status = $syncManager->getStatus();
            
            Console::info("Current database status:");
            echo "\n📊 Database Statistics:\n";
            echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            
            foreach ($status['tables'] as $table => $count) {
                $icon = match($table) {
                    'blocks' => '🧱',
                    'transactions' => '💳',
                    'nodes' => '🖥️',
                    'validators' => '✅',
                    'smart_contracts' => '📄',
                    'staking' => '🔒',
                    default => '📋'
                };
                
                echo sprintf("%-20s %s %s\n", 
                    $icon . ' ' . ucfirst(str_replace('_', ' ', $table)) . ':', 
                    formatNumber($count),
                    $count > 0 ? Console::COLOR_GREEN . '●' . Console::COLOR_RESET : Console::COLOR_RED . '●' . Console::COLOR_RESET
                );
            }
            
            echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            echo "🕐 Last check: " . $status['sync_time'] . "\n";
            echo "🧱 Latest block: #" . formatNumber($status['latest_block']) . "\n";
            echo "📅 Latest timestamp: " . $status['latest_timestamp'] . "\n";
            break;
            
        case 'logs':
            Console::info("Fetching recent logs...");
            $logFile = '../logs/sync_service.log';
            
            if (!file_exists($logFile)) {
                Console::warning("No log file found at: $logFile");
                break;
            }
            
            $lines = file($logFile);
            $recentLines = array_slice($lines, -30); // Last 30 lines
            
            echo "\n📋 Recent Sync Logs (last 30 entries):\n";
            echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            
            foreach ($recentLines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                
                // Color-code log entries
                if (strpos($line, 'ERROR') !== false || strpos($line, 'failed') !== false) {
                    Console::log($line, Console::COLOR_RED);
                } elseif (strpos($line, 'WARNING') !== false || strpos($line, 'warn') !== false) {
                    Console::log($line, Console::COLOR_YELLOW);
                } elseif (strpos($line, 'success') !== false || strpos($line, 'completed') !== false) {
                    Console::log($line, Console::COLOR_GREEN);
                } else {
                    echo $line . "\n";
                }
            }
            echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            break;
            
        case 'help':
        default:
            showHelp();
            break;
    }
    
} catch (Exception $e) {
    Console::error("Error: " . $e->getMessage());
    echo "\n💡 Troubleshooting tips:\n";
    echo "• Check your network connection\n";
    echo "• Verify database configuration in config/.env\n";
    echo "• Ensure network nodes are configured properly\n";
    echo "• Check logs with: php console.php logs\n\n";
    exit(1);
}

echo "\n";
?>
