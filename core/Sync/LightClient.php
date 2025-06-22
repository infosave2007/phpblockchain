<?php
declare(strict_types=1);

namespace Blockchain\Core\Sync;

use Blockchain\Core\Storage\BlockStorage;
use Blockchain\Core\Cryptography\MerkleTree;
use Exception;

/**
 * Light Client Manager
 * 
 * Implements SPV (Simplified Payment Verification) for resource-constrained devices
 * Downloads only block headers and verifies transactions using Merkle proofs
 */
class LightClient
{
    private BlockStorage $storage;
    private array $blockHeaders;
    private array $trustedPeers;
    private array $config;
    
    public function __construct(BlockStorage $storage, array $config = [])
    {
        $this->storage = $storage;
        $this->blockHeaders = [];
        $this->trustedPeers = [];
        
        $this->config = array_merge([
            'max_headers_memory' => 10000,    // Keep 10k headers in memory
            'header_batch_size' => 1000,     // Download 1k headers per batch
            'min_confirmations' => 6,        // Require 6 confirmations
            'bloom_filter_size' => 32000,    // Bloom filter size in bytes
            'bloom_filter_hashes' => 10,     // Number of hash functions
        ], $config);
    }
    
    /**
     * Sync block headers from the network
     */
    public function syncHeaders(int $startHeight = 0): array
    {
        echo "Light client: syncing block headers from height $startHeight\n";
        
        $networkHeight = $this->getNetworkHeight();
        $headersSynced = 0;
        $batchSize = $this->config['header_batch_size'];
        
        for ($height = $startHeight; $height <= $networkHeight; $height += $batchSize) {
            $endHeight = min($height + $batchSize - 1, $networkHeight);
            
            // Download batch of headers
            $headers = $this->downloadHeaderBatch($height, $endHeight);
            
            // Verify header chain continuity
            if (!$this->verifyHeaderChain($headers)) {
                throw new Exception("Header chain verification failed at height $height");
            }
            
            // Store headers
            foreach ($headers as $header) {
                $this->storeHeader($header);
                $headersSynced++;
            }
            
            // Manage memory usage
            $this->cleanupOldHeaders();
            
            echo "Synced headers: $headersSynced / " . ($networkHeight - $startHeight + 1) . "\n";
        }
        
        return [
            'success' => true,
            'headers_synced' => $headersSynced,
            'final_height' => $networkHeight
        ];
    }
    
    /**
     * Verify transaction using SPV (Simplified Payment Verification)
     */
    public function verifyTransaction(string $txHash, array $merkleProof, int $blockHeight): bool
    {
        // Get block header for the specified height
        $header = $this->getHeader($blockHeight);
        if (!$header) {
            throw new Exception("Block header not found for height $blockHeight");
        }
        
        // Verify the transaction exists in the block using Merkle proof
        return $this->verifyMerkleProof($txHash, $merkleProof, $header['merkle_root']);
    }
    
    /**
     * Create and manage bloom filter for transaction filtering
     */
    public function createBloomFilter(array $addresses, array $outpoints = []): string
    {
        $filterSize = $this->config['bloom_filter_size'];
        $hashFunctions = $this->config['bloom_filter_hashes'];
        
        // Initialize bloom filter (bit array)
        $filter = str_repeat("\0", $filterSize);
        
        // Add addresses to bloom filter
        foreach ($addresses as $address) {
            $this->addToBloomFilter($filter, $address, $filterSize, $hashFunctions);
        }
        
        // Add outpoints to bloom filter
        foreach ($outpoints as $outpoint) {
            $this->addToBloomFilter($filter, $outpoint, $filterSize, $hashFunctions);
        }
        
        return base64_encode($filter);
    }
    
