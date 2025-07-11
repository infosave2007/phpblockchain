#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Comprehensive Database-Binary Synchronization Tool
 * Handles complete blockchain data sync with schema migrations
 * 
 * Features:
 * - Full database export/import to/from binary files
 * - Schema migration and versioning
 * - Data integrity validation
 * - Conflict resolution
 * - Incremental synchronization
 */

// Include required files
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/core/Storage/BlockchainBinaryStorage.php';
require_once __DIR__ . '/core/Storage/DatabaseSyncManager.php';
require_once __DIR__ . '/config/config.php';

use Blockchain\Core\Storage\BlockchainBinaryStorage;
use Blockchain\Core\Storage\DatabaseSyncManager;

// Colors for terminal output
const COLORS = [
    'reset' => "\033[0m",
    'bold' => "\033[1m",
    'red' => "\033[31m",
    'green' => "\033[32m",
    'yellow' => "\033[33m",
    'blue' => "\033[34m",
    'cyan' => "\033[36m"
];

function colorText(string $text, string $color): string {
    return COLORS[$color] . $text . COLORS['reset'];
}

function printHeader(string $title): void {
    echo "\n" . colorText("=== $title ===", 'bold') . "\n\n";
}

function printSuccess(string $message): void {
    echo colorText("✓ $message", 'green') . "\n";
}

function printError(string $message): void {
    echo colorText("✗ $message", 'red') . "\n";
}

function printWarning(string $message): void {
    echo colorText("⚠ $message", 'yellow') . "\n";
}

function printInfo(string $message): void {
    echo colorText("ℹ $message", 'blue') . "\n";
}

// Command line options
$options = getopt('', [
    'help',
    'export-all:',
    'import-all:',
    'sync:',
    'validate',
    'migrate:',
    'status',
    'backup:',
    'config:'
]);

// Load configuration
$configFile = $options['config'] ?? __DIR__ . '/config/config.php';
if (!file_exists($configFile)) {
    printError("Configuration file not found: $configFile");
    exit(1);
}

$config = require $configFile;

