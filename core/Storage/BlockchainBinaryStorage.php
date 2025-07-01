<?php
declare(strict_types=1);

namespace Blockchain\Core\Storage;

use Exception;
use PDO;

/**
 * Professional Binary Blockchain Storage
 * Single binary file for permanent blockchain storage
 * 
 * Features:
 * - Immutable append-only storage
 * - Binary format with compression
 * - Encryption support
 * - Database synchronization
 * - Node synchronization
 * - Integrity validation
 */
class BlockchainBinaryStorage
{
    private string $binaryFile;
    private string $indexFile;
    private string $encryptionKey;
    private $fileHandle;
    private array $blockIndex = [];
    private bool $readonly;
    private array $config;
    
    // Binary format constants
    const MAGIC_BYTES = 'BLKC';
    const VERSION = 1;
    const BLOCK_HEADER_SIZE = 256;
    const HASH_SIZE = 32;
    const ENCRYPTION_METHOD = 'aes-256-cbc';
    
    public function __construct(string $dataDir, array $config = [], bool $readonly = false)
    {
        $this->binaryFile = $dataDir . '/blockchain.bin';
        $this->indexFile = $dataDir . '/blockchain.idx';
        $this->readonly = $readonly;
        $this->config = $config;
        $this->encryptionKey = $config['blockchain']['encryption_key'] ?? 'default_encryption_key_change_in_production';
        
        $this->initializeFiles();
        $this->loadIndex();
    }
    
    /**
     * Append block after consensus confirmation
     * IMPORTANT: Block is added only ONCE and CANNOT be modified
     */
    public function appendBlock(array $blockData): bool
    {
        if ($this->readonly) {
            throw new Exception('Cannot write to readonly blockchain');
        }
        
        // Validate block data
        if (!$this->validateBlock($blockData)) {
            throw new Exception('Invalid block data');
        }
        
        // Check blockchain integrity
        $lastBlock = $this->getLastBlock();
        if ($lastBlock && $blockData['previous_hash'] !== $lastBlock['hash']) {
            throw new Exception('Blockchain validation failed - invalid previous hash');
        }
        
        // Serialize block to binary format
        $binaryBlock = $this->serializeBlock($blockData);
        $blockSize = strlen($binaryBlock);
        
        // Get write position
        fseek($this->fileHandle, 0, SEEK_END);
        $position = ftell($this->fileHandle);
        
        // Write block (immutable!)
        $written = fwrite($this->fileHandle, $binaryBlock);
        if ($written !== $blockSize) {
            throw new Exception('Failed to write block to blockchain');
        }
        
        fflush($this->fileHandle);
        
        // Update index
        $this->blockIndex[$blockData['index']] = [
            'position' => $position,
            'size' => $blockSize,
            'hash' => $blockData['hash'],
            'timestamp' => $blockData['timestamp'],
            'tx_count' => count($blockData['transactions'] ?? [])
        ];
        
        $this->saveIndex();
        
        return true;
    }
    
    /**
     * Get block by index
     */
    public function getBlock(int $index): ?array
    {
        if (!isset($this->blockIndex[$index])) {
            return null;
        }
        
        $indexData = $this->blockIndex[$index];
        
        fseek($this->fileHandle, $indexData['position']);
        $binaryData = fread($this->fileHandle, $indexData['size']);
        
        return $this->deserializeBlock($binaryData);
    }
    
    /**
     * Get block by hash
     */
    public function getBlockByHash(string $hash): ?array
    {
        foreach ($this->blockIndex as $index => $indexData) {
            if ($indexData['hash'] === $hash) {
                return $this->getBlock($index);
            }
        }
        
        return null;
    }
    
    /**
     * Get last block
     */
    public function getLastBlock(): ?array
    {
        if (empty($this->blockIndex)) {
            return null;
        }
        
        $maxIndex = max(array_keys($this->blockIndex));
        return $this->getBlock($maxIndex);
    }
    
