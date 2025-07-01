<?php
declare(strict_types=1);

namespace Blockchain\Core\Blockchain;

use Blockchain\Core\Contracts\BlockchainInterface;
use Blockchain\Core\Contracts\BlockInterface;
use Blockchain\Core\Contracts\TransactionInterface;
use Blockchain\Core\Transaction\Transaction;
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
    public function addTransaction(Transaction $transaction): bool
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
    public function getLatestBlock(): ?Block
    {
        $blockCount = $this->storage->getBlockCount();
        if ($blockCount === 0) {
            return null;
        }
        return $this->storage->getBlockByIndex($blockCount - 1);
    }

    /**
     * Get block by index
     */
    public function getBlock(int $index): ?Block
    {
        if ($index < 0 || $index >= $this->storage->getBlockCount()) {
            return null;
        }
        
        return $this->storage->getBlockByIndex($index);
    }

    /**
     * Get block by hash
     */
    public function getBlockByHash(string $hash): ?Block
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
    public function isValid(): bool
    {
        return $this->isChainValid();
    }

    /**
     * Get blockchain height
     */
    public function getHeight(): int
    {
        return $this->storage->getBlockCount();
    }

    /**
     * Mine a new block with pending transactions
     */
    public function mineBlock(string $minerAddress): ?Block
    {
        return $this->createBlock($minerAddress);
    }

    /**
     * Get transaction by hash
     */
    public function getTransaction(string $hash): ?Transaction
    {
        $blockCount = $this->storage->getBlockCount();
        
        for ($i = 0; $i < $blockCount; $i++) {
            $block = $this->storage->getBlockByIndex($i);
            
            foreach ($block->getTransactions() as $transaction) {
                if ($transaction instanceof Transaction && $transaction->getHash() === $hash) {
                    return $transaction;
                }
            }
        }
        
        return null;
    }

    /**
     * Check entire chain validity (private method)
     */
    private function isChainValid(): bool
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

    /**
     * Create genesis block with database configuration
     */
    public static function createGenesisWithDatabase(\PDO $database, array $config = []): Block
    {
        // Add logging
        $logFile = __DIR__ . '/../../web-installer/install_debug.log';
        $logMessage = "\n=== BLOCKCHAIN CLASS DEBUG " . date('Y-m-d H:i:s') . " ===\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        
        $logMessage = "createGenesisWithDatabase called with config: " . json_encode($config) . "\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        
        try {
            $genesisTransactions = [
                [
                    'hash' => hash('sha256', 'genesis_network_' . time()),
                    'type' => 'genesis',
                    'from' => 'genesis',
                    'to' => 'genesis_address',
                    'amount' => (float)($config['initial_supply'] ?? 1000000),
                    'timestamp' => time(),
                    'network_name' => $config['network_name'] ?? 'Blockchain Network',
                    'token_symbol' => $config['token_symbol'] ?? 'TOKEN',
                    'consensus' => $config['consensus_algorithm'] ?? 'pos'
                ]
            ];
            
            $logMessage = "Genesis transactions array created\n";
            file_put_contents($logFile, $logMessage, FILE_APPEND);
            
            // Add wallet funding transaction if specified
            if (!empty($config['wallet_address']) && !empty($config['primary_wallet_amount'])) {
                $genesisTransactions[] = [
                    'hash' => hash('sha256', 'genesis_wallet_' . $config['wallet_address'] . '_' . time()),
                    'type' => 'transfer',
                    'from' => 'genesis_address',
                    'to' => $config['wallet_address'],
                    'amount' => (float)$config['primary_wallet_amount'],
                    'timestamp' => time() + 1
                ];
                
                $logMessage = "Added wallet funding transaction for: " . $config['wallet_address'] . "\n";
                file_put_contents($logFile, $logMessage, FILE_APPEND);
                
                // Add staking transaction to genesis block
                if (!empty($config['staking_amount'])) {
                    $genesisTransactions[] = [
                        'hash' => hash('sha256', 'genesis_stake_' . $config['wallet_address'] . '_' . time()),
                        'type' => 'stake',
                        'from' => $config['wallet_address'],
                        'to' => 'staking_contract',
                        'amount' => (float)$config['staking_amount'],
                        'timestamp' => time() + 2,
                        'metadata' => [
                            'action' => 'stake',
                            'validator' => $config['wallet_address'],
                            'min_stake' => $config['min_stake_amount'] ?? 1000
                        ]
                    ];
                    
                    $logMessage = "Added staking transaction for: " . $config['staking_amount'] . " tokens\n";
                    file_put_contents($logFile, $logMessage, FILE_APPEND);
                }
                
                // Add validator registration transaction
                $genesisTransactions[] = [
                    'hash' => hash('sha256', 'genesis_validator_' . $config['wallet_address'] . '_' . time()),
                    'type' => 'register_validator',
                    'from' => $config['wallet_address'],
                    'to' => 'validator_registry',
                    'amount' => 0.0,
                    'timestamp' => time() + 3,
                    'metadata' => [
                        'action' => 'register_validator',
                        'validator_address' => $config['wallet_address'],
                        'commission_rate' => 0.1,
                        'min_delegation' => $config['min_stake_amount'] ?? 1000
                    ]
                ];
                
                $logMessage = "Added validator registration transaction\n";
                file_put_contents($logFile, $logMessage, FILE_APPEND);
                
                // Add genesis node registration transaction
                $genesisTransactions[] = [
                    'hash' => hash('sha256', 'genesis_node_' . $config['wallet_address'] . '_' . time()),
                    'type' => 'register_node',
                    'from' => $config['wallet_address'],
                    'to' => 'node_registry',
                    'amount' => 0.0,
                    'timestamp' => time() + 4,
                    'metadata' => [
                        'action' => 'register_node',
                        'node_type' => 'primary',
                        'node_domain' => $config['node_domain'] ?? 'localhost',
                        'protocol' => $config['protocol'] ?? 'https',
                        'version' => '1.0.0',
                        'public_key' => 'genesis_public_key_placeholder'
                    ]
                ];
                
                $logMessage = "Added genesis node registration transaction\n";
                file_put_contents($logFile, $logMessage, FILE_APPEND);
            }
            
            $logMessage = "Creating Block instance...\n";
            file_put_contents($logFile, $logMessage, FILE_APPEND);
            
            $genesisBlock = new Block(0, $genesisTransactions, '0');
            
            $logMessage = "Block instance created successfully\n";
            file_put_contents($logFile, $logMessage, FILE_APPEND);
            
            // Save to database using BlockStorage
            $logMessage = "Creating BlockStorage instance...\n";
            file_put_contents($logFile, $logMessage, FILE_APPEND);
            
            $blockStorage = new \Blockchain\Core\Storage\BlockStorage('blockchain.json', $database);
            
            $logMessage = "BlockStorage created, calling saveToDatabaseStorage...\n";
            file_put_contents($logFile, $logMessage, FILE_APPEND);
            
            $result = $blockStorage->saveToDatabaseStorage($genesisBlock);
            
            $logMessage = "saveToDatabaseStorage result: " . ($result ? 'SUCCESS' : 'FAILED') . "\n";
            file_put_contents($logFile, $logMessage, FILE_APPEND);
            
            // Update config table with genesis hash
            try {
                $logMessage = "Updating config table...\n";
                file_put_contents($logFile, $logMessage, FILE_APPEND);
                
                $stmt = $database->prepare("UPDATE config SET value = ? WHERE key_name = 'blockchain.genesis_block'");
                $configResult = $stmt->execute([$genesisBlock->getHash()]);
                
                $logMessage = "Config update result: " . ($configResult ? 'SUCCESS' : 'FAILED') . "\n";
                file_put_contents($logFile, $logMessage, FILE_APPEND);
                
            } catch (\PDOException $e) {
                $logMessage = "Config update failed: " . $e->getMessage() . "\n";
                file_put_contents($logFile, $logMessage, FILE_APPEND);
                // Continue if config update fails
            }
            
            $logMessage = "createGenesisWithDatabase completed successfully\n";
            file_put_contents($logFile, $logMessage, FILE_APPEND);
            
            // Also initialize binary storage if enabled
            if (!empty($config['enable_binary_storage'])) {
                try {
                    $logMessage = "Initializing binary storage...\n";
                    file_put_contents($logFile, $logMessage, FILE_APPEND);
                    
                    $dataDir = dirname($logFile) . '/../storage/blockchain';
                    if (!is_dir($dataDir)) {
                        mkdir($dataDir, 0755, true);
                    }
                    
                    $binaryConfig = [
                        'enable_binary_storage' => true,
                        'enable_encryption' => $config['enable_encryption'] ?? false,
                        'data_dir' => 'storage/blockchain',
                        'network_name' => $config['network_name'] ?? 'Blockchain Network',
                        'token_symbol' => $config['token_symbol'] ?? 'TOKEN'
                    ];
                    
                    if (class_exists('\\Blockchain\\Core\\Storage\\BlockchainBinaryStorage')) {
                        $binaryStorage = new \Blockchain\Core\Storage\BlockchainBinaryStorage(
                            $dataDir,
                            $binaryConfig,
                            false
                        );
                        
                        // Convert genesis block to binary format
                        $binaryGenesisData = [
                            'index' => $genesisBlock->getIndex(),
                            'timestamp' => $genesisBlock->getTimestamp(),
                            'previous_hash' => $genesisBlock->getPreviousHash(),
                            'hash' => $genesisBlock->getHash(),
                            'merkle_root' => $genesisBlock->getMerkleRoot(),
                            'nonce' => $genesisBlock->getNonce(),
                            'transactions' => $genesisTransactions // Changed from 'data' to 'transactions'
                        ];
                        
                        $binaryResult = $binaryStorage->appendBlock($binaryGenesisData);
                        $logMessage = "Binary storage result: " . ($binaryResult ? 'SUCCESS' : 'FAILED') . "\n";
                        file_put_contents($logFile, $logMessage, FILE_APPEND);
                    }
                    
                } catch (\Exception $e) {
                    $logMessage = "Binary storage initialization failed: " . $e->getMessage() . "\n";
                    file_put_contents($logFile, $logMessage, FILE_APPEND);
                    // Continue even if binary storage fails
                }
            }
            
            return $genesisBlock;
            
        } catch (\Exception $e) {
            $logMessage = "ERROR in createGenesisWithDatabase: " . $e->getMessage() . "\n";
            $logMessage .= "Stack trace: " . $e->getTraceAsString() . "\n";
            file_put_contents($logFile, $logMessage, FILE_APPEND);
            throw $e;
        }
    }
}
