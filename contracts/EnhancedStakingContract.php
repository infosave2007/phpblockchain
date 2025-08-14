<?php
declare(strict_types=1);

namespace Blockchain\Contracts;

/**
 * Enhanced Staking Contract with Automatic Completion
 * 
 * This contract provides advanced staking features including:
 * - Automatic completion of expired stakings
 * - Dynamic reward calculation based on staking duration
 * - Early withdrawal penalties
 * - Multi-tier reward system
 * - Gas optimization for batch operations
 */
class EnhancedStakingContract
{
    private array $contractState;
    private array $config;
    
    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->contractState = [
            'minimumStake' => $config['minimum_stake'] ?? 1000,
            'rewardPerBlock' => $config['reward_per_block'] ?? 10,
            'earlyWithdrawalPenalty' => $config['early_withdrawal_penalty'] ?? 10, // 10%
            'stakingDuration' => $config['staking_duration'] ?? 30 * 24 * 60 * 60 / 15, // 30 days in blocks (15s blocks) as default
            'bonusTiers' => $config['bonus_tiers'] ?? [
                ['duration' => 30 * 24 * 60 * 60 / 15, 'bonus' => 120], // 20% bonus for 30 days
                ['duration' => 60 * 24 * 60 * 60 / 15, 'bonus' => 150], // 50% bonus for 60 days
                ['duration' => 90 * 24 * 60 * 60 / 15, 'bonus' => 200], // 100% bonus for 90 days
            ],
            'maxGasPerOperation' => $config['max_gas_per_operation'] ?? 500000,
            'batchSize' => $config['batch_size'] ?? 50, // Process 50 stakings per call
        ];
        
