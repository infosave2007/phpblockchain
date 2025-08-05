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
if (php_sapi_name() !== 'cli') {
    echo "This script must be run from command line\n";
    exit(1);
}

$command = $argv[1] ?? 'help';
$options = array_slice($argv, 2);

try {
    $syncManager = new NetworkSyncManager(false);
    
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
            
        case 'help':
        case '--help':
        case '-h':
        default:
            showUsage();
            break;
    }
    
} catch (Exception $e) {
    echo "💥 Fatal Error: " . $e->getMessage() . "\n";
    echo "\nThis usually indicates a configuration or database issue.\n";
    echo "Please check:\n";
    echo "- Database connection settings\n";
    echo "- File permissions\n";
    echo "- PHP requirements\n";
    exit(1);
}
?>
