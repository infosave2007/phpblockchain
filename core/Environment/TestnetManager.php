<?php
declare(strict_types=1);

namespace Blockchain\Core\Environment;

/**
 * Testnet Configuration and Management
 */
class TestnetManager
{
    private array $config;
    private string $environment;

    public function __construct(string $environment = 'mainnet')
    {
        $this->environment = $environment;
        $this->config = $this->loadEnvironmentConfig();
    }

    /**
     * Get current environment
     */
    public function getEnvironment(): string
    {
        return $this->environment;
    }

    /**
     * Check if running on testnet
     */
    public function isTestnet(): bool
    {
        return $this->environment === 'testnet';
    }

    /**
     * Check if running on devnet
     */
    public function isDevnet(): bool
    {
        return $this->environment === 'devnet';
    }

    /**
     * Get network configuration
     */
    public function getNetworkConfig(): array
    {
        return $this->config;
    }

    /**
     * Get genesis block configuration
     */
    public function getGenesisConfig(): array
    {
        return $this->config['genesis'];
    }

    /**
     * Get consensus parameters
     */
    public function getConsensusConfig(): array
    {
        return $this->config['consensus'];
    }

    /**
     * Get network parameters
     */
    public function getNetworkParameters(): array
    {
        return $this->config['network'];
    }

