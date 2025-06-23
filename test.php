<?php
declare(strict_types=1);

/**
 * PHP Blockchain - Simple Test
 * Quick verification that all core functionality works
 */

echo "🧪 PHP BLOCKCHAIN - SIMPLE TEST\n";
echo "===============================\n\n";

require_once 'vendor/autoload.php';

use Blockchain\Core\Cryptography\KeyPair;
use Blockchain\Core\Cryptography\Signature;
use Blockchain\Core\Transaction\Transaction;

$tests_passed = 0;
$tests_total = 0;

function test($name, $callback) {
    global $tests_passed, $tests_total;
    $tests_total++;
    
    echo "Test: $name ... ";
    
    try {
        $result = $callback();
        if ($result) {
            echo "✅ PASS\n";
            $tests_passed++;
        } else {
            echo "❌ FAIL\n";
        }
    } catch (Exception $e) {
        echo "❌ ERROR: " . $e->getMessage() . "\n";
    }
}

// ===========================================
// TESTS
// ===========================================

test("Key Pair Generation", function() {
    $keyPair = KeyPair::generate();
    return !empty($keyPair->getPrivateKey()) && 
           !empty($keyPair->getPublicKey()) && 
           !empty($keyPair->getAddress()) &&
           strlen($keyPair->getPrivateKey()) === 64 &&
           str_starts_with($keyPair->getAddress(), '0x');
});

test("Message Signing", function() {
    $keyPair = KeyPair::generate();
    $message = "Test message";
    $signature = Signature::sign($message, $keyPair->getPrivateKey());
    return !empty($signature) && strlen($signature) === 128;
});

test("Transaction Creation", function() {
    $alice = KeyPair::generate();
    $bob = KeyPair::generate();
    $tx = new Transaction($alice->getAddress(), $bob->getAddress(), 100.0, time());
    return $tx->getFrom() === $alice->getAddress() &&
           $tx->getTo() === $bob->getAddress() &&
           $tx->getAmount() === 100.0 &&
           !empty($tx->getHash());
});

test("Multiple Key Generation", function() {
    $addresses = [];
    for ($i = 0; $i < 10; $i++) {
        $keyPair = KeyPair::generate();
        $addresses[] = $keyPair->getAddress();
    }
    return count($addresses) === 10 && 
           count(array_unique($addresses)) === 10;
});

test("Performance Benchmark", function() {
    $startTime = microtime(true);
    
    // Generate 50 key pairs
    for ($i = 0; $i < 50; $i++) {
        KeyPair::generate();
    }
    
    $endTime = microtime(true);
    $executionTime = $endTime - $startTime;
    
    // Should complete in reasonable time (less than 2 seconds)
    return $executionTime < 2.0;
});

// ===========================================
// RESULTS
// ===========================================
echo "\n🎯 TEST RESULTS\n";
echo "===============\n";
echo "Passed: $tests_passed/$tests_total tests\n";

if ($tests_passed === $tests_total) {
    echo "🎉 ALL TESTS PASSED! System is ready for use.\n";
    exit(0);
} else {
    echo "⚠️  Some tests failed. Please check the implementation.\n";
    exit(1);
}