    /**
     * Export binary blockchain to MySQL database
     */
    public function exportToDatabase(PDO $pdo): array
    {
        $stats = ['exported' => 0, 'errors' => 0, 'skipped' => 0];
        
        try {
            $pdo->beginTransaction();
            
            // Clear tables (optional - can be disabled for incremental sync)
            if ($this->config['sync']['clear_db_on_export'] ?? false) {
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
                $pdo->exec('TRUNCATE TABLE blocks');
                $pdo->exec('TRUNCATE TABLE transactions');
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            }
            
            foreach ($this->blockIndex as $index => $indexData) {
                try {
                    $block = $this->getBlock($index);
                    if ($block) {
                        // Check if block already exists
                        $stmt = $pdo->prepare('SELECT id FROM blocks WHERE hash = ?');
                        $stmt->execute([$block['hash']]);
                        
                        if ($stmt->fetchColumn()) {
                            $stats['skipped']++;
                            continue;
                        }
                        
                        $this->insertBlockToDatabase($pdo, $block);
                        $stats['exported']++;
                    }
                } catch (Exception $e) {
                    $stats['errors']++;
                    error_log("Export error for block $index: " . $e->getMessage());
                }
            }
            
            $pdo->commit();
            
        } catch (Exception $e) {
            $pdo->rollBack();
            throw new Exception('Database export failed: ' . $e->getMessage());
        }
        
        return $stats;
    }
    
    /**
     * Import from MySQL database to binary blockchain
     */
    public function importFromDatabase(PDO $pdo): array
    {
        $stats = ['imported' => 0, 'errors' => 0, 'skipped' => 0];
        
        // Get all blocks from database in correct order
        $stmt = $pdo->query('SELECT * FROM blocks ORDER BY height ASC');
        $blocks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($blocks as $blockData) {
            try {
                // Check if block already exists in binary storage
                if (isset($this->blockIndex[$blockData['height']])) {
                    $stats['skipped']++;
                    continue;
                }
                
                // Get block transactions
                $txStmt = $pdo->prepare('SELECT * FROM transactions WHERE block_hash = ? ORDER BY id ASC');
                $txStmt->execute([$blockData['hash']]);
                $transactions = $txStmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Format block data
                $fullBlock = [
                    'index' => $blockData['height'],
                    'hash' => $blockData['hash'],
                    'previous_hash' => $blockData['parent_hash'],
                    'timestamp' => $blockData['timestamp'],
                    'merkle_root' => $blockData['merkle_root'],
                    'validator' => $blockData['validator'],
                    'signature' => $blockData['signature'],
                    'transactions' => $this->formatTransactions($transactions),
                    'metadata' => json_decode($blockData['metadata'] ?? '{}', true)
                ];
                
                $this->appendBlock($fullBlock);
                $stats['imported']++;
                
            } catch (Exception $e) {
                $stats['errors']++;
                error_log("Import error for block {$blockData['height']}: " . $e->getMessage());
            }
        }
        
        return $stats;
    }
    
    /**
     * Validate entire blockchain integrity
     */
    public function validateChain(): array
    {
        $errors = [];
        $warnings = [];
        $lastHash = '0';
        
        foreach ($this->blockIndex as $index => $indexData) {
            $block = $this->getBlock($index);
            
            if (!$block) {
                $errors[] = "Block $index: Cannot read block data";
                continue;
            }
            
            // Check previous hash
            if ($block['previous_hash'] !== $lastHash) {
                $errors[] = "Block $index: Invalid previous hash. Expected: $lastHash, Got: {$block['previous_hash']}";
            }
            
            // Check block hash
            $calculatedHash = $this->calculateBlockHash($block);
            if ($calculatedHash !== $block['hash']) {
                $errors[] = "Block $index: Invalid block hash. Expected: $calculatedHash, Got: {$block['hash']}";
            }
            
            // Check timestamp order
            if ($index > 0) {
                $prevBlock = $this->getBlock($index - 1);
                if ($prevBlock && $block['timestamp'] <= $prevBlock['timestamp']) {
                    $warnings[] = "Block $index: Timestamp not strictly increasing";
                }
            }
            
            // Validate transactions
            $txErrors = $this->validateBlockTransactions($block);
            $errors = array_merge($errors, $txErrors);
            
            $lastHash = $block['hash'];
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'blocks_checked' => count($this->blockIndex)
        ];
    }
    
