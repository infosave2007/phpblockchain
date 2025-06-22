<?php
declare(strict_types=1);

namespace Blockchain\Core\Blockchain;

use Blockchain\Core\Contracts\BlockchainInterface;
use Blockchain\Core\Contracts\BlockInterface;
use Blockchain\Core\Contracts\TransactionInterface;
use Blockchain\Core\Storage\BlockStorage;
use Blockchain\Core\Consensus\ProofOfStake;
use Blockchain\Core\Network\NodeManager;
use Blockchain\Core\Crypto\Hash;
use Blockchain\Core\Events\BlockAddedEvent;
use Blockchain\Core\Events\EventDispatcher;

/**
 * Main blockchain class with PoS support and smart contracts
 */
class Blockchain implements BlockchainInterface
{
    private BlockStorage $storage;
    private ProofOfStake $consensus;
    private NodeManager $nodeManager;
    private EventDispatcher $eventDispatcher;
    private array $pendingTransactions;
    private array $stakeholders;
    private int $blockTime;
    private int $maxTransactionsPerBlock;
    private string $genesisHash;
    private array $contractStorage;

    public function __construct(
        BlockStorage $storage,
        ProofOfStake $consensus,
        NodeManager $nodeManager,
        EventDispatcher $eventDispatcher
    ) {
        $this->storage = $storage;
        $this->consensus = $consensus;
        $this->nodeManager = $nodeManager;
        $this->eventDispatcher = $eventDispatcher;
        $this->pendingTransactions = [];
        $this->stakeholders = [];
        $this->contractStorage = [];
        $this->blockTime = 10; // 10 seconds between blocks
        $this->maxTransactionsPerBlock = 1000;
        $this->contractStorage = [];
        
        $this->initializeGenesis();
    }

    /**
     * Initialize genesis block
     */
    private function initializeGenesis(): void
    {
        if ($this->storage->getBlockCount() === 0) {
            $genesisBlock = $this->createGenesisBlock();
            $this->storage->saveBlock($genesisBlock);
            $this->genesisHash = $genesisBlock->getHash();
        } else {
            $genesisBlock = $this->storage->getBlockByIndex(0);
            $this->genesisHash = $genesisBlock->getHash();
        }
    }

    /**
     * Create genesis block
     */
    private function createGenesisBlock(): Block
    {
        $genesisTransactions = [
            // Initial token distribution
            [
                'type' => 'genesis',
                'to' => 'genesis_address',
                'amount' => 1000000,
                'timestamp' => time()
            ]
        ];

        return new Block(0, $genesisTransactions, '0');
    }

    /**
     * Add new block
     */
    public function addBlock(BlockInterface $block): bool
    {
        // Check block validity
        if (!$this->isBlockValid($block)) {
            return false;
        }

        // Check consensus
        if (!$this->consensus->validateBlock($block, $this->stakeholders)) {
            return false;
        }

        // Save block
        $this->storage->saveBlock($block);

        // Update network state
        $this->updateNetworkState($block);

        // Notify network nodes
        $this->nodeManager->broadcastBlock($block);

        // Dispatch event
        $this->eventDispatcher->dispatch(new BlockAddedEvent($block));

        return true;
    }

    /**
     * Create new block from pending transactions
     */
    public function createBlock(string $validatorAddress): ?Block
    {
        $lastBlock = $this->getLatestBlock();
        $nextIndex = $lastBlock->getIndex() + 1;

        // Select transactions for block
        $transactions = array_slice($this->pendingTransactions, 0, $this->maxTransactionsPerBlock);
        
        if (empty($transactions)) {
            return null;
        }

        // Check validator rights
        if (!$this->consensus->canValidate($validatorAddress, $this->stakeholders)) {
            return null;
        }

        // Create block
        $block = new Block(
            $nextIndex,
            $transactions,
            $lastBlock->getHash(),
            [$validatorAddress],
            $this->stakeholders
        );

        // Execute smart contracts
        $this->executeSmartContracts($block);

        // Sign block with validator
        $this->consensus->signBlock($block, $validatorAddress);

        return $block;
    }

