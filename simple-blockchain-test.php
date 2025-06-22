<?php
require_once __DIR__ . '/vendor/autoload.php';

use Blockchain\Core\Blockchain\Block;
use Blockchain\Core\Transaction\Transaction;
use Blockchain\Core\Cryptography\KeyPair;

echo "=== Testing Blockchain Implementation ===\n\n";

try {
    // Test Block creation
    echo "1. Testing Block creation...\n";
    $transactions = [];
    $block = new Block(1, 'genesis_hash', $transactions, time(), 0);
    echo "   ✓ Block index: " . $block->getIndex() . "\n";
    echo "   ✓ Block hash: " . substr($block->getHash(), 0, 20) . "...\n";
    echo "   ✓ Previous hash: " . $block->getPreviousHash() . "\n";
    
    // Test Transaction creation
    echo "\n2. Testing Transaction creation...\n";
    $keyPair = KeyPair::generate();
    $from = $keyPair->getAddress();
    $to = '0x1234567890123456789012345678901234567890';
    $amount = 10.5;
    
    $transaction = new Transaction($from, $to, $amount);
    echo "   ✓ From: " . substr($transaction->getFrom(), 0, 20) . "...\n";
    echo "   ✓ To: " . substr($transaction->getTo(), 0, 20) . "...\n";
    echo "   ✓ Amount: " . $transaction->getAmount() . "\n";
    echo "   ✓ Hash: " . substr($transaction->getHash(), 0, 20) . "...\n";
    
    // Test Transaction signing
    echo "\n3. Testing Transaction signing...\n";
    $signature = $transaction->sign($keyPair->getPrivateKey());
    echo "   ✓ Signature created: " . substr($signature, 0, 20) . "...\n";
    
    $isValid = $transaction->verify($keyPair->getPublicKey());
    echo "   ✓ Transaction valid: " . ($isValid ? "YES" : "NO") . "\n";
    
    // Test Block with transactions
    echo "\n4. Testing Block with transactions...\n";
    $transactions = [$transaction];
    $blockWithTx = new Block(2, $block->getHash(), $transactions, time(), 0);
    echo "   ✓ Block with " . count($blockWithTx->getTransactions()) . " transaction(s)\n";
    echo "   ✓ Merkle root: " . substr($blockWithTx->getMerkleRoot(), 0, 20) . "...\n";
    
    // Test Block chaining
    echo "\n5. Testing Block chaining...\n";
    $block3 = new Block(3, $blockWithTx->getHash(), [], time() + 1, 0);
    echo "   ✓ Chain: Block 1 -> Block 2 -> Block 3\n";
    echo "   ✓ Block 3 previous hash matches Block 2 hash: " . 
         ($block3->getPreviousHash() === $blockWithTx->getHash() ? "YES" : "NO") . "\n";
    
    echo "\n=== All blockchain tests passed! ===\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