    /**
     * Get blockchain statistics
     */
    public function getChainStats(): array
    {
        $totalSize = file_exists($this->binaryFile) ? filesize($this->binaryFile) : 0;
        $blockCount = count($this->blockIndex);
        
        $totalTransactions = 0;
        $earliestTime = null;
        $latestTime = null;
        
        foreach ($this->blockIndex as $index => $indexData) {
            $totalTransactions += $indexData['tx_count'] ?? 0;
            
            if ($earliestTime === null || $indexData['timestamp'] < $earliestTime) {
                $earliestTime = $indexData['timestamp'];
            }
            if ($latestTime === null || $indexData['timestamp'] > $latestTime) {
                $latestTime = $indexData['timestamp'];
            }
        }
        
        return [
            'total_blocks' => $blockCount,
            'total_transactions' => $totalTransactions,
            'blockchain_size' => $totalSize,
            'size_formatted' => $this->formatBytes($totalSize),
            'earliest_block_time' => $earliestTime,
            'latest_block_time' => $latestTime,
            'average_block_size' => $blockCount > 0 ? round($totalSize / $blockCount, 2) : 0,
            'index_file_size' => file_exists($this->indexFile) ? filesize($this->indexFile) : 0
        ];
    }
    
    /**
     * Get blockchain statistics (alias)
     */
    public function getStats(): array
    {
        return $this->getChainStats();
    }

    /**
     * Synchronize with network nodes
     */
    public function syncWithNodes(array $nodeUrls): array
    {
        $stats = ['synced_nodes' => 0, 'failed_nodes' => 0, 'new_blocks' => 0];
        
        foreach ($nodeUrls as $nodeUrl) {
            try {
                $nodeStats = $this->getNodeChainStats($nodeUrl);
                if ($nodeStats['total_blocks'] > count($this->blockIndex)) {
                    // Node has more blocks, sync from it
                    $syncResult = $this->syncFromNode($nodeUrl);
                    $stats['new_blocks'] += $syncResult['blocks_synced'];
                }
                $stats['synced_nodes']++;
            } catch (Exception $e) {
                $stats['failed_nodes']++;
                error_log("Failed to sync with node $nodeUrl: " . $e->getMessage());
            }
        }
        
        return $stats;
    }
    
    /**
     * Validate binary file integrity and structure
     */
    public function validateBinaryFile(): array
    {
        $validation = [
            'valid' => false,
            'errors' => [],
            'blocks_count' => 0,
            'file_size' => 0,
            'checksum' => null
        ];
        
        try {
            if (!file_exists($this->binaryFile)) {
                $validation['errors'][] = "Binary file does not exist";
                return $validation;
            }
            
            $validation['file_size'] = filesize($this->binaryFile);
            
            if ($validation['file_size'] === 0) {
                $validation['errors'][] = "Binary file is empty";
                return $validation;
            }
            
            // Open file for validation
            $handle = fopen($this->binaryFile, 'rb');
            if (!$handle) {
                $validation['errors'][] = "Cannot open binary file for reading";
                return $validation;
            }
            
            // Validate magic bytes and version
            $header = fread($handle, 8);
            if (strlen($header) < 8) {
                $validation['errors'][] = "File too small to contain valid header";
                fclose($handle);
                return $validation;
            }
            
            $magic = substr($header, 0, 4);
            $version = unpack('V', substr($header, 4, 4))[1];
            
            if ($magic !== self::MAGIC_BYTES) {
                $validation['errors'][] = "Invalid magic bytes: expected " . self::MAGIC_BYTES . ", got {$magic}";
                fclose($handle);
                return $validation;
            }
            
            if ($version !== self::VERSION) {
                $validation['errors'][] = "Unsupported version: {$version}";
                fclose($handle);
                return $validation;
            }
            
            // Count and validate blocks
            $blocksValidated = 0;
            $position = 8; // After header
            
            while (!feof($handle) && $position < $validation['file_size']) {
                // Read block header
                fseek($handle, $position);
                $blockHeader = fread($handle, self::BLOCK_HEADER_SIZE);
                
                if (strlen($blockHeader) < self::BLOCK_HEADER_SIZE) {
                    break; // End of file
                }
                
                // Parse block header
                $headerData = unpack('V1size/V1timestamp/a32hash/a32prevHash', $blockHeader);
                
                if ($headerData['size'] <= 0 || $headerData['size'] > 10 * 1024 * 1024) {
                    $validation['errors'][] = "Invalid block size at position {$position}: {$headerData['size']}";
                    break;
                }
                
                // Validate block data
                $blockData = fread($handle, $headerData['size']);
                if (strlen($blockData) < $headerData['size']) {
                    $validation['errors'][] = "Incomplete block data at position {$position}";
                    break;
                }
                
                // Validate hash
                $calculatedHash = hash('sha256', $blockData, true);
                if ($calculatedHash !== $headerData['hash']) {
                    $validation['errors'][] = "Block hash mismatch at position {$position}";
                    break;
                }
                
                $blocksValidated++;
                $position += self::BLOCK_HEADER_SIZE + $headerData['size'];
            }
            
            fclose($handle);
            
            $validation['blocks_count'] = $blocksValidated;
            $validation['checksum'] = hash_file('sha256', $this->binaryFile);
            $validation['valid'] = empty($validation['errors']);
            
        } catch (Exception $e) {
            $validation['errors'][] = "Validation exception: " . $e->getMessage();
        }
        
        return $validation;
    }
    
