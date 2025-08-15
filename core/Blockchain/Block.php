<?php
declare(strict_types=1);

namespace Blockchain\Core\Blockchain;

use Blockchain\Core\Contracts\BlockInterface;
use Blockchain\Core\Contracts\TransactionInterface;
use Blockchain\Core\Crypto\Hash;
use Blockchain\Core\Cryptography\MerkleTree;

/**
 * Modern block implementation with smart contract support
 */
class Block implements BlockInterface
{
    private int $index;
    private int $timestamp;
    private array $transactions;
    private string $previousHash;
    private string $hash;
    private int $nonce;
    private string $merkleRoot;
    private array $validators;
    private array $stakes;
    private string $stateRoot;
    private array $smartContractResults;
    private int $gasUsed;
    private int $gasLimit;
    private string $difficulty;
    private array $metadata;

    public function __construct(
        int $index,
        array $transactions,
        string $previousHash,
        array $validators = [],
        array $stakes = []
    ) {
        $this->index = $index;
        $this->timestamp = time();
        $this->transactions = $transactions;
        $this->previousHash = $previousHash;
        $this->validators = $validators;
        $this->stakes = $stakes;
        $this->nonce = 0;
        $this->gasUsed = 0;
        $this->gasLimit = 8000000; // 8M gas limit
        $this->difficulty = '0'; // PoS network - no difficulty mining
        $this->smartContractResults = [];
        $this->metadata = [];
        
        $this->calculateMerkleRoot();
        $this->calculateStateRoot();
        $this->calculateHash();
    }

    public function getIndex(): int
    {
        return $this->index;
    }

    public function getTimestamp(): int
    {
        return $this->timestamp;
    }

    public function getTransactions(): array
    {
        return $this->transactions;
    }

    public function getPreviousHash(): string
    {
        return $this->previousHash;
    }

    public function getHash(): string
    {
        return $this->hash;
    }

    public function getNonce(): int
    {
        return $this->nonce;
    }

    public function getMerkleRoot(): string
    {
        return $this->merkleRoot;
    }

    public function getValidators(): array
    {
        return $this->validators;
    }

    public function getStakes(): array
    {
        return $this->stakes;
    }

    public function getStateRoot(): string
    {
        return $this->stateRoot;
    }

    public function getSmartContractResults(): array
    {
        return $this->smartContractResults;
    }

    public function getGasUsed(): int
    {
        return $this->gasUsed;
    }

    public function getGasLimit(): int
    {
        return $this->gasLimit;
    }

