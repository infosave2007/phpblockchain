#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Selective Blockchain Sync Manager CLI
 * Синхронизирует только критичные блокчейн-данные
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/core/Storage/BlockchainBinaryStorage.php';
require_once __DIR__ . '/core/Storage/SelectiveBlockchainSyncManager.php';

echo "=== Selective Blockchain Sync Manager ===\n\n";

if ($argc < 2) {
    showHelp();
    exit(1);
}

$command = $argv[1];

try {
    // Load configuration
    $config = loadConfig();
    
    // Initialize database
    $pdo = createDatabaseConnection($config['database']);
    
    // Initialize binary storage
    $binaryStorage = new \Blockchain\Core\Storage\BlockchainBinaryStorage(
        $config['blockchain']['data_dir'] ?? 'storage/blockchain',
        $config['blockchain'] ?? []
    );
    
    // Initialize selective sync manager
    $syncManager = new \Blockchain\Core\Storage\SelectiveBlockchainSyncManager($pdo, $binaryStorage, $config);
    
    switch ($command) {
        case '--export-blockchain':
            $outputFile = $argv[2] ?? 'blockchain_export_' . date('Y-m-d_H-i-s') . '.dat';
            echo "Exporting blockchain data to: $outputFile\n";
            $result = $syncManager->exportBlockchainToFile($outputFile);
            displayResult('Export', $result);
            break;
            
        case '--import-blockchain':
            if (!isset($argv[2])) {
                echo "Error: Import file path required\n";
                exit(1);
            }
            $inputFile = $argv[2];
            echo "Importing blockchain data from: $inputFile\n";
            $result = $syncManager->importBlockchainFromFile($inputFile);
            displayResult('Import', $result);
            break;
            
        case '--sync':
            $direction = $argv[2] ?? 'both';
            echo "Syncing blockchain data (direction: $direction)\n";
            $result = $syncManager->syncBlockchainWithBinary($direction);
            displayResult('Sync', $result);
            break;
            
        case '--validate':
            echo "Validating blockchain data integrity...\n";
            $result = $syncManager->validateBlockchainIntegrity();
            displayValidationResult($result);
            break;
            
        case '--status':
            echo "Checking synchronization status...\n";
            $result = $syncManager->getSyncStatus();
            displayStatusResult($result);
            break;
            
        case '--analyze-tables':
            echo "Analyzing table synchronization strategy...\n";
            analyzeTableStrategy();
            break;
            
        case '--help':
            showHelp();
            break;
            
        default:
            echo "Unknown command: $command\n";
            showHelp();
            exit(1);
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

function showHelp(): void
{
    echo "Selective Blockchain Sync Manager\n";
    echo "Синхронизирует только критичные блокчейн-данные\n\n";
    
    echo "Usage: php selective_sync_manager.php [command] [options]\n\n";
    
    echo "Commands:\n";
    echo "  --export-blockchain [file]    Export blockchain tables to file\n";
    echo "  --import-blockchain <file>    Import blockchain tables from file\n";
    echo "  --sync [direction]           Sync blockchain data (both|db-to-binary|binary-to-db)\n";
    echo "  --validate                   Validate blockchain data integrity\n";
    echo "  --status                     Show synchronization status\n";
    echo "  --analyze-tables             Analyze which tables should be synced\n";
    echo "  --help                       Show this help\n\n";
    
    echo "Table Classification:\n";
    echo "  SYNCED (Global blockchain state):\n";
    echo "    • blocks - blockchain blocks\n";
    echo "    • transactions - transaction records\n";
    echo "    • wallets - account balances\n";
    echo "    • staking - PoS staking data\n";
    echo "    • validators - validator info\n";
    echo "    • smart_contracts - contract data\n\n";
    
    echo "  NOT SYNCED (Local node data):\n";
    echo "    • config - node configuration\n";
    echo "    • nodes - peer node info\n";
    echo "    • mempool - pending transactions\n";
    echo "    • logs - system logs\n";
    echo "    • users - local user accounts\n\n";
    
    echo "Examples:\n";
    echo "  php selective_sync_manager.php --export-blockchain backup.dat\n";
    echo "  php selective_sync_manager.php --sync both\n";
    echo "  php selective_sync_manager.php --validate\n";
}

function loadConfig(): array
{
    $configFile = __DIR__ . '/config/config.php';
    if (file_exists($configFile)) {
        return require $configFile;
    }
    
    // Fallback configuration
    return [
        'database' => [
            'host' => 'localhost',
            'port' => 3306,
            'username' => 'blockchain',
            'password' => 'password',
            'database' => 'blockchain'
        ],
        'blockchain' => [
            'data_dir' => 'storage/blockchain'
        ]
    ];
}

function createDatabaseConnection(array $dbConfig): PDO
{
    $dsn = "mysql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['database']};charset=utf8mb4";
    
    return new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
}

function displayResult(string $operation, array $result): void
{
    echo "\n$operation Results:\n";
    echo "================\n";
    
    foreach ($result as $key => $value) {
        if (is_array($value)) {
            echo "$key:\n";
            foreach ($value as $item) {
                echo "  - $item\n";
            }
        } else {
            echo "$key: $value\n";
        }
    }
    echo "\n";
}

function displayValidationResult(array $result): void
{
    echo "\nValidation Results:\n";
    echo "==================\n";
    
    if ($result['valid']) {
        echo "✅ Blockchain data integrity: VALID\n";
    } else {
        echo "❌ Blockchain data integrity: ISSUES FOUND\n";
    }
    
    echo "Tables checked: {$result['tables_checked']}\n";
    
    if (!empty($result['issues'])) {
        echo "\nIssues found:\n";
        foreach ($result['issues'] as $issue) {
            echo "  ❌ $issue\n";
        }
    }
    
    echo "\nLocal tables (not validated):\n";
    foreach ($result['local_tables_skipped'] as $table) {
        echo "  ℹ️  $table - local only, not part of blockchain\n";
    }
    echo "\n";
}

function displayStatusResult(array $result): void
{
    echo "\nSynchronization Status:\n";
    echo "======================\n";
    
    echo "\n📦 BLOCKCHAIN TABLES (Synced):\n";
    foreach ($result['blockchain_tables'] as $table => $info) {
        $syncStatus = $info['synced_to_binary'] ? '✅' : '❌';
        echo "  $syncStatus $table: {$info['records']} records";
        if ($info['last_update']) {
            echo " (last update: {$info['last_update']})";
        }
        echo "\n";
    }
    
    echo "\n🏠 LOCAL TABLES (Not synced):\n";
    foreach ($result['local_tables'] as $table => $info) {
        if (isset($info['error'])) {
            echo "  ⚠️  $table: {$info['error']}\n";
        } else {
            echo "  ℹ️  $table: {$info['records']} records - {$info['note']}\n";
        }
    }
    echo "\n";
}

function analyzeTableStrategy(): void
{
    echo "\n📊 Table Synchronization Analysis:\n";
    echo "=================================\n";
    
    echo "\n✅ TABLES FOR BINARY SYNC (Global Blockchain State):\n";
    $blockchainTables = [
        'blocks' => 'Core blockchain blocks - must be identical across all nodes',
        'transactions' => 'Transaction records - part of blockchain consensus',
        'wallets' => 'Account balances - global state that affects validation',
        'staking' => 'PoS staking data - critical for consensus mechanism',
        'validators' => 'Validator information - needed for block validation',
        'smart_contracts' => 'Contract code and state - part of blockchain state'
    ];
    
    foreach ($blockchainTables as $table => $reason) {
        echo "  📦 $table\n";
        echo "     → $reason\n";
    }
    
    echo "\n❌ TABLES NOT FOR BINARY SYNC (Local Node Data):\n";
    $localTables = [
        'config' => 'Node-specific configuration - each node has its own settings',
        'nodes' => 'Peer network info - different for each node depending on connections',
        'mempool' => 'Pending transactions - temporary data, not part of blockchain yet',
        'logs' => 'System logs - local debugging/monitoring information',
        'users' => 'User accounts - local authentication, not part of blockchain state'
    ];
    
    foreach ($localTables as $table => $reason) {
        echo "  🏠 $table\n";
        echo "     → $reason\n";
    }
    
    echo "\n💡 RATIONALE:\n";
    echo "   • Blockchain tables contain CONSENSUS-CRITICAL data\n";
    echo "   • Local tables contain NODE-SPECIFIC data\n";
    echo "   • Syncing local tables would cause conflicts between nodes\n";
    echo "   • Each node needs its own configuration and logs\n";
    echo "   • Mempool is temporary and varies between nodes\n";
    
    echo "\n📋 IMPLEMENTATION:\n";
    echo "   1. SelectiveBlockchainSyncManager syncs only blockchain tables\n";
    echo "   2. Local tables remain in database only\n";
    echo "   3. Binary file contains pure blockchain state\n";
    echo "   4. Each node can have different local data\n";
    echo "   5. Network consensus only affects blockchain tables\n";
}
