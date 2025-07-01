<?php
declare(strict_types=1);

namespace Blockchain\Core\Storage;

use PDO;
use Exception;

/**
 * Selective Database Sync Manager
 * Synchronizes only blockchain-critical tables
 * 
 * Synchronized tables (global blockchain state):
 * - blocks, transactions, wallets, staking, validators, smart_contracts, mempool, nodes
 * 
 * Non-synchronized tables (local node data):
 * - config, logs, users
 */
class SelectiveBlockchainSyncManager
{
    private PDO $database;
    private BlockchainBinaryStorage $binaryStorage;
    private array $config;
    
    // Only tables that should be synchronized (global blockchain state)
    private array $blockchainTables = [
        'blocks' => [
            'primary_key' => 'id',
            'hash_field' => 'hash',
            'timestamp_field' => 'timestamp',
            'dependencies' => []
        ],
        'transactions' => [
            'primary_key' => 'id',
            'hash_field' => 'hash',
            'timestamp_field' => 'timestamp',
            'dependencies' => ['blocks']
        ],
        'mempool' => [
            'primary_key' => 'id',
            'hash_field' => 'transaction_hash',
            'timestamp_field' => 'created_at',
            'dependencies' => []
        ],
        'wallets' => [
            'primary_key' => 'id',
            'hash_field' => 'address',
            'timestamp_field' => 'updated_at',
            'dependencies' => []
        ],
        'validators' => [
            'primary_key' => 'id',
            'hash_field' => 'address',
            'timestamp_field' => 'updated_at',
            'dependencies' => ['wallets']
        ],
        'staking' => [
            'primary_key' => 'id',
            'hash_field' => null,
            'timestamp_field' => 'updated_at',
            'dependencies' => ['wallets', 'validators']
        ],
        'smart_contracts' => [
            'primary_key' => 'id',
            'hash_field' => 'address',
            'timestamp_field' => 'updated_at',
            'dependencies' => ['wallets']
        ],
        'nodes' => [
            'primary_key' => 'id',
            'hash_field' => 'node_id',
            'timestamp_field' => 'last_seen',
            'dependencies' => []
        ]
    ];
    
    // Tables that are NOT synchronized (local node data)
    private array $localOnlyTables = [
        'config',     // Node configuration
        'logs',       // System logs
        'users'       // Users of this node
    ];
    
    public function __construct(PDO $database, BlockchainBinaryStorage $binaryStorage, array $config = [])
    {
        $this->database = $database;
        $this->binaryStorage = $binaryStorage;
        $this->config = $config;
    }
    
    /**
     * Export blockchain data to binary file (only critical tables)
     */
    public function exportBlockchainToFile(string $filePath): array
    {
        $stats = [
            'tables_exported' => 0,
            'total_records' => 0,
            'skipped_tables' => [],
            'errors' => []
        ];
        
        try {
            $exportData = [
                'metadata' => [
                    'export_timestamp' => time(),
                    'schema_version' => $this->getSchemaVersion(),
                    'node_info' => $this->getNodeInfo(),
                    'tables_included' => array_keys($this->blockchainTables)
                ],
                'blockchain_data' => []
            ];
            
            // Export only blockchain tables in correct order
            foreach ($this->getTablesSortedByDependencies() as $tableName) {
                $tableConfig = $this->blockchainTables[$tableName];
                
                $stmt = $this->database->prepare("SELECT * FROM {$tableName} ORDER BY {$tableConfig['primary_key']} ASC");
                $stmt->execute();
                $tableData = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $exportData['blockchain_data'][$tableName] = $tableData;
                $stats['total_records'] += count($tableData);
                $stats['tables_exported']++;
            }
            
            // Write to file
            $compressedData = gzcompress(json_encode($exportData), 9);
            if (file_put_contents($filePath, $compressedData) === false) {
                throw new Exception("Failed to write export file: $filePath");
            }
            
            // Log which tables were NOT exported
            $stats['skipped_tables'] = $this->localOnlyTables;
            
        } catch (Exception $e) {
            $stats['errors'][] = $e->getMessage();
        }
        
        return $stats;
    }
    
