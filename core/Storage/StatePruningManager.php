<?php
declare(strict_types=1);

namespace Blockchain\Core\Storage;

use Exception;
use Psr\Log\LoggerInterface;

/**
 * State Pruning Manager
 * Manages blockchain state pruning to prevent unlimited growth
 */
class StatePruningManager
{
    private LoggerInterface $logger;
    private string $dataDir;
    private array $config;
    private int $pruningInterval;
    private int $keepBlocks;
    private int $archiveInterval;

    public function __construct(
        LoggerInterface $logger,
        string $dataDir,
        array $config = []
    ) {
        $this->logger = $logger;
        $this->dataDir = $dataDir;
        $this->config = array_merge($this->getDefaultConfig(), $config);
        
        $this->pruningInterval = $this->config['pruning_interval']; // blocks
        $this->keepBlocks = $this->config['keep_blocks']; // number of recent blocks to keep
        $this->archiveInterval = $this->config['archive_interval']; // blocks between archives
    }

    /**
     * Execute pruning process
     */
    public function executePruning(int $currentBlockHeight): array
    {
        $startTime = microtime(true);
        $results = [
            'blocks_pruned' => 0,
            'transactions_pruned' => 0,
            'state_entries_pruned' => 0,
            'space_freed' => 0,
            'duration' => 0,
            'errors' => []
        ];

        try {
            $this->logger->info("Starting pruning process", [
                'current_height' => $currentBlockHeight,
                'keep_blocks' => $this->keepBlocks
            ]);

            // Only prune if we have enough blocks
            if ($currentBlockHeight < $this->keepBlocks + $this->pruningInterval) {
                $this->logger->info("Not enough blocks for pruning", [
                    'current_height' => $currentBlockHeight,
                    'minimum_required' => $this->keepBlocks + $this->pruningInterval
                ]);
                return $results;
            }

            // Calculate pruning range
            $pruneFromBlock = max(0, $currentBlockHeight - $this->keepBlocks - $this->pruningInterval);
            $pruneToBlock = $currentBlockHeight - $this->keepBlocks;

            $this->logger->info("Pruning range calculated", [
                'prune_from' => $pruneFromBlock,
                'prune_to' => $pruneToBlock
            ]);

            // Create archive before pruning
            if ($this->shouldCreateArchive($currentBlockHeight)) {
                $this->createArchive($pruneFromBlock, $pruneToBlock);
            }

            // Prune old blocks
            $results['blocks_pruned'] = $this->pruneBlocks($pruneFromBlock, $pruneToBlock);

            // Prune old transactions
            $results['transactions_pruned'] = $this->pruneTransactions($pruneFromBlock, $pruneToBlock);

            // Prune old state entries
            $results['state_entries_pruned'] = $this->pruneStateEntries($pruneFromBlock, $pruneToBlock);

            // Calculate space freed
            $results['space_freed'] = $this->calculateSpaceFreed();

            // Compact database
            $this->compactDatabase();

            // Update pruning metadata
            $this->updatePruningMetadata($currentBlockHeight, $pruneToBlock);

            $results['duration'] = microtime(true) - $startTime;

            $this->logger->info("Pruning completed successfully", $results);

        } catch (Exception $e) {
            $results['errors'][] = $e->getMessage();
            $this->logger->error("Pruning failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }

        return $results;
    }

    /**
     * Check if pruning is needed
     */
    public function isPruningNeeded(int $currentBlockHeight): bool
    {
        $metadata = $this->getPruningMetadata();
        $lastPrunedHeight = $metadata['last_pruned_height'] ?? 0;
        
        return ($currentBlockHeight - $lastPrunedHeight) >= $this->pruningInterval;
    }

    /**
     * Create archive of data before pruning
     */
    private function createArchive(int $fromBlock, int $toBlock): void
    {
        $archiveDir = $this->dataDir . '/archives';
        if (!is_dir($archiveDir)) {
            mkdir($archiveDir, 0755, true);
        }

        $archiveFile = $archiveDir . "/blocks_{$fromBlock}_{$toBlock}_" . date('Y-m-d_H-i-s') . '.tar.gz';

        $this->logger->info("Creating archive", [
            'archive_file' => $archiveFile,
            'from_block' => $fromBlock,
            'to_block' => $toBlock
        ]);

        // Create tar.gz archive of blocks being pruned
        $blockFiles = [];
        for ($i = $fromBlock; $i <= $toBlock; $i++) {
            $blockFile = $this->dataDir . "/blocks/block_{$i}.json";
            if (file_exists($blockFile)) {
                $blockFiles[] = $blockFile;
            }
        }

        if (!empty($blockFiles)) {
            $this->createTarArchive($archiveFile, $blockFiles);
        }
    }

    /**
     * Prune old blocks
     */
    private function pruneBlocks(int $fromBlock, int $toBlock): int
    {
        $prunedCount = 0;
        $blocksDir = $this->dataDir . '/blocks';

        for ($i = $fromBlock; $i <= $toBlock; $i++) {
            $blockFile = $blocksDir . "/block_{$i}.json";
            
            if (file_exists($blockFile)) {
                if (unlink($blockFile)) {
                    $prunedCount++;
                } else {
                    $this->logger->warning("Failed to delete block file", ['file' => $blockFile]);
                }
            }
        }

        $this->logger->info("Blocks pruned", ['count' => $prunedCount]);
        return $prunedCount;
    }

    /**
     * Prune old transactions
     */
    private function pruneTransactions(int $fromBlock, int $toBlock): int
    {
        $prunedCount = 0;
        
        // This would connect to your transaction storage system
        // For file-based storage:
        $transactionsDir = $this->dataDir . '/transactions';
        
        if (is_dir($transactionsDir)) {
            $files = glob($transactionsDir . '/tx_*.json');
            
            foreach ($files as $file) {
                // Extract block number from transaction file
                // This depends on your file naming convention
                $blockNumber = $this->extractBlockNumberFromTxFile($file);
                
                if ($blockNumber >= $fromBlock && $blockNumber <= $toBlock) {
                    if (unlink($file)) {
                        $prunedCount++;
                    }
                }
            }
        }

        $this->logger->info("Transactions pruned", ['count' => $prunedCount]);
        return $prunedCount;
    }

    /**
     * Prune old state entries
     */
    private function pruneStateEntries(int $fromBlock, int $toBlock): int
    {
        $prunedCount = 0;
        $stateDir = $this->dataDir . '/state';

        // Prune old state snapshots
        if (is_dir($stateDir)) {
            for ($i = $fromBlock; $i <= $toBlock; $i++) {
                $stateFile = $stateDir . "/state_{$i}.json";
                
                if (file_exists($stateFile)) {
                    if (unlink($stateFile)) {
                        $prunedCount++;
                    }
                }
            }
        }

        // Prune old contract state changes
        $contractStateDir = $stateDir . '/contracts';
        if (is_dir($contractStateDir)) {
            $files = glob($contractStateDir . '/state_*.json');
            
            foreach ($files as $file) {
                $blockNumber = $this->extractBlockNumberFromStateFile($file);
                
                if ($blockNumber >= $fromBlock && $blockNumber <= $toBlock) {
                    if (unlink($file)) {
                        $prunedCount++;
                    }
                }
            }
        }

        $this->logger->info("State entries pruned", ['count' => $prunedCount]);
        return $prunedCount;
    }

    /**
     * Check if archive should be created
     */
    private function shouldCreateArchive(int $currentBlockHeight): bool
    {
        if (!$this->config['create_archives']) {
            return false;
        }

        $metadata = $this->getPruningMetadata();
        $lastArchiveHeight = $metadata['last_archive_height'] ?? 0;
        
        return ($currentBlockHeight - $lastArchiveHeight) >= $this->archiveInterval;
    }

    /**
     * Calculate space freed by pruning
     */
    private function calculateSpaceFreed(): int
    {
        // This is a simple estimation
        // In a real implementation, you would track sizes before/after
        return 0; // Placeholder
    }

    /**
     * Compact database after pruning
     */
    private function compactDatabase(): void
    {
        try {
            // For SQLite databases
            $dbFile = $this->dataDir . '/blockchain.db';
            if (file_exists($dbFile)) {
                $pdo = new \PDO("sqlite:$dbFile");
                $pdo->exec('VACUUM');
                $this->logger->info("Database compacted");
            }
        } catch (Exception $e) {
            $this->logger->warning("Database compaction failed", ['error' => $e->getMessage()]);
        }
    }

    /**
     * Get pruning metadata
     */
    private function getPruningMetadata(): array
    {
        $metadataFile = $this->dataDir . '/pruning_metadata.json';
        
        if (file_exists($metadataFile)) {
            return json_decode(file_get_contents($metadataFile), true) ?? [];
        }
        
        return [];
    }

    /**
     * Update pruning metadata
     */
    private function updatePruningMetadata(int $currentHeight, int $prunedToHeight): void
    {
        $metadata = $this->getPruningMetadata();
        
        $metadata['last_pruned_height'] = $prunedToHeight;
        $metadata['last_pruning_time'] = time();
        $metadata['current_height'] = $currentHeight;
        
        if ($this->shouldCreateArchive($currentHeight)) {
            $metadata['last_archive_height'] = $currentHeight;
        }
        
        $metadataFile = $this->dataDir . '/pruning_metadata.json';
        file_put_contents($metadataFile, json_encode($metadata, JSON_PRETTY_PRINT));
    }

    /**
     * Create tar archive
     */
    private function createTarArchive(string $archiveFile, array $files): void
    {
        if (class_exists('\PharData')) {
            try {
                $archive = new \PharData($archiveFile);
                
                foreach ($files as $file) {
                    $archive->addFile($file, basename($file));
                }
                
                $archive->compress(\Phar::GZ);
                unlink($archiveFile); // Remove uncompressed version
                
            } catch (Exception $e) {
                $this->logger->error("Failed to create archive", [
                    'archive' => $archiveFile,
                    'error' => $e->getMessage()
                ]);
            }
        } else {
            $this->logger->warning("PharData not available, skipping archive creation");
        }
    }

    /**
     * Extract block number from transaction file name
     */
    private function extractBlockNumberFromTxFile(string $filename): int
    {
        // This depends on your file naming convention
        // Example: tx_12345_67890.json where 12345 is block number
        if (preg_match('/tx_(\d+)_/', basename($filename), $matches)) {
            return (int)$matches[1];
        }
        return 0;
    }

    /**
     * Extract block number from state file name
     */
    private function extractBlockNumberFromStateFile(string $filename): int
    {
        // Example: state_12345.json where 12345 is block number
        if (preg_match('/state_(\d+)\.json/', basename($filename), $matches)) {
            return (int)$matches[1];
        }
        return 0;
    }

    /**
     * Get default configuration
     */
    private function getDefaultConfig(): array
    {
        return [
            'pruning_interval' => 1000,    // Prune every 1000 blocks
            'keep_blocks' => 10000,        // Keep last 10000 blocks
            'archive_interval' => 50000,   // Create archive every 50000 blocks
            'create_archives' => true,     // Whether to create archives
            'max_archive_size' => 1024 * 1024 * 1024, // 1GB max archive size
        ];
    }

    /**
     * Get pruning statistics
     */
    public function getPruningStats(): array
    {
        $metadata = $this->getPruningMetadata();
        
        return [
            'last_pruned_height' => $metadata['last_pruned_height'] ?? 0,
            'last_pruning_time' => $metadata['last_pruning_time'] ?? 0,
            'last_archive_height' => $metadata['last_archive_height'] ?? 0,
            'pruning_interval' => $this->pruningInterval,
            'keep_blocks' => $this->keepBlocks,
            'archive_interval' => $this->archiveInterval
        ];
    }

    /**
     * Manual pruning trigger
     */
    public function forcePruning(int $currentBlockHeight, int $customKeepBlocks = null): array
    {
        $originalKeepBlocks = $this->keepBlocks;
        
        if ($customKeepBlocks !== null) {
            $this->keepBlocks = $customKeepBlocks;
        }
        
        $results = $this->executePruning($currentBlockHeight);
        
        // Restore original setting
        $this->keepBlocks = $originalKeepBlocks;
        
        return $results;
    }
}
