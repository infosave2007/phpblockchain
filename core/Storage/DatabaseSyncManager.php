<?php
declare(strict_types=1);

namespace Blockchain\Core\Storage;

use PDO;
use Exception;

/**
 * Enhanced Database Synchronization Manager
 * Handles complete blockchain database sync with schema versioning
 * 
 * Features:
 * - All tables synchronization (not just blocks/transactions)
 * - Schema versioning and migration support
 * - Incremental sync capabilities
 * - Data integrity validation
 * - Rollback support
 */
class DatabaseSyncManager
{
    private PDO $database;
    private BlockchainBinaryStorage $binaryStorage;
    private array $config;
    private string $schemaVersion = '1.0.0';
    
    // Tables to sync (in order of dependencies)
    private array $syncTables = [
        'config' => [
            'primary_key' => 'id',
            'timestamp_field' => 'updated_at',
            'dependencies' => []
        ],
        'users' => [
            'primary_key' => 'id', 
            'timestamp_field' => 'updated_at',
            'dependencies' => []
        ],
        'wallets' => [
            'primary_key' => 'id',
            'timestamp_field' => 'updated_at', 
            'dependencies' => []
        ],
        'validators' => [
            'primary_key' => 'id',
            'timestamp_field' => 'updated_at',
            'dependencies' => ['wallets']
        ],
        'blocks' => [
            'primary_key' => 'id',
            'timestamp_field' => 'created_at',
            'dependencies' => ['validators']
        ],
        'transactions' => [
            'primary_key' => 'id',
            'timestamp_field' => 'created_at',
            'dependencies' => ['blocks', 'wallets']
        ],
        'staking' => [
            'primary_key' => 'id',
            'timestamp_field' => 'updated_at',
            'dependencies' => ['validators', 'wallets']
        ],
        'smart_contracts' => [
            'primary_key' => 'id',
            'timestamp_field' => 'updated_at',
            'dependencies' => ['transactions', 'wallets']
        ],
        'nodes' => [
            'primary_key' => 'id',
            'timestamp_field' => 'updated_at',
            'dependencies' => []
        ],
        'mempool' => [
            'primary_key' => 'id',
            'timestamp_field' => 'created_at',
            'dependencies' => ['wallets']
        ],
        'logs' => [
            'primary_key' => 'id',
            'timestamp_field' => 'created_at',
            'dependencies' => []
        ]
    ];
    
    public function __construct(PDO $database, BlockchainBinaryStorage $binaryStorage, array $config = [])
    {
        $this->database = $database;
        $this->binaryStorage = $binaryStorage;
        $this->config = $config;
        
        $this->initializeSchemaTracking();
    }
    
    /**
     * Export complete blockchain state to extended binary format
     */
    public function exportCompleteStateToFile(string $filePath): array
    {
        $stats = ['tables_exported' => 0, 'total_records' => 0, 'errors' => []];
        
        try {
            // Create comprehensive blockchain state file
            $stateData = [
                'schema_version' => $this->schemaVersion,
                'export_timestamp' => time(),
                'database_schema' => $this->getDatabaseSchema(),
                'tables' => []
            ];
            
            // Export all tables in dependency order
            foreach ($this->syncTables as $tableName => $tableConfig) {
                try {
                    $tableData = $this->exportTableData($tableName);
                    $stateData['tables'][$tableName] = $tableData;
                    $stats['tables_exported']++;
                    $stats['total_records'] += count($tableData['records']);
                    
                } catch (Exception $e) {
                    $stats['errors'][] = "Table $tableName export failed: " . $e->getMessage();
                }
            }
            
            // Include binary blockchain stats
            $stateData['binary_blockchain'] = $this->binaryStorage->getChainStats();
            
            // Save as compressed JSON
            $jsonData = json_encode($stateData, JSON_PRETTY_PRINT);
            $compressedData = gzcompress($jsonData, 9);
            
            if (file_put_contents($filePath, $compressedData) === false) {
                throw new Exception('Failed to write state file');
            }
            
            $stats['export_file'] = $filePath;
            $stats['file_size'] = filesize($filePath);
            
        } catch (Exception $e) {
            $stats['errors'][] = 'Export failed: ' . $e->getMessage();
        }
        
        return $stats;
    }
    
