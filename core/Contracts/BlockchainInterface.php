<?php

namespace Blockchain\Core\Contracts;

use Blockchain\Core\Blockchain\Block;
use Blockchain\Core\Transaction\Transaction;

/**
 * Blockchain Interface
 * 
 * Defines the interface for blockchain implementations
 */
interface BlockchainInterface
{
    /**
     * Add a new block to the blockchain
     *
     * @param Block $block The block to add
     * @return bool True if successfully added
     */
    public function addBlock(Block $block): bool;

    /**
     * Get a block by index
     *
     * @param int $index Block index
     * @return Block|null The block or null if not found
     */
    public function getBlock(int $index): ?Block;

    /**
     * Get a block by hash
     *
     * @param string $hash Block hash
     * @return Block|null The block or null if not found
     */
    public function getBlockByHash(string $hash): ?Block;

    /**
     * Get the latest block
     *
     * @return Block|null The latest block
     */
    public function getLatestBlock(): ?Block;

    /**
     * Get blockchain height
     *
     * @return int Current height
     */
    public function getHeight(): int;

    /**
     * Validate the entire blockchain
     *
     * @return bool True if valid
     */
    public function isValid(): bool;

    /**
     * Add a transaction to the mempool
     *
     * @param Transaction $transaction The transaction to add
     * @return bool True if added successfully
     */
    public function addTransaction(Transaction $transaction): bool;

    /**
     * Get pending transactions
     *
     * @return array Array of pending transactions
     */
    public function getPendingTransactions(): array;

    /**
     * Mine a new block with pending transactions
     *
     * @param string $minerAddress Address of the miner
     * @return Block|null The mined block or null if failed
     */
    public function mineBlock(string $minerAddress): ?Block;

    /**
     * Get transaction by hash
     *
     * @param string $hash Transaction hash
     * @return Transaction|null The transaction or null if not found
     */
    public function getTransaction(string $hash): ?Transaction;

    /**
     * Get balance for an address
     *
     * @param string $address The address to check
     * @return float The balance
     */
    public function getBalance(string $address): float;
}