        // Initialize contract storage
        $this->contractState['stakes'] = [];
        $this->contractState['rewards'] = [];
        $this->contractState['stakingStartBlock'] = [];
        $this->contractState['isCompleted'] = [];
        $this->contractState['lastRewardBlock'] = [];
        $this->contractState['penaltyAmount'] = [];
        $this->contractState['totalStaked'] = 0;
        $this->contractState['totalRewardsPaid'] = 0;
        $this->contractState['lastCompletedCheck'] = 0;
    }
    
    /**
     * Stake tokens with enhanced features
     */
    public function stake(string $userAddress, float $amount, int $timestamp): array
    {
        try {
            // Validate staking amount
            if ($amount < $this->contractState['minimumStake']) {
                return [
                    'success' => false,
                    'error' => "Staking amount below minimum of " . $this->contractState['minimumStake']
                ];
            }
            
            // Check if user already has active staking
            if (isset($this->contractState['stakes'][$userAddress]) && $this->contractState['stakes'][$userAddress] > 0) {
                return [
                    'success' => false,
                    'error' => "User already has active staking"
                ];
            }
            
            // Record staking
            $this->contractState['stakes'][$userAddress] = $amount;
            $this->contractState['stakingStartBlock'][$userAddress] = $timestamp;
            $this->contractState['lastRewardBlock'][$userAddress] = $timestamp;
            $this->contractState['isCompleted'][$userAddress] = false;
            $this->contractState['totalStaked'] += $amount;
            
            return [
                'success' => true,
                'message' => "Successfully staked $amount tokens",
                'stakeAmount' => $amount,
                'startTime' => $timestamp
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => "Staking failed: " . $e->getMessage()
            ];
        }
    }
    
    /**
     * Unstake tokens with enhanced penalty calculation
     */
    public function unstake(string $userAddress, float $amount, int $timestamp): array
    {
        try {
            // Check if user has sufficient staked amount
            if (!isset($this->contractState['stakes'][$userAddress]) || 
                $this->contractState['stakes'][$userAddress] < $amount) {
                return [
                    'success' => false,
                    'error' => "Insufficient staked amount"
                ];
            }
            
            // Check if staking is already completed
            if ($this->contractState['isCompleted'][$userAddress]) {
                return [
                    'success' => false,
                    'error' => "Staking already completed"
                ];
            }
            
            // Calculate staking time and rewards
            $stakingTime = $timestamp - $this->contractState['stakingStartBlock'][$userAddress];
            $calculatedRewards = $this->calculateRewards($userAddress, $stakingTime);
            
            // Calculate early withdrawal penalty
            $penalty = 0;
            if ($stakingTime < $this->contractState['stakingDuration']) {
                $penalty = ($amount * $this->contractState['earlyWithdrawalPenalty']) / 100;
                $this->contractState['penaltyAmount'][$userAddress] += $penalty;
            }
            
            // Update stakes
            $this->contractState['stakes'][$userAddress] -= $amount;
            $this->contractState['totalStaked'] -= $amount;
            
            // Mark as completed if unstaking full amount
            if ($this->contractState['stakes'][$userAddress] == 0) {
                $this->contractState['isCompleted'][$userAddress] = true;
                $this->contractState['totalRewardsPaid'] += $calculatedRewards;
            }
            
            return [
                'success' => true,
                'message' => "Successfully unstaked $amount tokens",
                'unstakeAmount' => $amount,
                'penalty' => $penalty,
                'rewards' => $calculatedRewards,
                'remainingStake' => $this->contractState['stakes'][$userAddress]
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => "Unstaking failed: " . $e->getMessage()
            ];
        }
    }
    
    /**
     * Calculate rewards with multi-tier bonus system
     */
    public function calculateRewards(string $userAddress, int $stakingTime): float
    {
        if ($stakingTime == 0 || !isset($this->contractState['stakes'][$userAddress])) {
            return 0;
        }
        
        $stakeAmount = $this->contractState['stakes'][$userAddress];
        $blocksStaked = $stakingTime / 15; // Assuming 15-second blocks
        $baseRewards = ($stakeAmount * $blocksStaked * $this->contractState['rewardPerBlock']) / 1e18;
        
        // Apply multi-tier bonus system
        $bonusMultiplier = 100; // Base 100%
        foreach ($this->contractState['bonusTiers'] as $tier) {
            if ($stakingTime >= $tier['duration']) {
                $bonusMultiplier = $tier['bonus'];
            }
        }
        
        $finalRewards = ($baseRewards * $bonusMultiplier) / 100;
        
        return max(0, $finalRewards);
    }
    
    /**
     * Check and complete expired stakings automatically
     * This is the key enhancement for automatic completion
     */
    public function checkAndCompleteExpiredStakings(int $currentTimestamp, int $batchSize = null): array
    {
        if ($batchSize === null) {
            $batchSize = $this->contractState['batchSize'];
        }
        
        $completedStakings = [];
        $totalRewardsPaid = 0;
        $processedCount = 0;
        
        try {
            // Get list of active stakers
            $activeStakers = array_filter($this->contractState['stakes'], function($amount) {
                return $amount > 0;
            });
            
            // Process stakings in batches to optimize gas
            foreach ($activeStakers as $userAddress => $stakeAmount) {
                if ($processedCount >= $batchSize) {
                    break; // Process only batch size per call
                }
                
                if (!$this->contractState['isCompleted'][$userAddress]) {
                    $stakingTime = $currentTimestamp - $this->contractState['stakingStartBlock'][$userAddress];
                    $expectedDuration = $this->contractState['stakingDuration'];
                    
                    // Check if staking has expired
                    if ($stakingTime >= $expectedDuration) {
                        $calculatedRewards = $this->calculateRewards($userAddress, $stakingTime);
                        
                        // Auto-complete staking
                        $this->contractState['isCompleted'][$userAddress] = true;
                        $this->contractState['rewards'][$userAddress] = $calculatedRewards;
                        $this->contractState['totalRewardsPaid'] += $calculatedRewards;
                        
                        $completedStakings[] = [
                            'user' => $userAddress,
                            'stakeAmount' => $stakeAmount,
                            'rewards' => $calculatedRewards,
                            'stakingTime' => $stakingTime,
                            'completedAt' => $currentTimestamp
                        ];
                        
                        $totalRewardsPaid += $calculatedRewards;
                        $processedCount++;
                    }
                }
            }
            
            $this->contractState['lastCompletedCheck'] = $currentTimestamp;
            
            return [
                'success' => true,
                'completedStakings' => $completedStakings,
                'totalRewardsPaid' => $totalRewardsPaid,
                'processedCount' => $processedCount,
                'remainingStakers' => count($activeStakers) - $processedCount,
                'gasUsed' => $this->estimateGasForBatchOperation($processedCount)
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => "Failed to complete expired stakings: " . $e->getMessage(),
                'completedStakings' => $completedStakings,
                'totalRewardsPaid' => $totalRewardsPaid
            ];
        }
    }
    
    /**
     * Get comprehensive staking information
     */
    public function getStakingInfo(string $userAddress, int $currentTimestamp): array
    {
        if (!isset($this->contractState['stakes'][$userAddress])) {
            return [
                'success' => false,
                'error' => 'No staking found for user'
            ];
        }
        
        $stakeAmount = $this->contractState['stakes'][$userAddress];
        $startTime = $this->contractState['stakingStartBlock'][$userAddress];
        $completed = $this->contractState['isCompleted'][$userAddress];
        $rewards = $this->contractState['rewards'][$userAddress] ?? 0;
        $penalty = $this->contractState['penaltyAmount'][$userAddress] ?? 0;
        
        if (!$completed && $startTime > 0) {
            $stakingTime = $currentTimestamp - $startTime;
            $expectedDuration = $this->contractState['stakingDuration'];
            $timeRemaining = max(0, $expectedDuration - $stakingTime);
            $totalRewards = $this->calculateRewards($userAddress, $stakingTime);
        } else {
            $stakingTime = 0;
            $timeRemaining = 0;
            $totalRewards = $rewards;
        }
        
        // Calculate bonus tier
        $bonusTier = 'Base';
        foreach ($this->contractState['bonusTiers'] as $tier) {
            if ($stakingTime >= $tier['duration']) {
                $bonusTier = $tier['bonus'] . '%';
            }
        }
        
        return [
            'success' => true,
            'stakeAmount' => $stakeAmount,
            'startTime' => $startTime,
            'stakingTime' => $stakingTime,
            'timeRemaining' => $timeRemaining,
            'rewards' => $rewards,
            'totalRewards' => $totalRewards,
            'penalty' => $penalty,
            'completed' => $completed,
            'bonusTier' => $bonusTier,
            'estimatedAPY' => $this->calculateEstimatedAPY($stakingTime, $totalRewards, $stakeAmount)
        ];
    }
    
    /**
     * Get contract statistics
     */
    public function getContractStats(): array
    {
        $totalStakers = count(array_filter($this->contractState['stakes'], function($amount) {
            return $amount > 0;
        }));
        
        $completedStakers = count(array_filter($this->contractState['isCompleted'], function($completed) {
            return $completed;
        }));
        
        $activeStakers = $totalStakers - $completedStakers;
        
        return [
            'totalStaked' => $this->contractState['totalStaked'],
            'totalRewardsPaid' => $this->contractState['totalRewardsPaid'],
            'totalStakers' => $totalStakers,
            'activeStakers' => $activeStakers,
            'completedStakers' => $completedStakers,
            'minimumStake' => $this->contractState['minimumStake'],
            'rewardPerBlock' => $this->contractState['rewardPerBlock'],
            'stakingDuration' => $this->contractState['stakingDuration'],
            'earlyWithdrawalPenalty' => $this->contractState['earlyWithdrawalPenalty'],
            'lastCompletedCheck' => $this->contractState['lastCompletedCheck']
        ];
    }
    
    /**
     * Calculate estimated APY
     */
    private function calculateEstimatedAPY(int $stakingTime, float $totalRewards, float $stakeAmount): float
    {
        if ($stakeAmount == 0 || $stakingTime == 0) {
            return 0;
        }
        
        $yearSeconds = 365 * 24 * 60 * 60;
        $apy = (($totalRewards / $stakeAmount) * $yearSeconds) / $stakingTime;
        
        return round($apy * 100, 2); // Return as percentage
    }
    
    /**
     * Estimate gas cost for batch operations
     */
    private function estimateGasForBatchOperation(int $processedCount): int
    {
        // Base gas cost + gas per staking processed
        $baseGas = 21000; // Base transaction cost
        $gasPerStaking = 50000; // Estimated gas per staking processing
        
        return $baseGas + ($processedCount * $gasPerStaking);
    }
    
    /**
     * Get contract state for persistence
     */
    public function getContractState(): array
    {
        return $this->contractState;
    }
    
    /**
     * Set contract state from persisted data
     */
    public function setContractState(array $state): void
    {
        $this->contractState = $state;
    }
    
    /**
     * Get contract configuration
     */
    public function getConfig(): array
    {
        return $this->config;
    }
}