    /**
     * Create integrity checksum for backup validation
     */
    public function createIntegrityChecksum(): array
    {
        $checksums = [
            'file_hash' => null,
            'block_hashes' => [],
            'index_hash' => null,
            'total_blocks' => 0,
            'total_size' => 0
        ];
        
        try {
            // File checksum
            if (file_exists($this->binaryFile)) {
                $checksums['file_hash'] = hash_file('sha256', $this->binaryFile);
                $checksums['total_size'] = filesize($this->binaryFile);
            }
            
            // Index checksum
            if (file_exists($this->indexFile)) {
                $checksums['index_hash'] = hash_file('sha256', $this->indexFile);
            }
            
            // Individual block hashes
            foreach ($this->blockIndex as $blockInfo) {
                $checksums['block_hashes'][] = $blockInfo['hash'];
            }
            
            $checksums['total_blocks'] = count($this->blockIndex);
            
        } catch (Exception $e) {
            error_log("Failed to create integrity checksum: " . $e->getMessage());
        }
        
        return $checksums;
    }
    
    /**
     * Verify integrity against checksum
     */
    public function verifyIntegrity(array $expectedChecksums): array
    {
        $verification = [
            'valid' => false,
            'checks' => [],
            'errors' => []
        ];
        
        try {
            $currentChecksums = $this->createIntegrityChecksum();
            
            // Verify file hash
            $verification['checks']['file_hash'] = [
                'expected' => $expectedChecksums['file_hash'] ?? null,
                'actual' => $currentChecksums['file_hash'],
                'match' => ($expectedChecksums['file_hash'] ?? null) === $currentChecksums['file_hash']
            ];
            
            if (!$verification['checks']['file_hash']['match']) {
                $verification['errors'][] = "File hash mismatch";
            }
            
            // Verify block count
            $verification['checks']['block_count'] = [
                'expected' => $expectedChecksums['total_blocks'] ?? 0,
                'actual' => $currentChecksums['total_blocks'],
                'match' => ($expectedChecksums['total_blocks'] ?? 0) === $currentChecksums['total_blocks']
            ];
            
            if (!$verification['checks']['block_count']['match']) {
                $verification['errors'][] = "Block count mismatch";
            }
            
            // Verify individual block hashes
            $expectedHashes = $expectedChecksums['block_hashes'] ?? [];
            $actualHashes = $currentChecksums['block_hashes'];
            
            $verification['checks']['block_hashes'] = [
                'expected_count' => count($expectedHashes),
                'actual_count' => count($actualHashes),
                'matching_hashes' => 0
            ];
            
            foreach ($expectedHashes as $i => $expectedHash) {
                if (isset($actualHashes[$i]) && $actualHashes[$i] === $expectedHash) {
                    $verification['checks']['block_hashes']['matching_hashes']++;
                } else {
                    $verification['errors'][] = "Block hash mismatch at index {$i}";
                }
            }
            
            $verification['valid'] = empty($verification['errors']);
            
        } catch (Exception $e) {
            $verification['errors'][] = "Verification exception: " . $e->getMessage();
        }
        
        return $verification;
    }
    
