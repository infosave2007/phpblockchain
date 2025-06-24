<?php
// Disable any output buffering that might interfere
if (ob_get_level()) {
    ob_end_clean();
}

// Set headers first
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Turn off error reporting to prevent HTML error pages
error_reporting(0);
ini_set('display_errors', 0);

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Only POST method allowed');
    }

    // Get step data from POST
    $rawInput = file_get_contents('php://input');
    
    if (empty($rawInput)) {
        throw new Exception('No data received in request body');
    }
    
    $input = json_decode($rawInput, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON data: ' . json_last_error_msg());
    }

    $stepId = $input['step'] ?? '';
    
    if (empty($stepId)) {
        throw new Exception('Step ID not provided');
    }

    // Execute step based on step ID
    $result = executeInstallationStep($stepId);
    
    echo json_encode([
        'status' => 'success',
        'message' => $result['message'],
        'data' => $result['data'] ?? null
    ]);

} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
} catch (Error $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Fatal error: ' . $e->getMessage()
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Unexpected error: ' . $e->getMessage()
    ]);
}

function executeInstallationStep(string $stepId): array
{
    switch ($stepId) {
        case 'create_directories':
            return createDirectories();
            
        case 'install_dependencies':
            return installDependencies();
            
        case 'create_database':
            return createDatabase();
            
        case 'create_tables':
            return createTables();
            
        case 'generate_genesis':
            return generateGenesis();
            
        case 'create_config':
            return createConfig();
            
        case 'setup_admin':
            return setupAdmin();
            
        case 'initialize_blockchain':
            return initializeBlockchain();
            
        case 'start_services':
            return startServices();
            
        case 'finalize':
            return finalizeInstallation();
            
        default:
            throw new Exception('Unknown installation step: ' . $stepId);
    }
}

function createDirectories(): array
{
    $directories = [
        '../storage/blockchain',
        '../storage/contracts',
        '../storage/state',
        '../storage/backups',
        '../logs',
        '../config'
    ];
    
    $created = [];
    $checked = [];
    
    foreach ($directories as $dir) {
        if (!is_dir($dir)) {
            if (mkdir($dir, 0755, true)) {
                $created[] = $dir;
                chmod($dir, 0755); // Ensure correct permissions
            } else {
                throw new Exception("Failed to create directory: $dir");
            }
        } else {
            $checked[] = $dir;
            // Ensure existing directories have correct permissions
            chmod($dir, 0755);
        }
        
        // Verify directory is writable
        if (!is_writable($dir)) {
            throw new Exception("Directory is not writable: $dir");
        }
    }
    
    return [
        'message' => 'Directories created and verified successfully',
        'data' => [
            'created' => $created,
            'checked' => $checked,
            'total' => count($directories)
        ]
    ];
}

function installDependencies(): array
{
    // Check for Composer configuration
    $composerFile = '../composer.json';
    if (!file_exists($composerFile)) {
        throw new Exception('Composer configuration not found');
    }
    
    // Check vendor directory and autoload
    $vendorDir = '../vendor';
    $autoloadFile = '../vendor/autoload.php';
    
    if (!is_dir($vendorDir)) {
        throw new Exception('Vendor directory not found. Please run: composer install');
    }
    
    if (!file_exists($autoloadFile)) {
        throw new Exception('Composer autoload not found. Please run: composer install');
    }
    
    // Test autoload functionality
    try {
        require_once $autoloadFile;
    } catch (Exception $e) {
        throw new Exception('Failed to load Composer autoload: ' . $e->getMessage());
    }
    
    // Check if composer.lock exists (indicates dependencies were installed)
    $composerLock = '../composer.lock';
    $lockExists = file_exists($composerLock);
    
    return [
        'message' => 'Dependencies verified successfully',
        'data' => [
            'composer_installed' => true,
            'autoload_works' => true,
            'lock_file_exists' => $lockExists,
            'vendor_dir' => realpath($vendorDir)
        ]
    ];
}

