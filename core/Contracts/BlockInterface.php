<?php

namespace Blockchain\Core\Contracts;

/**
 * Block Interface
 * 
 * Defines the interface for blockchain blocks
 */
interface BlockInterface
{
    /**
     * Get block index/height
     *
     * @return int Block index
     */
    public function getIndex(): int;

    /**
     * Get block hash
     *
     * @return string Block hash
     */
    public function getHash(): string;

    /**
     * Get previous block hash
     *
     * @return string Previous block hash
     */
    public function getPreviousHash(): string;

    /**
     * Get block timestamp
     *
     * @return int Block timestamp (Unix timestamp)
     */
    public function getTimestamp(): int;

    /**
     * Get block transactions
     *
     * @return array Block transactions
     */
    public function getTransactions(): array;

    /**
     * Get block data
     *
     * @return array Block data
     */
    public function getData(): array;

    /**
     * Get block nonce
     *
     * @return int Block nonce
     */
    public function getNonce(): int;

    /**
     * Get block difficulty
     *
     * @return string Block difficulty
     */
    public function getDifficulty(): string;

    /**
     * Get block Merkle root
     *
     * @return string Block Merkle root
     */
    public function getMerkleRoot(): string;

    /**
     * Validate block
     *
     * @return bool True if valid
     */
    public function isValid(): bool;
}
