<?php
declare(strict_types=1);

namespace Blockchain\Core\State;

use Exception;

/**
 * Professional State Manager
 * 
 * Manages blockchain state with efficient storage and retrieval
 */
class StateManager
{
    private array $accountStates = [];
    private array $contractStates = [];
    private array $storageRoots = [];
    private array $stateHistory = [];
    private string $currentStateRoot;
    private int $blockHeight = 0;
    
    public function __construct()
    {
        $this->currentStateRoot = str_repeat('0', 64);
    }
    
    /**
     * Load state directly from snapshot payload
     * Expected shape: ['accounts'=>[], 'contracts'=>[], 'storageRoots'=>[], 'blockHeight'=>int]
     */
    public function loadFromSnapshot(array $snapshot): bool
    {
        if (empty($snapshot['accounts']) || empty($snapshot['contracts'])) {
            return false;
        }

        // Assign with sane defaults
        $this->accountStates = is_array($snapshot['accounts']) ? $snapshot['accounts'] : [];
        $this->contractStates = is_array($snapshot['contracts']) ? $snapshot['contracts'] : [];
        $this->storageRoots = is_array($snapshot['storageRoots'] ?? null) ? $snapshot['storageRoots'] : [];
        $this->blockHeight = (int)($snapshot['blockHeight'] ?? 0);

        // Recalculate and set current state root
        $this->updateStateRoot();
        return true;
    }

    /**
     * Get account balance
     */
    public function getBalance(string $address): float
    {
        return $this->accountStates[$address]['balance'] ?? 0.0;
    }
    
    /**
     * Set account balance
     */
    public function setBalance(string $address, float $balance): void
    {
        if (!isset($this->accountStates[$address])) {
            $this->accountStates[$address] = [
                'balance' => 0.0,
                'nonce' => 0,
                'codeHash' => '',
                'storageRoot' => str_repeat('0', 64)
            ];
        }
        
        $this->accountStates[$address]['balance'] = $balance;
    }
    
    /**
     * Get account nonce
     */
    public function getNonce(string $address): int
    {
        return $this->accountStates[$address]['nonce'] ?? 0;
    }
    
    /**
     * Increment account nonce
     */
    public function incrementNonce(string $address): void
    {
        if (!isset($this->accountStates[$address])) {
            $this->accountStates[$address] = [
                'balance' => 0.0,
                'nonce' => 0,
                'codeHash' => '',
                'storageRoot' => str_repeat('0', 64)
            ];
        }
        
        $this->accountStates[$address]['nonce']++;
    }
    
    /**
     * Transfer funds between accounts
     */
    public function transfer(string $from, string $to, float $amount): bool
    {
        if ($this->getBalance($from) < $amount) {
            return false;
        }
        
        $this->setBalance($from, $this->getBalance($from) - $amount);
        $this->setBalance($to, $this->getBalance($to) + $amount);
        
        return true;
    }
    
    /**
     * Create new contract account
     */
    public function createContract(string $address, string $bytecode): void
    {
        $codeHash = hash('sha256', $bytecode);
        
        $this->accountStates[$address] = [
            'balance' => 0.0,
            'nonce' => 1,
            'codeHash' => $codeHash,
            'storageRoot' => str_repeat('0', 64)
        ];
        
        $this->contractStates[$address] = [
            'bytecode' => $bytecode,
            'storage' => [],
            'created' => time()
        ];
    }
    
    /**
     * Get contract bytecode
     */
    public function getContractCode(string $address): string
    {
        return $this->contractStates[$address]['bytecode'] ?? '';
    }
    
    /**
     * Set contract storage
     */
    public function setContractStorage(string $address, string $key, string $value): void
    {
        if (!isset($this->contractStates[$address])) {
            throw new Exception("Contract not found at address: $address");
        }
        
        $this->contractStates[$address]['storage'][$key] = $value;
        
        // Update storage root
        $this->updateStorageRoot($address);
    }
    
    /**
     * Get contract storage
     */
    public function getContractStorage(string $address, string $key): string
    {
        return $this->contractStates[$address]['storage'][$key] ?? '';
    }
    
    /**
     * Get all contract storage
     */
    public function getAllContractStorage(string $address): array
    {
        return $this->contractStates[$address]['storage'] ?? [];
    }
    
    /**
     * Check if address is contract
     */
    public function isContract(string $address): bool
    {
        return isset($this->contractStates[$address]) && 
               !empty($this->accountStates[$address]['codeHash']);
    }
    
    /**
     * Update storage root for contract
     */
    private function updateStorageRoot(string $address): void
    {
        if (!isset($this->contractStates[$address])) {
            return;
        }
        
        $storage = $this->contractStates[$address]['storage'];
        $storageItems = [];
        
        foreach ($storage as $key => $value) {
            $storageItems[] = $key . ':' . $value;
        }
        
        $storageRoot = hash('sha256', implode('|', $storageItems));
        $this->accountStates[$address]['storageRoot'] = $storageRoot;
        $this->storageRoots[$address] = $storageRoot;
    }
    
