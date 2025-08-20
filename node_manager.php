<?php
/**
 * Simple Node Manager for Budget Hosting
 * Basic PHP CLI without complex dependencies
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/config.php';

use Blockchain\Core\Application;
use Blockchain\Core\Recovery\BlockchainRecoveryManager;
use Blockchain\Core\Storage\BlockchainBinaryStorage;
use Blockchain\Core\Network\NodeHealthMonitor;

function showHelp(): void
{
    echo "Simple Node Manager\n";
    echo "==================\n\n";
    echo "Usage: php node_manager.php <command>\n\n";
    echo "Commands:\n";
    echo "  status     - Show node health status\n";
    echo "  check      - Perform full health check\n";
    echo "  backup     - Create backup\n";
    echo "  recover    - Attempt recovery\n";
    echo "  install    - Install/create database tables\n";
    echo "  help       - Show this help\n\n";
}

function createRequiredDirectories(): void
{
    $dirs = [
        __DIR__ . '/storage',
        __DIR__ . '/storage/blockchain',
        __DIR__ . '/storage/backups',
        __DIR__ . '/storage/state',
        __DIR__ . '/storage/contracts',
        __DIR__ . '/logs'
    ];
    
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
            echo "Created directory: {$dir}\n";
        }
    }
}

function initializeComponents(): array
{
    try {
        $config = include __DIR__ . '/config/config.php';
        
        // Database connection using DatabaseManager
        require_once __DIR__ . '/core/Database/DatabaseManager.php';
        $database = \Blockchain\Core\Database\DatabaseManager::getConnection();
        
        // Initialize components
        $binaryStorage = new BlockchainBinaryStorage('blockchain.json');
        $healthMonitor = new NodeHealthMonitor($binaryStorage, $database, $config);
        $syncManager = new \Blockchain\Core\Storage\SelectiveBlockchainSyncManager($database, $binaryStorage, $config);
        $recoveryManager = new BlockchainRecoveryManager($database, $binaryStorage, $syncManager, 'node1', $config);
        
        return [
            'database' => $database,
            'binary_storage' => $binaryStorage,
            'health_monitor' => $healthMonitor,
            'recovery_manager' => $recoveryManager,
            'config' => $config
        ];
        
    } catch (Exception $e) {
        echo "❌ Failed to initialize: " . $e->getMessage() . "\n";
        exit(1);
    }
}

function showStatus(array $components): void
{
    echo "Node Health Status\n";
    echo "==================\n\n";
    
    $healthMonitor = $components['health_monitor'];
    $health = $healthMonitor->quickHealthCheck();
    
    echo "Overall Status: " . ($health['healthy'] ? "✅ Healthy" : "❌ Unhealthy") . "\n";
    echo "Node ID: {$health['node_id']}\n";
    echo "Check Time: {$health['check_time']} ms\n";
    echo "Timestamp: " . date('Y-m-d H:i:s', $health['timestamp']) . "\n\n";
    
    echo "Component Checks:\n";
    foreach ($health['checks'] as $check => $result) {
        $icon = $result ? "✅" : "❌";
        echo "  {$icon} " . ucfirst(str_replace('_', ' ', $check)) . "\n";
    }
    
    if (!empty($health['errors'])) {
        echo "\nErrors:\n";
        foreach ($health['errors'] as $error) {
            echo "  • {$error}\n";
        }
    }
    
    // Network stats
    $networkStats = $healthMonitor->getNetworkStats();
    if (!empty($networkStats)) {
        echo "\nNetwork Statistics:\n";
        foreach ($networkStats as $stat) {
            echo "  {$stat['status']}: {$stat['count']} nodes\n";
        }
    }
    
    echo "\n";
}

function performFullCheck(array $components): void
{
    echo "Performing Full Health Check\n";
    echo "============================\n\n";
    
    $healthMonitor = $components['health_monitor'];
    $health = $healthMonitor->fullHealthCheck();
    
    echo "Overall Status: " . ($health['healthy'] ? "✅ Healthy" : "❌ Unhealthy") . "\n";
    echo "Check Time: {$health['check_time']} ms\n\n";
    
    echo "Detailed Checks:\n";
    foreach ($health['checks'] as $check => $result) {
        $icon = $result ? "✅" : "❌";
        echo "  {$icon} " . ucfirst(str_replace('_', ' ', $check)) . "\n";
    }
    
    if (isset($health['details'])) {
        echo "\nDetailed Information:\n";
        foreach ($health['details'] as $key => $value) {
            if (is_numeric($value)) {
                if ($key === 'binary_file_size' || $key === 'disk_free') {
                    $value = formatBytes($value);
                } elseif ($key === 'memory_usage' || $key === 'memory_peak') {
                    $value = formatBytes($value);
                }
            }
            echo "  " . ucfirst(str_replace('_', ' ', $key)) . ": {$value}\n";
        }
    }
    
    echo "\n";
}

function createBackup(array $components): void
{
    echo "Creating Backup\n";
    echo "===============\n\n";
    
    $recoveryManager = $components['recovery_manager'];
    $backupPath = __DIR__ . '/storage/backups/backup_' . date('Y-m-d_H-i-s');
    
    echo "Backup location: {$backupPath}\n";
    
    $result = $recoveryManager->createBackup();
    
    if ($result['success']) {
        echo "✅ Backup created successfully\n";
        echo "Files backed up:\n";
        foreach ($result['files'] as $type => $file) {
            echo "  • {$type}: " . basename($file) . "\n";
        }
        echo "Total size: " . formatBytes($result['size']) . "\n";
    } else {
        echo "❌ Backup failed\n";
        foreach ($result['errors'] as $error) {
            echo "  • {$error}\n";
        }
    }
    
    echo "\n";
}

function attemptRecovery(array $components): void
{
    echo "Attempting Recovery\n";
    echo "===================\n\n";
    
    $recoveryManager = $components['recovery_manager'];
    
    echo "Running auto-recovery...\n";
    $result = $recoveryManager->performAutoRecovery();
    
    if ($result['success']) {
        echo "✅ Recovery completed successfully\n";
        echo "Actions taken:\n";
        foreach ($result['actions_taken'] as $action) {
            echo "  • {$action}\n";
        }
    } else {
        echo "❌ Recovery failed\n";
        foreach ($result['errors'] as $error) {
            echo "  • {$error}\n";
        }
    }
    
    echo "\n";
}


function formatBytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    } elseif ($bytes < 1048576) {
        return round($bytes / 1024, 2) . ' KB';
    } elseif ($bytes < 1073741824) {
        return round($bytes / 1048576, 2) . ' MB';
    } else {
        return round($bytes / 1073741824, 2) . ' GB';
    }
}

// Main execution
if (php_sapi_name() !== 'cli') {
    echo "This script must be run from command line\n";
    exit(1);
}

$command = $argv[1] ?? 'help';

switch ($command) {
    case 'status':
        createRequiredDirectories();
        $components = initializeComponents();
        showStatus($components);
        break;
        
    case 'check':
        createRequiredDirectories();
        $components = initializeComponents();
        performFullCheck($components);
        break;
        
    case 'backup':
        createRequiredDirectories();
        $components = initializeComponents();
        createBackup($components);
        break;
        
    case 'recover':
        createRequiredDirectories();
        $components = initializeComponents();
        attemptRecovery($components);
        break;
        
    case 'install':
        echo "Database tables are now managed by Migration.php\n";
        echo "Use the Migration class to create tables:\n";
        echo "  \$migration = new \\Blockchain\\Database\\Migration(\$pdo);\n";
        echo "  \$migration->createSchema();\n";
        break;
        
    case 'help':
    default:
        showHelp();
        break;
}
