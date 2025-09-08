<?php
declare(strict_types=1);

namespace Blockchain\Contracts;

/**
 * Uniswap V2 Factory Contract
 * Creates trading pairs and manages fee collection
 */
class UniswapV2Factory
{
    private string $feeTo = ''; // Fee recipient address
    private string $feeToSetter = ''; // Address authorized to change fee recipient
    private array $pairs = []; // Mapping of token pairs to pair addresses
    private array $pairTokens = []; // Reverse mapping for lookups

    // Contract metadata
    private array $allPairs = [];
    private int $pairCount = 0;

    public function constructor(array $params = []): array
    {
        // Set fee to setter (deployer initially)
        if (!empty($params['feeToSetter'])) {
            $this->feeToSetter = $params['feeToSetter'];
        }

        return [
            'success' => true,
            'feeToSetter' => $this->feeToSetter,
            'factory_address' => $params['contractAddress'] ?? ''
        ];
    }

    /**
     * Create a new trading pair
     */
    public function createPair(array $params, array $context): array
    {
        $tokenA = $params['tokenA'] ?? '';
        $tokenB = $params['tokenB'] ?? '';

        if (empty($tokenA) || empty($tokenB)) {
            return ['success' => false, 'error' => 'Both tokens must be provided'];
        }

        // Ensure tokens are ordered correctly (by address)
        if ($tokenA > $tokenB) {
            [$tokenA, $tokenB] = [$tokenB, $tokenA];
        }

        // Check if pair already exists
        $pairKey = $tokenA . '_' . $tokenB;
        if (isset($this->pairs[$pairKey])) {
            return [
                'success' => false,
                'error' => 'Pair already exists',
                'pair' => $this->pairs[$pairKey]
            ];
        }

        // Generate pair contract address
        $pairAddress = $this->generatePairAddress($tokenA, $tokenB);

        // Store pair information
        $this->pairs[$pairKey] = $pairAddress;
        $this->pairTokens[$pairAddress] = [$tokenA, $tokenB];
        $this->allPairs[] = $pairAddress;
        $this->pairCount++;

        // Emit PairCreated event (would be handled by blockchain events)
        $eventData = [
            'token0' => $tokenA,
            'token1' => $tokenB,
            'pair' => $pairAddress,
            'pair_count' => $this->pairCount
        ];

        return [
            'success' => true,
            'pair' => $pairAddress,
            'token0' => $tokenA,
            'token1' => $tokenB,
            'event' => $eventData
        ];
    }

    /**
     * Set fee recipient
     */
    public function setFeeTo(array $params, array $context): array
    {
        $caller = $context['caller'] ?? '';

        if ($caller !== $this->feeToSetter) {
            return ['success' => false, 'error' => 'Only feeToSetter can change fee recipient'];
        }

        $this->feeTo = $params['feeTo'] ?? '';

        return ['success' => true, 'feeTo' => $this->feeTo];
    }

    /**
     * Set fee to setter (admin function)
     */
    public function setFeeToSetter(array $params, array $context): array
    {
        $caller = $context['caller'] ?? '';

        if ($caller !== $this->feeToSetter) {
            return ['success' => false, 'error' => 'Only current feeToSetter can change feeToSetter'];
        }

        $this->feeToSetter = $params['feeToSetter'] ?? '';

        return ['success' => true, 'feeToSetter' => $this->feeToSetter];
    }

    /**
     * Get pair address for token pair
     */
    public function getPair(array $params): array
    {
        $tokenA = $params['tokenA'] ?? '';
        $tokenB = $params['tokenB'] ?? '';

        if (empty($tokenA) || empty($tokenB)) {
            return ['success' => false, 'error' => 'Both tokens must be provided'];
        }

        // Ensure proper ordering
        if ($tokenA > $tokenB) {
            [$tokenA, $tokenB] = [$tokenB, $tokenA];
        }

        $pairKey = $tokenA . '_' . $tokenB;
        $pair = $this->pairs[$pairKey] ?? '';

        return [
            'success' => true,
            'pair' => $pair,
            'token0' => $tokenA,
            'token1' => $tokenB
        ];
    }

    /**
     * Get all pairs
     */
    public function allPairs(array $params): array
    {
        $index = (int)($params['index'] ?? 0);

        if ($index < 0 || $index >= $this->pairCount) {
            return ['success' => false, 'error' => 'Index out of bounds'];
        }

        return [
            'success' => true,
            'pair' => $this->allPairs[$index] ?? '',
            'index' => $index
        ];
    }

    /**
     * Get all pairs length
     */
    public function allPairsLength(): array
    {
        return ['success' => true, 'length' => $this->pairCount];
    }

    /**
     * Get fee recipient
     */
    public function feeTo(): array
    {
        return ['success' => true, 'feeTo' => $this->feeTo];
    }

    /**
     * Get fee to setter
     */
    public function feeToSetter(): array
    {
        return ['success' => true, 'feeToSetter' => $this->feeToSetter];
    }

    /**
     * Get pair count
     */
    public function pairCount(): array
    {
        return ['success' => true, 'count' => $this->pairCount];
    }

    /**
     * Generate deterministic pair address
     */
    private function generatePairAddress(string $tokenA, string $tokenB): string
    {
        // Create deterministic address based on token pair
        $salt = hash('sha256', $tokenA . $tokenB . 'PAIR_SALT');
        $pairAddress = '0x' . substr($salt, 0, 40);

        return $pairAddress;
    }

    /**
     * Get factory information
     */
    public function getInfo(): array
    {
        return [
            'feeTo' => $this->feeTo,
            'feeToSetter' => $this->feeToSetter,
            'allPairs' => $this->allPairs,
            'allPairsLength' => $this->pairCount,
            'pairs' => $this->pairs
        ];
    }
}