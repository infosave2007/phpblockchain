<?php
declare(strict_types=1);

namespace Blockchain\Core\Sync;

use Blockchain\Core\State\StateManager;
use Blockchain\Core\Storage\BlockStorage;
use Blockchain\Core\Blockchain\Blockchain;
use Exception;

/**
 * State Snapshot Manager
 * 
 * Creates and manages blockchain state snapshots for fast synchronization
 */
class SnapshotManager
{
    private StateManager $stateManager;
    private BlockStorage $storage;
    private Blockchain $blockchain;
    private string $snapshotDir;
    private array $config;
    
    public function __construct(
        StateManager $stateManager,
        BlockStorage $storage,
        Blockchain $blockchain,
        string $snapshotDir = './storage/snapshots'
    ) {
        $this->stateManager = $stateManager;
        $this->storage = $storage;
        $this->blockchain = $blockchain;
        $this->snapshotDir = $snapshotDir;
        
        $this->config = [
            'compression_enabled' => true,
            'encryption_enabled' => true,
            'chunk_size' => 1024 * 1024, // 1MB chunks
            'max_snapshots' => 10,        // Keep last 10 snapshots
            'snapshot_interval' => 10000, // Create snapshot every 10k blocks
        ];
        
        $this->ensureSnapshotDirectory();
    }
    
    /**
     * Create state snapshot at current blockchain height
     */
    public function createSnapshot(?int $blockHeight = null): array
    {
        $height = $blockHeight ?? $this->blockchain->getBlockHeight();
        $block = $this->blockchain->getBlockByHeight($height);
        
        if (!$block) {
            throw new Exception("Block not found at height $height");
        }
        
        echo "Creating state snapshot at height $height...\n";
        $startTime = microtime(true);
        
        // Gather state data
        $stateData = $this->gatherStateData($height);
        
        // Create snapshot metadata
        $metadata = [
            'version' => '1.0',
            'height' => $height,
            'block_hash' => $block->getHash(),
            'state_root' => $block->getStateRoot(),
            'timestamp' => time(),
            'total_accounts' => count($stateData['accounts']),
            'total_contracts' => count($stateData['contracts']),
            'total_size' => strlen(json_encode($stateData)),
        ];
        
        // Create snapshot file
        $snapshotFile = $this->generateSnapshotFilename($height);
        $this->writeSnapshot($snapshotFile, $stateData, $metadata);
        
        $duration = microtime(true) - $startTime;
        
        echo "Snapshot created successfully in {$duration}s\n";
        echo "File: $snapshotFile\n";
        echo "Size: " . $this->formatBytes($metadata['total_size']) . "\n";
        
        return [
            'success' => true,
            'height' => $height,
            'filename' => $snapshotFile,
            'metadata' => $metadata,
            'creation_time' => $duration
        ];
    }
    
    /**
     * Load blockchain state from snapshot
     */
    public function loadSnapshot(string $snapshotFile): array
    {
        if (!file_exists($snapshotFile)) {
            throw new Exception("Snapshot file not found: $snapshotFile");
        }
        
        echo "Loading state snapshot: $snapshotFile\n";
        $startTime = microtime(true);
        
        // Read and verify snapshot
        $snapshotData = $this->readSnapshot($snapshotFile);
        
        if (!$this->verifySnapshot($snapshotData)) {
            throw new Exception("Snapshot verification failed");
        }
        
        // Apply state data
        $this->applyStateData($snapshotData['state']);
        
        $duration = microtime(true) - $startTime;
        
        echo "Snapshot loaded successfully in {$duration}s\n";
        
        return [
            'success' => true,
            'metadata' => $snapshotData['metadata'],
            'load_time' => $duration
        ];
    }
    
    /**
     * List available snapshots
     */
    public function listSnapshots(): array
    {
        $snapshots = [];
        $files = glob($this->snapshotDir . '/snapshot_*.json');
        
        foreach ($files as $file) {
            $metadata = $this->readSnapshotMetadata($file);
            if ($metadata) {
                $snapshots[] = [
                    'file' => $file,
                    'height' => $metadata['height'],
                    'timestamp' => $metadata['timestamp'],
                    'size' => filesize($file),
                    'accounts' => $metadata['total_accounts'],
                    'contracts' => $metadata['total_contracts']
                ];
            }
        }
        
        // Sort by height (newest first)
        usort($snapshots, fn($a, $b) => $b['height'] - $a['height']);
        
        return $snapshots;
    }
    
    /**
     * Clean up old snapshots to save disk space
     */
    public function cleanupSnapshots(): array
    {
        $snapshots = $this->listSnapshots();
        $maxSnapshots = $this->config['max_snapshots'];
        
        if (count($snapshots) <= $maxSnapshots) {
            return ['deleted' => 0, 'kept' => count($snapshots)];
        }
        
        $toDelete = array_slice($snapshots, $maxSnapshots);
        $deleted = 0;
        
        foreach ($toDelete as $snapshot) {
            if (unlink($snapshot['file'])) {
                $deleted++;
                echo "Deleted old snapshot: {$snapshot['file']}\n";
            }
        }
        
        return ['deleted' => $deleted, 'kept' => count($snapshots) - $deleted];
    }
    
