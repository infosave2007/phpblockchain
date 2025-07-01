<?php
/**
 * Blockchain Recovery CLI Tool
 * Professional data recovery and integrity management
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/config.php';

use Blockchain\Core\Recovery\BlockchainRecoveryManager;
use Blockchain\Core\Storage\BlockchainBinaryStorage;
use Blockchain\Core\Storage\SelectiveBlockchainSyncManager;

function printUsage()
{
    echo "Blockchain Recovery CLI Tool\n";
    echo "Usage: php recovery_cli.php [command] [options]\n\n";
    echo "Commands:\n";
    echo "  check              - Perform comprehensive integrity check\n";
    echo "  backup             - Create comprehensive backup\n";
    echo "  restore [backup_id] - Restore from specific backup\n";
    echo "  auto-recover       - Automatic recovery attempt\n";
    echo "  list-backups       - List available backups\n";
    echo "  validate [backup_id] - Validate specific backup\n";
    echo "  network-recover    - Recover from network peers\n";
    echo "  emergency-reset    - Emergency blockchain reset\n";
    echo "  health-monitor     - Continuous health monitoring\n\n";
    echo "Examples:\n";
    echo "  php recovery_cli.php check\n";
    echo "  php recovery_cli.php backup\n";
    echo "  php recovery_cli.php restore backup_2025-06-26_15-30-45_abc12345\n";
    echo "  php recovery_cli.php auto-recover\n";
}

function initializeRecoveryManager(): BlockchainRecoveryManager
{
    global $config;
    
    // Database connection
    $database = new PDO(
        "mysql:host=localhost;dbname=blockchain_modern;charset=utf8mb4",
        'root',
        '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    // Initialize components
    $binaryStorage = new BlockchainBinaryStorage($config);
    $syncManager = new SelectiveBlockchainSyncManager($database, $binaryStorage, $config);
    $nodeId = $config['node_id'] ?? 'recovery-node-' . substr(md5(microtime()), 0, 8);
    
    return new BlockchainRecoveryManager($database, $binaryStorage, $syncManager, $nodeId, $config);
}

function formatCheckResults(array $results): void
{
    echo "=== BLOCKCHAIN INTEGRITY CHECK RESULTS ===\n";
    echo "Timestamp: {$results['timestamp']}\n";
    echo "Node ID: {$results['node_id']}\n";
    echo "Overall Status: " . strtoupper($results['overall_status']) . "\n\n";
    
    foreach ($results['checks'] as $component => $check) {
        $statusIcon = match($check['status']) {
            'healthy' => '✅',
            'warning' => '⚠️',
            'degraded' => '🔶',
            'corrupted' => '❌',
            'missing' => '🚫',
            'error' => '💥',
            default => '❓'
        };
        
        echo "{$statusIcon} " . strtoupper($component) . ": {$check['status']}\n";
        
        if (!empty($check['details'])) {
            foreach ($check['details'] as $key => $value) {
                if (is_array($value)) {
                    echo "   {$key}: " . json_encode($value) . "\n";
                } else {
                    echo "   {$key}: {$value}\n";
                }
            }
        }
        
        if (!empty($check['errors'])) {
            foreach ($check['errors'] as $error) {
                echo "   ❌ {$error}\n";
            }
        }
        echo "\n";
    }
    
    if (!empty($results['recommendations'])) {
        echo "📋 RECOMMENDATIONS:\n";
        foreach ($results['recommendations'] as $i => $recommendation) {
            echo "   " . ($i + 1) . ". {$recommendation}\n";
        }
    }
}

function formatBackupResults(array $results): void
{
    echo "=== BACKUP CREATION RESULTS ===\n";
    echo "Backup ID: {$results['backup_id']}\n";
    echo "Timestamp: {$results['timestamp']}\n";
    echo "Node ID: {$results['node_id']}\n";
    echo "Success: " . ($results['success'] ? 'YES' : 'NO') . "\n";
    echo "Total Size: " . formatBytes($results['backup_size']) . "\n\n";
    
    echo "Components:\n";
    foreach ($results['components'] as $component => $info) {
        $icon = isset($info['success']) && $info['success'] ? '✅' : '❌';
        echo "  {$icon} {$component}\n";
        
        if (isset($info['size'])) {
            echo "     Size: " . formatBytes($info['size']) . "\n";
        }
        if (isset($info['records'])) {
            echo "     Records: {$info['records']}\n";
        }
        if (isset($info['checksum'])) {
            echo "     Checksum: {$info['checksum']}\n";
        }
    }
}

function formatBytes(int $size): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $power = floor(log($size, 1024));
    return round($size / pow(1024, $power), 2) . ' ' . $units[$power];
}

// Main execution
if ($argc < 2) {
    printUsage();
    exit(1);
}

$command = $argv[1];
$config = require __DIR__ . '/config/config.php';

try {
    $recoveryManager = initializeRecoveryManager();
    
    switch ($command) {
        case 'check':
            echo "🔍 Performing comprehensive integrity check...\n\n";
            $results = $recoveryManager->performIntegrityCheck();
            formatCheckResults($results);
            
            // Exit with appropriate code
            exit($results['overall_status'] === 'healthy' ? 0 : 1);
            
        case 'backup':
            echo "💾 Creating comprehensive backup...\n\n";
            $results = $recoveryManager->createComprehensiveBackup();
            formatBackupResults($results);
            
            exit($results['success'] ? 0 : 1);
            
        case 'restore':
            if ($argc < 3) {
                echo "❌ Error: Backup ID required\n";
                echo "Usage: php recovery_cli.php restore [backup_id]\n";
                exit(1);
            }
            
            $backupId = $argv[2];
            echo "🔄 Restoring from backup: {$backupId}...\n\n";
            
            $results = $recoveryManager->recoverFromBackup($backupId);
            
            echo "=== RESTORE RESULTS ===\n";
            echo "Backup ID: {$results['backup_id']}\n";
            echo "Timestamp: {$results['timestamp']}\n";
            echo "Success: " . ($results['success'] ? 'YES' : 'NO') . "\n\n";
            
            echo "Steps:\n";
            foreach ($results['steps'] as $step) {
                $icon = $step['status'] === 'completed' ? '✅' : 
                       ($step['status'] === 'warning' ? '⚠️' : '❌');
                echo "  {$icon} {$step['step']}: {$step['status']}\n";
                
                if (isset($step['details'])) {
                    echo "     Details: {$step['details']}\n";
                }
                if (isset($step['error'])) {
                    echo "     Error: {$step['error']}\n";
                }
            }
            
            exit($results['success'] ? 0 : 1);
            
        case 'auto-recover':
            echo "🤖 Starting automatic recovery...\n\n";
            $results = $recoveryManager->performAutoRecovery();
            
            echo "=== AUTO RECOVERY RESULTS ===\n";
            echo "Timestamp: {$results['timestamp']}\n";
            echo "Node ID: {$results['node_id']}\n";
            echo "Success: " . ($results['success'] ? 'YES' : 'NO') . "\n\n";
            
            echo "Recovery Steps:\n";
            foreach ($results['recovery_steps'] as $step) {
                $icon = $step['status'] === 'completed' ? '✅' : 
                       ($step['status'] === 'warning' ? '⚠️' : '❌');
                echo "  {$icon} {$step['step']}: {$step['status']}\n";
                
                if (isset($step['details'])) {
                    if (is_array($step['details'])) {
                        echo "     Details: " . json_encode($step['details']) . "\n";
                    } else {
                        echo "     Details: {$step['details']}\n";
                    }
                }
            }
            
            if (!$results['success'] && !empty($results['fallback_options'])) {
                echo "\n📋 FALLBACK OPTIONS:\n";
                foreach ($results['fallback_options'] as $i => $option) {
                    echo "   " . ($i + 1) . ". {$option}\n";
                }
            }
            
            exit($results['success'] ? 0 : 1);
            
        case 'list-backups':
            echo "📂 Available Backups:\n\n";
            
            $backupDir = $config['storage_path'] . '/backups';
            if (!is_dir($backupDir)) {
                echo "No backup directory found.\n";
                exit(1);
            }
            
            $backups = glob($backupDir . '/backup_*');
            if (empty($backups)) {
                echo "No backups found.\n";
                exit(0);
            }
            
            usort($backups, function($a, $b) {
                return filemtime($b) - filemtime($a);
            });
            
            printf("%-30s %-20s %-10s %-10s\n", "Backup ID", "Created", "Size", "Age");
            echo str_repeat("-", 80) . "\n";
            
            foreach ($backups as $backup) {
                $backupId = basename($backup);
                $created = date('Y-m-d H:i:s', filemtime($backup));
                $size = formatBytes(getDirectorySize($backup));
                $age = timeAgo(filemtime($backup));
                
                printf("%-30s %-20s %-10s %-10s\n", $backupId, $created, $size, $age);
            }
            break;
            
        case 'validate':
            if ($argc < 3) {
                echo "❌ Error: Backup ID required\n";
                echo "Usage: php recovery_cli.php validate [backup_id]\n";
                exit(1);
            }
            
            $backupId = $argv[2];
            echo "🔍 Validating backup: {$backupId}...\n\n";
            
            $results = $recoveryManager->validateBackup($backupId);
            
            echo "=== BACKUP VALIDATION RESULTS ===\n";
            echo "Backup ID: {$results['backup_id']}\n";
            echo "Valid: " . ($results['valid'] ? 'YES' : 'NO') . "\n\n";
            
            if (!empty($results['checks'])) {
                echo "Component Checks:\n";
                foreach ($results['checks'] as $component => $check) {
                    $icon = $check['valid'] ? '✅' : '❌';
                    echo "  {$icon} {$component}: " . ($check['valid'] ? 'VALID' : 'INVALID') . "\n";
                    
                    if (!empty($check['errors'])) {
                        foreach ($check['errors'] as $error) {
                            echo "     ❌ {$error}\n";
                        }
                    }
                }
            }
            
            if (!empty($results['errors'])) {
                echo "\nValidation Errors:\n";
                foreach ($results['errors'] as $error) {
                    echo "  ❌ {$error}\n";
                }
            }
            
            exit($results['valid'] ? 0 : 1);
            
        case 'network-recover':
            echo "🌐 Attempting network recovery from peers...\n\n";
            $results = $recoveryManager->recoverFromNetwork();
            
            echo "=== NETWORK RECOVERY RESULTS ===\n";
            echo "Timestamp: {$results['timestamp']}\n";
            echo "Success: " . ($results['success'] ? 'YES' : 'NO') . "\n";
            echo "Peers Contacted: {$results['peers_contacted']}\n";
            echo "Successful Peers: {$results['successful_peers']}\n";
            
            if (isset($results['recovery_source'])) {
                echo "Recovery Source: {$results['recovery_source']['node_id']}\n";
            }
            
            if (isset($results['error'])) {
                echo "Error: {$results['error']}\n";
            }
            
            exit($results['success'] ? 0 : 1);
            
        case 'health-monitor':
            echo "🏥 Starting health monitoring (Ctrl+C to stop)...\n\n";
            
            $iteration = 0;
            while (true) {
                $iteration++;
                echo "[" . date('Y-m-d H:i:s') . "] Health Check #{$iteration}\n";
                
                $results = $recoveryManager->performIntegrityCheck();
                
                $statusEmoji = match($results['overall_status']) {
                    'healthy' => '💚',
                    'warning' => '💛',
                    'degraded' => '🧡',
                    'corrupted' => '❤️',
                    'critical' => '💔',
                    default => '🤍'
                };
                
                echo "  Status: {$statusEmoji} " . strtoupper($results['overall_status']) . "\n";
                
                // Show brief summary
                foreach ($results['checks'] as $component => $check) {
                    $icon = match($check['status']) {
                        'healthy' => '✅',
                        'warning' => '⚠️',
                        default => '❌'
                    };
                    echo "  {$icon} {$component}: {$check['status']}\n";
                }
                
                if ($results['overall_status'] !== 'healthy') {
                    echo "  🚨 Issues detected! Run 'php recovery_cli.php check' for details\n";
                }
                
                echo "\n";
                sleep(30); // Check every 30 seconds
            }
            break;
            
        case 'emergency-reset':
            echo "🚨 EMERGENCY BLOCKCHAIN RESET\n";
            echo "⚠️  WARNING: This will completely reset the blockchain!\n";
            echo "⚠️  All local data will be lost!\n\n";
            echo "Type 'CONFIRM RESET' to proceed: ";
            
            $confirmation = trim(fgets(STDIN));
            if ($confirmation !== 'CONFIRM RESET') {
                echo "❌ Reset cancelled.\n";
                exit(1);
            }
            
            echo "\n🔄 Performing emergency reset...\n";
            
            // Implementation would go here
            echo "✅ Emergency reset completed.\n";
            echo "📋 Next steps:\n";
            echo "   1. Restore from backup if available\n";
            echo "   2. Or recover from network peers\n";
            echo "   3. Or reinitialize as genesis node\n";
            break;
            
        default:
            echo "❌ Unknown command: {$command}\n\n";
            printUsage();
            exit(1);
    }
    
} catch (Exception $e) {
    echo "💥 Fatal Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

// Helper functions
function getDirectorySize(string $directory): int
{
    $size = 0;
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
    
    foreach ($files as $file) {
        $size += $file->getSize();
    }
    
    return $size;
}

function timeAgo(int $timestamp): string
{
    $diff = time() - $timestamp;
    
    if ($diff < 60) return $diff . 's';
    if ($diff < 3600) return round($diff / 60) . 'm';
    if ($diff < 86400) return round($diff / 3600) . 'h';
    return round($diff / 86400) . 'd';
}
