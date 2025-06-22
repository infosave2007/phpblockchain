<?php

namespace Blockchain\Core\Contracts;

/**
 * Consensus Interface
 * 
 * Defines the interface for consensus mechanisms
 */
interface ConsensusInterface
{
    /**
     * Validate a block according to consensus rules
     *
     * @param BlockInterface $block The block to validate
     * @param array $stakeholders List of stakeholders (optional)
     * @return bool True if valid
     */
    public function validateBlock(BlockInterface $block, array $stakeholders = []): bool;

    /**
     * Check if an address can create the next block
     *
     * @param string $address The validator address
     * @param array $stakeholders List of stakeholders (optional)
     * @return bool True if can validate
     */
    public function canValidate(string $address, array $stakeholders = []): bool;

    /**
     * Select the next validator
     *
     * @param int $blockHeight Current block height
     * @param string $previousHash Hash of previous block
     * @return string|null Selected validator address
     */
    public function selectValidator(int $blockHeight, string $previousHash): ?string;

    /**
     * Get consensus statistics
     *
     * @return array Consensus stats
     */
    public function getConsensusStats(): array;
}