    /**
     * Create state snapshot
     */
    public function createSnapshot(): string
    {
        $snapshot = [
            'accounts' => $this->accountStates,
            'contracts' => $this->contractStates,
            'storageRoots' => $this->storageRoots,
            'blockHeight' => $this->blockHeight,
            'timestamp' => time()
        ];
        
        $snapshotId = hash('sha256', json_encode($snapshot));
        $this->stateHistory[$snapshotId] = $snapshot;
        
        return $snapshotId;
    }
    
    /**
     * Restore state from snapshot
     */
    public function restoreSnapshot(string $snapshotId): bool
    {
        if (!isset($this->stateHistory[$snapshotId])) {
            return false;
        }
        
        $snapshot = $this->stateHistory[$snapshotId];
        
        $this->accountStates = $snapshot['accounts'];
        $this->contractStates = $snapshot['contracts'];
        $this->storageRoots = $snapshot['storageRoots'];
        $this->blockHeight = $snapshot['blockHeight'];
        
        $this->updateStateRoot();
        
        return true;
    }
    
    /**
     * Calculate current state root
     */
    public function calculateStateRoot(): string
    {
        $stateItems = [];
        
        // Add account states
        foreach ($this->accountStates as $address => $state) {
            $stateItems[] = $address . ':' . json_encode($state);
        }
        
        // Add contract storage roots
        foreach ($this->storageRoots as $address => $root) {
            $stateItems[] = $address . ':storage:' . $root;
        }
        
        sort($stateItems); // Ensure deterministic order
        
        $this->currentStateRoot = hash('sha256', implode('|', $stateItems));
        return $this->currentStateRoot;
    }
    
    /**
     * Update state root
     */
    private function updateStateRoot(): void
    {
        $this->calculateStateRoot();
    }
    
    /**
     * Get current state root
     */
    public function getStateRoot(): string
    {
        return $this->currentStateRoot;
    }
    
    /**
     * Set block height
     */
    public function setBlockHeight(int $height): void
    {
        $this->blockHeight = $height;
    }
    
    /**
     * Get block height
     */
    public function getBlockHeight(): int
    {
        return $this->blockHeight;
    }
    
    /**
     * Get all accounts
     */
    public function getAllAccounts(): array
    {
        return array_keys($this->accountStates);
    }
    
    /**
     * Get account state
     */
    public function getAccountState(string $address): array
    {
        return $this->accountStates[$address] ?? [
            'balance' => 0.0,
            'nonce' => 0,
            'codeHash' => '',
            'storageRoot' => str_repeat('0', 64)
        ];
    }
    
    /**
     * Apply state changes from transaction
     */
    public function applyTransaction(array $changes): void
    {
        foreach ($changes as $address => $change) {
            if (isset($change['balance'])) {
                $this->setBalance($address, $change['balance']);
            }
            
            if (isset($change['nonce'])) {
                $this->accountStates[$address]['nonce'] = $change['nonce'];
            }
            
            if (isset($change['storage'])) {
                foreach ($change['storage'] as $key => $value) {
                    $this->setContractStorage($address, $key, $value);
                }
            }
        }
        
        $this->updateStateRoot();
    }
    
    /**
     * Commit state to storage
     */
    public function commit(): string
    {
        $stateRoot = $this->calculateStateRoot();
        
        // In a real implementation, this would persist to disk
        // For now, we just update the current state root
        $this->currentStateRoot = $stateRoot;
        
        return $stateRoot;
    }
    
    /**
     * Rollback to previous state
     */
    public function rollback(string $previousStateRoot): bool
    {
        // In a real implementation, this would restore from disk
        // For now, we just search through history
        foreach ($this->stateHistory as $snapshotId => $snapshot) {
            if ($snapshot['stateRoot'] ?? '' === $previousStateRoot) {
                return $this->restoreSnapshot($snapshotId);
            }
        }
        
        return false;
    }
    
    /**
     * Get state statistics
     */
    public function getStatistics(): array
    {
        $totalBalance = 0;
        $totalAccounts = count($this->accountStates);
        $totalContracts = count($this->contractStates);
        
        foreach ($this->accountStates as $state) {
            $totalBalance += $state['balance'];
        }
        
        return [
            'totalAccounts' => $totalAccounts,
            'totalContracts' => $totalContracts,
            'totalBalance' => $totalBalance,
            'stateRoot' => $this->currentStateRoot,
            'blockHeight' => $this->blockHeight,
            'snapshotCount' => count($this->stateHistory)
        ];
    }
    
    /**
     * Validate state integrity
     */
    public function validateState(): bool
    {
        try {
            // Check all account balances are non-negative
            foreach ($this->accountStates as $address => $state) {
                if ($state['balance'] < 0) {
                    return false;
                }
            }
            
            // Check all contracts have valid bytecode
            foreach ($this->contractStates as $address => $contract) {
                if (empty($contract['bytecode'])) {
                    return false;
                }
            }
            
            // Recalculate state root and compare
            $calculatedRoot = $this->calculateStateRoot();
            return $calculatedRoot === $this->currentStateRoot;
            
        } catch (Exception $e) {
            return false;
        }
    }
}