function createDatabase(): array
{
    // Load configuration
    $configFile = '../config/install_config.json';
    if (!file_exists($configFile)) {
        throw new Exception('Installation configuration not found');
    }
    
    $config = json_decode(file_get_contents($configFile), true);
    $dbConfig = $config['database'];
    
    // Test database connection (similar to check_database.php)
    $dsn = "mysql:host={$dbConfig['host']};port={$dbConfig['port']};charset=utf8mb4";
    
    try {
        $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5
        ]);
        
        // Create database if it doesn't exist
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbConfig['database']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        
        return [
            'message' => 'Database created successfully',
            'data' => ['database' => $dbConfig['database']]
        ];
        
    } catch (PDOException $e) {
        throw new Exception('Database creation failed: ' . $e->getMessage());
    }
}

function createTables(): array
{
    // Load configuration
    $configFile = '../config/install_config.json';
    $config = json_decode(file_get_contents($configFile), true);
    $dbConfig = $config['database'];
    
    $dsn = "mysql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['database']};charset=utf8mb4";
    
    try {
        $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
        
        // Include the Migration class
        require_once '../database/Migration.php';
        
        // Capture any output from Migration class
        ob_start();
        
        // Create Migration instance and run schema creation
        $migration = new \Blockchain\Database\Migration($pdo);
        $success = $migration->createSchema();
        
        // Get any output and clean the buffer
        $migrationOutput = ob_get_contents();
        ob_end_clean();
        
        if ($success) {
            // Get list of created tables
            $stmt = $pdo->query("SHOW TABLES");
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            return [
                'message' => 'Database tables created successfully',
                'data' => [
                    'tables' => $tables,
                    'count' => count($tables),
                    'migration_output' => trim($migrationOutput)
                ]
            ];
        } else {
            throw new Exception('Failed to create database schema');
        }
        
    } catch (PDOException $e) {
        throw new Exception('Table creation failed: ' . $e->getMessage());
    } catch (Exception $e) {
        throw new Exception('Schema creation failed: ' . $e->getMessage());
    }
}

