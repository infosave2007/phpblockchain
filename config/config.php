<?php
declare(strict_types=1);

return [
    // Database Configuration
    'database' => [
        'host' => $_ENV['DB_HOST'] ?? 'localhost',
        'port' => (int)($_ENV['DB_PORT'] ?? 3306),
        'username' => $_ENV['DB_USERNAME'] ?? '',
        'password' => $_ENV['DB_PASSWORD'] ?? '',
        'database' => $_ENV['DB_DATABASE'] ?? 'blockchain_modern',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'options' => [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    ],

    // Blockchain Configuration
    'blockchain' => [
        'network_name' => $_ENV['NETWORK_NAME'] ?? 'My Blockchain Network',
        'token_symbol' => $_ENV['TOKEN_SYMBOL'] ?? 'MBC',
        'token_name' => $_ENV['TOKEN_NAME'] ?? 'My Blockchain Coin',
        'token_decimals' => (int)($_ENV['TOKEN_DECIMALS'] ?? 18),
        'chain_id' => (int)($_ENV['CHAIN_ID'] ?? 1337),
        'consensus_algorithm' => $_ENV['CONSENSUS_ALGORITHM'] ?? 'pos',
        'initial_supply' => (float)($_ENV['INITIAL_SUPPLY'] ?? 1000000),
        'block_time' => (int)($_ENV['BLOCK_TIME'] ?? 10),
        'block_reward' => (float)($_ENV['BLOCK_REWARD'] ?? 10),
        'max_transactions_per_block' => 1000,
        'difficulty_adjustment_interval' => 2016,
        'genesis_timestamp' => '2024-01-01T00:00:00Z'
    ],

    // Token/Cryptocurrency Configuration
    'token' => [
        'name' => $_ENV['TOKEN_NAME'] ?? 'My Blockchain Coin',
        'symbol' => $_ENV['TOKEN_SYMBOL'] ?? 'MBC',
        'decimals' => (int)($_ENV['TOKEN_DECIMALS'] ?? 18),
        'total_supply' => (float)($_ENV['TOKEN_TOTAL_SUPPLY'] ?? 1000000),
        'contract_address' => $_ENV['TOKEN_CONTRACT_ADDRESS'] ?? null,
        'logo_uri' => $_ENV['TOKEN_LOGO_URI'] ?? '/assets/token-logo.png',
        'website' => $_ENV['TOKEN_WEBSITE'] ?? 'https://myblockchain.local',
        'description' => $_ENV['TOKEN_DESCRIPTION'] ?? 'Native token of My Blockchain Network',
        'social' => [
            'twitter' => $_ENV['TOKEN_TWITTER'] ?? null,
            'telegram' => $_ENV['TOKEN_TELEGRAM'] ?? null,
            'discord' => $_ENV['TOKEN_DISCORD'] ?? null,
            'github' => $_ENV['TOKEN_GITHUB'] ?? null
        ],
        'explorer' => $_ENV['TOKEN_EXPLORER'] ?? 'https://explorer.myblockchain.local',
        'coingecko_id' => $_ENV['TOKEN_COINGECKO_ID'] ?? null,
        'coinmarketcap_id' => $_ENV['TOKEN_COINMARKETCAP_ID'] ?? null
    ],

    // Network Configuration
    'network' => [
        'node_type' => $_ENV['NODE_TYPE'] ?? 'full',
        'p2p_port' => (int)($_ENV['P2P_PORT'] ?? 8545),
        'rpc_port' => (int)($_ENV['RPC_PORT'] ?? 8546),
        'max_peers' => (int)($_ENV['MAX_PEERS'] ?? 25),
        'bootstrap_nodes' => array_filter(explode(',', $_ENV['BOOTSTRAP_NODES'] ?? '')),
        'connection_timeout' => 5,
        'request_timeout' => 30,
        'max_concurrent_connections' => 50,
        'heartbeat_interval' => 30,
        'sync_interval' => 300,
        'user_agent' => $_ENV['USER_AGENT'] ?? 'BlockchainNode/2.0',
        'user_agent' => $_ENV['NETWORK_USER_AGENT'] ?? 'BlockchainNode/2.0'
    ],

    // Security Configuration
    'security' => [
        'jwt_secret' => $_ENV['JWT_SECRET'] ?? '',
        'encryption_key' => $_ENV['ENCRYPTION_KEY'] ?? '',
        'api_key' => $_ENV['API_KEY'] ?? '',
        'rate_limit' => [
            'enabled' => true,
            'requests_per_minute' => 60,
            'burst_limit' => 10
        ],
        'cors' => [
            'enabled' => true,
            'allowed_origins' => ['*'],
            'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
            'allowed_headers' => ['Content-Type', 'Authorization', 'X-API-Key']
        ]
    ],

    // Logging Configuration
    'logging' => [
        'level' => $_ENV['LOG_LEVEL'] ?? 'info',
        'file' => $_ENV['LOG_FILE'] ?? 'logs/blockchain.log',
        'max_files' => 30,
        'max_size' => '10MB',
        'channels' => [
            'blockchain' => 'logs/blockchain.log',
            'api' => 'logs/api.log',
            'network' => 'logs/network.log',
            'contracts' => 'logs/contracts.log',
            'consensus' => 'logs/consensus.log'
        ]
    ],

    // Cache Configuration
    'cache' => [
        'driver' => $_ENV['CACHE_DRIVER'] ?? 'redis',
        'redis' => [
            'host' => $_ENV['REDIS_HOST'] ?? '127.0.0.1',
            'port' => (int)($_ENV['REDIS_PORT'] ?? 6379),
            'password' => $_ENV['REDIS_PASSWORD'] ?? null,
            'database' => 0,
            'timeout' => 2.5
        ],
        'file' => [
            'path' => 'storage/cache'
        ],
        'ttl' => [
            'blocks' => 3600,
            'transactions' => 1800,
            'balances' => 300,
            'contracts' => 7200
        ]
    ],

    // Smart Contracts Configuration
    'contracts' => [
        'vm_enabled' => filter_var($_ENV['VM_ENABLED'] ?? true, FILTER_VALIDATE_BOOLEAN),
        'gas_price' => (int)($_ENV['GAS_PRICE'] ?? 1),
        'max_gas_per_transaction' => (int)($_ENV['MAX_GAS_PER_TX'] ?? 1000000),
        'storage_path' => $_ENV['CONTRACT_STORAGE_PATH'] ?? 'storage/contracts',
        'compiler' => [
            'enabled' => true,
            'optimization' => true,
            'version' => '1.0.0'
        ],
        'libraries' => [
            'math' => true,
            'string' => true,
            'array' => true,
            'crypto' => true
        ]
    ],

    // Consensus Configuration
    'consensus' => [
        'algorithm' => $_ENV['CONSENSUS_ALGORITHM'] ?? 'pos',
        'pos' => [
            'minimum_stake' => (int)($_ENV['MINIMUM_STAKE'] ?? 1000),
            'slashing_penalty' => (int)($_ENV['SLASHING_PENALTY'] ?? 100),
            'epoch_length' => (int)($_ENV['EPOCH_LENGTH'] ?? 100),
            'validator_rotation' => true,
            'finality_threshold' => 67, // 2/3 majority
            'slash_conditions' => [
                'double_signing' => true,
                'nothing_at_stake' => true,
                'long_range_attack' => true
            ]
        ],
        'pow' => [
            'initial_difficulty' => '0000',
            'target_block_time' => 600,
            'difficulty_adjustment' => 2016
        ]
    ],

    // Wallet Configuration
    'wallet' => [
        'default_derivation_path' => "m/44'/623'/0'/0/0",
        'mnemonic_strength' => 256,
        'pbkdf2_iterations' => 4096,
        'encryption_algorithm' => 'AES-256-GCM',
        'backup' => [
            'enabled' => true,
            'path' => 'storage/wallet_backups',
            'encryption' => true
        ]
    ],

    // Storage Configuration
    'storage' => [
        'blockchain_path' => 'storage/blockchain',
        'state_path' => 'storage/state',
        'index_path' => 'storage/indexes',
        'backup_path' => 'storage/backups',
        'compression' => [
            'enabled' => true,
            'algorithm' => 'gzip',
            'level' => 6
        ],
        'pruning' => [
            'enabled' => false,
            'keep_blocks' => 10000,
            'prune_interval' => 1000
        ]
    ],

    // API Configuration
    'api' => [
        'version' => '2.0',
        'base_url' => 'http://localhost:8080/api/v2',
        'documentation_url' => 'http://localhost:8080/docs',
        'rate_limiting' => [
            'enabled' => true,
            'requests_per_minute' => 100,
            'burst_size' => 20
        ],
        'pagination' => [
            'default_limit' => 20,
            'max_limit' => 100
        ],
        'response_format' => 'json',
        'pretty_print' => false
    ],

    // Monitoring Configuration
    'monitoring' => [
        'enabled' => true,
        'metrics_port' => 9090,
        'health_check_interval' => 30,
        'performance_tracking' => true,
        'alerts' => [
            'enabled' => true,
            'email_notifications' => true,
            'webhook_url' => '',
            'conditions' => [
                'high_block_time' => 60,
                'low_peer_count' => 3,
                'high_memory_usage' => 85,
                'disk_space_warning' => 90
            ]
        ]
    ],

    // Development Configuration
    'development' => [
        'debug' => filter_var($_ENV['DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'development_mode' => filter_var($_ENV['DEVELOPMENT_MODE'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'profiling' => false,
        'test_network' => false,
        'mock_mining' => false,
        'fast_sync' => true
    ],

    // Feature Flags
    'features' => [
        'smart_contracts' => true,
        'staking' => true,
        'governance' => true,
        'atomic_swaps' => false,
        'privacy_transactions' => false,
        'sharding' => false,
        'light_clients' => true,
        'mobile_wallet' => false
    ],

    // Paths
    'paths' => [
        'root' => dirname(__DIR__),
        'storage' => dirname(__DIR__) . '/storage',
        'logs' => dirname(__DIR__) . '/logs',
        'config' => dirname(__DIR__) . '/config',
        'contracts' => dirname(__DIR__) . '/contracts',
        'wallet' => dirname(__DIR__) . '/wallet',
        'web' => dirname(__DIR__) . '/web'
    ]
];