    /**
     * Repair corrupted binary file (if possible)
     */
    public function repairBinaryFile(): array
    {
        $repair = [
            'success' => false,
            'actions_taken' => [],
            'recovered_blocks' => 0,
            'lost_blocks' => 0,
            'errors' => []
        ];
        
        try {
            $validation = $this->validateBinaryFile();
            
            if ($validation['valid']) {
                $repair['success'] = true;
                $repair['actions_taken'][] = "File is already valid, no repair needed";
                return $repair;
            }
            
            // Create backup before repair
            $backupFile = $this->binaryFile . '.corrupt.' . time();
            if (file_exists($this->binaryFile)) {
                copy($this->binaryFile, $backupFile);
                $repair['actions_taken'][] = "Created backup: {$backupFile}";
            }
            
            // Try to recover readable blocks
            $recoveredBlocks = $this->extractValidBlocks();
            $repair['recovered_blocks'] = count($recoveredBlocks);
            
            if (empty($recoveredBlocks)) {
                $repair['errors'][] = "No valid blocks could be recovered";
                return $repair;
            }
            
            // Rebuild binary file with recovered blocks
            $this->rebuildBinaryFile($recoveredBlocks);
            $repair['actions_taken'][] = "Rebuilt binary file with {$repair['recovered_blocks']} blocks";
            
            // Revalidate
            $newValidation = $this->validateBinaryFile();
            if ($newValidation['valid']) {
                $repair['success'] = true;
                $repair['actions_taken'][] = "Repair successful - file now valid";
            } else {
                $repair['errors'][] = "Repair failed - file still invalid";
            }
            
        } catch (Exception $e) {
            $repair['errors'][] = "Repair exception: " . $e->getMessage();
        }
        
        return $repair;
    }
    
    /**
     * Create complete backup of binary storage
     */
    public function createBackup(string $backupPath): array
    {
        $backup = [
            'success' => false,
            'files' => [],
            'checksums' => [],
            'size' => 0,
            'errors' => []
        ];
        
        try {
            if (!is_dir($backupPath)) {
                mkdir($backupPath, 0755, true);
            }
            
            // Backup binary file
            if (file_exists($this->binaryFile)) {
                $binaryBackup = $backupPath . '/blockchain.bin';
                copy($this->binaryFile, $binaryBackup);
                $backup['files']['binary'] = $binaryBackup;
                $backup['checksums']['binary'] = hash_file('sha256', $binaryBackup);
                $backup['size'] += filesize($binaryBackup);
            }
            
            // Backup index file
            if (file_exists($this->indexFile)) {
                $indexBackup = $backupPath . '/blockchain.idx';
                copy($this->indexFile, $indexBackup);
                $backup['files']['index'] = $indexBackup;
                $backup['checksums']['index'] = hash_file('sha256', $indexBackup);
                $backup['size'] += filesize($indexBackup);
            }
            
            // Create integrity manifest
            $integrity = $this->createIntegrityChecksum();
            $manifestFile = $backupPath . '/integrity.json';
            file_put_contents($manifestFile, json_encode($integrity, JSON_PRETTY_PRINT));
            $backup['files']['integrity'] = $manifestFile;
            $backup['checksums']['integrity'] = hash_file('sha256', $manifestFile);
            
            $backup['success'] = true;
            
        } catch (Exception $e) {
            $backup['errors'][] = "Backup failed: " . $e->getMessage();
        }
        
        return $backup;
    }
    