    /**
     * Execute smart contracts in block
     */
    private function executeSmartContracts(Block $block): void
    {
        foreach ($block->getTransactions() as $transaction) {
            if ($transaction instanceof TransactionInterface && $transaction->hasSmartContract()) {
                $contractResult = $this->executeSmartContract($transaction);
                $block->addSmartContractResult($transaction->getTo(), $contractResult);
            }
        }
    }

    /**
     * Execute single smart contract with real VM
     */
    private function executeSmartContract(TransactionInterface $transaction): array
    {
        try {
            // Get contract bytecode
            $contractAddress = $transaction->getTo();
            $bytecode = $this->getContractBytecode($contractAddress);
            
            if (empty($bytecode)) {
                throw new Exception("Contract not found at address: $contractAddress");
            }
            
            // Create VM instance
            $vm = new \Blockchain\Core\SmartContract\VirtualMachine($transaction->getGasLimit());
            
            // Prepare execution context
            $context = [
                'caller' => $transaction->getFrom(),
                'value' => $transaction->getAmount(),
                'gasPrice' => $transaction->getGasPrice(),
                'blockNumber' => $this->getBlockHeight(),
                'timestamp' => time(),
                'getBalance' => function($address) {
                    return $this->getBalance($address);
                }
            ];
            
            // Execute contract
            $result = $vm->execute($bytecode, $context);
            
            // Apply state changes if execution was successful
            if ($result['success'] && !empty($result['stateChanges'])) {
                $this->applyStateChanges($contractAddress, $result['stateChanges']);
            }
            
            return $result;
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'gasUsed' => $transaction->getGasLimit(),
                'error' => $e->getMessage(),
                'logs' => []
            ];
        }
    }
    
    /**
     * Get contract bytecode from storage
     */
    private function getContractBytecode(string $address): string
    {
        // In a real implementation, this would query contract storage
        // For now, return empty bytecode for non-contract addresses
        if (substr($address, 0, 2) !== '0x' || strlen($address) !== 42) {
            return '';
        }
        
        // Check if this is a contract address
        $contractData = $this->contractStorage[$address] ?? null;
        if ($contractData && isset($contractData['bytecode'])) {
            return $contractData['bytecode'];
        }
        
        return '';
    }
    
    /**
     * Apply smart contract state changes
     */
    private function applyStateChanges(string $contractAddress, array $stateChanges): void
    {
        if (!isset($this->contractStorage[$contractAddress])) {
            $this->contractStorage[$contractAddress] = ['state' => []];
        }
        
        foreach ($stateChanges as $key => $value) {
            $this->contractStorage[$contractAddress]['state'][$key] = $value;
        }
    }

    /**
     * Add transaction to pending pool
     */
    public function addTransaction(TransactionInterface $transaction): bool
    {
        if (!$transaction->isValid()) {
            return false;
        }

        // Check that sender has sufficient funds
        if (!$this->hasBalance($transaction->getFrom(), $transaction->getAmount())) {
            return false;
        }

        $this->pendingTransactions[] = $transaction;
        
        // Notify network nodes
        $this->nodeManager->broadcastTransaction($transaction);

        return true;
    }

    /**
     * Check address balance
     */
    public function hasBalance(string $address, float $amount): bool
    {
        $balance = $this->getBalance($address);
        return $balance >= $amount;
    }

    /**
     * Get address balance
     */
    public function getBalance(string $address): float
    {
        $balance = 0.0;
        $blockCount = $this->storage->getBlockCount();

        for ($i = 0; $i < $blockCount; $i++) {
            $block = $this->storage->getBlockByIndex($i);
            
            foreach ($block->getTransactions() as $transaction) {
                if ($transaction instanceof TransactionInterface) {
                    if ($transaction->getTo() === $address) {
                        $balance += $transaction->getAmount();
                    }
                    if ($transaction->getFrom() === $address) {
                        $balance -= $transaction->getAmount();
                        $balance -= $transaction->getFee();
                    }
                }
            }
        }

        return $balance;
    }

    /**
     * Get latest block
     */
    public function getLatestBlock(): BlockInterface
    {
        $blockCount = $this->storage->getBlockCount();
        return $this->storage->getBlockByIndex($blockCount - 1);
    }

    /**
     * Get block by index
     */
    public function getBlock(int $index): ?BlockInterface
    {
        if ($index < 0 || $index >= $this->storage->getBlockCount()) {
            return null;
        }
        
        return $this->storage->getBlockByIndex($index);
    }

    /**
     * Get block by hash
     */
    public function getBlockByHash(string $hash): ?BlockInterface
    {
        return $this->storage->getBlockByHash($hash);
    }

    /**
     * Check block validity
     */
    private function isBlockValid(BlockInterface $block): bool
    {
        // Check basic block validity
        if (!$block->isValid()) {
            return false;
        }

        // Check connection with previous block
        $previousBlock = $this->storage->getBlockByIndex($block->getIndex() - 1);
        if (!$previousBlock || $previousBlock->getHash() !== $block->getPreviousHash()) {
            return false;
        }

        // Check timestamps
        if ($block->getTimestamp() <= $previousBlock->getTimestamp()) {
            return false;
        }

        return true;
    }

    /**
     * Check entire chain validity
     */
    public function isChainValid(): bool
    {
        $blockCount = $this->storage->getBlockCount();
        
        for ($i = 1; $i < $blockCount; $i++) {
            $currentBlock = $this->storage->getBlockByIndex($i);
            $previousBlock = $this->storage->getBlockByIndex($i - 1);
            
            if (!$currentBlock->isValid()) {
                return false;
            }
            
            if ($currentBlock->getPreviousHash() !== $previousBlock->getHash()) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Update network state after adding block
     */
    private function updateNetworkState(BlockInterface $block): void
    {
        // Remove processed transactions from pending pool
        $blockTransactions = $block->getTransactions();
        $this->pendingTransactions = array_filter($this->pendingTransactions, function($pendingTx) use ($blockTransactions) {
            foreach ($blockTransactions as $blockTx) {
                if ($blockTx instanceof TransactionInterface && $pendingTx instanceof TransactionInterface) {
                    if ($blockTx->getHash() === $pendingTx->getHash()) {
                        return false;
                    }
                }
            }
            return true;
        });

        // Update stakeholders
        $this->updateStakeholders($block);
    }

    /**
     * Update stakeholders
     */
    private function updateStakeholders(BlockInterface $block): void
    {
        foreach ($block->getTransactions() as $transaction) {
            if ($transaction instanceof TransactionInterface) {
                $from = $transaction->getFrom();
                $to = $transaction->getTo();
                $amount = $transaction->getAmount();

                // Update sender stake
                if (isset($this->stakeholders[$from])) {
                    $this->stakeholders[$from] = max(0, $this->stakeholders[$from] - $amount);
                }

                // Update receiver stake
                if (!isset($this->stakeholders[$to])) {
                    $this->stakeholders[$to] = 0;
                }
                $this->stakeholders[$to] += $amount;

                // Remove participants with zero stake
                if (isset($this->stakeholders[$from]) && $this->stakeholders[$from] <= 0) {
                    unset($this->stakeholders[$from]);
                }
            }
        }
    }

    /**
     * Get blockchain statistics
     */
    public function getStats(): array
    {
        $blockCount = $this->storage->getBlockCount();
        $totalTransactions = 0;
        $totalVolume = 0.0;

        for ($i = 0; $i < $blockCount; $i++) {
            $block = $this->storage->getBlockByIndex($i);
            $totalTransactions += count($block->getTransactions());
            
            foreach ($block->getTransactions() as $transaction) {
                if ($transaction instanceof TransactionInterface) {
                    $totalVolume += $transaction->getAmount();
                }
            }
        }

        return [
            'blockCount' => $blockCount,
            'totalTransactions' => $totalTransactions,
            'totalVolume' => $totalVolume,
            'pendingTransactions' => count($this->pendingTransactions),
            'stakeholders' => count($this->stakeholders),
            'genesisHash' => $this->genesisHash,
            'latestBlockHash' => $this->getLatestBlock()->getHash()
        ];
    }

    /**
     * Get pending transactions
     */
    public function getPendingTransactions(): array
    {
        return $this->pendingTransactions;
    }

    /**
     * Get stakeholders
     */
    public function getStakeholders(): array
    {
        return $this->stakeholders;
    }

    /**
     * Set block time
     */
    public function setBlockTime(int $seconds): void
    {
        $this->blockTime = $seconds;
    }

    /**
     * Get block time
     */
    public function getBlockTime(): int
    {
        return $this->blockTime;
    }
}
