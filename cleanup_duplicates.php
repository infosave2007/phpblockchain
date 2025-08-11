<?php
/**
 * Clean up duplicate transactions
 */

require_once __DIR__ . '/vendor/autoload.php';

// Load environment
require_once __DIR__ . '/core/Environment/EnvironmentLoader.php';
\Blockchain\Core\Environment\EnvironmentLoader::load(__DIR__);

// Load config
$configFile = __DIR__ . '/config/config.php';
$config = [];
if (file_exists($configFile)) {
    $config = require $configFile;
}

// Database connection
$host = $_ENV['DB_HOST'] ?? $config['database']['host'] ?? 'localhost';
$dbname = $_ENV['DB_NAME'] ?? $config['database']['database'] ?? 'blockchain';
$username = $_ENV['DB_USER'] ?? $config['database']['username'] ?? 'root';
$password = $_ENV['DB_PASS'] ?? $config['database']['password'] ?? '';
$port = $_ENV['DB_PORT'] ?? $config['database']['port'] ?? 3306;

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
    
    echo "Connected to database successfully.\n";
    
    // Find and remove duplicate transactions
    echo "Finding duplicate transactions...\n";
    
    $duplicateQuery = "
        SELECT from_address, to_address, amount, nonce, MIN(id) as keep_id, GROUP_CONCAT(id) as all_ids, COUNT(*) as count
        FROM transactions 
        WHERE from_address != 'genesis' AND from_address != 'system'
        GROUP BY from_address, to_address, amount, nonce 
        HAVING COUNT(*) > 1
        ORDER BY COUNT(*) DESC
    ";
    
    $stmt = $pdo->query($duplicateQuery);
    $duplicates = $stmt->fetchAll();
    
    echo "Found " . count($duplicates) . " groups of duplicate transactions.\n";
    
    $totalRemoved = 0;
    
    foreach ($duplicates as $dup) {
        $allIds = explode(',', $dup['all_ids']);
        $keepId = (int)$dup['keep_id'];
        $removeIds = array_filter($allIds, function($id) use ($keepId) {
            return (int)$id !== $keepId;
        });
        
        echo "Group: {$dup['from_address']} -> {$dup['to_address']} ({$dup['amount']} tokens, nonce {$dup['nonce']})\n";
        echo "  Keeping ID: $keepId\n";
        echo "  Removing IDs: " . implode(', ', $removeIds) . "\n";
        
        if (!empty($removeIds)) {
            $placeholders = str_repeat('?,', count($removeIds) - 1) . '?';
            $deleteStmt = $pdo->prepare("DELETE FROM transactions WHERE id IN ($placeholders)");
            $deleteStmt->execute($removeIds);
            $removed = $deleteStmt->rowCount();
            $totalRemoved += $removed;
            echo "  Removed: $removed transactions\n";
        }
        echo "\n";
    }
    
    echo "Total removed: $totalRemoved duplicate transactions.\n";
    
    // Clean up mempool duplicates too
    echo "\nCleaning mempool duplicates...\n";
    
    $mempoolDuplicateQuery = "
        SELECT from_address, to_address, amount, nonce, MIN(id) as keep_id, GROUP_CONCAT(id) as all_ids, COUNT(*) as count
        FROM mempool 
        GROUP BY from_address, to_address, amount, nonce 
        HAVING COUNT(*) > 1
        ORDER BY COUNT(*) DESC
    ";
    
    $stmt = $pdo->query($mempoolDuplicateQuery);
    $mempoolDuplicates = $stmt->fetchAll();
    
    echo "Found " . count($mempoolDuplicates) . " groups of duplicate mempool transactions.\n";
    
    $mempoolRemoved = 0;
    
    foreach ($mempoolDuplicates as $dup) {
        $allIds = explode(',', $dup['all_ids']);
        $keepId = (int)$dup['keep_id'];
        $removeIds = array_filter($allIds, function($id) use ($keepId) {
            return (int)$id !== $keepId;
        });
        
        if (!empty($removeIds)) {
            $placeholders = str_repeat('?,', count($removeIds) - 1) . '?';
            $deleteStmt = $pdo->prepare("DELETE FROM mempool WHERE id IN ($placeholders)");
            $deleteStmt->execute($removeIds);
            $removed = $deleteStmt->rowCount();
            $mempoolRemoved += $removed;
        }
    }
    
    echo "Total removed from mempool: $mempoolRemoved duplicate transactions.\n";
    
    echo "\nCleanup completed successfully!\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
