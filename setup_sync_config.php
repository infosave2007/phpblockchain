<?php
/**
 * Configuration Setup Script for Sync Service
 * This script ensures proper network configuration for synchronization
 */

require_once 'core/Environment/EnvironmentLoader.php';
require_once 'core/Database/DatabaseManager.php';

try {
    echo "=== Blockchain Sync Configuration Setup ===\n\n";
    
    // Connect to database
    $pdo = \Blockchain\Core\Database\DatabaseManager::getConnection();
    echo "✓ Database connection established\n";
    
    // Check if config table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'config'");
    if ($stmt->rowCount() === 0) {
        echo "✗ Config table not found\n";
        exit(1);
    }
    echo "✓ Config table found\n";
    
    // Check for network nodes configuration
    $stmt = $pdo->prepare("SELECT key_name, value FROM config WHERE key_name LIKE 'network.%'");
    $stmt->execute();
    $configs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "\nCurrent network configuration:\n";
    foreach ($configs as $config) {
        echo "  {$config['key_name']}: {$config['value']}\n";
    }
    
    // Check if network.nodes exists
    $stmt = $pdo->prepare("SELECT value FROM config WHERE key_name = 'network.nodes'");
    $stmt->execute();
    $networkNodes = $stmt->fetchColumn();
    
    if (empty($networkNodes)) {
        echo "\n⚠ No network.nodes found, adding default genesis node...\n";
        
        // Add default network nodes configuration
        $defaultNode = "https://wallet.coursefactory.pro";
        
        $stmt = $pdo->prepare("
            INSERT INTO config (key_name, value, description, is_system) 
            VALUES ('network.nodes', ?, 'Network nodes for synchronization', 1)
            ON DUPLICATE KEY UPDATE value = VALUES(value)
        ");
        $stmt->execute([$defaultNode]);
        
        echo "✓ Added default network node: $defaultNode\n";
    } else {
        echo "✓ Network nodes configuration found\n";
    }
    
    // Add selection strategy if not exists
    $stmt = $pdo->prepare("SELECT value FROM config WHERE key_name = 'node.selection_strategy'");
    $stmt->execute();
    $strategy = $stmt->fetchColumn();
    
    if (empty($strategy)) {
        $stmt = $pdo->prepare("
            INSERT INTO config (key_name, value, description, is_system) 
            VALUES ('node.selection_strategy', 'fastest_response', 'Node selection strategy for sync', 1)
            ON DUPLICATE KEY UPDATE value = VALUES(value)
        ");
        $stmt->execute();
        echo "✓ Added node selection strategy\n";
    }
    
    // Test sync service
    echo "\n=== Testing Sync Service ===\n";
    
    require_once 'sync-service/SyncManager.php';
    
    $syncManager = new SyncManager(false);
    $status = $syncManager->getStatus();
    
    echo "Current database status:\n";
    foreach ($status['tables'] as $table => $count) {
        echo "  $table: $count records\n";
    }
    echo "  Latest block: {$status['latest_block']}\n";
    echo "  Last sync: {$status['latest_timestamp']}\n";
    
    echo "\n✓ Sync service configuration complete!\n";
    echo "\nTo run full synchronization, use:\n";
    echo "  php sync-service/sync.php\n\n";
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}
