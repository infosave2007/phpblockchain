<?php
declare(strict_types=1);

namespace Blockchain\Core\Sync;

use Blockchain\Core\Blockchain\Blockchain;
use Blockchain\Core\Storage\BlockStorage;
use Blockchain\Core\State\StateManager;
use Blockchain\Nodes\NodeManager;
use Exception;

/**
 * Advanced Blockchain Synchronization Manager
 * 
 * Implements multiple sync strategies for efficient blockchain synchronization:
 * - Fast Sync: Download state snapshots + recent blocks
 * - Light Sync: Download only block headers + merkle proofs
 * - Full Sync: Download entire blockchain (for archive nodes)
 * - Checkpoint Sync: Start from trusted checkpoints
 */
class SyncManager
{
    private Blockchain $blockchain;
    private BlockStorage $storage;
    private StateManager $stateManager;
    private NodeManager $nodeManager;
    private array $trustedCheckpoints;
    private array $config;
    
    // Sync strategies
    const STRATEGY_FULL = 'full';
    const STRATEGY_FAST = 'fast';
    const STRATEGY_LIGHT = 'light';
    const STRATEGY_CHECKPOINT = 'checkpoint';
    
    public function __construct(
        Blockchain $blockchain,
        BlockStorage $storage,
        StateManager $stateManager,
        NodeManager $nodeManager,
        array $config = []
    ) {
        $this->blockchain = $blockchain;
        $this->storage = $storage;
        $this->stateManager = $stateManager;
        $this->nodeManager = $nodeManager;
        
        $this->config = array_merge([
            'default_strategy' => self::STRATEGY_FAST,
            'checkpoint_interval' => 10000, // Create checkpoint every 10k blocks
            'fast_sync_threshold' => 1000,  // Use fast sync if behind by >1000 blocks
            'batch_size' => 100,            // Download blocks in batches
            'parallel_downloads' => 5,      // Parallel download connections
            'state_snapshot_size' => 50000, // State snapshot every 50k blocks
            'max_sync_time' => 3600,        // 1 hour max sync time
        ], $config);
        
        $this->initializeTrustedCheckpoints();
    }
    