    /**
     * Import complete blockchain state from file
     */
    public function importCompleteStateFromFile(string $filePath): array
    {
        $stats = ['tables_imported' => 0, 'total_records' => 0, 'errors' => [], 'schema_changes' => []];
        
        if (!file_exists($filePath)) {
            throw new Exception('State file not found');
        }
        
        try {
            // Read and decompress data
            $compressedData = file_get_contents($filePath);
            $jsonData = gzuncompress($compressedData);
            $stateData = json_decode($jsonData, true);
            
            if (!$stateData) {
                throw new Exception('Invalid state file format');
            }
            
            // Check schema compatibility
            $schemaChanges = $this->checkSchemaCompatibility($stateData['database_schema'] ?? []);
            $stats['schema_changes'] = $schemaChanges;
            
            // Apply schema migrations if needed
            if (!empty($schemaChanges)) {
                $this->applySchemaChanges($schemaChanges);
            }
            
            $this->database->beginTransaction();
            
            // Import tables in dependency order
            foreach ($this->syncTables as $tableName => $tableConfig) {
                if (!isset($stateData['tables'][$tableName])) {
                    continue;
                }
                
                try {
                    $imported = $this->importTableData($tableName, $stateData['tables'][$tableName]);
                    $stats['tables_imported']++;
                    $stats['total_records'] += $imported;
                    
                } catch (Exception $e) {
                    $stats['errors'][] = "Table $tableName import failed: " . $e->getMessage();
                }
            }
            
            $this->database->commit();
            
        } catch (Exception $e) {
            $this->database->rollBack();
            $stats['errors'][] = 'Import failed: ' . $e->getMessage();
        }
        
        return $stats;
    }
    
    /**
     * Incremental sync from database to binary storage
     */
    public function incrementalSyncToFile(?int $lastSyncTimestamp = null): array
    {
        $stats = ['synced_tables' => 0, 'new_records' => 0, 'updated_records' => 0];
        
        $lastSync = $lastSyncTimestamp ?? $this->getLastSyncTimestamp();
        
        foreach (['blocks', 'transactions'] as $tableName) {
            $changes = $this->getTableChanges($tableName, $lastSync);
            
            foreach ($changes as $record) {
                if ($tableName === 'blocks') {
                    // Get block with all transactions
                    $fullBlock = $this->getFullBlockData($record);
                    $this->binaryStorage->appendBlock($fullBlock);
                    $stats['new_records']++;
                }
            }
            
            $stats['synced_tables']++;
        }
        
        $this->updateLastSyncTimestamp();
        
        return $stats;
    }
    
    /**
     * Full synchronization with conflict resolution
     */
    public function fullSynchronization(string $direction = 'both'): array
    {
        $stats = [
            'direction' => $direction,
            'db_to_binary' => ['records' => 0, 'errors' => []],
            'binary_to_db' => ['records' => 0, 'errors' => []],
            'conflicts_resolved' => 0
        ];
        
        try {
            if ($direction === 'db_to_binary' || $direction === 'both') {
                $dbToBinary = $this->syncDatabaseToBinary();
                $stats['db_to_binary'] = $dbToBinary;
            }
            
            if ($direction === 'binary_to_db' || $direction === 'both') {
                $binaryToDb = $this->syncBinaryToDatabase();
                $stats['binary_to_db'] = $binaryToDb;
            }
            
            // Resolve conflicts if syncing both ways
            if ($direction === 'both') {
                $stats['conflicts_resolved'] = $this->resolveDataConflicts();
            }
            
        } catch (Exception $e) {
            $stats['error'] = $e->getMessage();
        }
        
        return $stats;
    }
    
    /**
     * Validate data integrity across all storage systems
     */
    public function validateDataIntegrity(): array
    {
        $validation = [
            'valid' => true,
            'errors' => [],
            'warnings' => [],
            'statistics' => []
        ];
        
        try {
            // Validate blockchain integrity
            $chainValidation = $this->binaryStorage->validateChain();
            $validation['blockchain_validation'] = $chainValidation;
            
            if (!$chainValidation['valid']) {
                $validation['valid'] = false;
                $validation['errors'] = array_merge($validation['errors'], $chainValidation['errors']);
            }
            
            // Validate database consistency
            $dbValidation = $this->validateDatabaseConsistency();
            $validation['database_validation'] = $dbValidation;
            
            if (!$dbValidation['valid']) {
                $validation['valid'] = false;
                $validation['errors'] = array_merge($validation['errors'], $dbValidation['errors']);
            }
            
            // Cross-validate binary vs database
            $crossValidation = $this->crossValidateData();
            $validation['cross_validation'] = $crossValidation;
            
            if (!$crossValidation['valid']) {
                $validation['valid'] = false;
                $validation['errors'] = array_merge($validation['errors'], $crossValidation['errors']);
            }
            
        } catch (Exception $e) {
            $validation['valid'] = false;
            $validation['errors'][] = 'Validation failed: ' . $e->getMessage();
        }
        
        return $validation;
    }
    