    /**
     * Restore from backup
     */
    public function restoreFromBackup(string $backupPath): array
    {
        $restore = [
            'success' => false,
            'actions_taken' => [],
            'errors' => []
        ];
        
        try {
            // Verify backup integrity first
            $integrityFile = $backupPath . '/integrity.json';
            if (!file_exists($integrityFile)) {
                $restore['errors'][] = "Backup integrity file not found";
                return $restore;
            }
            
            $expectedIntegrity = json_decode(file_get_contents($integrityFile), true);
            if (!$expectedIntegrity) {
                $restore['errors'][] = "Invalid backup integrity file";
                return $restore;
            }
            
            // Create backup of current files
            $currentBackup = dirname($this->binaryFile) . '/pre_restore_backup_' . time();
            mkdir($currentBackup, 0755, true);
            
            if (file_exists($this->binaryFile)) {
                copy($this->binaryFile, $currentBackup . '/blockchain.bin');
                $restore['actions_taken'][] = "Backed up current binary file";
            }
            
            if (file_exists($this->indexFile)) {
                copy($this->indexFile, $currentBackup . '/blockchain.idx');
                $restore['actions_taken'][] = "Backed up current index file";
            }
            
            // Restore binary file
            $binaryBackup = $backupPath . '/blockchain.bin';
            if (file_exists($binaryBackup)) {
                copy($binaryBackup, $this->binaryFile);
                $restore['actions_taken'][] = "Restored binary file";
            }
            
            // Restore index file
            $indexBackup = $backupPath . '/blockchain.idx';
            if (file_exists($indexBackup)) {
                copy($indexBackup, $this->indexFile);
                $restore['actions_taken'][] = "Restored index file";
            }
            
            // Reload index
            $this->loadIndex();
            $restore['actions_taken'][] = "Reloaded block index";
            
            // Verify restoration
            $verification = $this->verifyIntegrity($expectedIntegrity);
            if ($verification['valid']) {
                $restore['success'] = true;
                $restore['actions_taken'][] = "Restoration verified successfully";
            } else {
                $restore['errors'][] = "Restoration verification failed";
                $restore['errors'] = array_merge($restore['errors'], $verification['errors']);
            }
            
        } catch (Exception $e) {
            $restore['errors'][] = "Restore failed: " . $e->getMessage();
        }
        
        return $restore;
    }
    
    // Private helper methods for recovery
    
    private function extractValidBlocks(): array
    {
        $validBlocks = [];
        
        try {
            $handle = fopen($this->binaryFile, 'rb');
            if (!$handle) {
                return $validBlocks;
            }
            
            fseek($handle, 8); // Skip header
            
            while (!feof($handle)) {
                $position = ftell($handle);
                $blockHeader = fread($handle, self::BLOCK_HEADER_SIZE);
                
                if (strlen($blockHeader) < self::BLOCK_HEADER_SIZE) {
                    break;
                }
                
                $headerData = unpack('V1size/V1timestamp/a32hash/a32prevHash', $blockHeader);
                
                if ($headerData['size'] <= 0 || $headerData['size'] > 10 * 1024 * 1024) {
                    // Skip invalid block
                    continue;
                }
                
                $blockData = fread($handle, $headerData['size']);
                if (strlen($blockData) < $headerData['size']) {
                    break;
                }
                
                // Validate hash
                $calculatedHash = hash('sha256', $blockData, true);
                if ($calculatedHash === $headerData['hash']) {
                    $validBlocks[] = [
                        'header' => $headerData,
                        'data' => $blockData,
                        'position' => $position
                    ];
                }
            }
            
            fclose($handle);
            
        } catch (Exception $e) {
            error_log("Error extracting valid blocks: " . $e->getMessage());
        }
        
        return $validBlocks;
    }
    
    private function rebuildBinaryFile(array $blocks): void
    {
        $tempFile = $this->binaryFile . '.rebuild.' . time();
        
        $handle = fopen($tempFile, 'wb');
        if (!$handle) {
            throw new Exception("Cannot create rebuild file");
        }
        
        // Write header
        fwrite($handle, self::MAGIC_BYTES);
        fwrite($handle, pack('V', self::VERSION));
        
        // Write blocks
        foreach ($blocks as $block) {
            $header = pack('V1V1a32a32',
                $block['header']['size'],
                $block['header']['timestamp'],
                $block['header']['hash'],
                $block['header']['prevHash']
            );
            
            // Pad header to required size
            $header = str_pad($header, self::BLOCK_HEADER_SIZE, "\0");
            
            fwrite($handle, $header);
            fwrite($handle, $block['data']);
        }
        
        fclose($handle);
        
        // Replace original file
        rename($tempFile, $this->binaryFile);
        
        // Rebuild index
        $this->rebuildIndex();
    }
    
