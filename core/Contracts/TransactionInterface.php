<?php

namespace Blockchain\Core\Contracts;

/**
 * Transaction Interface
 * 
 * Defines the interface for blockchain transactions
 */
interface TransactionInterface
{
    /**
     * Get transaction hash
     *
     * @return string Transaction hash
     */
    public function getHash(): string;

    /**
     * Get sender address
     *
     * @return string Sender address
     */
    public function getFrom(): string;

    /**
     * Get recipient address
     *
     * @return string Recipient address
     */
    public function getTo(): string;

    /**
     * Get transaction amount
     *
     * @return float Transaction amount
     */
    public function getAmount(): float;

    /**
     * Get transaction timestamp
     *
     * @return int Transaction timestamp
     */
    public function getTimestamp(): int;

    /**
     * Sign transaction
     *
     * @param string $privateKey Private key for signing
     * @return string Signature
     */
    public function sign(string $privateKey): string;

    /**
     * Verify transaction signature
     *
     * @param string $publicKey Public key for verification
     * @return bool True if valid
     */
    public function verify(string $publicKey): bool;

    /**
     * Validate transaction
     *
     * @return bool True if valid
     */
    public function isValid(): bool;

    /**
     * Convert transaction to array
     *
     * @return array Transaction data
     */
    public function toArray(): array;
}
