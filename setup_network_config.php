<?php
/**
 * Setup network configuration for synchronization
 */

require_once 'sync-service/SyncManager.php';

try {
    echo "Setting up network configuration for node synchronization...\n";
    
    // Create SyncManager instance to access database
    $sync = new SyncManager();
    
    // Check if network.nodes configuration exists
    $configExists = false;
    
    // Use reflection to access private PDO property
    $reflection = new ReflectionClass($sync);
    $pdoProperty = $reflection->getProperty('pdo');
    $pdoProperty->setAccessible(true);
    $pdo = $pdoProperty->getValue($sync);
    
    // Check if network.nodes config exists
    $stmt = $pdo->prepare("SELECT value FROM config WHERE key_name = 'network.nodes'");
    $stmt->execute();
    $result = $stmt->fetch();
    
    if ($result && !empty($result['value'])) {
        echo "Network nodes configuration already exists: " . $result['value'] . "\n";
        $configExists = true;
    }
    
    if (!$configExists) {
        echo "Adding network nodes configuration...\n";
        
        // Add genesis node configuration
        $stmt = $pdo->prepare("
            INSERT INTO config (key_name, value, description, is_system) 
            VALUES ('network.nodes', ?, 'List of network nodes for synchronization', 1)
            ON DUPLICATE KEY UPDATE value = VALUES(value)
        ");
        
        $networkNodes = "https://wallet.coursefactory.pro";
        $stmt->execute([$networkNodes]);
        
        echo "Network nodes configuration added: $networkNodes\n";
    }
    
    // Also ensure we have node selection strategy
    $stmt = $pdo->prepare("
        INSERT INTO config (key_name, value, description, is_system) 
        VALUES ('node.selection_strategy', 'fastest_response', 'Node selection strategy for sync', 1)
        ON DUPLICATE KEY UPDATE value = VALUES(value)
    ");
    $stmt->execute();
    
    echo "Network configuration setup completed!\n";
    echo "You can now run synchronization.\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