try {
    // Initialize database connection using DatabaseManager
    require_once 'core/Database/DatabaseManager.php';
    $pdo = \Blockchain\Core\Database\DatabaseManager::getConnection();
    
    // Initialize storage systems
    $dataDir = $config['blockchain']['binary_storage']['data_dir'] ?? 'storage/blockchain';
    $binaryStorage = new BlockchainBinaryStorage($dataDir, $config['blockchain']);
    $syncManager = new DatabaseSyncManager($pdo, $binaryStorage, $config);
    
    printHeader("Comprehensive Blockchain Synchronization Tool");
    
    // Show help
    if (isset($options['help']) || empty($options)) {
        showHelp();
        exit(0);
    }
    
    // Export all data to file
    if (isset($options['export-all'])) {
        $outputFile = $options['export-all'];
        printHeader("Exporting Complete Blockchain State");
        
        printInfo("Exporting all database tables and binary blockchain to: $outputFile");
        $stats = $syncManager->exportCompleteStateToFile($outputFile);
        
        if (!empty($stats['errors'])) {
            printError("Export completed with errors:");
            foreach ($stats['errors'] as $error) {
                echo "  - $error\n";
            }
        } else {
            printSuccess("Export completed successfully");
        }
        
        echo "\nExport Statistics:\n";
        echo "  Tables exported: {$stats['tables_exported']}\n";
        echo "  Total records: {$stats['total_records']}\n";
        echo "  File size: " . formatBytes($stats['file_size'] ?? 0) . "\n";
        echo "  Output file: {$stats['export_file']}\n";
    }
    
    // Import all data from file
    if (isset($options['import-all'])) {
        $inputFile = $options['import-all'];
        printHeader("Importing Complete Blockchain State");
        
        if (!file_exists($inputFile)) {
            printError("Import file not found: $inputFile");
            exit(1);
        }
        
        printWarning("This will overwrite existing data. Continue? (y/N)");
        $confirm = readline();
        if (strtolower($confirm) !== 'y') {
            printInfo("Import cancelled");
            exit(0);
        }
        
        printInfo("Importing blockchain state from: $inputFile");
        $stats = $syncManager->importCompleteStateFromFile($inputFile);
        
        if (!empty($stats['errors'])) {
            printError("Import completed with errors:");
            foreach ($stats['errors'] as $error) {
                echo "  - $error\n";
            }
        } else {
            printSuccess("Import completed successfully");
        }
        
        echo "\nImport Statistics:\n";
        echo "  Tables imported: {$stats['tables_imported']}\n";
        echo "  Total records: {$stats['total_records']}\n";
        
        if (!empty($stats['schema_changes'])) {
            printWarning("Schema changes detected:");
            foreach ($stats['schema_changes'] as $change) {
                echo "  - {$change['type']}: {$change['table']}" . 
                     (isset($change['column']) ? ".{$change['column']}" : '') . "\n";
            }
        }
    }
    
    // Synchronization
    if (isset($options['sync'])) {
        $direction = $options['sync'];
        printHeader("Blockchain Data Synchronization");
        
        if (!in_array($direction, ['db-to-binary', 'binary-to-db', 'both'])) {
            printError("Invalid sync direction. Use: db-to-binary, binary-to-db, or both");
            exit(1);
        }
        
        $syncDirection = str_replace('-', '_', $direction);
        printInfo("Starting synchronization: $direction");
        
        $stats = $syncManager->fullSynchronization($syncDirection);
        
        echo "\nSynchronization Results:\n";
        echo "  Direction: {$stats['direction']}\n";
        
        if (isset($stats['db_to_binary'])) {
            echo "  DB → Binary: {$stats['db_to_binary']['records']} records\n";
            if (!empty($stats['db_to_binary']['errors'])) {
                printWarning("DB → Binary errors:");
                foreach ($stats['db_to_binary']['errors'] as $error) {
                    echo "    - $error\n";
                }
            }
        }
        
        if (isset($stats['binary_to_db'])) {
            echo "  Binary → DB: {$stats['binary_to_db']['records']} records\n";
            if (!empty($stats['binary_to_db']['errors'])) {
                printWarning("Binary → DB errors:");
                foreach ($stats['binary_to_db']['errors'] as $error) {
                    echo "    - $error\n";
                }
            }
        }
        
        if ($stats['conflicts_resolved'] > 0) {
            printInfo("Conflicts resolved: {$stats['conflicts_resolved']}");
        }
        
        printSuccess("Synchronization completed");
    }
    
    // Data validation
    if (isset($options['validate'])) {
        printHeader("Data Integrity Validation");
        
        printInfo("Validating blockchain data integrity...");
        $validation = $syncManager->validateDataIntegrity();
        
        if ($validation['valid']) {
            printSuccess("All data integrity checks passed");
        } else {
            printError("Data integrity issues found:");
            foreach ($validation['errors'] as $error) {
                echo "  - $error\n";
            }
        }
        
        if (!empty($validation['warnings'])) {
            printWarning("Warnings:");
            foreach ($validation['warnings'] as $warning) {
                echo "  - $warning\n";
            }
        }
        
        // Show detailed validation results
        if (isset($validation['blockchain_validation'])) {
            echo "\nBlockchain Validation:\n";
            $bv = $validation['blockchain_validation'];
            echo "  Blocks checked: {$bv['blocks_checked']}\n";
            echo "  Valid: " . ($bv['valid'] ? 'Yes' : 'No') . "\n";
        }
        
        if (isset($validation['database_validation'])) {
            echo "\nDatabase Validation:\n";
            $dv = $validation['database_validation'];
            echo "  Valid: " . ($dv['valid'] ? 'Yes' : 'No') . "\n";
        }
        
        if (isset($validation['cross_validation'])) {
            echo "\nCross Validation (Binary ↔ Database):\n";
            $cv = $validation['cross_validation'];
            echo "  Valid: " . ($cv['valid'] ? 'Yes' : 'No') . "\n";
        }
    }
    
    // Schema migration
    if (isset($options['migrate'])) {
        $newVersion = $options['migrate'];
        printHeader("Schema Migration");
        
        printWarning("Schema migration to version $newVersion");
        printWarning("This will modify the database structure. Continue? (y/N)");
        $confirm = readline();
        if (strtolower($confirm) !== 'y') {
            printInfo("Migration cancelled");
            exit(0);
        }
        
        // Load migration scripts (this would be expanded)
        $migrationScripts = [
            "ALTER TABLE blocks ADD COLUMN IF NOT EXISTS consensus_data JSON",
            "ALTER TABLE transactions ADD COLUMN IF NOT EXISTS smart_contract_call JSON",
            "CREATE TABLE IF NOT EXISTS contract_storage (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                contract_address VARCHAR(42) NOT NULL,
                storage_key VARCHAR(64) NOT NULL,
                storage_value TEXT,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_storage (contract_address, storage_key)
            )"
        ];
        
        $migration = $syncManager->handleSchemaMigration($newVersion, $migrationScripts);
        
        if ($migration['success']) {
            printSuccess("Schema migration completed successfully");
            echo "  Old version: {$migration['old_version']}\n";
            echo "  New version: {$migration['new_version']}\n";
            echo "  Steps executed: " . count($migration['steps_executed']) . "\n";
            
            if (isset($migration['backup_file'])) {
                printInfo("Backup created: {$migration['backup_file']}");
            }
        } else {
            printError("Schema migration failed:");
            foreach ($migration['errors'] as $error) {
                echo "  - $error\n";
            }
            
            if (isset($migration['restored_from_backup']) && $migration['restored_from_backup']) {
                printInfo("Database restored from backup");
            }
        }
    }
    
    // Status information
    if (isset($options['status'])) {
        printHeader("Blockchain Synchronization Status");
        
        // Binary storage stats
        $binaryStats = $binaryStorage->getChainStats();
        echo colorText("Binary Blockchain:", 'cyan') . "\n";
        echo "  Total blocks: {$binaryStats['total_blocks']}\n";
        echo "  Total transactions: {$binaryStats['total_transactions']}\n";
        echo "  File size: {$binaryStats['size_formatted']}\n";
        echo "  Index size: " . formatBytes($binaryStats['index_file_size']) . "\n";
        
        // Database stats
        echo colorText("\nDatabase:", 'cyan') . "\n";
        $tables = ['blocks', 'transactions', 'wallets', 'validators', 'staking', 'smart_contracts'];
        foreach ($tables as $table) {
            $stmt = $pdo->query("SELECT COUNT(*) FROM `$table`");
            $count = $stmt->fetchColumn();
            echo "  $table: $count records\n";
        }
        
        // Sync status
        $stmt = $pdo->prepare('SELECT value FROM config WHERE key_name = ?');
        $stmt->execute(['system.last_sync_timestamp']);
        $lastSync = $stmt->fetchColumn();
        
        echo colorText("\nSync Status:", 'cyan') . "\n";
        echo "  Last sync: " . ($lastSync ? date('Y-m-d H:i:s', (int)$lastSync) : 'Never') . "\n";
        
        // Schema version
        $stmt->execute(['system.schema_version']);
        $schemaVersion = $stmt->fetchColumn();
        echo "  Schema version: " . ($schemaVersion ?: 'Unknown') . "\n";
    }
    
    // Create backup
    if (isset($options['backup'])) {
        $backupPath = $options['backup'];
        printHeader("Creating Blockchain Backup");
        
        printInfo("Creating complete blockchain backup...");
        $stats = $syncManager->exportCompleteStateToFile($backupPath);
        
        if (!empty($stats['errors'])) {
            printError("Backup completed with errors:");
            foreach ($stats['errors'] as $error) {
                echo "  - $error\n";
            }
        } else {
            printSuccess("Backup created successfully");
            echo "  Backup file: $backupPath\n";
            echo "  File size: " . formatBytes($stats['file_size'] ?? 0) . "\n";
            echo "  Tables: {$stats['tables_exported']}\n";
            echo "  Records: {$stats['total_records']}\n";
        }
    }
    
} catch (Exception $e) {
    printError("Error: " . $e->getMessage());
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

function showHelp(): void {
    echo colorText("Comprehensive Blockchain Synchronization Tool", 'bold') . "\n\n";
    echo "This tool manages complete synchronization between database and binary blockchain storage,\n";
    echo "including schema migrations and data integrity validation.\n\n";
    
    echo colorText("USAGE:", 'cyan') . "\n";
    echo "  php sync_manager.php [OPTIONS]\n\n";
    
    echo colorText("OPTIONS:", 'cyan') . "\n";
    echo "  --help                    Show this help message\n";
    echo "  --export-all FILE         Export complete blockchain state to file\n";
    echo "  --import-all FILE         Import complete blockchain state from file\n";
    echo "  --sync DIRECTION          Synchronize data (db-to-binary|binary-to-db|both)\n";
    echo "  --validate                Validate data integrity across all storage\n";
    echo "  --migrate VERSION         Migrate database schema to new version\n";
    echo "  --status                  Show synchronization status and statistics\n";
    echo "  --backup FILE             Create complete blockchain backup\n";
    echo "  --config FILE             Use custom configuration file\n\n";
    
    echo colorText("EXAMPLES:", 'cyan') . "\n";
    echo "  # Export complete blockchain state\n";
    echo "  php sync_manager.php --export-all blockchain_backup.dat\n\n";
    echo "  # Import blockchain state from backup\n";
    echo "  php sync_manager.php --import-all blockchain_backup.dat\n\n";
    echo "  # Synchronize database to binary storage\n";
    echo "  php sync_manager.php --sync db-to-binary\n\n";
    echo "  # Validate all data integrity\n";
    echo "  php sync_manager.php --validate\n\n";
    echo "  # Migrate to new schema version\n";
    echo "  php sync_manager.php --migrate 2.0.0\n\n";
    echo "  # Show current status\n";
    echo "  php sync_manager.php --status\n\n";
    
    echo colorText("TABLES SYNCHRONIZED:", 'cyan') . "\n";
    echo "  • config          - System configuration\n";
    echo "  • users          - User accounts\n";
    echo "  • wallets        - Wallet addresses and balances\n";
    echo "  • validators     - Proof of Stake validators\n";
    echo "  • blocks         - Blockchain blocks\n";
    echo "  • transactions   - Blockchain transactions\n";
    echo "  • staking        - Staking records\n";
    echo "  • smart_contracts - Smart contract data\n";
    echo "  • nodes          - Network nodes\n";
    echo "  • mempool        - Pending transactions\n";
    echo "  • logs           - System logs\n\n";
    
    echo colorText("FEATURES:", 'cyan') . "\n";
    echo "  ✓ Complete blockchain state export/import\n";
    echo "  ✓ Schema versioning and migration support\n";
    echo "  ✓ Data integrity validation\n";
    echo "  ✓ Incremental synchronization\n";
    echo "  ✓ Conflict resolution\n";
    echo "  ✓ Automatic backup creation\n";
    echo "  ✓ Rollback support for failed migrations\n\n";
}

function formatBytes(int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    
    $bytes /= pow(1024, $pow);
    
    return round($bytes, 2) . ' ' . $units[$pow];
}