    /**
     * Initialize testnet environment
     */
    public function initializeTestnet(): bool
    {
        try {
            // Create testnet data directory
            $dataDir = $this->getDataDirectory();
            if (!is_dir($dataDir)) {
                mkdir($dataDir, 0755, true);
            }

            // Create genesis block for testnet
            $this->createGenesisBlock();

            // Setup bootstrap nodes
            $this->setupBootstrapNodes();

            // Configure testnet faucet
            $this->setupFaucet();

            return true;
        } catch (Exception $e) {
            error_log("Failed to initialize testnet: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Reset testnet (clear all data)
     */
    public function resetTestnet(): bool
    {
        if (!$this->isTestnet()) {
            throw new Exception("Cannot reset non-testnet environment");
        }

        $dataDir = $this->getDataDirectory();
        
        // Backup current state if needed
        $backupDir = $dataDir . '/backup_' . date('Y-m-d_H-i-s');
        if (is_dir($dataDir . '/blockchain')) {
            rename($dataDir . '/blockchain', $backupDir . '/blockchain');
        }

        // Clear blockchain data
        $this->clearDirectory($dataDir . '/blockchain');
        $this->clearDirectory($dataDir . '/state');
        $this->clearDirectory($dataDir . '/mempool');

        // Reinitialize
        return $this->initializeTestnet();
    }

    /**
     * Get testnet faucet configuration
     */
    public function getFaucetConfig(): array
    {
        return $this->config['faucet'] ?? [];
    }

    /**
     * Request testnet tokens from faucet
     */
    public function requestTestTokens(string $address, int $amount = null): array
    {
        if (!$this->isTestnet()) {
            throw new Exception("Faucet only available on testnet");
        }

        $faucetConfig = $this->getFaucetConfig();
        $maxAmount = $faucetConfig['max_amount'] ?? 1000;
        $amount = $amount ?? $faucetConfig['default_amount'] ?? 100;

        if ($amount > $maxAmount) {
            throw new Exception("Requested amount exceeds maximum");
        }

        // Check rate limiting for faucet
        $rateLimiter = new \Blockchain\Core\Security\RateLimiter();
        if (!$rateLimiter->isAllowed($address, 'faucet')) {
            throw new Exception("Faucet rate limit exceeded");
        }

        // Create faucet transaction
        $faucetAddress = $faucetConfig['address'];
        $transaction = $this->createFaucetTransaction($faucetAddress, $address, $amount);

        $rateLimiter->recordRequest($address, 'faucet');

        return [
            'success' => true,
            'transaction_hash' => $transaction['hash'],
            'amount' => $amount,
            'recipient' => $address
        ];
    }

    /**
     * Load environment-specific configuration
     */
    private function loadEnvironmentConfig(): array
    {
        $configFile = __DIR__ . "/../../config/networks/{$this->environment}.php";
        
        if (!file_exists($configFile)) {
            // Create default config if not exists
            $this->createDefaultConfig($configFile);
        }

        return require $configFile;
    }

    /**
     * Create default configuration for environment
     */
    private function createDefaultConfig(string $configFile): void
    {
        $dir = dirname($configFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $config = $this->getDefaultConfig();
        
        $configContent = "<?php\nreturn " . var_export($config, true) . ";\n";
        file_put_contents($configFile, $configContent);
    }

    /**
     * Get default configuration based on environment
     */
    private function getDefaultConfig(): array
    {
        $baseConfig = [
            'mainnet' => [
                'network_id' => 1,
                'name' => 'Mainnet',
                'genesis' => [
                    'timestamp' => 1640995200,
                    'difficulty' => 1000,
                    'initial_supply' => 1000000,
                    'validator_addresses' => []
                ],
                'consensus' => [
                    'type' => 'pos',
                    'block_time' => 10,
                    'block_reward' => 10,
                    'minimum_stake' => 1000,
                    'slashing_penalty' => 100
                ],
                'network' => [
                    'p2p_port' => 8545,
                    'rpc_port' => 8546,
                    'max_peers' => 25,
                    'bootstrap_nodes' => []
                ]
            ],
            'testnet' => [
                'network_id' => 2,
                'name' => 'Testnet',
                'genesis' => [
                    'timestamp' => time(),
                    'difficulty' => 100,
                    'initial_supply' => 10000000,
                    'validator_addresses' => [
                        '0x742d35Cc6635C0532925a3b8D4067d5E0E5ae58a' // Test validator
                    ]
                ],
                'consensus' => [
                    'type' => 'pos',
                    'block_time' => 5, // Faster blocks for testing
                    'block_reward' => 100,
                    'minimum_stake' => 100,
                    'slashing_penalty' => 10
                ],
                'network' => [
                    'p2p_port' => 18545,
                    'rpc_port' => 18546,
                    'max_peers' => 10,
                    'bootstrap_nodes' => [
                        'testnet-node1.blockchain.local:18545',
                        'testnet-node2.blockchain.local:18545'
                    ]
                ],
                'faucet' => [
                    'enabled' => true,
                    'address' => '0x742d35Cc6635C0532925a3b8D4067d5E0E5ae58a',
                    'default_amount' => 1000,
                    'max_amount' => 10000,
                    'rate_limit' => [
                        'requests' => 5,
                        'window' => 3600 // 1 hour
                    ]
                ]
            ],
            'devnet' => [
                'network_id' => 3,
                'name' => 'Development Network',
                'genesis' => [
                    'timestamp' => time(),
                    'difficulty' => 1,
                    'initial_supply' => 100000000,
                    'validator_addresses' => [
                        '0x742d35Cc6635C0532925a3b8D4067d5E0E5ae58a'
                    ]
                ],
                'consensus' => [
                    'type' => 'pos',
                    'block_time' => 1, // Very fast for development
                    'block_reward' => 1000,
                    'minimum_stake' => 10,
                    'slashing_penalty' => 1
                ],
                'network' => [
                    'p2p_port' => 28545,
                    'rpc_port' => 28546,
                    'max_peers' => 5,
                    'bootstrap_nodes' => []
                ],
                'faucet' => [
                    'enabled' => true,
                    'address' => '0x742d35Cc6635C0532925a3b8D4067d5E0E5ae58a',
                    'default_amount' => 10000,
                    'max_amount' => 100000,
                    'rate_limit' => [
                        'requests' => 100,
                        'window' => 3600
                    ]
                ]
            ]
        ];

        return $baseConfig[$this->environment] ?? $baseConfig['mainnet'];
    }

    /**
     * Get data directory for current environment
     */
    private function getDataDirectory(): string
    {
        return __DIR__ . "/../../storage/{$this->environment}";
    }

    /**
     * Create genesis block for environment
     */
    private function createGenesisBlock(): void
    {
        $genesisConfig = $this->getGenesisConfig();
        
        // Implementation would create the actual genesis block
        // This is a placeholder for the full implementation
        
        $genesisData = [
            'index' => 0,
            'timestamp' => $genesisConfig['timestamp'],
            'previous_hash' => '0',
            'transactions' => [],
            'validator' => $genesisConfig['validator_addresses'][0] ?? '0x0',
            'network_id' => $this->config['network_id']
        ];

        $genesisFile = $this->getDataDirectory() . '/genesis.json';
        file_put_contents($genesisFile, json_encode($genesisData, JSON_PRETTY_PRINT));
    }

    /**
     * Setup bootstrap nodes for P2P network
     */
    private function setupBootstrapNodes(): void
    {
        $networkConfig = $this->getNetworkParameters();
        $bootstrapFile = $this->getDataDirectory() . '/bootstrap_nodes.json';
        
        file_put_contents($bootstrapFile, json_encode($networkConfig['bootstrap_nodes'], JSON_PRETTY_PRINT));
    }

    /**
     * Setup testnet faucet
     */
    private function setupFaucet(): void
    {
        if (!$this->isTestnet() && !$this->isDevnet()) {
            return;
        }

        $faucetConfig = $this->getFaucetConfig();
        if (!$faucetConfig['enabled']) {
            return;
        }

        // Create faucet wallet if not exists
        $faucetFile = $this->getDataDirectory() . '/faucet_config.json';
        file_put_contents($faucetFile, json_encode($faucetConfig, JSON_PRETTY_PRINT));
    }

    /**
     * Create faucet transaction
     */
    private function createFaucetTransaction(string $from, string $to, int $amount): array
    {
        // This would integrate with the actual transaction system
        // Placeholder implementation
        
        $transaction = [
            'hash' => bin2hex(random_bytes(32)),
            'from' => $from,
            'to' => $to,
            'amount' => $amount,
            'timestamp' => time(),
            'type' => 'faucet'
        ];

        return $transaction;
    }

    /**
     * Clear directory contents
     */
    private function clearDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $fileinfo) {
            $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            $todo($fileinfo->getRealPath());
        }
    }
}