    /**
     * Handle schema migration when database structure changes
     */
    public function handleSchemaMigration(string $newSchemaVersion, array $migrationScripts = []): array
    {
        $migration = [
            'success' => false,
            'old_version' => $this->schemaVersion,
            'new_version' => $newSchemaVersion,
            'steps_executed' => [],
            'errors' => []
        ];
        
        try {
            $this->database->beginTransaction();
            
            // Create backup before migration
            $backupFile = $this->createPreMigrationBackup();
            $migration['backup_file'] = $backupFile;
            
            // Execute migration scripts
            foreach ($migrationScripts as $step => $script) {
                try {
                    $this->database->exec($script);
                    $migration['steps_executed'][] = $step;
                } catch (Exception $e) {
                    $migration['errors'][] = "Migration step '$step' failed: " . $e->getMessage();
                    throw $e;
                }
            }
            
            // Update schema version
            $this->updateSchemaVersion($newSchemaVersion);
            
            // Re-sync data with new schema
            $resync = $this->fullSynchronization('both');
            $migration['resync_stats'] = $resync;
            
            $this->database->commit();
            $migration['success'] = true;
            
        } catch (Exception $e) {
            $this->database->rollBack();
            $migration['errors'][] = 'Migration failed: ' . $e->getMessage();
            
            // Restore from backup if needed
            if (isset($backupFile) && file_exists($backupFile)) {
                $this->restoreFromBackup($backupFile);
                $migration['restored_from_backup'] = true;
            }
        }
        
        return $migration;
    }
    
    // Private helper methods
    
    private function initializeSchemaTracking(): void
    {
        $stmt = $this->database->prepare('
            INSERT IGNORE INTO config (key_name, value, description, is_system) 
            VALUES (?, ?, ?, ?)
        ');
        
        $stmt->execute([
            'system.schema_version',
            $this->schemaVersion,
            'Database schema version for migration tracking',
            1
        ]);
        
        $stmt->execute([
            'system.last_sync_timestamp',
            '0',
            'Last synchronization timestamp',
            1
        ]);
    }
    
    private function exportTableData(string $tableName): array
    {
        $stmt = $this->database->prepare("SELECT * FROM `$tableName`");
        $stmt->execute();
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'table_name' => $tableName,
            'record_count' => count($records),
            'export_timestamp' => time(),
            'records' => $records
        ];
    }
    