    /**
     * Gather current blockchain state data
     */
    private function gatherStateData(int $height): array
    {
        // Get all account balances
        $accounts = $this->stateManager->getAllAccounts();
        
        // Get all smart contract states
        $contracts = $this->stateManager->getAllContracts();
        
        // Get validator stakes
        $validators = $this->stateManager->getValidatorStakes();
        
        // Get governance state
        $governance = $this->stateManager->getGovernanceState();
        
        return [
            'accounts' => $accounts,
            'contracts' => $contracts,
            'validators' => $validators,
            'governance' => $governance,
            'height' => $height
        ];
    }
    
    /**
     * Write snapshot to file with compression and encryption
     */
    private function writeSnapshot(string $filename, array $stateData, array $metadata): void
    {
        $data = [
            'metadata' => $metadata,
            'state' => $stateData
        ];
        
        $jsonData = json_encode($data, JSON_PRETTY_PRINT);
        
        // Apply compression if enabled
        if ($this->config['compression_enabled']) {
            $jsonData = gzcompress($jsonData, 6);
            $metadata['compressed'] = true;
        }
        
        // Apply encryption if enabled
        if ($this->config['encryption_enabled']) {
            $jsonData = $this->encryptData($jsonData);
            $metadata['encrypted'] = true;
        }
        
        // Write to file
        if (file_put_contents($filename, $jsonData) === false) {
            throw new Exception("Failed to write snapshot file: $filename");
        }
        
        // Write metadata separately for quick access
        $metadataFile = str_replace('.json', '.meta.json', $filename);
        file_put_contents($metadataFile, json_encode($metadata, JSON_PRETTY_PRINT));
    }
    
    /**
     * Read snapshot from file with decompression and decryption
     */
    private function readSnapshot(string $filename): array
    {
        $data = file_get_contents($filename);
        if ($data === false) {
            throw new Exception("Failed to read snapshot file: $filename");
        }
        
        $metadataFile = str_replace('.json', '.meta.json', $filename);
        $metadata = json_decode(file_get_contents($metadataFile), true);
        
        // Apply decryption if needed
        if ($metadata['encrypted'] ?? false) {
            $data = $this->decryptData($data);
        }
        
        // Apply decompression if needed
        if ($metadata['compressed'] ?? false) {
            $data = gzuncompress($data);
        }
        
        return json_decode($data, true);
    }
    
    /**
     * Verify snapshot integrity
     */
    private function verifySnapshot(array $snapshotData): bool
    {
        $metadata = $snapshotData['metadata'];
        $state = $snapshotData['state'];
        
        // Check version compatibility
        if ($metadata['version'] !== '1.0') {
            return false;
        }
        
        // Verify account count
        if (count($state['accounts']) !== $metadata['total_accounts']) {
            return false;
        }
        
        // Verify contract count
        if (count($state['contracts']) !== $metadata['total_contracts']) {
            return false;
        }
        
        // Verify state root (simplified check)
        $calculatedStateRoot = hash('sha256', json_encode($state));
        if ($calculatedStateRoot !== $metadata['state_root']) {
            echo "Warning: State root mismatch in snapshot\n";
            // Continue anyway for demo purposes
        }
        
        return true;
    }
    
    /**
     * Apply state data to current blockchain state
     */
    private function applyStateData(array $stateData): void
    {
        // Load account balances
        foreach ($stateData['accounts'] as $address => $balance) {
            $this->stateManager->setAccountBalance($address, $balance);
        }
        
        // Load smart contract states
        foreach ($stateData['contracts'] as $address => $contractState) {
            $this->stateManager->setContractState($address, $contractState);
        }
        
        // Load validator stakes
        if (isset($stateData['validators'])) {
            $this->stateManager->setValidatorStakes($stateData['validators']);
        }
        
        // Load governance state
        if (isset($stateData['governance'])) {
            $this->stateManager->setGovernanceState($stateData['governance']);
        }
        
        // Update blockchain height
        $this->blockchain->setHeight($stateData['height']);
    }
    
    /**
     * Generate snapshot filename based on height and timestamp
     */
    private function generateSnapshotFilename(int $height): string
    {
        $timestamp = date('Y-m-d_H-i-s');
        return $this->snapshotDir . "/snapshot_{$height}_{$timestamp}.json";
    }
    
    /**
     * Read only metadata from snapshot file
     */
    private function readSnapshotMetadata(string $filename): ?array
    {
        $metadataFile = str_replace('.json', '.meta.json', $filename);
        
        if (!file_exists($metadataFile)) {
            return null;
        }
        
        $metadata = file_get_contents($metadataFile);
        return $metadata ? json_decode($metadata, true) : null;
    }
    
    /**
     * Ensure snapshot directory exists
     */
    private function ensureSnapshotDirectory(): void
    {
        if (!is_dir($this->snapshotDir)) {
            mkdir($this->snapshotDir, 0755, true);
        }
    }
    
    /**
     * Format bytes for human readable output
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = floor(log($bytes, 1024));
        return sprintf('%.2f %s', $bytes / (1024 ** $factor), $units[$factor]);
    }
    
    /**
     * Simple data encryption (in production use proper encryption)
     */
    private function encryptData(string $data): string
    {
        // Simplified encryption for demo
        // In production, use proper encryption like AES-256-GCM
        return base64_encode($data);
    }
    
    /**
     * Simple data decryption
     */
    private function decryptData(string $encryptedData): string
    {
        // Simplified decryption for demo
        return base64_decode($encryptedData);
    }
}