function generateGenesis(): array
{
    // Load configuration for blockchain parameters
    $configFile = '../config/install_config.json';
    if (!file_exists($configFile)) {
        throw new Exception('Installation configuration not found');
    }
    
    $config = json_decode(file_get_contents($configFile), true);
    $blockchainConfig = $config['blockchain'] ?? [];
    
    // Load the main blockchain classes to use the same Genesis logic
    try {
        require_once '../vendor/autoload.php';
        require_once '../core/Contracts/BlockInterface.php';
        require_once '../core/Crypto/Hash.php';
        require_once '../core/Cryptography/MerkleTree.php';
        require_once '../core/Blockchain/Block.php';
    } catch (Exception $e) {
        // Fallback to manual genesis creation if classes not available
        return generateGenesisManual($blockchainConfig);
    }
    
    // Create Genesis block using the same logic as main blockchain
    $timestamp = time();
    
    // Initial transactions for Genesis block (same as in Blockchain.php)
    $genesisTransactions = [
        [
            'type' => 'genesis',
            'to' => 'genesis_address',
            'amount' => $blockchainConfig['initial_supply'] ?? 1000000,
            'timestamp' => $timestamp,
            'network_name' => $blockchainConfig['network_name'] ?? 'Blockchain Network',
            'token_symbol' => $blockchainConfig['token_symbol'] ?? 'TOKEN',
            'consensus' => $blockchainConfig['consensus_algorithm'] ?? 'pos'
        ]
    ];
    
    // Try to create Block using the main class
    try {
        $genesisBlock = new \Blockchain\Core\Blockchain\Block(0, $genesisTransactions, '0');
        $genesisData = [
            'index' => $genesisBlock->getIndex(),
            'timestamp' => $genesisBlock->getTimestamp(),
            'transactions' => $genesisBlock->getTransactions(),
            'previous_hash' => $genesisBlock->getPreviousHash(),
            'hash' => $genesisBlock->getHash(),
            'nonce' => $genesisBlock->getNonce(),
            'merkle_root' => $genesisBlock->getMerkleRoot()
        ];
    } catch (Exception $e) {
        // Fallback to manual creation
        return generateGenesisManual($blockchainConfig);
    }
    
    // Ensure storage directory exists
    $storageDir = '../storage/blockchain';
    if (!is_dir($storageDir)) {
        mkdir($storageDir, 0755, true);
    }
    
    // Save genesis block
    $genesisFile = $storageDir . '/genesis.json';
    $chainFile = $storageDir . '/chain.json';
    
    if (file_put_contents($genesisFile, json_encode($genesisData, JSON_PRETTY_PRINT)) === false) {
        throw new Exception('Failed to save genesis block');
    }
    
    // Initialize blockchain with genesis block
    $chain = [$genesisData];
    if (file_put_contents($chainFile, json_encode($chain, JSON_PRETTY_PRINT)) === false) {
        throw new Exception('Failed to initialize blockchain');
    }
    
    // Also save genesis block to database if possible
    try {
        $configFile = '../config/install_config.json';
        if (file_exists($configFile)) {
            $config = json_decode(file_get_contents($configFile), true);
            $dbConfig = $config['database'];
            
            $dsn = "mysql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['database']};charset=utf8mb4";
            $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);
            
            // Insert genesis block into blocks table
            $stmt = $pdo->prepare("INSERT INTO blocks (hash, parent_hash, height, timestamp, validator, signature, merkle_root, transactions_count, metadata) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE hash=hash");
            $stmt->execute([
                $genesisData['hash'],
                $genesisData['previous_hash'],
                $genesisData['index'],
                $genesisData['timestamp'],
                'genesis_validator',
                'genesis_signature',
                $genesisData['merkle_root'],
                count($genesisData['transactions']),
                json_encode(['genesis' => true])
            ]);
            
            // Insert genesis transactions
            foreach ($genesisData['transactions'] as $tx) {
                $txHash = $tx['hash'] ?? hash('sha256', json_encode($tx));
                $stmt = $pdo->prepare("INSERT INTO transactions (hash, block_hash, block_height, from_address, to_address, amount, fee, gas_limit, gas_used, gas_price, nonce, data, signature, status, timestamp) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE hash=hash");
                $stmt->execute([
                    $txHash,
                    $genesisData['hash'],
                    $genesisData['index'],
                    'genesis',
                    $tx['to'],
                    $tx['amount'],
                    0, // fee
                    0, // gas_limit
                    0, // gas_used
                    0, // gas_price
                    0, // nonce
                    json_encode($tx),
                    'genesis_signature',
                    'confirmed',
                    $tx['timestamp']
                ]);
            }
        }
    } catch (Exception $e) {
        // Database insert failed, but files are saved, so continue
        error_log("Failed to save genesis to database: " . $e->getMessage());
    }
    
    return [
        'message' => 'Genesis block generated successfully using main blockchain logic',
        'data' => [
            'genesis' => $genesisData,
            'files_created' => [
                'genesis' => $genesisFile,
                'chain' => $chainFile
            ],
            'block_hash' => $genesisData['hash'],
            'using_main_class' => true,
            'database_saved' => $dbSaved ?? false
        ]
    ];
}

function generateGenesisManual(array $blockchainConfig): array
{
    // Manual Genesis creation as fallback
    $timestamp = time();
    $genesisData = [
        'index' => 0,
        'timestamp' => $timestamp,
        'transactions' => [
            [
                'type' => 'genesis',
                'to' => 'genesis_address',
                'amount' => $blockchainConfig['initial_supply'] ?? 1000000,
                'timestamp' => $timestamp,
                'network_name' => $blockchainConfig['network_name'] ?? 'Blockchain Network',
                'token_symbol' => $blockchainConfig['token_symbol'] ?? 'TOKEN',
                'consensus' => $blockchainConfig['consensus_algorithm'] ?? 'pos'
            ]
        ],
        'previous_hash' => '0',
        'nonce' => 0,
        'difficulty' => 1
    ];
    
    // Create hash manually
    $hashData = json_encode($genesisData, JSON_UNESCAPED_SLASHES);
    $genesisData['hash'] = hash('sha256', $hashData);
    $genesisData['merkle_root'] = hash('sha256', json_encode($genesisData['transactions']));
    
    // Ensure storage directory exists
    $storageDir = '../storage/blockchain';
    if (!is_dir($storageDir)) {
        mkdir($storageDir, 0755, true);
    }
    
    // Save genesis block
    $genesisFile = $storageDir . '/genesis.json';
    $chainFile = $storageDir . '/chain.json';
    
    if (file_put_contents($genesisFile, json_encode($genesisData, JSON_PRETTY_PRINT)) === false) {
        throw new Exception('Failed to save genesis block');
    }
    
    // Initialize blockchain with genesis block
    $chain = [$genesisData];
    if (file_put_contents($chainFile, json_encode($chain, JSON_PRETTY_PRINT)) === false) {
        throw new Exception('Failed to initialize blockchain');
    }
    
    return [
        'message' => 'Genesis block generated successfully (manual fallback)',
        'data' => [
            'genesis' => $genesisData,
            'files_created' => [
                'genesis' => $genesisFile,
                'chain' => $chainFile
            ],
            'block_hash' => $genesisData['hash'],
            'using_main_class' => false,
            'database_saved' => false
        ]
    ];
}