    public function getDifficulty(): string
    {
        return $this->difficulty;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Calculate block hash
     */
    private function calculateHash(): void
    {
        $data = $this->index . 
                $this->timestamp . 
                $this->previousHash . 
                $this->merkleRoot . 
                $this->stateRoot . 
                $this->nonce . 
                $this->gasUsed . 
                $this->gasLimit . 
                $this->difficulty . 
                json_encode($this->validators) . 
                json_encode($this->stakes);
        
        $this->hash = Hash::sha256($data);
    }

    /**
     * Calculate Merkle tree root for transactions
     */
    private function calculateMerkleRoot(): void
    {
        if (empty($this->transactions)) {
            $this->merkleRoot = Hash::sha256('');
            return;
        }

        $transactionHashes = array_map(function($tx) {
            return $tx instanceof TransactionInterface ? $tx->getHash() : Hash::sha256(json_encode($tx));
        }, $this->transactions);

        $merkleTree = new MerkleTree($transactionHashes);
        $this->merkleRoot = $merkleTree->getRoot();
    }

    /**
     * Calculate state root
     */
    private function calculateStateRoot(): void
    {
        $stateData = [
            'contracts' => $this->smartContractResults,
            'balances' => $this->extractBalances(),
            'metadata' => $this->metadata
        ];
        
        $this->stateRoot = Hash::sha256(json_encode($stateData));
    }

    /**
     * Extract balances from transactions
     */
    private function extractBalances(): array
    {
        $balances = [];
        
        foreach ($this->transactions as $transaction) {
            if ($transaction instanceof TransactionInterface) {
                $from = $transaction->getFrom();
                $to = $transaction->getTo();
                $amount = $transaction->getAmount();
                
                if (!isset($balances[$from])) {
                    $balances[$from] = 0;
                }
                if (!isset($balances[$to])) {
                    $balances[$to] = 0;
                }
                
                $balances[$from] -= $amount;
                $balances[$to] += $amount;
            }
        }
        
        return $balances;
    }

    /**
     * Add smart contract execution result
     */
    public function addSmartContractResult(string $contractAddress, array $result): void
    {
        $this->smartContractResults[$contractAddress] = $result;
        $this->gasUsed += $result['gasUsed'] ?? 0;
        $this->calculateStateRoot();
    }

    /**
     * Add metadata
     */
    public function addMetadata(string $key, $value): void
    {
        $this->metadata[$key] = $value;
        $this->calculateStateRoot();
    }

    /**
     * Check block validity
     */
    public function isValid(): bool
    {
        // Check hash
        $calculatedHash = $this->hash;
        $this->calculateHash();
        
        if ($calculatedHash !== $this->hash) {
            return false;
        }
        
        // Check Merkle root
        $this->calculateMerkleRoot();
        
        // Check gas limit
        if ($this->gasUsed > $this->gasLimit) {
            return false;
        }
        
        // Check transaction validity
        foreach ($this->transactions as $transaction) {
            if ($transaction instanceof TransactionInterface && !$transaction->isValid()) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Get block size in bytes
     */
    public function getSize(): int
    {
        return strlen(json_encode($this->toArray()));
    }

    /**
     * Get block data (alias for toArray for interface compatibility)
     */
    public function getData(): array
    {
        return $this->toArray();
    }

    /**
     * Serialize block to array
     */
    public function toArray(): array
    {
        return [
            'index' => $this->index,
            'timestamp' => $this->timestamp,
            'transactions' => array_map(function($tx) {
                return $tx instanceof TransactionInterface ? $tx->toArray() : $tx;
            }, $this->transactions),
            'previousHash' => $this->previousHash,
            'hash' => $this->hash,
            'nonce' => $this->nonce,
            'merkleRoot' => $this->merkleRoot,
            'validators' => $this->validators,
            'stakes' => $this->stakes,
            'stateRoot' => $this->stateRoot,
            'smartContractResults' => $this->smartContractResults,
            'gasUsed' => $this->gasUsed,
            'gasLimit' => $this->gasLimit,
            'difficulty' => $this->difficulty,
            'metadata' => $this->metadata
        ];
    }

    /**
     * Construct Block from network payload without changing DB/schema.
     * Uses payload values when provided; otherwise falls back to defaults/constructor behavior.
     */
    public static function fromPayload(array $payload): Block
    {
        $index = (int)($payload['height'] ?? $payload['index'] ?? 0);
        $previousHash = (string)($payload['previous_hash'] ?? ($payload['previousHash'] ?? '0'));
        $transactions = $payload['transactions'] ?? ($payload['tx'] ?? []);
        if (!is_array($transactions)) {
            $transactions = [];
        }

        // Normalize transactions arrays; leave as-is if already arrays
        $txs = [];
        foreach ($transactions as $tx) {
            $txs[] = is_array($tx) ? $tx : (array)$tx;
        }

        $validators = isset($payload['validators']) && is_array($payload['validators']) ? $payload['validators'] : [];
        $stakes = isset($payload['stakes']) && is_array($payload['stakes']) ? $payload['stakes'] : [];

        $block = new Block($index, $txs, $previousHash, $validators, $stakes);

        // Apply metadata early without recalculating stateRoot
        if (isset($payload['metadata']) && is_array($payload['metadata'])) {
            $block->metadata = $payload['metadata'];
        }

        // Override basic fields if provided
        if (isset($payload['timestamp'])) {
            $block->timestamp = (int)$payload['timestamp'];
        }
        if (isset($payload['nonce'])) {
            $block->nonce = (int)$payload['nonce'];
        }
        if (isset($payload['merkle_root'])) {
            $block->merkleRoot = (string)$payload['merkle_root'];
        } elseif (isset($payload['merkleRoot'])) {
            $block->merkleRoot = (string)$payload['merkleRoot'];
        }
        if (isset($payload['state_root'])) {
            $block->stateRoot = (string)$payload['state_root'];
        } elseif (isset($payload['stateRoot'])) {
            $block->stateRoot = (string)$payload['stateRoot'];
        }

        // Finally, set hash if provided; else keep computed
        if (isset($payload['hash'])) {
            $block->hash = (string)$payload['hash'];
        } else {
            // Recalculate hash with possibly overridden fields
            $block->calculateHash();
        }

        // Map possible signature fields from network payload into metadata for PoS verification
        if (isset($payload['validator']) && isset($payload['signature'])) {
            $block->addMetadata('validator_signature', [
                'validator' => (string)$payload['validator'],
                'signature' => (string)$payload['signature'],
                'timestamp' => (int)($payload['timestamp'] ?? time()),
                'method' => 'network'
            ]);
        } elseif (isset($payload['metadata']['validator_signature'])) {
            // Preserve incoming validator_signature if nested in metadata
            $vs = $payload['metadata']['validator_signature'];
            if (is_array($vs) && isset($vs['validator'], $vs['signature'])) {
                $block->addMetadata('validator_signature', $vs);
            }
        }

        return $block;
    }
}