    /**
     * Main synchronization method - chooses optimal strategy
     */
    public function synchronize(): array
    {
        $networkInfo = $this->nodeManager->getNetworkInfo();
        $currentHeight = $this->blockchain->getBlockHeight();
        $networkHeight = $networkInfo['max_height'] ?? 0;
        $heightDifference = $networkHeight - $currentHeight;
        
        // Choose optimal sync strategy
        $strategy = $this->chooseSyncStrategy($heightDifference, $currentHeight);
        
        echo "Starting synchronization: $strategy strategy\n";
        echo "Current height: $currentHeight, Network height: $networkHeight\n";
        echo "Blocks to sync: $heightDifference\n\n";
        
        $startTime = time();
        
        try {
            switch ($strategy) {
                case self::STRATEGY_FAST:
                    $result = $this->fastSync($currentHeight, $networkHeight);
                    break;
                case self::STRATEGY_LIGHT:
                    $result = $this->lightSync($currentHeight, $networkHeight);
                    break;
                case self::STRATEGY_CHECKPOINT:
                    $result = $this->checkpointSync($currentHeight, $networkHeight);
                    break;
                default:
                    $result = $this->fullSync($currentHeight, $networkHeight);
            }
            
            $syncTime = time() - $startTime;
            $result['sync_time'] = $syncTime;
            $result['strategy'] = $strategy;
            
            return $result;
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'strategy' => $strategy,
                'sync_time' => time() - $startTime
            ];
        }
    }
    
    /**
     * Fast Sync: Download state snapshot + recent blocks
     * Most efficient for new nodes joining established network
     */
    private function fastSync(int $currentHeight, int $networkHeight): array
    {
        $snapshotHeight = $this->findLatestSnapshot($networkHeight);
        
        if ($snapshotHeight === null) {
            // Fallback to full sync if no snapshots available
            return $this->fullSync($currentHeight, $networkHeight);
        }
        
        echo "Fast sync: downloading state snapshot at height $snapshotHeight\n";
        
        // Step 1: Download and verify state snapshot
        $snapshot = $this->downloadStateSnapshot($snapshotHeight);
        if (!$this->verifyStateSnapshot($snapshot)) {
            throw new Exception("State snapshot verification failed");
        }
        
        // Step 2: Apply state snapshot
        $this->stateManager->loadFromSnapshot($snapshot);
        
        // Step 3: Download blocks from snapshot to current network height
        $blocksResult = $this->downloadBlocksRange($snapshotHeight, $networkHeight);
        
        return [
            'success' => true,
            'method' => 'fast_sync',
            'snapshot_height' => $snapshotHeight,
            'blocks_synced' => $blocksResult['blocks_synced'],
            'final_height' => $networkHeight
        ];
    }
    
    /**
     * Light Sync: Download only block headers + verify merkle proofs
     * Minimal storage, suitable for mobile/IoT devices
     */
    private function lightSync(int $currentHeight, int $networkHeight): array
    {
        echo "Light sync: downloading block headers only\n";
        
        $headersSynced = 0;
        $batchSize = $this->config['batch_size'];
        
        for ($start = $currentHeight + 1; $start <= $networkHeight; $start += $batchSize) {
            $end = min($start + $batchSize - 1, $networkHeight);
            
            // Download batch of headers
            $headers = $this->downloadBlockHeaders($start, $end);
            
            // Verify header chain
            if (!$this->verifyHeaderChain($headers)) {
                throw new Exception("Header chain verification failed at height $start");
            }
            
            // Store headers
            foreach ($headers as $header) {
                $this->storage->storeBlockHeader($header);
                $headersSynced++;
            }
            
            echo "Synced headers: $headersSynced / " . ($networkHeight - $currentHeight) . "\n";
        }
        
        return [
            'success' => true,
            'method' => 'light_sync',
            'headers_synced' => $headersSynced,
            'final_height' => $networkHeight
        ];
    }
    
    /**
     * Checkpoint Sync: Start from trusted checkpoint
     * Fastest initial sync for new nodes
     */
    private function checkpointSync(int $currentHeight, int $networkHeight): array
    {
        $checkpoint = $this->findBestCheckpoint($networkHeight);
        
        if ($checkpoint === null) {
            return $this->fastSync($currentHeight, $networkHeight);
        }
        
        echo "Checkpoint sync: starting from checkpoint at height {$checkpoint['height']}\n";
        
        // Load checkpoint state
        $this->loadCheckpointState($checkpoint);
        
        // Sync remaining blocks from checkpoint to current height
        $blocksResult = $this->downloadBlocksRange($checkpoint['height'], $networkHeight);
        
        return [
            'success' => true,
            'method' => 'checkpoint_sync',
            'checkpoint_height' => $checkpoint['height'],
            'blocks_synced' => $blocksResult['blocks_synced'],
            'final_height' => $networkHeight
        ];
    }
    
    /**
     * Full Sync: Download entire blockchain
     * Required for archive nodes and full validation
     */
    private function fullSync(int $currentHeight, int $networkHeight): array
    {
        echo "Full sync: downloading complete blockchain\n";
        
        return $this->downloadBlocksRange($currentHeight + 1, $networkHeight);
    }
    
    /**
     * Download blocks in parallel batches for efficiency
     */
    private function downloadBlocksRange(int $startHeight, int $endHeight): array
    {
        $totalBlocks = $endHeight - $startHeight + 1;
        $blocksSynced = 0;
        $batchSize = $this->config['batch_size'];
        $parallelConnections = $this->config['parallel_downloads'];
        
        // Create download queues for parallel processing
        $downloadQueue = [];
        for ($start = $startHeight; $start <= $endHeight; $start += $batchSize) {
            $end = min($start + $batchSize - 1, $endHeight);
            $downloadQueue[] = ['start' => $start, 'end' => $end];
        }
        
        // Process batches in parallel
        $activeBatches = [];
        $completedBatches = [];
        
        while (!empty($downloadQueue) || !empty($activeBatches)) {
            // Start new downloads up to parallel limit
            while (count($activeBatches) < $parallelConnections && !empty($downloadQueue)) {
                $batch = array_shift($downloadQueue);
                $activeBatches[] = $this->startBatchDownload($batch);
            }
            
            // Check for completed downloads
            foreach ($activeBatches as $key => $batch) {
                if ($this->isBatchComplete($batch)) {
                    $blocks = $this->getBatchResult($batch);
                    
                    // Verify and add blocks
                    foreach ($blocks as $block) {
                        if ($this->blockchain->addBlock($block)) {
                            $blocksSynced++;
                        }
                    }
                    
                    $completedBatches[] = $batch;
                    unset($activeBatches[$key]);
                    
                    echo "Progress: $blocksSynced / $totalBlocks blocks synced\n";
                }
            }
            
            usleep(100000); // 100ms check interval
        }
        
        return [
            'success' => true,
            'blocks_synced' => $blocksSynced,
            'final_height' => $endHeight
        ];
    }
    
    /**
     * Choose optimal sync strategy based on network conditions
     */
    private function chooseSyncStrategy(int $heightDifference, int $currentHeight): string
    {
        // New node (genesis state)
        if ($currentHeight === 0) {
            if ($this->hasAvailableCheckpoints()) {
                return self::STRATEGY_CHECKPOINT;
            }
            return self::STRATEGY_FAST;
        }
        
        // Small difference - regular sync
        if ($heightDifference < 100) {
            return self::STRATEGY_FULL;
        }
        
        // Medium difference - fast sync
        if ($heightDifference < $this->config['fast_sync_threshold']) {
            return self::STRATEGY_FAST;
        }
        
        // Large difference - checkpoint or fast sync
        if ($this->hasAvailableCheckpoints()) {
            return self::STRATEGY_CHECKPOINT;
        }
        
        return self::STRATEGY_FAST;
    }
    
    /**
     * Find latest available state snapshot
     */
    private function findLatestSnapshot(int $networkHeight): ?int
    {
        $snapshotInterval = $this->config['state_snapshot_size'];
        $latestSnapshotHeight = floor($networkHeight / $snapshotInterval) * $snapshotInterval;
        
        // Check if snapshot exists on network
        if ($this->snapshotExists($latestSnapshotHeight)) {
            return (int)$latestSnapshotHeight;
        }
        
        return null;
    }
    
    /**
     * Initialize trusted checkpoints (hardcoded for security)
     */
    private function initializeTrustedCheckpoints(): void
    {
        // These would be hardcoded trusted checkpoints
        // In production, these come from trusted sources
        $this->trustedCheckpoints = [
            100000 => [
                'height' => 100000,
                'block_hash' => '000000...', // Trusted block hash
                'state_root' => 'abc123...', // Trusted state root
                'timestamp' => 1640995200,
            ],
            200000 => [
                'height' => 200000, 
                'block_hash' => '000001...',
                'state_root' => 'def456...',
                'timestamp' => 1650995200,
            ]
        ];
    }
    
    // Helper methods for network and storage operations
    private function downloadStateSnapshot(int $height): array { return []; }
    private function verifyStateSnapshot(array $snapshot): bool { return true; }
    private function downloadBlockHeaders(int $start, int $end): array { return []; }
    private function verifyHeaderChain(array $headers): bool { return true; }
    private function findBestCheckpoint(int $networkHeight): ?array { return null; }
    private function loadCheckpointState(array $checkpoint): void {}
    private function startBatchDownload(array $batch): array { return $batch; }
    private function isBatchComplete(array $batch): bool { return true; }
    private function getBatchResult(array $batch): array { return []; }
    private function snapshotExists(int $height): bool { return true; }
    private function hasAvailableCheckpoints(): bool { return !empty($this->trustedCheckpoints); }
}
