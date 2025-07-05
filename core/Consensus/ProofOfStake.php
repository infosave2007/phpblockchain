<?php
declare(strict_types=1);

namespace Blockchain\Core\Consensus;

use Blockchain\Core\Contracts\BlockInterface;
use Blockchain\Core\Contracts\ConsensusInterface;
use Blockchain\Core\Crypto\Hash;
use Blockchain\Core\Crypto\Signature;
use Psr\Log\LoggerInterface;

/**
 * Proof of Stake algorithm implementation
 * Now integrated with ValidatorManager for centralized validator management
 */
class ProofOfStake implements ConsensusInterface
{
    private LoggerInterface $logger;
    private int $minimumStake;
    private int $slashingPenalty;
    private int $blockReward;
    private int $epochLength;
    private array $validators;
    private array $stakes;
    private array $penalties;
    private array $rewards;
    private int $currentEpoch;
    private float $totalStaked;
    private ?ValidatorManager $validatorManager;

    public function __construct(
        LoggerInterface $logger,
        int $minimumStake = 1000,
        int $slashingPenalty = 100,
        int $blockReward = 10,
        int $epochLength = 100,
        ?ValidatorManager $validatorManager = null
    ) {
        $this->logger = $logger;
        $this->minimumStake = $minimumStake;
        $this->slashingPenalty = $slashingPenalty;
        $this->blockReward = $blockReward;
        $this->epochLength = $epochLength;
        $this->validators = [];
        $this->stakes = [];
        $this->penalties = [];
        $this->rewards = [];
        $this->currentEpoch = 0;
        $this->totalStaked = 0.0;
        $this->validatorManager = $validatorManager;
    }

    /**
     * Set ValidatorManager for centralized validator operations
     */
    public function setValidatorManager(ValidatorManager $validatorManager): void
    {
        $this->validatorManager = $validatorManager;
    }

    /**
     * Add validator
     */
    public function addValidator(string $address, float $stake, string $publicKey): bool
    {
        if ($stake < $this->minimumStake) {
            $this->logger->warning("Insufficient stake for validator: {$address}", [
                'stake' => $stake,
                'minimum' => $this->minimumStake
            ]);
            return false;
        }

        if (isset($this->validators[$address])) {
            $this->logger->warning("Validator already exists: {$address}");
            return false;
        }

        $this->validators[$address] = [
            'address' => $address,
            'publicKey' => $publicKey,
            'stake' => $stake,
            'active' => true,
            'joinedEpoch' => $this->currentEpoch,
            'blocksProduced' => 0,
            'penalties' => 0,
            'lastActivity' => time()
        ];

        $this->stakes[$address] = $stake;
        $this->totalStaked += $stake;

        $this->logger->info("Validator added: {$address}", [
            'stake' => $stake,
            'total_staked' => $this->totalStaked
        ]);

        return true;
    }

    /**
     * Remove validator (exit from staking)
     */
    public function removeValidator(string $address): bool
    {
        if (!isset($this->validators[$address])) {
            return false;
        }

        $stake = $this->stakes[$address];
        $this->totalStaked -= $stake;

        unset($this->validators[$address]);
        unset($this->stakes[$address]);

        $this->logger->info("Validator removed: {$address}", [
            'stake' => $stake,
            'total_staked' => $this->totalStaked
        ]);

        return true;
    }

    /**
     * Increase validator stake
     */
    public function increaseStake(string $address, float $amount): bool
    {
        if (!isset($this->validators[$address])) {
            return false;
        }

        $this->validators[$address]['stake'] += $amount;
        $this->stakes[$address] += $amount;
        $this->totalStaked += $amount;

        $this->logger->info("Stake increased for validator: {$address}", [
            'amount' => $amount,
            'new_stake' => $this->stakes[$address]
        ]);

        return true;
    }