    /**
     * Import blockchain data from file
     */
    public function importBlockchainFromFile(string $filePath): array
    {
        $stats = [
            'tables_imported' => 0,
            'total_records' => 0,
            'errors' => [],
            'warnings' => []
        ];
        
        try {
            if (!file_exists($filePath)) {
                throw new Exception("Import file not found: $filePath");
            }
            
            $compressedData = file_get_contents($filePath);
            $data = json_decode(gzuncompress($compressedData), true);
            
            if (!$data || !isset($data['blockchain_data'])) {
                throw new Exception("Invalid import file format");
            }
            
            // Check schema compatibility
            $fileSchemaVersion = $data['metadata']['schema_version'] ?? '1.0.0';
            if ($fileSchemaVersion !== $this->getSchemaVersion()) {
                $stats['warnings'][] = "Schema version mismatch. File: $fileSchemaVersion, Current: " . $this->getSchemaVersion();
            }
            
            $this->database->beginTransaction();
            
            // Clear only blockchain tables (DO NOT touch local tables!)
            foreach (array_reverse($this->getTablesSortedByDependencies()) as $tableName) {
                $this->database->exec("SET FOREIGN_KEY_CHECKS = 0");
                $this->database->exec("TRUNCATE TABLE {$tableName}");
                $this->database->exec("SET FOREIGN_KEY_CHECKS = 1");
            }
            
            // Import data
            foreach ($this->getTablesSortedByDependencies() as $tableName) {
                if (!isset($data['blockchain_data'][$tableName])) {
                    $stats['warnings'][] = "Table $tableName not found in import file";
                    continue;
                }
                
                $tableData = $data['blockchain_data'][$tableName];
                $recordsImported = $this->importTableData($tableName, $tableData);
                
                $stats['total_records'] += $recordsImported;
                $stats['tables_imported']++;
            }
            
            $this->database->commit();
            
        } catch (Exception $e) {
            $this->database->rollBack();
            $stats['errors'][] = $e->getMessage();
        }
        
        return $stats;
    }
    
    /**
     * Synchronize only blockchain data with binary file
     */
    public function syncBlockchainWithBinary(string $direction = 'both'): array
    {
        $stats = ['sync_direction' => $direction, 'operations' => []];
        
        try {
            switch ($direction) {
                case 'db-to-binary':
                    $stats['operations'][] = $this->syncDatabaseToBinary();
                    break;
                    
                case 'binary-to-db':
                    $stats['operations'][] = $this->syncBinaryToDatabase();
                    break;
                    
                case 'both':
                    $stats['operations'][] = $this->syncDatabaseToBinary();
                    $stats['operations'][] = $this->syncBinaryToDatabase();
                    break;
                    
                default:
                    throw new Exception("Invalid sync direction: $direction");
            }
            
        } catch (Exception $e) {
            $stats['error'] = $e->getMessage();
        }
        
        return $stats;
    }
    
    /**
     * Validate blockchain data integrity
     */
    public function validateBlockchainIntegrity(): array
    {
        $issues = [];
        $checks = 0;
        
        foreach ($this->blockchainTables as $tableName => $config) {
            try {
                // Check table existence
                $stmt = $this->database->prepare("SHOW TABLES LIKE ?");
                $stmt->execute([$tableName]);
                
                if (!$stmt->fetchColumn()) {
                    $issues[] = "Table $tableName does not exist";
                    continue;
                }
                
                // Check required fields
                $stmt = $this->database->prepare("DESCRIBE $tableName");
                $stmt->execute();
                $columns = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
                
                if (!in_array($config['primary_key'], $columns)) {
                    $issues[] = "Table $tableName missing primary key: {$config['primary_key']}";
                }
                
                if (!in_array($config['timestamp_field'], $columns)) {
                    $issues[] = "Table $tableName missing timestamp field: {$config['timestamp_field']}";
                }
                
                $checks++;
                
            } catch (Exception $e) {
                $issues[] = "Error checking table $tableName: " . $e->getMessage();
            }
        }
        
        return [
            'valid' => empty($issues),
            'tables_checked' => $checks,
            'issues' => $issues,
            'local_tables_skipped' => $this->localOnlyTables
        ];
    }
    