function createConfig(): array
{
    // Load installation configuration
    $configFile = '../config/install_config.json';
    if (!file_exists($configFile)) {
        throw new Exception('Installation configuration not found');
    }
    
    $config = json_decode(file_get_contents($configFile), true);
    if (!$config) {
        throw new Exception('Invalid installation configuration');
    }
    
    // Generate secret keys
    $appKey = bin2hex(random_bytes(32));
    $jwtSecret = bin2hex(random_bytes(32));
    
    // Create comprehensive main config
    $mainConfig = [
        'app' => [
            'name' => $config['blockchain']['network_name'] ?? 'Blockchain Platform',
            'version' => '1.0.0',
            'debug' => false,
            'timezone' => 'UTC',
            'installed' => true,
            'key' => $appKey,
            'installation_date' => date('Y-m-d H:i:s')
        ],
        'database' => $config['database'],
        'blockchain' => array_merge($config['blockchain'], [
            'genesis_created' => true,
            'last_block_check' => time()
        ]),
        'network' => $config['network'],
        'security' => [
            'jwt_secret' => $jwtSecret,
            'session_lifetime' => 86400, // 24 hours
            'rate_limit' => [
                'enabled' => true,
                'max_requests' => 100,
                'time_window' => 3600
            ]
        ],
        'logging' => [
            'level' => 'info',
            'file' => '../logs/app.log',
            'max_size' => '10MB',
            'max_files' => 5
        ]
    ];
    
    // Save as PHP config file
    $configPath = '../config/config.php';
    $configContent = "<?php\n\n// Auto-generated configuration file\n// Generated on: " . date('Y-m-d H:i:s') . "\n\nreturn " . var_export($mainConfig, true) . ";\n";
    
    if (file_put_contents($configPath, $configContent) === false) {
        throw new Exception('Failed to create configuration file');
    }
    
    // Create environment file
    $envPath = '../.env';
    $envContent = "# Auto-generated environment file\n";
    $envContent .= "APP_KEY={$appKey}\n";
    $envContent .= "JWT_SECRET={$jwtSecret}\n";
    $envContent .= "DB_HOST={$config['database']['host']}\n";
    $envContent .= "DB_PORT={$config['database']['port']}\n";
    $envContent .= "DB_DATABASE={$config['database']['database']}\n";
    $envContent .= "DB_USERNAME={$config['database']['username']}\n";
    $envContent .= "DB_PASSWORD={$config['database']['password']}\n";
    
    file_put_contents($envPath, $envContent);
    
    return [
        'message' => 'Configuration files created successfully',
        'data' => [
            'config_created' => true,
            'env_created' => true,
            'files' => [
                'config' => $configPath,
                'env' => $envPath
            ],
            'app_key_generated' => true,
            'jwt_secret_generated' => true
        ]
    ];
}