    /**
     * Decrease validator stake
     */
    public function decreaseStake(string $address, float $amount): bool
    {
        if (!isset($this->validators[$address])) {
            return false;
        }

        $currentStake = $this->stakes[$address];
        $newStake = $currentStake - $amount;

        if ($newStake < $this->minimumStake) {
            $this->logger->warning("Cannot decrease stake below minimum: {$address}", [
                'current_stake' => $currentStake,
                'decrease_amount' => $amount,
                'minimum' => $this->minimumStake
            ]);
            return false;
        }

        $this->validators[$address]['stake'] = $newStake;
        $this->stakes[$address] = $newStake;
        $this->totalStaked -= $amount;

        $this->logger->info("Stake decreased for validator: {$address}", [
            'amount' => $amount,
            'new_stake' => $newStake
        ]);

        return true;
    }

    /**
     * Select validator for block creation
     */
    public function selectValidator(int $blockHeight, string $previousHash): ?string
    {
        $activeValidators = $this->getActiveValidators();
        
        if (empty($activeValidators)) {
            $this->logger->warning('No active validators available');
            return null;
        }

        // Use deterministic algorithm based on previous block hash
        $seed = Hash::sha256($previousHash . $blockHeight);
        $seedInt = hexdec(substr($seed, 0, 8));
        
        // Weighted random selection based on stake
        $cumulativeStakes = [];
        $totalStake = 0;
        
        foreach ($activeValidators as $address => $validator) {
            $stake = $validator['stake'];
            $totalStake += $stake;
            $cumulativeStakes[$address] = $totalStake;
        }
        
        $randomValue = ($seedInt % 1000000) / 1000000; // Normalize from 0 to 1
        $targetValue = $randomValue * $totalStake;
        
        foreach ($cumulativeStakes as $address => $cumulativeStake) {
            if ($targetValue <= $cumulativeStake) {
                $this->logger->debug("Validator selected: {$address}", [
                    'block_height' => $blockHeight,
                    'stake' => $this->stakes[$address],
                    'probability' => round(($this->stakes[$address] / $totalStake) * 100, 2) . '%'
                ]);
                
                return $address;
            }
        }
        
        // Fallback - return first validator
        return array_key_first($activeValidators);
    }

    /**
     * Check validator rights for block validation
     */
    public function canValidate(string $address, array $stakeholders = []): bool
    {
        if (!isset($this->validators[$address])) {
            return false;
        }

        $validator = $this->validators[$address];
        
        // Check activity
        if (!$validator['active']) {
            return false;
        }
        
        // Check minimum stake
        if ($validator['stake'] < $this->minimumStake) {
            return false;
        }
        
        // Check for absence of recent violations
        if (isset($this->penalties[$address]) && $this->penalties[$address] > time() - 3600) {
            return false;
        }
        
        return true;
    }

    /**
     * Validate block
     */
    public function validateBlock(BlockInterface $block, array $stakeholders = []): bool
    {
        $validators = $block->getValidators();
        
        if (empty($validators)) {
            $this->logger->warning('Block has no validators');
            return false;
        }
        
        $validator = $validators[0]; // Assume single validator
        
        if (!$this->canValidate($validator, $stakeholders)) {
            $this->logger->warning("Validator cannot validate block: {$validator}");
            return false;
        }
        
        // Verify block signature
        if (!$this->verifyBlockSignature($block, $validator)) {
            $this->logger->warning("Invalid block signature: {$validator}");
            return false;
        }
        
        // Additional PoS checks
        if (!$this->validateProofOfStake($block, $validator)) {
            $this->logger->warning("Invalid proof of stake: {$validator}");
            return false;
        }
        
        return true;
    }

