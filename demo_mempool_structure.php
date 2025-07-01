<?php
/**
 * Mempool Structure Demo
 * Demonstrates the professional mempool implementation
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/config.php';

use Blockchain\Core\Transaction\ProfessionalMempoolManager;

echo "=== MEMPOOL STRUCTURE DEMONSTRATION ===\n\n";

try {
    // Database connection
    $config = require __DIR__ . '/config/config.php';
    $database = new PDO(
        "mysql:host=localhost;dbname=blockchain_modern;charset=utf8mb4",
        'root',
        '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    // Initialize mempool manager
    $nodeId = 'demo-node-' . substr(md5(microtime()), 0, 8);
    $mempoolManager = new ProfessionalMempoolManager($database, $nodeId);
    
    echo "1. MEMPOOL TABLE STRUCTURE:\n";
    echo "   Current structure supports consensus-critical operations:\n\n";
    
    // Show table structure
    $stmt = $database->prepare("DESCRIBE mempool");
    $stmt->execute();
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    printf("   %-20s %-15s %-10s %-5s %-15s\n", "Field", "Type", "Null", "Key", "Extra");
    echo "   " . str_repeat("-", 80) . "\n";
    
    foreach ($columns as $column) {
        printf("   %-20s %-15s %-10s %-5s %-15s\n",
            $column['Field'],
            $column['Type'],
            $column['Null'],
            $column['Key'],
            $column['Extra']
        );
    }
    
    echo "\n2. CONSENSUS-CRITICAL FIELDS:\n";
    $criticalFields = [
        'tx_hash' => 'Unique transaction identifier',
        'from_address' => 'Sender wallet address',
        'to_address' => 'Recipient wallet address',
        'amount' => 'Transaction amount',
        'fee' => 'Transaction fee for validators',
        'nonce' => 'Sequence number (prevents replay)',
        'signature' => 'Cryptographic proof of authorization',
        'priority_score' => 'Consensus ordering priority',
        'created_at' => 'Transaction creation time',
        'expires_at' => 'Transaction expiration time'
    ];
    
    foreach ($criticalFields as $field => $description) {
        echo "   • {$field}: {$description}\n";
    }
    
    echo "\n3. ADDING SAMPLE TRANSACTIONS:\n";
    
    // Generate sample transactions
    $sampleTransactions = [
        [
            'hash' => '0x' . hash('sha256', 'tx1' . microtime()),
            'from' => '0x1234567890123456789012345678901234567890',
            'to' => '0xabcdefabcdefabcdefabcdefabcdefabcdefabcd',
            'amount' => 10.5,
            'fee' => 0.001,
            'nonce' => 1,
            'signature' => 'signature_data_1',
            'gas_price' => 20,
            'gas_limit' => 21000
        ],
        [
            'hash' => '0x' . hash('sha256', 'tx2' . microtime()),
            'from' => '0xfedcbafedcbafedcbafedcbafedcbafedcbafedcba',
            'to' => '0x9876543210987654321098765432109876543210',
            'amount' => 5.0,
            'fee' => 0.002,
            'nonce' => 1,
            'signature' => 'signature_data_2',
            'gas_price' => 25,
            'gas_limit' => 21000
        ],
        [
            'hash' => '0x' . hash('sha256', 'tx3' . microtime()),
            'from' => '0x1111111111111111111111111111111111111111',
            'to' => '0x2222222222222222222222222222222222222222',
            'amount' => 100.0,
            'fee' => 0.005,
            'nonce' => 1,
            'signature' => 'signature_data_3',
            'gas_price' => 30,
            'gas_limit' => 50000
        ]
    ];
    
    $added = 0;
    foreach ($sampleTransactions as $i => $tx) {
        if ($mempoolManager->addTransaction($tx)) {
            $added++;
            echo "   ✓ Transaction " . ($i + 1) . " added (fee: {$tx['fee']}, amount: {$tx['amount']})\n";
        } else {
            echo "   ❌ Failed to add transaction " . ($i + 1) . "\n";
        }
    }
    
    echo "\n4. MEMPOOL STATISTICS:\n";
    $stats = $mempoolManager->getStatistics();
    
    echo "   Transactions by Status:\n";
    foreach ($stats['by_status'] as $status => $count) {
        echo "     • {$status}: {$count}\n";
    }
    
    if (isset($stats['pending'])) {
        $pending = $stats['pending'];
        echo "\n   Pending Transaction Metrics:\n";
        echo "     • Total pending: {$pending['total_pending']}\n";
        echo "     • Total value: " . number_format($pending['total_value'], 8) . "\n";
        echo "     • Total fees: " . number_format($pending['total_fees'], 8) . "\n";
        echo "     • Average fee: " . number_format($pending['avg_fee'], 8) . "\n";
        echo "     • Average priority: " . number_format($pending['avg_priority'], 2) . "\n";
    }
    
    echo "\n5. TRANSACTIONS FOR BLOCK PROPOSAL:\n";
    $blockTxs = $mempoolManager->getTransactionsForBlock(10);
    
    echo "   Consensus-ordered transactions (highest priority first):\n";
    printf("   %-10s %-12s %-12s %-8s %-8s\n", "Priority", "From", "To", "Amount", "Fee");
    echo "   " . str_repeat("-", 60) . "\n";
    
    foreach ($blockTxs as $tx) {
        printf("   %-10d %-12s %-12s %-8.3f %-8.6f\n",
            $tx['priority_score'],
            substr($tx['from_address'], 0, 10) . '...',
            substr($tx['to_address'], 0, 10) . '...',
            $tx['amount'],
            $tx['fee']
        );
    }
    
    echo "\n6. CONSENSUS VALIDATION:\n";
    $validation = $mempoolManager->validateConsensusIntegrity();
    
    if ($validation['valid']) {
        echo "   ✅ Mempool passes consensus validation\n";
    } else {
        echo "   ❌ Consensus validation issues:\n";
        foreach ($validation['issues'] as $issue) {
            echo "     • {$issue}\n";
        }
    }
    
    echo "\n7. EXPORT FOR SYNCHRONIZATION:\n";
    $exportData = $mempoolManager->exportForConsensus();
    
    echo "   Export metadata:\n";
    echo "     • Node ID: {$exportData['metadata']['node_id']}\n";
    echo "     • Transaction count: {$exportData['metadata']['transaction_count']}\n";
    echo "     • Export type: {$exportData['metadata']['export_type']}\n";
    echo "     • Export size: " . strlen(json_encode($exportData)) . " bytes\n";
    
    echo "\n8. WHY MEMPOOL IS CONSENSUS-CRITICAL:\n";
    echo "   🔥 Block Proposal Consistency:\n";
    echo "      All validators must choose from identical transaction pools\n";
    echo "   🔥 Fee Market Integrity:\n";
    echo "      Consistent fee calculations across the network\n";
    echo "   🔥 Nonce Ordering:\n";
    echo "      Prevents transaction reordering attacks\n";
    echo "   🔥 Double-Spend Prevention:\n";
    echo "      Network-wide transaction visibility\n";
    echo "   🔥 Network Synchronization:\n";
    echo "      Identical pending state across all nodes\n";
    
    echo "\n9. CLEANUP OPERATIONS:\n";
    $cleanupStats = $mempoolManager->consensusCleanup();
    
    echo "   Cleanup results:\n";
    foreach ($cleanupStats as $type => $count) {
        echo "     • {$type}: {$count} transactions\n";
    }
    
    echo "\n=== MEMPOOL DEMONSTRATION COMPLETE ===\n";
    echo "The mempool structure supports professional blockchain operations\n";
    echo "with consensus-critical transaction management and synchronization.\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "\n🎯 MEMPOOL CONSENSUS REQUIREMENTS SUMMARY:\n";
echo "✓ Identical transaction pools across all validator nodes\n";
echo "✓ Deterministic transaction ordering for block proposals\n";
echo "✓ Cryptographic integrity validation\n";
echo "✓ Double-spend detection and prevention\n";
echo "✓ Network-wide synchronization capabilities\n";
echo "✓ Priority-based consensus transaction selection\n";
echo "✓ Automatic cleanup and maintenance\n";
echo "✓ Professional logging and monitoring\n";