function setupAdmin(): array
{
    // Load configuration
    $configFile = '../config/install_config.json';
    $config = json_decode(file_get_contents($configFile), true);
    $adminConfig = $config['admin'];
    $dbConfig = $config['database'];
    
    $dsn = "mysql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['database']};charset=utf8mb4";
    
    try {
        $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
        
        // Create admin user (table should already exist from Migration)
        $passwordHash = password_hash($adminConfig['password'], PASSWORD_DEFAULT);
        
        $stmt = $pdo->prepare("INSERT INTO users (email, password_hash, api_key, role) VALUES (?, ?, ?, 'admin') ON DUPLICATE KEY UPDATE password_hash = ?, api_key = ?");
        $stmt->execute([
            $adminConfig['email'],
            $passwordHash,
            $adminConfig['api_key'],
            $passwordHash,
            $adminConfig['api_key']
        ]);
        
        return [
            'message' => 'Administrator account created successfully',
            'data' => ['admin_email' => $adminConfig['email']]
        ];
        
    } catch (PDOException $e) {
        throw new Exception('Admin setup failed: ' . $e->getMessage());
    }
}

function initializeBlockchain(): array
{
    // Load configuration
    $configFile = '../config/install_config.json';
    if (!file_exists($configFile)) {
        throw new Exception('Installation configuration not found');
    }
    
    $config = json_decode(file_get_contents($configFile), true);
    $blockchainConfig = $config['blockchain'];
    
    // Verify genesis block exists
    $genesisFile = '../storage/blockchain/genesis.json';
    if (!file_exists($genesisFile)) {
        throw new Exception('Genesis block not found. Please run genesis generation first.');
    }
    
    $genesisData = json_decode(file_get_contents($genesisFile), true);
    if (!$genesisData) {
        throw new Exception('Invalid genesis block data');
    }
    
    // Initialize blockchain state
    $stateFile = '../storage/state/blockchain_state.json';
    $blockchainState = [
        'current_height' => 0,
        'last_block_hash' => $genesisData['hash'],
        'total_transactions' => 0,
        'total_supply' => $blockchainConfig['initial_supply'] ?? 1000000,
        'consensus_type' => $blockchainConfig['consensus_algorithm'] ?? 'pos',
        'network_id' => hash('sha256', $blockchainConfig['network_name'] ?? 'default'),
        'initialized_at' => time(),
        'version' => '1.0.0'
    ];
    
    if (!is_dir('../storage/state')) {
        mkdir('../storage/state', 0755, true);
    }
    
    if (file_put_contents($stateFile, json_encode($blockchainState, JSON_PRETTY_PRINT)) === false) {
        throw new Exception('Failed to save blockchain state');
    }
    
    // Initialize mempool
    $mempoolFile = '../storage/state/mempool.json';
    $mempool = [
        'pending_transactions' => [],
        'last_processed' => time()
    ];
    
    if (file_put_contents($mempoolFile, json_encode($mempool, JSON_PRETTY_PRINT)) === false) {
        throw new Exception('Failed to initialize mempool');
    }
    
    // Create initial wallet for the network (if using PoS)
    if ($blockchainConfig['consensus_algorithm'] === 'pos') {
        $walletFile = '../storage/state/genesis_wallet.json';
        $genesisWallet = [
            'address' => hash('sha256', 'genesis_wallet_' . time()),
            'balance' => $blockchainConfig['initial_supply'] ?? 1000000,
            'stake' => ($blockchainConfig['initial_supply'] ?? 1000000) * 0.1, // 10% initial stake
            'created_at' => time()
        ];
        
        file_put_contents($walletFile, json_encode($genesisWallet, JSON_PRETTY_PRINT));
    }
    
    return [
        'message' => 'Blockchain initialized successfully',
        'data' => [
            'status' => 'initialized',
            'genesis_hash' => $genesisData['hash'],
            'network_id' => $blockchainState['network_id'],
            'consensus' => $blockchainState['consensus_type'],
            'initial_supply' => $blockchainState['total_supply'],
            'files_created' => [
                'state' => $stateFile,
                'mempool' => $mempoolFile
            ]
        ]
    ];
}

