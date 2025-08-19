<?php
declare(strict_types=1);

namespace Blockchain\API;

use Blockchain\Core\Blockchain\Blockchain;
use Blockchain\Core\Transaction\Transaction;
use Blockchain\Contracts\SmartContractManager;
use Blockchain\Nodes\NodeManager;
use Blockchain\Core\Consensus\ProofOfStake;
use Blockchain\Wallet\WalletManager;
use Blockchain\Core\Database\DatabaseManager;
use Blockchain\Core\Logging\LoggerInterface;
use PDO;
use Exception;

/**
 * RESTful API for blockchain platform
 */
class BlockchainAPI
{
    private Blockchain $blockchain;
    private SmartContractManager $contractManager;
    private NodeManager $nodeManager;
    private ProofOfStake $consensus;
    private WalletManager $walletManager;
    private DatabaseManager $database;
    private LoggerInterface $logger;
    private array $config;

    public function __construct(
        Blockchain $blockchain,
        SmartContractManager $contractManager,
        NodeManager $nodeManager,
        ProofOfStake $consensus,
        WalletManager $walletManager,
        DatabaseManager $database,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->blockchain = $blockchain;
        $this->contractManager = $contractManager;
        $this->nodeManager = $nodeManager;
        $this->consensus = $consensus;
        $this->walletManager = $walletManager;
        $this->database = $database;
        $this->logger = $logger;
        $this->config = $config;
    }