    /**
     * Get synchronization status
     */
    public function getSyncStatus(): array
    {
        $status = [
            'blockchain_tables' => [],
            'local_tables' => [],
            'sync_needed' => false
        ];
        
        // Check blockchain tables
        foreach ($this->blockchainTables as $tableName => $config) {
            $stmt = $this->database->prepare("SELECT COUNT(*) as count, MAX({$config['timestamp_field']}) as last_update FROM $tableName");
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $status['blockchain_tables'][$tableName] = [
                'records' => $result['count'] ?? 0,
                'last_update' => $result['last_update'],
                'synced_to_binary' => true // TODO: check sync with binary file
            ];
        }
        
        // Check local tables (for information)
        foreach ($this->localOnlyTables as $tableName) {
            try {
                $stmt = $this->database->prepare("SELECT COUNT(*) as count FROM $tableName");
                $stmt->execute();
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $status['local_tables'][$tableName] = [
                    'records' => $result['count'] ?? 0,
                    'note' => 'Local only - not synced to binary file'
                ];
            } catch (Exception $e) {
                $status['local_tables'][$tableName] = [
                    'error' => 'Table does not exist or is inaccessible'
                ];
            }
        }
        
        return $status;
    }
    
    // Private methods
    
    private function getTablesSortedByDependencies(): array
    {
        $sorted = [];
        $visited = [];
        
        foreach (array_keys($this->blockchainTables) as $table) {
            $this->sortTableByDependencies($table, $sorted, $visited);
        }
        
        return $sorted;
    }
    
    private function sortTableByDependencies(string $table, array &$sorted, array &$visited): void
    {
        if (in_array($table, $visited)) {
            return;
        }
        
        $visited[] = $table;
        $config = $this->blockchainTables[$table];
        
        foreach ($config['dependencies'] as $dependency) {
            if (isset($this->blockchainTables[$dependency])) {
                $this->sortTableByDependencies($dependency, $sorted, $visited);
            }
        }
        
        if (!in_array($table, $sorted)) {
            $sorted[] = $table;
        }
    }
    
    private function importTableData(string $tableName, array $data): int
    {
        if (empty($data)) {
            return 0;
        }
        
        $firstRow = reset($data);
        $columns = array_keys($firstRow);
        $placeholders = ':' . implode(', :', $columns);
        
        $sql = "INSERT INTO {$tableName} (`" . implode('`, `', $columns) . "`) VALUES ({$placeholders})";
        $stmt = $this->database->prepare($sql);
        
        $imported = 0;
        foreach ($data as $row) {
            if ($stmt->execute($row)) {
                $imported++;
            }
        }
        
        return $imported;
    }
    
    private function syncDatabaseToBinary(): array
    {
        // Synchronize database data with binary file
        return $this->binaryStorage->exportToDatabase($this->database);
    }
    
    private function syncBinaryToDatabase(): array
    {
        // Synchronize binary file with database
        return $this->binaryStorage->importFromDatabase($this->database);
    }
    
    private function getSchemaVersion(): string
    {
        try {
            $stmt = $this->database->prepare("SELECT value FROM config WHERE key_name = 'system.schema_version' LIMIT 1");
            $stmt->execute();
            return $stmt->fetchColumn() ?: '1.0.0';
        } catch (Exception $e) {
            return '1.0.0';
        }
    }
    
    private function getNodeInfo(): array
    {
        return [
            'node_id' => $this->config['node_id'] ?? 'unknown',
            'network' => $this->config['network'] ?? 'mainnet',
            'version' => $this->config['version'] ?? '1.0.0'
        ];
    }
}