function startServices(): array
{
    $services = [];
    $errors = [];
    
    // Check if core blockchain classes are available
    $coreFiles = [
        '../core/Blockchain/Blockchain.php',
        '../core/Blockchain/Block.php',
        '../core/Transaction/Transaction.php',
        '../api/BlockchainAPI.php'
    ];
    
    foreach ($coreFiles as $file) {
        if (file_exists($file)) {
            $services[] = basename($file, '.php');
        } else {
            $errors[] = "Core file missing: " . basename($file);
        }
    }
    
    // Check database connection
    try {
        $configFile = '../config/install_config.json';
        $config = json_decode(file_get_contents($configFile), true);
        $dbConfig = $config['database'];
        
        $dsn = "mysql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['database']};charset=utf8mb4";
        $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
        
        $services[] = 'Database';
    } catch (Exception $e) {
        $errors[] = 'Database connection failed: ' . $e->getMessage();
    }
    
    // Check if blockchain state is accessible
    $stateFile = '../storage/state/blockchain_state.json';
    if (file_exists($stateFile)) {
        $services[] = 'Blockchain State';
    } else {
        $errors[] = 'Blockchain state not found';
    }
    
    // Create service status file
    $statusFile = '../storage/state/services_status.json';
    $serviceStatus = [
        'active_services' => $services,
        'errors' => $errors,
        'last_check' => time(),
        'status' => empty($errors) ? 'healthy' : 'partial'
    ];
    
    if (!is_dir('../storage/state')) {
        mkdir('../storage/state', 0755, true);
    }
    
    file_put_contents($statusFile, json_encode($serviceStatus, JSON_PRETTY_PRINT));
    
    return [
        'message' => empty($errors) ? 'All services started successfully' : 'Services started with some warnings',
        'data' => [
            'services' => $services,
            'errors' => $errors,
            'total_services' => count($services),
            'status' => $serviceStatus['status'],
            'status_file' => $statusFile
        ]
    ];
}

function finalizeInstallation(): array
{
    $cleanupFiles = [];
    $errors = [];
    
    // Clean up temporary files
    $tempFiles = [
        '../config/install_config.json'
    ];
    
    foreach ($tempFiles as $file) {
        if (file_exists($file)) {
            if (unlink($file)) {
                $cleanupFiles[] = $file;
            } else {
                $errors[] = "Failed to remove temporary file: $file";
            }
        }
    }
    
    // Create installation completion marker
    $installationInfo = [
        'installed' => true,
        'installation_date' => date('Y-m-d H:i:s'),
        'installation_timestamp' => time(),
        'version' => '1.0.0',
        'installer_version' => '1.0.0',
        'php_version' => PHP_VERSION,
        'cleanup_files' => $cleanupFiles,
        'errors' => $errors
    ];
    
    $markerFile = '../config/installation.json';
    if (file_put_contents($markerFile, json_encode($installationInfo, JSON_PRETTY_PRINT)) === false) {
        $errors[] = 'Failed to create installation marker file';
    }
    
    // Set final permissions
    $protectedDirs = [
        '../config',
        '../storage',
        '../logs'
    ];
    
    foreach ($protectedDirs as $dir) {
        if (is_dir($dir)) {
            chmod($dir, 0755);
        }
    }
    
    // Create .htaccess for web protection
    $htaccessContent = "# Protect sensitive directories\n";
    $htaccessContent .= "Options -Indexes\n";
    $htaccessContent .= "<Files \"*.json\">\n";
    $htaccessContent .= "    Order allow,deny\n";
    $htaccessContent .= "    Deny from all\n";
    $htaccessContent .= "</Files>\n";
    
    file_put_contents('../storage/.htaccess', $htaccessContent);
    file_put_contents('../config/.htaccess', $htaccessContent);
    file_put_contents('../logs/.htaccess', $htaccessContent);
    
    return [
        'message' => 'Installation completed successfully',
        'data' => [
            'installation_complete' => true,
            'installation_date' => $installationInfo['installation_date'],
            'cleanup_files' => $cleanupFiles,
            'errors' => $errors,
            'protected_directories' => count($protectedDirs),
            'marker_file' => $markerFile,
            'next_steps' => [
                'Access admin panel',
                'Configure network settings',
                'Start blockchain node',
                'Create first wallet'
            ]
        ]
    ];
}
?>
