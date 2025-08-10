<?php
declare(strict_types=1);

namespace Blockchain\Core\Transaction;

use Blockchain\Core\Contracts\TransactionInterface;
use Blockchain\Core\Cryptography\Signature;
use Exception;

/**
 * Transaction Class
 * 
 * Represents a blockchain transaction
 */
class Transaction implements TransactionInterface
{
    private string $hash;
    private string $fromAddress;
    private string $toAddress;
    private float $amount;
    private float $fee;
    private int $gasLimit;
    private int $gasUsed;
    private float $gasPrice;
    private int $nonce;
    private ?string $data;
    private ?string $signature;
    private string $status;
    private int $timestamp;
    
    public function __construct(
        string $fromAddress,
        string $toAddress,
    float $amount,
    float $fee = 0.0,
        int $nonce = 0,
        ?string $data = null,
        int $gasLimit = 21000,
        float $gasPrice = 0.00001
    ) {
        $this->fromAddress = $fromAddress;
        $this->toAddress = $toAddress;
        $this->amount = $amount;
        $this->fee = $fee;
        $this->nonce = $nonce;
        $this->data = $data;
        $this->gasLimit = $gasLimit;
        $this->gasUsed = 0;
        $this->gasPrice = $gasPrice;
        $this->signature = null;
        $this->status = 'pending';
        $this->timestamp = time();
        $this->hash = $this->calculateHash();
    }
    
    /**
     * Calculate transaction hash
     */
    private function calculateHash(): string
    {
        $data = json_encode([
            'from' => $this->fromAddress,
            'to' => $this->toAddress,
            'amount' => $this->amount,
            'fee' => $this->fee,
            'nonce' => $this->nonce,
            'gas_limit' => $this->gasLimit,
            'gas_price' => $this->gasPrice,
            'data' => $this->data,
            'timestamp' => $this->timestamp
        ]);
        
        return hash('sha256', $data);
    }
    
    /**
     * Sign transaction
     */
    public function sign(string $privateKey): string
    {
        $this->signature = Signature::sign($this->hash, $privateKey);
        return $this->signature;
    }
    
    /**
     * Verify transaction signature
     */
    public function verify(string $publicKey): bool
    {
        if (!$this->signature) {
            return false;
        }
        
        return Signature::verify($this->hash, $this->signature, $publicKey);
    }
    
    /**
     * Validate transaction
     */
    public function isValid(): bool
    {
        // Basic validation
        if ($this->amount < 0 || $this->fee < 0) {
            return false;
        }
        
        if ($this->fromAddress === $this->toAddress) {
            return false;
        }
        
        if (!$this->isValidAddress($this->fromAddress) || !$this->isValidAddress($this->toAddress)) {
            return false;
        }
        
        // Allow placeholder signature for externally supplied (Ethereum raw) transactions
        // These are verified by upstream network rules and injected via processing worker.
        if (!$this->signature) {
            return false;
        }
        if ($this->signature === 'external_raw') {
            return true; // trust external validation pipeline
        }
        
        // Verify hash
        $expectedHash = $this->calculateHash();
        if ($this->hash !== $expectedHash) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Validate address format
     */
    private function isValidAddress(string $address): bool
    {
        return preg_match('/^0x[a-fA-F0-9]{40}$/', $address) === 1;
    }
    
    /**
     * Get transaction size in bytes
     */
    public function getSize(): int
    {
        return strlen(json_encode($this->toArray()));
    }
    
    /**
     * Calculate transaction fee based on gas
     */
    public function calculateGasFee(): float
    {
        return $this->gasUsed * $this->gasPrice;
    }
    
    /**
     * Set gas used (after execution)
     */
    public function setGasUsed(int $gasUsed): void
    {
        $this->gasUsed = min($gasUsed, $this->gasLimit);
    }
    
    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return [
            'hash' => $this->hash,
            'from_address' => $this->fromAddress,
            'to_address' => $this->toAddress,
            'amount' => $this->amount,
            'fee' => $this->fee,
            'gas_limit' => $this->gasLimit,
            'gas_used' => $this->gasUsed,
            'gas_price' => $this->gasPrice,
            'nonce' => $this->nonce,
            'data' => $this->data,
            'signature' => $this->signature,
            'status' => $this->status,
            'timestamp' => $this->timestamp
        ];
    }
    
    /**
     * Create from array
     */
    public static function fromArray(array $data): self
    {
        $tx = new self(
            $data['from_address'],
            $data['to_address'],
            (float)$data['amount'],
            (float)($data['fee'] ?? 0.0),
            (int)($data['nonce'] ?? 0),
            $data['data'] ?? null,
            (int)($data['gas_limit'] ?? 21000),
            (float)($data['gas_price'] ?? 0.00001)
        );
        
        if (isset($data['hash'])) {
            $tx->hash = $data['hash'];
        }
        
        if (isset($data['signature'])) {
            $tx->signature = $data['signature'];
        }
        
        if (isset($data['status'])) {
            $tx->status = $data['status'];
        }
        
        if (isset($data['timestamp'])) {
            $tx->timestamp = (int)$data['timestamp'];
        }
        
        if (isset($data['gas_used'])) {
            $tx->gasUsed = (int)$data['gas_used'];
        }
        
        return $tx;
    }
    
    // Getters
    public function getHash(): string { return $this->hash; }
    public function getFromAddress(): string { return $this->fromAddress; }
    public function getToAddress(): string { return $this->toAddress; }
    public function getAmount(): float { return $this->amount; }
    public function getFee(): float { return $this->fee; }
    public function getGasLimit(): int { return $this->gasLimit; }
    public function getGasUsed(): int { return $this->gasUsed; }
    public function getGasPrice(): float { return $this->gasPrice; }
    public function getNonce(): int { return $this->nonce; }
    public function getData(): ?string { return $this->data; }
    public function getSignature(): ?string { return $this->signature; }
    public function getStatus(): string { return $this->status; }
    public function getTimestamp(): int { return $this->timestamp; }
    
    // Interface compatibility methods
    public function getFrom(): string { return $this->fromAddress; }
    public function getTo(): string { return $this->toAddress; }
    
    // Setters
    public function setSignature(string $signature): void { $this->signature = $signature; }
    public function setStatus(string $status): void { $this->status = $status; }
    
    /**
     * Check if transaction is a contract deployment
     */
    public function isContractDeployment(): bool
    {
        return $this->toAddress === '0x0000000000000000000000000000000000000000' && !empty($this->data);
    }
    
    /**
     * Check if transaction is a contract call
     */
    public function isContractCall(): bool
    {
        return !empty($this->data) && !$this->isContractDeployment();
    }
    
    /**
     * Get priority score for mempool ordering
     */
    public function getPriorityScore(): int
    {
        // Higher fee and gas price = higher priority
        $feeScore = (int)($this->fee * 1000);
        $gasPriceScore = (int)($this->gasPrice * 1000000);
        
        return $feeScore + $gasPriceScore;
    }
}