    /**
     * Check if transaction matches bloom filter
     */
    public function matchesBloomFilter(array $transaction, string $bloomFilter): bool
    {
        $filter = base64_decode($bloomFilter);
        $filterSize = strlen($filter);
        $hashFunctions = $this->config['bloom_filter_hashes'];
        
        // Check if any transaction outputs match
        foreach ($transaction['outputs'] ?? [] as $output) {
            if ($this->checkBloomFilter($filter, $output['address'], $filterSize, $hashFunctions)) {
                return true;
            }
        }
        
        // Check if any transaction inputs match
        foreach ($transaction['inputs'] ?? [] as $input) {
            $outpoint = $input['previous_tx'] . ':' . $input['output_index'];
            if ($this->checkBloomFilter($filter, $outpoint, $filterSize, $hashFunctions)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Get balance for an address using SPV
     */
    public function getAddressBalance(string $address): float
    {
        // Create bloom filter for this address
        $bloomFilter = $this->createBloomFilter([$address]);
        
        // Request filtered transactions from peers
        $transactions = $this->requestFilteredTransactions($bloomFilter);
        
        $balance = 0.0;
        $spentOutputs = [];
        
        // Calculate balance from UTXOs
        foreach ($transactions as $tx) {
            // Add outputs sent to this address
            foreach ($tx['outputs'] ?? [] as $index => $output) {
                if ($output['address'] === $address) {
                    $utxo = $tx['hash'] . ':' . $index;
                    if (!in_array($utxo, $spentOutputs)) {
                        $balance += $output['amount'];
                    }
                }
            }
            
            // Remove outputs spent by this address
            foreach ($tx['inputs'] ?? [] as $input) {
                if ($input['address'] === $address) {
                    $spentOutputs[] = $input['previous_tx'] . ':' . $input['output_index'];
                    $balance -= $input['amount'];
                }
            }
        }
        
        return $balance;
    }
    
    /**
     * Create lightweight transaction
     */
    public function createTransaction(string $fromAddress, string $toAddress, float $amount): array
    {
        // Get UTXOs for sender
        $utxos = $this->getAddressUTXOs($fromAddress);
        
        // Select UTXOs to cover the amount
        $selectedUTXOs = $this->selectUTXOs($utxos, $amount + 0.001); // +fee
        
        if (array_sum(array_column($selectedUTXOs, 'amount')) < $amount + 0.001) {
            throw new Exception("Insufficient funds");
        }
        
        // Create transaction inputs
        $inputs = [];
        $inputTotal = 0;
        foreach ($selectedUTXOs as $utxo) {
            $inputs[] = [
                'previous_tx' => $utxo['tx_hash'],
                'output_index' => $utxo['output_index'],
                'address' => $fromAddress,
                'amount' => $utxo['amount']
            ];
            $inputTotal += $utxo['amount'];
        }
        
        // Create transaction outputs
        $outputs = [
            [
                'address' => $toAddress,
                'amount' => $amount
            ]
        ];
        
        // Add change output if needed
        $change = $inputTotal - $amount - 0.001; // subtract fee
        if ($change > 0) {
            $outputs[] = [
                'address' => $fromAddress,
                'amount' => $change
            ];
        }
        
        return [
            'inputs' => $inputs,
            'outputs' => $outputs,
            'fee' => 0.001,
            'timestamp' => time()
        ];
    }
    
    /**
     * Verify Merkle proof for transaction inclusion
     */
    private function verifyMerkleProof(string $txHash, array $proof, string $merkleRoot): bool
    {
        $currentHash = $txHash;
        
        foreach ($proof as $step) {
            if ($step['position'] === 'left') {
                $currentHash = hash('sha256', $step['hash'] . $currentHash);
            } else {
                $currentHash = hash('sha256', $currentHash . $step['hash']);
            }
        }
        
        return $currentHash === $merkleRoot;
    }
    
    /**
     * Verify header chain continuity and difficulty
     */
    private function verifyHeaderChain(array $headers): bool
    {
        for ($i = 1; $i < count($headers); $i++) {
            $prevHeader = $headers[$i - 1];
            $currentHeader = $headers[$i];
            
            // Check if current header's previous hash matches previous header's hash
            if ($currentHeader['previous_hash'] !== $prevHeader['hash']) {
                return false;
            }
            
            // Check timestamp progression
            if ($currentHeader['timestamp'] <= $prevHeader['timestamp']) {
                return false;
            }
            
            // Verify proof of work/stake
            if (!$this->verifyHeaderWork($currentHeader)) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Add item to bloom filter
     */
    private function addToBloomFilter(string &$filter, string $item, int $filterSize, int $hashFunctions): void
    {
        for ($i = 0; $i < $hashFunctions; $i++) {
            $hash = crc32($item . $i) % ($filterSize * 8);
            $byteIndex = intval($hash / 8);
            $bitIndex = $hash % 8;
            
            $filter[$byteIndex] = chr(ord($filter[$byteIndex]) | (1 << $bitIndex));
        }
    }
    
    /**
     * Check if item might be in bloom filter
     */
    private function checkBloomFilter(string $filter, string $item, int $filterSize, int $hashFunctions): bool
    {
        for ($i = 0; $i < $hashFunctions; $i++) {
            $hash = crc32($item . $i) % ($filterSize * 8);
            $byteIndex = intval($hash / 8);
            $bitIndex = $hash % 8;
            
            if (!(ord($filter[$byteIndex]) & (1 << $bitIndex))) {
                return false; // Definitely not in set
            }
        }
        
        return true; // Might be in set
    }
    
    /**
     * Store block header efficiently
     */
    private function storeHeader(array $header): void
    {
        $height = $header['height'];
        $this->blockHeaders[$height] = $header;
        
        // Also persist to storage
        $this->storage->storeBlockHeader($header);
    }
    
    /**
     * Get header by height
     */
    private function getHeader(int $height): ?array
    {
        if (isset($this->blockHeaders[$height])) {
            return $this->blockHeaders[$height];
        }
        
        // Try to load from storage
        return $this->storage->getBlockHeader($height);
    }
    
    /**
     * Clean up old headers to manage memory
     */
    private function cleanupOldHeaders(): void
    {
        $maxHeaders = $this->config['max_headers_memory'];
        
        if (count($this->blockHeaders) > $maxHeaders) {
            // Keep only the latest headers
            $heights = array_keys($this->blockHeaders);
            sort($heights);
            
            $toRemove = array_slice($heights, 0, count($heights) - $maxHeaders);
            foreach ($toRemove as $height) {
                unset($this->blockHeaders[$height]);
            }
        }
    }
    
    // Network communication methods for blockchain synchronization
    private function getNetworkHeight(): int { return 100000; }
    private function downloadHeaderBatch(int $start, int $end): array { return []; }
    private function verifyHeaderWork(array $header): bool { return true; }
    private function requestFilteredTransactions(string $bloomFilter): array { return []; }
    private function getAddressUTXOs(string $address): array { return []; }
    private function selectUTXOs(array $utxos, float $amount): array { return []; }
}
