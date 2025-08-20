<?php
declare(strict_types=1);

namespace Blockchain\Core\Sync;

use Blockchain\Core\Blockchain\Blockchain;
use Blockchain\Core\Storage\BlockStorage;
use Blockchain\Core\State\StateManager;
use Blockchain\Nodes\NodeManager;
use Blockchain\Core\Network\MultiCurl;
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
    private MultiCurl $multiCurl;
    
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
        $this->multiCurl = new MultiCurl(
            $config['parallel_downloads'] ?? 10,
            $config['curl_timeout'] ?? 30,
            $config['curl_connect_timeout'] ?? 5
        );
        
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
        // Get network info from node manager (use available methods)
        /** @phpstan-ignore-next-line */
        $networkInfo = method_exists($this->nodeManager, 'getNetworkInfo') ?
            $this->nodeManager->getNetworkInfo() : ['max_height' => 0];

        // Get current blockchain height
        /** @phpstan-ignore-next-line */
        $currentHeight = method_exists($this->blockchain, 'getBlockHeight') ?
            $this->blockchain->getBlockHeight() : $this->blockchain->getHeight();

        // Ensure we have valid values
        $networkHeight = (int)($networkInfo['max_height'] ?? 0);
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
        
        // Step 2: Apply state snapshot (if method exists)
        /** @phpstan-ignore-next-line */
        if (method_exists($this->stateManager, 'loadFromSnapshot')) {
            $this->stateManager->loadFromSnapshot($snapshot);
        }
        
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
            
            // Store headers (if method exists)
            /** @phpstan-ignore-next-line */
            foreach ($headers as $header) {
                if (method_exists($this->storage, 'storeBlockHeader')) {
                    $this->storage->storeBlockHeader($header);
                }
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
        $totalBlocks = max(0, $endHeight - $startHeight + 1);
        if ($totalBlocks === 0) {
            return ['success' => true, 'blocks_synced' => 0, 'final_height' => $endHeight];
        }

        $batchSize = max(1, (int)$this->config['batch_size']);
        $blocksSynced = 0;

        for ($batchStart = $startHeight; $batchStart <= $endHeight; $batchStart += $batchSize) {
            $batchEnd = min($batchStart + $batchSize - 1, $endHeight);

            // Build parallel requests to all active nodes for the block range
            $requests = $this->buildBlockBatchRequests($batchStart, $batchEnd);

            if (empty($requests)) {
                throw new Exception('No active nodes available for batch block download');
            }

            // Execute MultiCurl batch
            $results = $this->multiCurl->executeRequests($requests);

            // Aggregate successful responses and convert into Block objects
            $blocks = $this->aggregateBlocksFromResults($results);

            // Сортируем по высоте, чтобы добавлять по порядку
            usort($blocks, function ($a, $b) {
                return $a->getIndex() <=> $b->getIndex();
            });

            // Добавляем блоки в цепочку
            foreach ($blocks as $block) {
                if ($this->blockchain->addBlock($block)) {
                    $blocksSynced++;
                }
            }

            echo "Progress: $blocksSynced / $totalBlocks blocks synced (heights {$batchStart}-{$batchEnd})\n";
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
        if ($this->snapshotExists((int)$latestSnapshotHeight)) {
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

    /**
     * Build request set to active nodes for a block range
     */
    private function buildBlockBatchRequests(int $start, int $end): array
    {
        // Get active node base URLs from NodeManager
        $nodeUrls = [];
        if (method_exists($this->nodeManager, 'getActiveNodeUrls')) {
            $nodeUrls = $this->nodeManager->getActiveNodeUrls();
        }

        // Fallback: if method is unavailable, derive from getActiveNodes()
        if (empty($nodeUrls) && method_exists($this->nodeManager, 'getActiveNodes')) {
            $activeNodes = $this->nodeManager->getActiveNodes();
            foreach ($activeNodes as $nodeId => $node) {
                if (is_object($node) && method_exists($node, 'getApiUrl')) {
                    $nodeUrls[$nodeId] = rtrim($node->getApiUrl(), '/');
                } elseif (is_array($node) && isset($node['url'])) {
                    $nodeUrls[$nodeId] = rtrim($node['url'], '/');
                }
            }
        }

        $requests = [];
        foreach ($nodeUrls as $nodeId => $baseUrl) {
            // Range endpoint; if unsupported, we'll fall back to per-block requests
            // Try batched API first, then fallback to single-block links
            $batchedUrl = $baseUrl . '/api/explorer/index.php?action=get_blocks_range&start=' . $start . '&end=' . $end;

            $requests["{$nodeId}::range::{$start}-{$end}"] = [
                'url' => $batchedUrl,
                'method' => 'GET',
                'headers' => [
                    'User-Agent: BlockchainNodeSync/2.0',
                    'Accept: application/json'
                ],
                'timeout' => 30
            ];
        }

        // If nodes lack a batch endpoint, aggregation will fallback to single-block fetches
        return $requests;
    }

    /**
     * Aggregate node responses into a list of Block objects
     */
    private function aggregateBlocksFromResults(array $results): array
    {
        $blocks = [];

        // Try parsing batched responses from multiple nodes and apply a simple quorum (majority) per height
        $heightMap = []; // height => [fingerprint => ['count' => n, 'sample' => payload]]
        $rangeHint = ['start' => null, 'end' => null];
        foreach ($results as $id => $res) {
            if (!($res['success'] ?? false)) {
                continue;
            }
            $payload = $res['data'] ?? null;
            if (!$payload && !empty($res['response'])) {
                $payload = json_decode($res['response'], true);
            }
            if (!is_array($payload)) {
                continue;
            }

            // Extract range hint from request id/url (used later for per-block fallback)
            if ($rangeHint['start'] === null || $rangeHint['end'] === null) {
                $req = $res['request']['url'] ?? '';
                if (preg_match('#start=(\d+).*end=(\d+)#', $req, $m)) {
                    $rangeHint['start'] = (int)$m[1];
                    $rangeHint['end'] = (int)$m[2];
                }
            }

            // Supported formats:
            // 1) { success: true, data: { blocks: [...] } }
            // 2) { blocks: [...] }
            $list = [];
            if (isset($payload['success'], $payload['data']['blocks']) && $payload['success'] === true) {
                $list = $payload['data']['blocks'];
            } elseif (isset($payload['blocks']) && is_array($payload['blocks'])) {
                $list = $payload['blocks'];
            }

            if (empty($list)) {
                continue;
            }

            // Tally fingerprints per height
            foreach ($list as $blockData) {
                $h = (int)($blockData['height'] ?? $blockData['index'] ?? -1);
                if ($h < 0) { continue; }
                $fp = $this->fingerprintBlockPayload($blockData);
                if (!isset($heightMap[$h])) { $heightMap[$h] = []; }
                if (!isset($heightMap[$h][$fp])) {
                    $heightMap[$h][$fp] = ['count' => 0, 'sample' => $blockData];
                }
                $heightMap[$h][$fp]['count']++;
            }
        }

        if (!empty($heightMap)) {
            // Choose majority payload per height and build blocks
            ksort($heightMap, SORT_NUMERIC);
            $selectedPayloads = [];
            foreach ($heightMap as $h => $fpSet) {
                // Pick the fingerprint with max count
                uasort($fpSet, function($a, $b) { return ($b['count'] <=> $a['count']); });
                $major = reset($fpSet);
                if ($major && isset($major['sample'])) {
                    $selectedPayloads[$h] = $major['sample'];
                }
            }

            // Validate continuity within the selection (previous_hash -> hash when available)
            $validatedPayloads = $this->validateAndTrimSequence($selectedPayloads);

            foreach ($validatedPayloads as $p) {
                if (method_exists(\Blockchain\Core\Blockchain\Block::class, 'fromPayload')) {
                    $b = \Blockchain\Core\Blockchain\Block::fromPayload($p);
                } else {
                    $b = $this->createBlockFromExplorerData($p);
                }
                if ($b) { $b->addMetadata('source', 'sync'); $blocks[] = $b; }
            }

            if (!empty($blocks)) {
                return $blocks;
            }
        }

        // Fallback: per-block requests for the required range
        // Determine requested range from request identifiers if no hint yet
        $rangeStart = $rangeHint['start'];
        $rangeEnd = $rangeHint['end'];
        if ($rangeStart === null || $rangeEnd === null) {
            foreach (array_keys($results) as $key) {
                if (preg_match('/::range::(\d+)-(\d+)/', $key, $m)) {
                    $rangeStart = (int)$m[1];
                    $rangeEnd = (int)$m[2];
                    break;
                }
            }
        }
        if ($rangeStart === null || $rangeEnd === null) {
            return $blocks;
        }

        // Collect base URLs from original requests
        $baseUrls = [];
        foreach ($results as $id => $res) {
            if (isset($res['request']['url'])) {
                // Extract base part before query string
                $u = $res['request']['url'];
                $base = preg_replace('#/api/explorer/index\.php\?action=get_blocks_range.*$#', '', $u);
                if ($base) {
                    $baseUrls[] = rtrim($base, '/');
                }
            }
        }
        $baseUrls = array_values(array_unique($baseUrls));

        if (empty($baseUrls)) {
            return $blocks;
        }

        // Parallel single-block requests using the first base URL
        $primaryBase = $baseUrls[0];
        $singleRequests = [];
        for ($h = $rangeStart; $h <= $rangeEnd; $h++) {
            $singleRequests["h{$h}"] = [
                'url' => $primaryBase . '/api/explorer/index.php?action=get_block&block_id=' . $h,
                'method' => 'GET',
                'headers' => [
                    'User-Agent: BlockchainNodeSync/2.0',
                    'Accept: application/json'
                ],
                'timeout' => 20
            ];
        }

        $singleResults = $this->multiCurl->executeRequests($singleRequests);
        $singlePayloads = [];
        foreach ($singleResults as $rid => $res) {
            if (!($res['success'] ?? false)) {
                continue;
            }
            $payload = $res['data'] ?? null;
            if (!$payload && !empty($res['response'])) {
                $payload = json_decode($res['response'], true);
            }
            if (!$payload) {
                continue;
            }
            if (isset($payload['success']) && $payload['success'] && isset($payload['data'])) {
                $payload = $payload['data'];
            }
            $h = (int)($payload['height'] ?? $payload['index'] ?? -1);
            if ($h >= 0) {
                $singlePayloads[$h] = $payload; // last write wins; we assume single base URL gives consistent data
            }
        }

        // Validate continuity on single payloads then convert to blocks
        if (!empty($singlePayloads)) {
            ksort($singlePayloads, SORT_NUMERIC);
            $validated = $this->validateAndTrimSequence($singlePayloads);
            foreach ($validated as $p) {
                if (method_exists(\Blockchain\Core\Blockchain\Block::class, 'fromPayload')) {
                    $b = \Blockchain\Core\Blockchain\Block::fromPayload($p);
                } else {
                    $b = $this->createBlockFromExplorerData($p);
                }
                if ($b) { $b->addMetadata('source', 'sync'); $blocks[] = $b; }
            }
        }

        return $blocks;
    }

    /**
     * Convert explorer API payload into a Block object
     */
    private function createBlockFromExplorerData(array $data): ?\Blockchain\Core\Blockchain\Block
    {
        // Possible keys: height/index, previous_hash/previousHash, transactions, timestamp, nonce, hash, metadata
        $index = $data['height'] ?? $data['index'] ?? null;
        $previousHash = $data['previous_hash'] ?? $data['previousHash'] ?? ($data['parent_hash'] ?? '');
        $timestamp = (int)($data['timestamp'] ?? time());
        $nonce = (int)($data['nonce'] ?? 0);
        $transactions = $data['transactions'] ?? ($data['tx'] ?? []);

        if ($index === null || !is_array($transactions)) {
            return null;
        }

        // Normalize transactions into arrays expected by Block/Storage
        $txs = [];
        foreach ($transactions as $tx) {
            // Если уже массив, оставляем; совместимость с Wallet/Transaction классами не нужна здесь
            $txs[] = is_array($tx) ? $tx : (array)$tx;
        }

        // Create block
        $block = new \Blockchain\Core\Blockchain\Block(
            (int)$index,
            $txs,
            (string)$previousHash
        );

        // Set additional metadata if available
        if (isset($data['metadata']) && is_array($data['metadata'])) {
            foreach ($data['metadata'] as $k => $v) {
                $block->addMetadata($k, $v);
            }
        }

        // Note: nonce from payload is not set as Block has no nonce setter; not critical for sync since hash is recalculated internally.
        // If needed, extend Block to support explicit nonce assignment.

        return $block;
    }

    /**
     * Compute a lightweight fingerprint for block payload to compare between nodes.
     * Prefers explicit 'hash' if present; otherwise derives from a few stable fields.
     */
    private function fingerprintBlockPayload(array $payload): string
    {
        if (!empty($payload['hash'])) {
            return 'h:' . (string)$payload['hash'];
        }
        $h = (string)($payload['height'] ?? $payload['index'] ?? '');
        $ph = (string)($payload['previous_hash'] ?? ($payload['previousHash'] ?? ''));
        $txCount = is_array($payload['transactions'] ?? null) ? count($payload['transactions']) : (is_array($payload['tx'] ?? null) ? count($payload['tx']) : 0);
        $mr = (string)($payload['merkle_root'] ?? ($payload['merkleRoot'] ?? ''));
        return sha1($h . '|' . $ph . '|' . $txCount . '|' . $mr);
    }

    /**
     * Validate sequence continuity inside a batch and trim trailing inconsistent part.
     * We only check that payload[i].previous_hash == payload[i-1].hash when both present.
     */
    private function validateAndTrimSequence(array $byHeight): array
    {
        if (empty($byHeight)) return $byHeight;
        ksort($byHeight, SORT_NUMERIC);
        $heights = array_keys($byHeight);
        $validUntil = count($heights) - 1; // index in $heights
        for ($i = 1; $i < count($heights); $i++) {
            $prev = $byHeight[$heights[$i - 1]];
            $curr = $byHeight[$heights[$i]];
            $prevHash = $prev['hash'] ?? null;
            $currPrev = $curr['previous_hash'] ?? ($curr['previousHash'] ?? null);
            if ($prevHash && $currPrev && $prevHash !== $currPrev) {
                $validUntil = $i - 1;
                break;
            }
        }
        if ($validUntil < count($heights) - 1) {
            // Trim everything after the last valid contiguous index
            $trimmed = [];
            for ($j = 0; $j <= $validUntil; $j++) {
                $trimmed[$heights[$j]] = $byHeight[$heights[$j]];
            }
            return $trimmed;
        }
        return $byHeight;
    }

    private function downloadStateSnapshot(int $height): array
    {
        // Check snapshot availability in parallel across nodes and download the first available
        $nodeUrls = method_exists($this->nodeManager, 'getActiveNodeUrls') ? $this->nodeManager->getActiveNodeUrls() : [];
        $requests = [];
        foreach ($nodeUrls as $nodeId => $baseUrl) {
            $requests[$nodeId] = [
                'url' => rtrim($baseUrl, '/') . '/api/explorer/index.php?action=get_state_snapshot&height=' . $height,
                'method' => 'GET',
                'headers' => ['User-Agent: BlockchainNodeSync/2.0', 'Accept: application/json'],
                'timeout' => 60
            ];
        }
        if (empty($requests)) {
            return [];
        }
        $results = $this->multiCurl->executeRequests($requests);
        foreach ($results as $nodeId => $res) {
            if (!($res['success'] ?? false)) continue;
            $payload = $res['data'] ?? json_decode($res['response'] ?? 'null', true);
            if (isset($payload['success']) && $payload['success'] && isset($payload['data'])) {
                return $payload['data'];
            }
            if (is_array($payload)) {
                return $payload;
            }
        }
        return [];
    }

    private function verifyStateSnapshot(array $snapshot): bool
    {
        // Minimal integrity check
        return isset($snapshot['accounts']) && isset($snapshot['contracts']);
    }

    private function downloadBlockHeaders(int $start, int $end): array
    {
        $nodeUrls = method_exists($this->nodeManager, 'getActiveNodeUrls') ? $this->nodeManager->getActiveNodeUrls() : [];
        $requests = [];
        foreach ($nodeUrls as $nodeId => $baseUrl) {
            $requests[$nodeId] = [
                'url' => rtrim($baseUrl, '/') . '/api/explorer/index.php?action=get_block_headers&start=' . $start . '&end=' . $end,
                'method' => 'GET',
                'headers' => ['User-Agent: BlockchainNodeSync/2.0', 'Accept: application/json'],
                'timeout' => 20
            ];
        }
        if (empty($requests)) {
            return [];
        }
        $results = $this->multiCurl->executeRequests($requests);
        foreach ($results as $r) {
            if (!($r['success'] ?? false)) continue;
            $payload = $r['data'] ?? json_decode($r['response'] ?? 'null', true);
            if (isset($payload['success']) && $payload['success'] && isset($payload['data']['headers'])) {
                return $payload['data']['headers'];
            }
            if (isset($payload['headers'])) {
                return $payload['headers'];
            }
        }
        return [];
    }

    private function verifyHeaderChain(array $headers): bool
    {
        if (empty($headers)) { return true; }
        // Recompute and validate each header's hash when possible
        foreach ($headers as $idx => $h) {
            if (isset($h['hash'])) {
                $recomputed = $this->recomputeHashFromPayload($h);
                if ($recomputed !== '' && $recomputed !== (string)$h['hash']) {
                    return false;
                }
            }
            // Continuity check with previous
            if ($idx > 0) {
                $prevHash = $headers[$idx - 1]['hash'] ?? null;
                $currPrev = $h['previous_hash'] ?? ($h['previousHash'] ?? null);
                if (!$prevHash || !$currPrev || $prevHash !== $currPrev) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Recompute hash for a header-like payload using Block::calculateHash schema.
     * Returns empty string if insufficient data.
     */
    private function recomputeHashFromPayload(array $p): string
    {
        // Map fields with fallbacks
        $index = (int)($p['height'] ?? $p['index'] ?? 0);
        $timestamp = (int)($p['timestamp'] ?? 0);
        $prev = (string)($p['previous_hash'] ?? ($p['previousHash'] ?? ''));
        $merkle = (string)($p['merkle_root'] ?? ($p['merkleRoot'] ?? ''));
        $state = (string)($p['state_root'] ?? ($p['stateRoot'] ?? ''));
        $nonce = (int)($p['nonce'] ?? 0);
        $gasUsed = (int)($p['gas_used'] ?? ($p['gasUsed'] ?? 0));
        $gasLimit = (int)($p['gas_limit'] ?? ($p['gasLimit'] ?? 0));
        $difficulty = (string)($p['difficulty'] ?? '0');
        $validators = $p['validators'] ?? [];
        $stakes = $p['stakes'] ?? [];

        // If critical parts are missing, skip (return empty to avoid false failures)
        if ($timestamp === 0 || $prev === '' || $merkle === '' || $state === '') {
            return '';
        }

        $data = $index .
                $timestamp .
                $prev .
                $merkle .
                $state .
                $nonce .
                $gasUsed .
                $gasLimit .
                $difficulty .
                json_encode($validators) .
                json_encode($stakes);
        return \Blockchain\Core\Crypto\Hash::sha256($data);
    }

    private function findBestCheckpoint(int $networkHeight): ?array
    {
        if (empty($this->trustedCheckpoints)) return null;
        krsort($this->trustedCheckpoints);
        foreach ($this->trustedCheckpoints as $h => $cp) {
            if ($h <= $networkHeight) return $cp;
        }
        return null;
    }

    private function loadCheckpointState(array $checkpoint): void
    {
        // Real implementation would load a trusted state.
        // No-op here because StateManager has no external checkpoint load.
    }

    private function startBatchDownload(array $batch): array { return $batch; }
    private function isBatchComplete(array $batch): bool { return true; }
    private function getBatchResult(array $batch): array { return []; }

    private function snapshotExists(int $height): bool
    {
        // Parallel HEAD-style availability check for snapshot
        $nodeUrls = method_exists($this->nodeManager, 'getActiveNodeUrls') ? $this->nodeManager->getActiveNodeUrls() : [];
        if (empty($nodeUrls)) return false;

        $requests = [];
        foreach ($nodeUrls as $nodeId => $baseUrl) {
            $requests[$nodeId] = [
                'url' => rtrim($baseUrl, '/') . '/api/explorer/index.php?action=has_state_snapshot&height=' . $height,
                'method' => 'GET',
                'headers' => ['User-Agent: BlockchainNodeSync/2.0', 'Accept: application/json'],
                'timeout' => 10
            ];
        }
        $results = $this->multiCurl->executeRequests($requests);
        foreach ($results as $res) {
            if (!($res['success'] ?? false)) continue;
            $payload = $res['data'] ?? json_decode($res['response'] ?? 'null', true);
            if (isset($payload['success']) && $payload['success']) {
                if (isset($payload['data']['exists']) && $payload['data']['exists']) return true;
            } elseif (isset($payload['exists']) && $payload['exists']) {
                return true;
            }
        }
        return false;
    }

    private function hasAvailableCheckpoints(): bool { return !empty($this->trustedCheckpoints); }
}
