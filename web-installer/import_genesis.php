<?php
// Script to import existing genesis block into database

try {
    // Load DatabaseManager
    require_once '../core/Database/DatabaseManager.php';
    $pdo = \Blockchain\Core\Database\DatabaseManager::getConnection();
    
    // Load genesis block from file
    $genesisFile = '../storage/blockchain/genesis.json';
    if (!file_exists($genesisFile)) {
        throw new Exception('Genesis block file not found');
    }
    
    $genesisData = json_decode(file_get_contents($genesisFile), true);
    if (!$genesisData) {
        throw new Exception('Invalid genesis block data');
    }
    
    echo "Importing genesis block into database...\n";
    
    // Check if genesis block already exists
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM blocks WHERE hash = ?");
    $stmt->execute([$genesisData['hash']]);
    $exists = $stmt->fetchColumn();
    
    if ($exists > 0) {
        echo "Genesis block already exists in database.\n";
    } else {
        // Insert genesis block into blocks table
        $stmt = $pdo->prepare("INSERT INTO blocks (hash, parent_hash, height, timestamp, validator, signature, merkle_root, transactions_count, metadata) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $genesisData['hash'],
            $genesisData['previous_hash'],
            $genesisData['index'],
            $genesisData['timestamp'],
            'genesis_validator',
            'genesis_signature',
            $genesisData['merkle_root'],
            count($genesisData['transactions']),
            json_encode(['genesis' => true])
        ]);
        
        echo "Genesis block inserted into database.\n";
    }
    
    // Insert genesis transactions
    foreach ($genesisData['transactions'] as $tx) {
        $txHash = '0x' . ($tx['hash'] ?? hash('sha256', json_encode($tx)));
        
        // Check if transaction already exists
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE hash = ?");
        $stmt->execute([$txHash]);
        $txExists = $stmt->fetchColumn();
        
        if ($txExists == 0) {
            $stmt = $pdo->prepare("INSERT INTO transactions (hash, block_hash, block_height, from_address, to_address, amount, fee, gas_limit, gas_used, gas_price, nonce, data, signature, status, timestamp) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $txHash,
                $genesisData['hash'],
                $genesisData['index'],
                'genesis',
                $tx['to'],
                $tx['amount'],
                0, // fee
                0, // gas_limit
                0, // gas_used
                0, // gas_price
                0, // nonce
                json_encode($tx),
                'genesis_signature',
                'confirmed',
                $tx['timestamp']
            ]);
            
            echo "Genesis transaction {$txHash} inserted.\n";
        } else {
            echo "Genesis transaction {$txHash} already exists.\n";
        }
    }
    
    // Update config with genesis block hash
    $stmt = $pdo->prepare("UPDATE config SET value = ? WHERE key_name = 'blockchain.genesis_block'");
    $stmt->execute([$genesisData['hash']]);
    
    echo "Genesis block import completed successfully!\n";
    echo "Block hash: {$genesisData['hash']}\n";
    echo "Transactions: " . count($genesisData['transactions']) . "\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>
