<?php
/**
 * Simple Node Manager for Budget Hosting
 * Basic PHP CLI without complex dependencies
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/config.php';

use Core\Application;
use Core\Recovery\BlockchainRecoveryManager;
use Core\Storage\BlockchainBinaryStorage;
use Core\Network\NodeHealthMonitor;

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
        
        // Database connection
        $database = new PDO(
            "mysql:host={$config['database']['host']};dbname={$config['database']['database']};charset={$config['database']['charset']}",
            $config['database']['username'],
            $config['database']['password']
        );
        $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Initialize components
        $binaryStorage = new BlockchainBinaryStorage();
        $healthMonitor = new NodeHealthMonitor($binaryStorage, $database, $config);
        $recoveryManager = new BlockchainRecoveryManager($binaryStorage);
        
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

function installTables(array $components): void
{
    echo "Installing Database Tables\n";
    echo "==========================\n\n";
    
    $database = $components['database'];
    
    try {
        // Create node_status table
        $database->exec("
            CREATE TABLE IF NOT EXISTS node_status (
                node_id VARCHAR(64) PRIMARY KEY,
                node_url VARCHAR(255) NOT NULL,
                status ENUM('healthy', 'degraded', 'recovering', 'offline', 'error') NOT NULL,
                details JSON,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_status (status),
                INDEX idx_updated (updated_at)
            )
        ");
        
        // Create other required tables
        $database->exec("
            CREATE TABLE IF NOT EXISTS blocks (
                id INT AUTO_INCREMENT PRIMARY KEY,
                hash VARCHAR(64) UNIQUE NOT NULL,
                previous_hash VARCHAR(64),
                merkle_root VARCHAR(64),
                timestamp INT NOT NULL,
                nonce INT,
                difficulty INT,
                data TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_hash (hash),
                INDEX idx_timestamp (timestamp)
            )
        ");
        
        $database->exec("
            CREATE TABLE IF NOT EXISTS transactions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                hash VARCHAR(64) UNIQUE NOT NULL,
                from_address VARCHAR(42),
                to_address VARCHAR(42) NOT NULL,
                amount DECIMAL(20,8) NOT NULL,
                fee DECIMAL(20,8) DEFAULT 0,
                data TEXT,
                signature TEXT,
                block_hash VARCHAR(64),
                timestamp INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_hash (hash),
                INDEX idx_addresses (from_address, to_address),
                INDEX idx_timestamp (timestamp),
                FOREIGN KEY (block_hash) REFERENCES blocks(hash)
            )
        ");
        
        $database->exec("
            CREATE TABLE IF NOT EXISTS wallets (
                id INT AUTO_INCREMENT PRIMARY KEY,
                address VARCHAR(42) UNIQUE NOT NULL,
                public_key TEXT NOT NULL,
                private_key TEXT,
                balance DECIMAL(20,8) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_address (address)
            )
        ");
        
        $database->exec("
            CREATE TABLE IF NOT EXISTS mempool (
                id INT AUTO_INCREMENT PRIMARY KEY,
                transaction_hash VARCHAR(64) UNIQUE NOT NULL,
                transaction_data JSON NOT NULL,
                priority INT DEFAULT 1,
                status ENUM('pending', 'processing', 'confirmed', 'rejected', 'expired') DEFAULT 'pending',
                retry_count INT DEFAULT 0,
                expires_at TIMESTAMP NULL,
                node_id VARCHAR(64),
                broadcast_count INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_hash (transaction_hash),
                INDEX idx_status (status),
                INDEX idx_priority (priority),
                INDEX idx_expires (expires_at),
                INDEX idx_node (node_id)
            )
        ");
        
        $database->exec("
            CREATE TABLE IF NOT EXISTS nodes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                url VARCHAR(255) UNIQUE NOT NULL,
                node_id VARCHAR(64),
                active BOOLEAN DEFAULT 1,
                last_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_url (url),
                INDEX idx_active (active),
                INDEX idx_last_seen (last_seen)
            )
        ");
        
        echo "✅ All tables created successfully\n";
        
    } catch (Exception $e) {
        echo "❌ Failed to create tables: " . $e->getMessage() . "\n";
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
        createRequiredDirectories();
        $components = initializeComponents();
        installTables($components);
        break;
        
    case 'help':
    default:
        showHelp();
        break;
}