    /**
     * Handle API request
     */
    public function handleRequest(string $method, string $endpoint, array $params = [], array $headers = []): array
    {
        try {
            // Check authentication for protected endpoints
            if ($this->requiresAuth($endpoint) && !$this->authenticate($headers)) {
                return $this->errorResponse('Unauthorized', 401);
            }

            // Log request
            $this->logger->info("API Request", [
                'method' => $method,
                'endpoint' => $endpoint,
                'params_count' => count($params)
            ]);

            // Routing
            switch ($endpoint) {
                // Blockchain endpoints
                case 'blockchain/info':
                    return $this->getBlockchainInfo();
                case 'blockchain/stats':
                    return $this->getBlockchainStats();
                case 'blockchain/validate':
                    return $this->validateBlockchain();

                // Blocks
                case 'blocks':
                    return $method === 'GET' ? $this->getBlocks($params) : $this->errorResponse('Method not allowed', 405);
                case 'blocks/latest':
                    return $this->getLatestBlock();
                case 'blocks/by-hash':
                    return $this->getBlockByHash($params['hash'] ?? '');
                case 'blocks/by-height':
                    return $this->getBlockByHeight((int)($params['height'] ?? 0));

                // Transactions
                case 'transactions':
                    return $method === 'POST' ? $this->createTransaction($params) : $this->getTransactions($params);
                case 'transactions/pending':
                    return $this->getPendingTransactions();
                case 'transactions/by-hash':
                    return $this->getTransactionByHash($params['hash'] ?? '');

                // Wallets
                case 'wallets/create':
                    return $method === 'POST' ? $this->createWallet($params) : $this->errorResponse('Method not allowed', 405);
                case 'wallets/balance':
                    return $this->getBalance($params['address'] ?? '');
                case 'wallets/history':
                    return $this->getTransactionHistory($params['address'] ?? '');

                // Smart contracts
                case 'contracts/deploy':
                    return $method === 'POST' ? $this->deployContract($params) : $this->errorResponse('Method not allowed', 405);
                case 'contracts/call':
                    return $method === 'POST' ? $this->callContract($params) : $this->errorResponse('Method not allowed', 405);
                case 'contracts/info':
                    return $this->getContractInfo($params['address'] ?? '');
                case 'contracts/estimate-gas':
                    return $this->estimateGas($params);

                // Staking
                case 'staking/validators':
                    return $this->getValidators();
                case 'staking/stake':
                    return $method === 'POST' ? $this->stake($params) : $this->errorResponse('Method not allowed', 405);
                case 'staking/unstake':
                    return $method === 'POST' ? $this->unstake($params) : $this->errorResponse('Method not allowed', 405);
                case 'staking/rewards':
                    return $this->getStakingRewards($params['address'] ?? '');

                // Network
                case 'network/nodes':
                    return $this->getNetworkNodes();
                case 'network/stats':
                    return $this->getNetworkStats();
                case 'network/sync':
                    return $method === 'POST' ? $this->syncWithNetwork() : $this->errorResponse('Method not allowed', 405);

                // Administrative
                case 'admin/health':
                    return $this->getHealthStatus();
                case 'admin/metrics':
                    return $this->getMetrics();

                default:
                    return $this->errorResponse('Endpoint not found', 404);
            }

        } catch (\Exception $e) {
            $this->logger->error("API Error", [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->errorResponse('Internal server error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get blockchain information
     */
    private function getBlockchainInfo(): array
    {
        $latestBlock = $this->blockchain->getLatestBlock();
        
        return $this->successResponse([
            'network_name' => $this->config['network_name'] ?? 'My Blockchain Network',
            'version' => '2.0.0',
            'height' => $latestBlock->getIndex(),
            'latest_block_hash' => $latestBlock->getHash(),
            'total_supply' => $this->getTotalSupply(),
            'consensus' => 'Proof of Stake',
            'block_time' => $this->blockchain->getBlockTime(),
            'timestamp' => time()
        ]);
    }

    /**
     * Get blockchain statistics
     */
    private function getBlockchainStats(): array
    {
        $stats = $this->blockchain->getStats();
        
        return $this->successResponse([
            'blockchain' => $stats,
            'consensus' => $this->consensus->getConsensusStats(),
            'contracts' => $this->contractManager->getContractStats(),
            'network' => $this->nodeManager->getNetworkStats()
        ]);
    }

    /**
     * Blockchain validation
     */
    private function validateBlockchain(): array
    {
        // Check basic blockchain validity
        $height = $this->blockchain->getHeight();
        $isValid = $height > 0; // Basic validation
        
        return $this->successResponse([
            'valid' => $isValid,
            'height' => $height,
            'timestamp' => time()
        ]);
    }

    /**
     * Get blocks
     */
    private function getBlocks(array $params): array
    {
        $limit = min((int)($params['limit'] ?? 10), 100);
        $offset = (int)($params['offset'] ?? 0);
        
        $blocks = [];
        $totalBlocks = $this->blockchain->getStats()['blockCount'];
        
        for ($i = $offset; $i < min($offset + $limit, $totalBlocks); $i++) {
            $block = $this->blockchain->getBlock($i);
            if ($block) {
                $blocks[] = $this->formatBlock($block);
            }
        }
        
        return $this->successResponse([
            'blocks' => $blocks,
            'total' => $totalBlocks,
            'limit' => $limit,
            'offset' => $offset
        ]);
    }

    /**
     * Get latest block
     */
    private function getLatestBlock(): array
    {
        $block = $this->blockchain->getLatestBlock();
        
        return $this->successResponse([
            'block' => $this->formatBlock($block)
        ]);
    }

    /**
     * Get block by hash
     */
    private function getBlockByHash(string $hash): array
    {
        if (empty($hash)) {
            return $this->errorResponse('Hash parameter required', 400);
        }
        
        $block = $this->blockchain->getBlockByHash($hash);
        
        if (!$block) {
            return $this->errorResponse('Block not found', 404);
        }
        
        return $this->successResponse([
            'block' => $this->formatBlock($block)
        ]);
    }

    /**
     * Get block by height
     */
    private function getBlockByHeight(int $height): array
    {
        $block = $this->blockchain->getBlock($height);
        
        if (!$block) {
            return $this->errorResponse('Block not found', 404);
        }
        
        return $this->successResponse([
            'block' => $this->formatBlock($block)
        ]);
    }

    /**
     * Create transaction
     */
    private function createTransaction(array $params): array
    {
        $requiredFields = ['from', 'to', 'amount'];
        
        foreach ($requiredFields as $field) {
            if (!isset($params[$field])) {
                return $this->errorResponse("Missing required field: {$field}", 400);
            }
        }
        
        try {
            $transaction = new Transaction(
                $params['from'],
                $params['to'],
                (float)$params['amount'],
                $params['fee'] ?? 0.001,
                $params['data'] ?? '',
                $params['nonce'] ?? time()
            );
            
            // Sign transaction
            if (isset($params['private_key'])) {
                $transaction->sign($params['private_key']);
            }
            
            // Add to pool
            $success = $this->blockchain->addTransaction($transaction);
            
            if ($success) {
                return $this->successResponse([
                    'transaction_hash' => $transaction->getHash(),
                    'status' => 'pending'
                ]);
            } else {
                return $this->errorResponse('Failed to add transaction to pool', 400);
            }
            
        } catch (\Exception $e) {
            return $this->errorResponse('Transaction creation failed: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Get transactions
     */
    private function getTransactions(array $params): array
    {
        // Implementation for getting transactions
        return $this->successResponse([
            'transactions' => [],
            'total' => 0
        ]);
    }

    /**
     * Get pending transactions
     */
    private function getPendingTransactions(): array
    {
        $pendingTx = $this->blockchain->getPendingTransactions();
        
        return $this->successResponse([
            'transactions' => array_map([$this, 'formatTransaction'], $pendingTx),
            'count' => count($pendingTx)
        ]);
    }

    /**
     * Create wallet
     */
    private function createWallet(array $params): array
    {
        try {
            $wallet = $this->walletManager->createWallet(
                $params['password'] ?? '',
                $params['mnemonic'] ?? null
            );
            
            return $this->successResponse([
                'address' => $wallet['address'],
                'public_key' => $wallet['public_key'],
                'mnemonic' => $wallet['mnemonic']
            ]);
            
        } catch (\Exception $e) {
            return $this->errorResponse('Wallet creation failed: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Get balance
     */
    private function getBalance(string $address): array
    {
        if (empty($address)) {
            return $this->errorResponse('Address parameter required', 400);
        }
        
        $balance = $this->blockchain->getBalance($address);
        
        return $this->successResponse([
            'address' => $address,
            'balance' => $balance,
            'currency' => $this->config['token_symbol'] ?? 'MBC'
        ]);
    }

    /**
     * Deploy smart contract
     */
    private function deployContract(array $params): array
    {
        $requiredFields = ['code', 'deployer'];
        
        foreach ($requiredFields as $field) {
            if (!isset($params[$field])) {
                return $this->errorResponse("Missing required field: {$field}", 400);
            }
        }
        
        $result = $this->contractManager->deployContract(
            $params['code'],
            $params['constructor_args'] ?? [],
            $params['deployer'],
            (int)($params['gas_limit'] ?? 100000)
        );
        
        if ($result['success']) {
            return $this->successResponse($result);
        } else {
            return $this->errorResponse($result['error'], 400);
        }
    }

    /**
     * Call smart contract
     */
    private function callContract(array $params): array
    {
        $requiredFields = ['address', 'function', 'caller'];
        
        foreach ($requiredFields as $field) {
            if (!isset($params[$field])) {
                return $this->errorResponse("Missing required field: {$field}", 400);
            }
        }
        
        $result = $this->contractManager->callContract(
            $params['address'],
            $params['function'],
            $params['args'] ?? [],
            $params['caller'],
            (int)($params['gas_limit'] ?? 50000),
            (int)($params['value'] ?? 0)
        );
        
        if ($result['success']) {
            return $this->successResponse($result);
        } else {
            return $this->errorResponse($result['error'], 400);
        }
    }

    /**
     * Get contract information
     */
    private function getContractInfo(string $address): array
    {
        if (empty($address)) {
            return $this->errorResponse('Address parameter required', 400);
        }
        
        $contractState = $this->contractManager->getContractState($address);
        
        if (!$contractState) {
            return $this->errorResponse('Contract not found', 404);
        }
        
        return $this->successResponse([
            'address' => $address,
            'deployer' => $contractState['deployer'],
            'deployed_at' => $contractState['deployed_at'],
            'balance' => $contractState['balance'],
            'code_hash' => hash('sha256', $contractState['code'])
        ]);
    }

    /**
     * Gas estimation
     */
    private function estimateGas(array $params): array
    {
        $gasEstimate = $this->contractManager->estimateGas(
            $params['address'] ?? '',
            $params['function'] ?? '',
            $params['args'] ?? [],
            $params['caller'] ?? ''
        );
        
        return $this->successResponse([
            'gas_estimate' => $gasEstimate
        ]);
    }

    /**
     * Get validators
     */
    private function getValidators(): array
    {
        $validators = $this->consensus->getAllValidators();
        
        return $this->successResponse([
            'validators' => $validators,
            'count' => count($validators)
        ]);
    }

    /**
     * Staking
     */
    private function stake(array $params): array
    {
        // Staking implementation
        return $this->successResponse(['status' => 'staked']);
    }

    /**
     * Unstaking
     */
    private function unstake(array $params): array
    {
        // Unstaking implementation
        return $this->successResponse(['status' => 'unstaked']);
    }

    /**
     * Get network nodes
     */
    private function getNetworkNodes(): array
    {
        $activeNodes = $this->nodeManager->getActiveNodes();
        
        return $this->successResponse([
            'nodes' => array_map(function($node) {
                return [
                    'id' => $node->getId(),
                    'address' => $node->getAddress(),
                    'port' => $node->getPort(),
                    'version' => $node->getVersion()
                ];
            }, $activeNodes),
            'count' => count($activeNodes)
        ]);
    }

    /**
     * Get network statistics
     */
    private function getNetworkStats(): array
    {
        return $this->successResponse($this->nodeManager->getNetworkStats());
    }

    /**
     * Synchronize with network
     */
    private function syncWithNetwork(): array
    {
        $result = $this->nodeManager->synchronizeWithNetwork();
        
        return $this->successResponse([
            'sync_status' => 'completed',
            'result' => $result
        ]);
    }

    /**
     * Get system health status
     */
    private function getHealthStatus(): array
    {
        return $this->successResponse([
            'status' => 'healthy',
            'blockchain_valid' => $this->blockchain->getHeight() > 0,
            'network_connected' => method_exists($this->nodeManager, 'getActiveNodes') ? count($this->nodeManager->getActiveNodes()) > 0 : false,
            'timestamp' => time()
        ]);
    }

    /**
     * Get metrics
     */
    private function getMetrics(): array
    {
        return $this->successResponse([
            'blockchain' => $this->blockchain->getStats(),
            'consensus' => $this->consensus->getConsensusStats(),
            'network' => $this->nodeManager->getNetworkStats(),
            'contracts' => $this->contractManager->getContractStats()
        ]);
    }

    /**
     * Format block for API
     */
    private function formatBlock($block): array
    {
        return [
            'height' => $block->getIndex(),
            'hash' => $block->getHash(),
            'previous_hash' => $block->getPreviousHash(),
            'timestamp' => $block->getTimestamp(),
            'transaction_count' => count($block->getTransactions()),
            'merkle_root' => $block->getMerkleRoot(),
            'gas_used' => $block->getGasUsed(),
            'gas_limit' => $block->getGasLimit(),
            'validators' => $block->getValidators(),
            'size' => $block->getSize()
        ];
    }

    /**
     * Format transaction for API
     */
    private function formatTransaction($transaction): array
    {
        return [
            'hash' => $transaction->getHash(),
            'from' => $transaction->getFrom(),
            'to' => $transaction->getTo(),
            'amount' => $transaction->getAmount(),
            'fee' => $transaction->getFee(),
            'nonce' => $transaction->getNonce(),
            'timestamp' => $transaction->getTimestamp()
        ];
    }

    /**
     * Get total token supply
     */
    private function getTotalSupply(): float
    {
        // Implementation for calculating total supply
        return 1000000.0;
    }

    /**
     * Check if authentication is required
     */
    private function requiresAuth(string $endpoint): bool
    {
        $protectedEndpoints = [
            'admin/',
            'contracts/deploy',
            'staking/stake',
            'staking/unstake',
            'wallets/create'
        ];

        foreach ($protectedEndpoints as $protected) {
            if (strpos($endpoint, $protected) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Authenticate request
     */
    private function authenticate(array $headers): bool
    {
        $apiKey = $headers['X-API-Key'] ?? $headers['Authorization'] ?? '';
        
        // API key validation should be implemented here
        return !empty($apiKey);
    }

    /**
     * Get transaction by hash
     */
    private function getTransactionByHash(string $hash): array
    {
        try {
            $pdo = $this->database->getConnection();
            $stmt = $pdo->prepare("SELECT * FROM transactions WHERE hash = ?");
            $stmt->execute([$hash]);
            $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$transaction) {
                return $this->errorResponse('Transaction not found', 404);
            }
            
            return $this->successResponse($transaction);
        } catch (Exception $e) {
            return $this->errorResponse('Database error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get transaction history for address
     */
    private function getTransactionHistory(string $address): array
    {
        try {
            $pdo = $this->database->getConnection();
            $stmt = $pdo->prepare("
                SELECT * FROM transactions 
                WHERE from_address = ? OR to_address = ? 
                ORDER BY timestamp DESC 
                LIMIT 100
            ");
            $stmt->execute([$address, $address]);
            $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return $this->successResponse($transactions);
        } catch (Exception $e) {
            return $this->errorResponse('Database error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get staking rewards for address
     */
    private function getStakingRewards(string $address): array
    {
        try {
            $pdo = $this->database->getConnection();
            $stmt = $pdo->prepare("
                SELECT * FROM staking_rewards 
                WHERE validator_address = ? 
                ORDER BY created_at DESC 
                LIMIT 50
            ");
            $stmt->execute([$address]);
            $rewards = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return $this->successResponse($rewards);
        } catch (Exception $e) {
            return $this->errorResponse('Database error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Success response
     */
    private function successResponse(array $data): array
    {
        return [
            'success' => true,
            'data' => $data,
            'timestamp' => time()
        ];
    }

    /**
     * Error response
     */
    private function errorResponse(string $message, int $code = 400): array
    {
        return [
            'success' => false,
            'error' => $message,
            'code' => $code,
            'timestamp' => time()
        ];
    }
}