    /**
     * Sign block with validator using ValidatorManager
     */
    public function signBlock(BlockInterface $block, string $validatorAddress = null): bool
    {
        try {
            // Use ValidatorManager if available (centralized approach)
            if ($this->validatorManager) {
                $blockData = [
                    'hash' => $block->getHash(),
                    'index' => $block->getIndex(),
                    'timestamp' => $block->getTimestamp(),
                    'previous_hash' => $block->getPreviousHash(),
                    'merkle_root' => method_exists($block, 'getMerkleRoot') ? $block->getMerkleRoot() : '',
                    'transactions_count' => count($block->getTransactions())
                ];
                
                $signatureData = $this->validatorManager->signBlock($blockData);
                
                // Add signature metadata to block
                $block->addMetadata('validator_signature', [
                    'validator' => $signatureData['validator_address'],
                    'signature' => $signatureData['signature'],
                    'timestamp' => $signatureData['timestamp'],
                    'signing_data' => $signatureData['signing_data'],
                    'method' => 'validator_manager'
                ]);
                
                // Update local validator statistics if validator exists
                if (isset($this->validators[$signatureData['validator_address']])) {
                    $this->validators[$signatureData['validator_address']]['blocksProduced']++;
                    $this->validators[$signatureData['validator_address']]['lastActivity'] = time();
                }
                
                return true;
            }
            
            // Fallback to legacy method
            if (!$validatorAddress || !isset($this->validators[$validatorAddress])) {
                return false;
            }
            
            $validator = $this->validators[$validatorAddress];
            $blockHash = $block->getHash();
            
            // Create KeyPair for validator
            $keyPair = \Blockchain\Core\Cryptography\KeyPair::fromPrivateKey($validator['privateKey']);
            
            // Sign block hash
            $signature = $keyPair->sign($blockHash);
            
            // Add signature metadata
            $block->addMetadata('validator_signature', [
                'validator' => $validatorAddress,
                'signature' => $signature,
                'timestamp' => time(),
                'method' => 'legacy'
            ]);
            
            // Update validator statistics
            $this->validators[$validatorAddress]['blocksProduced']++;
            $this->validators[$validatorAddress]['lastActivity'] = time();
            
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error("Failed to sign block: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Verify block signature using ValidatorManager
     */
    public function verifyBlockSignature(BlockInterface $block, string $validatorAddress = null): bool
    {
        try {
            $metadata = $block->getMetadata();
            
            if (!isset($metadata['validator_signature'])) {
                return false;
            }
            
            $signatureData = $metadata['validator_signature'];
            $signatureValidator = $signatureData['validator'];
            $signature = $signatureData['signature'];
            $method = $signatureData['method'] ?? 'unknown';
            
            // If specific validator provided, check it matches
            if ($validatorAddress && $signatureValidator !== $validatorAddress) {
                return false;
            }
            
            // Use ValidatorManager if available and signature was created with it
            if ($this->validatorManager && $method === 'validator_manager') {
                $blockData = [
                    'hash' => $block->getHash(),
                    'index' => $block->getIndex(),
                    'timestamp' => $block->getTimestamp(),
                    'previous_hash' => $block->getPreviousHash(),
                    'merkle_root' => method_exists($block, 'getMerkleRoot') ? $block->getMerkleRoot() : '',
                    'transactions_count' => count($block->getTransactions())
                ];
                
                return $this->validatorManager->verifyBlockSignature($blockData, $signature, $signatureValidator);
            }
            
            // Fallback to legacy verification
            if (!isset($this->validators[$signatureValidator])) {
                return false;
            }
            
            // Get validator's public key
            $validator = $this->validators[$signatureValidator];
            $publicKey = $validator['publicKey'];
            
            // Verify signature using real cryptography
            $blockHash = $block->getHash();
            
            return \Blockchain\Core\Cryptography\Signature::verify($blockHash, $signature, $publicKey);
            
        } catch (\Exception $e) {
            $this->logger->error("Failed to verify block signature: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Validate proof of stake
     */
    private function validateProofOfStake(BlockInterface $block, string $validatorAddress): bool
    {
        if (!isset($this->validators[$validatorAddress])) {
            return false;
        }
        
        $validator = $this->validators[$validatorAddress];
        $requiredStake = $this->calculateRequiredStake($block);
        
        if ($validator['stake'] < $requiredStake) {
            return false;
        }
        
        return true;
    }

    /**
     * Calculate required stake for block creation
     */
    private function calculateRequiredStake(BlockInterface $block): float
    {
        // Basic calculation - can be made more complex
        $baseStake = $this->minimumStake;
        $transactionCount = count($block->getTransactions());
        
        // Increase requirements for blocks with many transactions
        $additionalStake = $transactionCount * 0.1;
        
        return $baseStake + $additionalStake;
    }

    /**
     * Apply penalty to validator
     */
    public function penalizeValidator(string $address, string $reason): bool
    {
        if (!isset($this->validators[$address])) {
            return false;
        }
        
        $penalty = $this->slashingPenalty;
        $currentStake = $this->validators[$address]['stake'];
        
        if ($currentStake >= $penalty) {
            $this->validators[$address]['stake'] -= $penalty;
            $this->stakes[$address] -= $penalty;
            $this->totalStaked -= $penalty;
            
            $this->validators[$address]['penalties']++;
            $this->penalties[$address] = time();
            
            $this->logger->warning("Validator penalized: {$address}", [
                'reason' => $reason,
                'penalty' => $penalty,
                'remaining_stake' => $this->validators[$address]['stake']
            ]);
            
            // Deactivate validator if stake becomes less than minimum
            if ($this->validators[$address]['stake'] < $this->minimumStake) {
                $this->validators[$address]['active'] = false;
                $this->logger->warning("Validator deactivated due to insufficient stake: {$address}");
            }
        } else {
            // Completely remove validator if insufficient stake for penalty
            $this->removeValidator($address);
            $this->logger->warning("Validator removed due to insufficient stake for penalty: {$address}");
        }
        
        return true;
    }

    /**
     * Award reward to validator
     */
    public function rewardValidator(string $address, int $blockHeight): bool
    {
        if (!isset($this->validators[$address])) {
            return false;
        }
        
        $reward = $this->calculateBlockReward($blockHeight);
        
        if (!isset($this->rewards[$address])) {
            $this->rewards[$address] = 0;
        }
        
        $this->rewards[$address] += $reward;
        
        $this->logger->info("Validator rewarded: {$address}", [
            'reward' => $reward,
            'total_rewards' => $this->rewards[$address],
            'block_height' => $blockHeight
        ]);
        
        return true;
    }

    /**
     * Calculate block reward
     */
    private function calculateBlockReward(int $blockHeight): float
    {
        // Base reward with reduction every 100000 blocks
        $halvingInterval = 100000;
        $halvings = intval($blockHeight / $halvingInterval);
        
        $reward = $this->blockReward;
        for ($i = 0; $i < $halvings; $i++) {
            $reward /= 2;
        }
        
        return max($reward, 0.1); // Minimum reward
    }

    /**
     * Get active validators
     */
    public function getActiveValidators(): array
    {
        return array_filter($this->validators, function($validator) {
            return $validator['active'] && $validator['stake'] >= $this->minimumStake;
        });
    }

    /**
     * Get validator information
     */
    public function getValidator(string $address): ?array
    {
        return $this->validators[$address] ?? null;
    }

    /**
     * Get all validators
     */
    public function getAllValidators(): array
    {
        return $this->validators;
    }

    /**
     * Get validator statistics
     */
    public function getConsensusStats(): array
    {
        $activeValidators = $this->getActiveValidators();
        $totalValidators = count($this->validators);
        $activeCount = count($activeValidators);
        
        $avgStake = $activeCount > 0 ? $this->totalStaked / $activeCount : 0;
        
        return [
            'total_validators' => $totalValidators,
            'active_validators' => $activeCount,
            'total_staked' => $this->totalStaked,
            'average_stake' => round($avgStake, 2),
            'minimum_stake' => $this->minimumStake,
            'current_epoch' => $this->currentEpoch,
            'epoch_length' => $this->epochLength,
            'block_reward' => $this->blockReward,
            'slashing_penalty' => $this->slashingPenalty
        ];
    }

    /**
     * Start new epoch
     */
    public function startNewEpoch(): void
    {
        $this->currentEpoch++;
        
        // Reset epoch statistics
        foreach ($this->validators as $address => &$validator) {
            $validator['blocksProduced'] = 0;
        }
        
        $this->logger->info("New epoch started: {$this->currentEpoch}");
    }

    /**
     * Get current epoch
     */
    public function getCurrentEpoch(): int
    {
        return $this->currentEpoch;
    }

    /**
     * Check if new epoch needed
     */
    public function shouldStartNewEpoch(int $currentBlockHeight): bool
    {
        return $currentBlockHeight % $this->epochLength === 0;
    }
}