    private function rebuildIndex(): void
    {
        $this->blockIndex = [];
        
        $handle = fopen($this->binaryFile, 'rb');
        if (!$handle) {
            return;
        }
        
        fseek($handle, 8); // Skip header
        $blockId = 0;
        
        while (!feof($handle)) {
            $position = ftell($handle);
            $blockHeader = fread($handle, self::BLOCK_HEADER_SIZE);
            
            if (strlen($blockHeader) < self::BLOCK_HEADER_SIZE) {
                break;
            }
            
            $headerData = unpack('V1size/V1timestamp/a32hash/a32prevHash', $blockHeader);
            
            $this->blockIndex[$blockId] = [
                'position' => $position,
                'size' => $headerData['size'],
                'timestamp' => $headerData['timestamp'],
                'hash' => bin2hex($headerData['hash']),
                'prevHash' => bin2hex($headerData['prevHash'])
            ];
            
            fseek($handle, $position + self::BLOCK_HEADER_SIZE + $headerData['size']);
            $blockId++;
        }
        
        fclose($handle);
        $this->saveIndex();
    }
    
    // Private methods for internal operations
    
    private function initializeFiles(): void
    {
        // Create directory if needed
        $dir = dirname($this->binaryFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        // Open file for read/write
        $mode = $this->readonly ? 'rb' : 'r+b';
        
        if (!file_exists($this->binaryFile)) {
            if ($this->readonly) {
                throw new Exception('Blockchain file does not exist');
            }
            // Create new file with header
            file_put_contents($this->binaryFile, $this->createFileHeader());
        }
        
        $this->fileHandle = fopen($this->binaryFile, $mode);
        if (!$this->fileHandle) {
            throw new Exception('Cannot open blockchain file');
        }
    }
    
    private function createFileHeader(): string
    {
        return pack('A4NNN', self::MAGIC_BYTES, self::VERSION, time(), 0);
    }
    
    private function serializeBlock(array $block): string
    {
        // Convert block to JSON
        $data = json_encode($block, JSON_UNESCAPED_SLASHES);
        
        // Compress data
        $compressed = gzcompress($data, 9);
        
        // Encrypt if enabled
        if ($this->config['blockchain']['encrypt_storage'] ?? false) {
            $compressed = $this->encryptData($compressed);
        }
        
        // Create block header: size + checksum + flags
        $size = strlen($compressed);
        $checksum = crc32($compressed);
        $flags = ($this->config['blockchain']['encrypt_storage'] ?? false) ? 1 : 0;
        
        return pack('NNC', $size, $checksum, $flags) . $compressed;
    }
    
    private function deserializeBlock(string $binaryData): array
    {
        // Read header
        $header = unpack('Nsize/Nchecksum/Cflags', substr($binaryData, 0, 9));
        $compressed = substr($binaryData, 9);
        
        // Verify checksum
        if (crc32($compressed) !== $header['checksum']) {
            throw new Exception('Block data corruption detected');
        }
        
        // Decrypt if needed
        if ($header['flags'] & 1) {
            $compressed = $this->decryptData($compressed);
        }
        
        // Decompress
        $data = gzuncompress($compressed);
        if ($data === false) {
            throw new Exception('Failed to decompress block data');
        }
        
        return json_decode($data, true);
    }
    
    private function encryptData(string $data): string
    {
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($data, self::ENCRYPTION_METHOD, $this->encryptionKey, 0, $iv);
        return $iv . base64_decode($encrypted);
    }
    
    private function decryptData(string $encryptedData): string
    {
        $iv = substr($encryptedData, 0, 16);
        $encrypted = base64_encode(substr($encryptedData, 16));
        return openssl_decrypt($encrypted, self::ENCRYPTION_METHOD, $this->encryptionKey, 0, $iv);
    }
    
    private function validateBlock(array $block): bool
    {
        $required = ['index', 'hash', 'previous_hash', 'timestamp', 'transactions'];
        
        foreach ($required as $field) {
            if (!isset($block[$field])) {
                return false;
            }
        }
        
        // Validate hash format
        if (!preg_match('/^[a-f0-9]{64}$/', $block['hash'])) {
            return false;
        }
        
        // Validate timestamp
        if (!is_numeric($block['timestamp']) || $block['timestamp'] <= 0) {
            return false;
        }
        
        return true;
    }
    
    private function calculateBlockHash(array $block): string
    {
        $data = $block['index'] . 
                $block['timestamp'] . 
                $block['previous_hash'] . 
                ($block['merkle_root'] ?? '') . 
                json_encode($block['transactions']);
        
        return hash('sha256', $data);
    }
    
    private function validateBlockTransactions(array $block): array
    {
        $errors = [];
        
        if (!isset($block['transactions']) || !is_array($block['transactions'])) {
            $errors[] = "Block {$block['index']}: Invalid transactions format";
            return $errors;
        }
        
        foreach ($block['transactions'] as $i => $tx) {
            if (!isset($tx['hash'])) {
                $errors[] = "Block {$block['index']}, Transaction $i: Missing hash";
            }
            
            if (!isset($tx['from_address']) || !isset($tx['to_address'])) {
                $errors[] = "Block {$block['index']}, Transaction $i: Missing address fields";
            }
        }
        
        return $errors;
    }
    
    private function loadIndex(): void
    {
        if (file_exists($this->indexFile)) {
            $data = file_get_contents($this->indexFile);
            $this->blockIndex = json_decode($data, true) ?? [];
        }
    }
    
    private function saveIndex(): void
    {
        file_put_contents($this->indexFile, json_encode($this->blockIndex, JSON_PRETTY_PRINT));
    }
    
    private function insertBlockToDatabase(PDO $pdo, array $block): void
    {
        // Insert block
        $stmt = $pdo->prepare('
            INSERT INTO blocks (height, hash, parent_hash, timestamp, merkle_root, validator, signature, transactions_count, metadata)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        
        $stmt->execute([
            $block['index'],
            $block['hash'],
            $block['previous_hash'],
            $block['timestamp'],
            $block['merkle_root'] ?? '',
            $block['validator'] ?? '',
            $block['signature'] ?? '',
            count($block['transactions']),
            json_encode($block['metadata'] ?? ['imported_from_binary' => true])
        ]);
        
        // Insert transactions
        foreach ($block['transactions'] as $tx) {
            $this->insertTransactionToDatabase($pdo, $tx, $block);
        }
    }
    
    private function insertTransactionToDatabase(PDO $pdo, array $tx, array $block): void
    {
        $stmt = $pdo->prepare('
            INSERT INTO transactions (hash, block_hash, block_height, from_address, to_address, amount, fee, data, signature, status, timestamp)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        
        $stmt->execute([
            $tx['hash'] ?? hash('sha256', json_encode($tx)),
            $block['hash'],
            $block['index'],
            $tx['from_address'] ?? $tx['from'] ?? '',
            $tx['to_address'] ?? $tx['to'] ?? '',
            $tx['amount'] ?? 0,
            $tx['fee'] ?? 0,
            json_encode($tx),
            $tx['signature'] ?? '',
            'confirmed',
            $tx['timestamp'] ?? $block['timestamp']
        ]);
    }
    
    private function formatTransactions(array $transactions): array
    {
        return array_map(function($tx) {
            return [
                'hash' => $tx['hash'],
                'from_address' => $tx['from_address'],
                'to_address' => $tx['to_address'],
                'amount' => (float)$tx['amount'],
                'fee' => (float)$tx['fee'],
                'signature' => $tx['signature'],
                'timestamp' => $tx['timestamp'],
                'data' => json_decode($tx['data'] ?? '{}', true)
            ];
        }, $transactions);
    }
    
    private function getNodeChainStats(string $nodeUrl): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $nodeUrl . '/api/blockchain/stats',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json']
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception("Node returned HTTP $httpCode");
        }
        
        $data = json_decode($response, true);
        if (!$data || !isset($data['success']) || !$data['success']) {
            throw new Exception('Invalid response from node');
        }
        
        return $data['stats'];
    }
    
    private function syncFromNode(string $nodeUrl): array
    {
        // This would implement the actual sync logic
        // For now, return a placeholder
        return ['blocks_synced' => 0];
    }
    
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
    
    public function __destruct()
    {
        if ($this->fileHandle) {
            fclose($this->fileHandle);
        }
    }

    /**
     * Get binary file path
     */
    public function getBinaryFilePath(): string
    {
        return $this->binaryFile;
    }
    
    /**
     * Get block count from binary file
     */
    public function getBlockCount(): int
    {
        return count($this->blockIndex);
    }
}