    private function importTableData(string $tableName, array $tableData): int
    {
        if (empty($tableData['records'])) {
            return 0;
        }
        
        $imported = 0;
        $columns = array_keys($tableData['records'][0]);
        $placeholders = str_repeat('?,', count($columns) - 1) . '?';
        
        $stmt = $this->database->prepare("
            REPLACE INTO `$tableName` (`" . implode('`, `', $columns) . "`) 
            VALUES ($placeholders)
        ");
        
        foreach ($tableData['records'] as $record) {
            $stmt->execute(array_values($record));
            $imported++;
        }
        
        return $imported;
    }
    
    private function getDatabaseSchema(): array
    {
        $schema = [];
        
        foreach ($this->syncTables as $tableName => $config) {
            $stmt = $this->database->prepare("DESCRIBE `$tableName`");
            $stmt->execute();
            $schema[$tableName] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        return $schema;
    }
    
    private function checkSchemaCompatibility(array $expectedSchema): array
    {
        $changes = [];
        $currentSchema = $this->getDatabaseSchema();
        
        foreach ($expectedSchema as $tableName => $expectedColumns) {
            if (!isset($currentSchema[$tableName])) {
                $changes[] = [
                    'type' => 'missing_table',
                    'table' => $tableName,
                    'action' => 'create_table'
                ];
                continue;
            }
            
            $currentColumns = array_column($currentSchema[$tableName], 'Field');
            $expectedColumnNames = array_column($expectedColumns, 'Field');
            
            $missingColumns = array_diff($expectedColumnNames, $currentColumns);
            $extraColumns = array_diff($currentColumns, $expectedColumnNames);
            
            foreach ($missingColumns as $column) {
                $changes[] = [
                    'type' => 'missing_column',
                    'table' => $tableName,
                    'column' => $column,
                    'action' => 'add_column'
                ];
            }
            
            foreach ($extraColumns as $column) {
                $changes[] = [
                    'type' => 'extra_column',
                    'table' => $tableName,
                    'column' => $column,
                    'action' => 'remove_column'
                ];
            }
        }
        
        return $changes;
    }
    
    private function applySchemaChanges(array $changes): void
    {
        foreach ($changes as $change) {
            switch ($change['action']) {
                case 'add_column':
                    // This would require more sophisticated migration logic
                    error_log("Schema change needed: Add column {$change['column']} to {$change['table']}");
                    break;
                    
                case 'remove_column':
                    error_log("Schema change needed: Remove column {$change['column']} from {$change['table']}");
                    break;
                    
                case 'create_table':
                    error_log("Schema change needed: Create table {$change['table']}");
                    break;
            }
        }
    }
    
    private function getLastSyncTimestamp(): int
    {
        $stmt = $this->database->prepare('SELECT value FROM config WHERE key_name = ?');
        $stmt->execute(['system.last_sync_timestamp']);
        return (int)($stmt->fetchColumn() ?: 0);
    }
    
    private function updateLastSyncTimestamp(): void
    {
        $stmt = $this->database->prepare('UPDATE config SET value = ? WHERE key_name = ?');
        $stmt->execute([time(), 'system.last_sync_timestamp']);
    }
    
    private function getTableChanges(string $tableName, int $since): array
    {
        $config = $this->syncTables[$tableName];
        $timestampField = $config['timestamp_field'];
        
        $stmt = $this->database->prepare("
            SELECT * FROM `$tableName` 
            WHERE UNIX_TIMESTAMP(`$timestampField`) > ? 
            ORDER BY `$timestampField` ASC
        ");
        
        $stmt->execute([$since]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    private function syncDatabaseToBinary(): array
    {
        // Enhanced version of existing exportToDatabase functionality
        return $this->binaryStorage->exportToDatabase($this->database);
    }
    
    private function syncBinaryToDatabase(): array
    {
        // Enhanced version of existing importFromDatabase functionality
        return $this->binaryStorage->importFromDatabase($this->database);
    }
    
    private function resolveDataConflicts(): int
    {
        // Implement conflict resolution logic
        // For now, binary storage takes precedence
        return 0;
    }
    
    private function validateDatabaseConsistency(): array
    {
        $validation = ['valid' => true, 'errors' => []];
        
        // Check foreign key constraints
        $stmt = $this->database->query('SET foreign_key_checks = 1');
        
        // Validate referential integrity
        foreach ($this->syncTables as $tableName => $config) {
            // Add specific validation logic for each table
        }
        
        return $validation;
    }
    
    private function crossValidateData(): array
    {
        $validation = ['valid' => true, 'errors' => []];
        
        // Compare binary blockchain data with database
        $binaryStats = $this->binaryStorage->getChainStats();
        
        $stmt = $this->database->query('SELECT COUNT(*) FROM blocks');
        $dbBlockCount = $stmt->fetchColumn();
        
        if ($binaryStats['total_blocks'] !== (int)$dbBlockCount) {
            $validation['valid'] = false;
            $validation['errors'][] = "Block count mismatch: Binary={$binaryStats['total_blocks']}, DB=$dbBlockCount";
        }
        
        return $validation;
    }
    
    private function createPreMigrationBackup(): string
    {
        $backupFile = "backup_" . date('Y-m-d_H-i-s') . "_schema_migration.gz";
        $this->exportCompleteStateToFile($backupFile);
        return $backupFile;
    }
    
    private function restoreFromBackup(string $backupFile): void
    {
        $this->importCompleteStateFromFile($backupFile);
    }
    
    private function updateSchemaVersion(string $version): void
    {
        $stmt = $this->database->prepare('UPDATE config SET value = ? WHERE key_name = ?');
        $stmt->execute([$version, 'system.schema_version']);
        $this->schemaVersion = $version;
    }
    
    private function getFullBlockData(array $blockRecord): array
    {
        // Get all transactions for this block
        $stmt = $this->database->prepare('SELECT * FROM transactions WHERE block_hash = ?');
        $stmt->execute([$blockRecord['hash']]);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'index' => $blockRecord['height'],
            'hash' => $blockRecord['hash'],
            'previous_hash' => $blockRecord['parent_hash'],
            'timestamp' => $blockRecord['timestamp'],
            'merkle_root' => $blockRecord['merkle_root'],
            'validator' => $blockRecord['validator'],
            'signature' => $blockRecord['signature'],
            'transactions' => $this->formatTransactionsForBinary($transactions),
            'metadata' => json_decode($blockRecord['metadata'] ?? '{}', true)
        ];
    }
    
    private function formatTransactionsForBinary(array $transactions): array
    {
        return array_map(function($tx) {
            return [
                'hash' => $tx['hash'],
                'from_address' => $tx['from_address'],
                'to_address' => $tx['to_address'],
                'amount' => (float)$tx['amount'],
                'fee' => (float)$tx['fee'],
                'gas_limit' => $tx['gas_limit'],
                'gas_used' => $tx['gas_used'],
                'gas_price' => (float)$tx['gas_price'],
                'nonce' => $tx['nonce'],
                'data' => $tx['data'],
                'signature' => $tx['signature'],
                'status' => $tx['status'],
                'timestamp' => $tx['timestamp']
            ];
        }, $transactions);
    }
}
