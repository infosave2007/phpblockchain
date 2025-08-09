<?php
// Disable any output buffering that might interfere
if (ob_get_level()) {
    ob_end_clean();
}

// Include common installation functions
require_once __DIR__ . '/install_functions.php';

/**
 * Helper function to create database connection using installer parameters
 * Falls back to DatabaseManager when configuration is available
 */
function createInstallerDatabaseConnection(array $params): PDO {
    $dsn = "mysql:host={$params['host']};port={$params['port']};charset=utf8mb4";
    if (!empty($params['database'])) {
        $dsn .= ";dbname={$params['database']}";
    }
    
    return new PDO($dsn, $params['username'], $params['password'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 10,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
    ]);
}

/**
 * Try to use DatabaseManager if config exists, otherwise use installer connection
 */
function getOptimalDatabaseConnection(?array $installerParams = null): PDO {
    // Try DatabaseManager first if config exists
    if (file_exists('../config/config.php') && !$installerParams) {
        try {
            require_once '../core/Database/DatabaseManager.php';
            return \Blockchain\Core\Database\DatabaseManager::getConnection();
        } catch (Exception $e) {
            // Fall back to installer method
        }
    }
    
    // Use installer parameters
    if ($installerParams) {
        return createInstallerDatabaseConnection($installerParams);
    }
    
    throw new Exception('No database connection method available');
}

// Set headers first
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Turn off error reporting to prevent HTML error pages
error_reporting(E_ALL);
ini_set('display_errors', 0); // Disable HTML output
ini_set('log_errors', 1);

// Clean any output that might have been generated
if (ob_get_level()) {
    ob_end_clean();
}
ob_start();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Only POST method allowed');
    }

    // Get step data from POST
    $rawInput = file_get_contents('php://input');
    
    // Log request for debugging
    $logFile = __DIR__ . '/install_debug.log';
    $logMessage = "\n=== REQUEST DEBUG " . date('Y-m-d H:i:s') . " ===\n";
    $logMessage .= "Raw input: " . $rawInput . "\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND);
    
    if (empty($rawInput)) {
        throw new Exception('No data received in request body');
    }
    
    $input = json_decode($rawInput, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON data: ' . json_last_error_msg());
    }

    $stepId = $input['step'] ?? '';
    $config = $input['config'] ?? [];
    
    $logMessage = "Step ID: $stepId\n";
    $logMessage .= "Config keys: " . implode(', ', array_keys($config)) . "\n";
    $logMessage .= "Full config: " . json_encode($config, JSON_PRETTY_PRINT) . "\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND);
    
    if (empty($stepId)) {
        throw new Exception('Step ID not provided');
    }

    // Execute step based on step ID
    $result = executeInstallationStep($stepId, $config);
    
    // Clean any output buffer before sending JSON
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    echo json_encode([
        'status' => 'success',
        'message' => $result['message'],
        'data' => $result['data'] ?? null
    ]);

} catch (Exception $e) {
    // Clean any output buffer before sending JSON
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    $errorMsg = 'Exception: ' . $e->getMessage();
    $errorDetails = [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ];
    
    // Log detailed error
    $logFile = __DIR__ . '/install_debug.log';
    $logMessage = "\n=== EXCEPTION DEBUG " . date('Y-m-d H:i:s') . " ===\n";
    $logMessage .= "Error: " . json_encode($errorDetails, JSON_PRETTY_PRINT) . "\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND);
    
    echo json_encode([
        'status' => 'error',
        'message' => $errorMsg,
        'details' => $errorDetails
    ]);
} catch (Error $e) {
    // Clean any output buffer before sending JSON
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    $errorMsg = 'Fatal error: ' . $e->getMessage();
    $errorDetails = [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ];
    
    // Log detailed error
    $logFile = __DIR__ . '/install_debug.log';
    $logMessage = "\n=== FATAL ERROR DEBUG " . date('Y-m-d H:i:s') . " ===\n";
    $logMessage .= "Error: " . json_encode($errorDetails, JSON_PRETTY_PRINT) . "\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND);
    
    echo json_encode([
        'status' => 'error',
        'message' => $errorMsg,
        'details' => $errorDetails
    ]);
} catch (Throwable $e) {
    // Clean any output buffer before sending JSON
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    $errorMsg = 'Unexpected error: ' . $e->getMessage();
    $errorDetails = [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ];
    
    // Log detailed error
    $logFile = __DIR__ . '/install_debug.log';
    $logMessage = "\n=== THROWABLE ERROR DEBUG " . date('Y-m-d H:i:s') . " ===\n";
    $logMessage .= "Error: " . json_encode($errorDetails, JSON_PRETTY_PRINT) . "\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND);
    
    echo json_encode([
        'status' => 'error',
        'message' => $errorMsg,
        'details' => $errorDetails
    ]);
}

function executeInstallationStep(string $stepId, array $config = []): array
{
    try {
        // Load configuration from file if not provided
        if (empty($config)) {
            $configFile = '../config/install_config.json';
            if (file_exists($configFile)) {
                $fileConfig = json_decode(file_get_contents($configFile), true);
                if ($fileConfig && count($fileConfig) > 0) {
                    $config = $fileConfig;
                }
            }
        }
        
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
                // Use manual_genesis.php logic if config is empty
                if (empty($config) || count($config) === 0) {
                    // Try to load config from file
                    $configFile = '../config/install_config.json';
                    if (file_exists($configFile)) {
                        $fileConfig = json_decode(file_get_contents($configFile), true);
                        if ($fileConfig && count($fileConfig) > 0) {
                            return generateGenesis($fileConfig);
                        }
                    }
                    
                    // If still no config, use manual approach
                    return generateGenesisManual([]);
                } else {
                    return generateGenesis($config);
                }
                
            case 'create_wallet':
                return createWallet($config);
                
            case 'sync_blockchain':
                return syncBlockchainWithGenesisNode($config);
                
            case 'initialize_binary_storage':
                return initializeBinaryStorage();
                
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
    } catch (Exception $e) {
        // Log step-specific error
        $logFile = __DIR__ . '/install_debug.log';
        $logMessage = "\n=== STEP ERROR DEBUG " . date('Y-m-d H:i:s') . " ===\n";
        $logMessage .= "Step: $stepId\n";
        $logMessage .= "Error: " . $e->getMessage() . "\n";
        $logMessage .= "File: " . $e->getFile() . "\n";
        $logMessage .= "Line: " . $e->getLine() . "\n";
        $logMessage .= "Trace: " . $e->getTraceAsString() . "\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        
        throw $e; // Re-throw to be caught by main handler
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
    if (!$config) {
        throw new Exception('Failed to parse installation configuration');
    }
    
    file_put_contents('install_debug.log', "=== CREATE DATABASE DEBUG ===\n", FILE_APPEND);
    file_put_contents('install_debug.log', "Config: " . print_r($config, true) . "\n", FILE_APPEND);
    
    // Extract database settings from the simple format
    $host = $config['db_host'] ?? '';
    $port = $config['db_port'] ?? 3306;
    $username = $config['db_username'] ?? '';
    $password = $config['db_password'] ?? '';
    $database = $config['db_name'] ?? '';
    
    if (empty($host) || empty($username) || empty($database)) {
        throw new Exception('Database configuration incomplete. Host: ' . $host . ', Username: ' . $username . ', Database: ' . $database);
    }
    
    file_put_contents('install_debug.log', "Connection params: host=$host, port=$port, username=$username, database=$database\n", FILE_APPEND);
    
    // Test database connection
    $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
    
    try {
        file_put_contents('install_debug.log', "Attempting connection with DSN: $dsn\n", FILE_APPEND);
        
        $pdo = createInstallerDatabaseConnection([
            'host' => $host,
            'port' => $port,
            'username' => $username,
            'password' => $password
        ]);
        
        file_put_contents('install_debug.log', "Connection successful, creating database: $database\n", FILE_APPEND);
        
        // Create database if it doesn't exist
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        
        file_put_contents('install_debug.log', "Database created/verified successfully\n", FILE_APPEND);
        
        return [
            'message' => 'Database created successfully',
            'data' => ['database' => $database]
        ];
        
    } catch (PDOException $e) {
        $errorMsg = 'Database creation failed: ' . $e->getMessage();
        file_put_contents('install_debug.log', "PDO Error: " . $errorMsg . "\n", FILE_APPEND);
        file_put_contents('install_debug.log', "Error Code: " . $e->getCode() . "\n", FILE_APPEND);
        throw new Exception($errorMsg);
    }
}

function createTables(): array
{
    // Load configuration
    $configFile = '../config/install_config.json';
    if (!file_exists($configFile)) {
        throw new Exception('Installation configuration not found');
    }
    
    $config = json_decode(file_get_contents($configFile), true);
    if (!$config) {
        throw new Exception('Failed to parse installation configuration');
    }
    
    // Extract database settings from the simple format
    $host = $config['db_host'] ?? '';
    $port = $config['db_port'] ?? 3306;
    $username = $config['db_username'] ?? '';
    $password = $config['db_password'] ?? '';
    $database = $config['db_name'] ?? '';
    
    $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
    
    try {
        $pdo = createInstallerDatabaseConnection([
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'username' => $username,
            'password' => $password
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
            
            // Initialize network configuration in database
            $networkConfigData = [];
            
            // For regular nodes, get configuration from existing network nodes
            if (($config['node_type'] ?? '') === 'regular' && !empty($config['network_nodes'])) {
                try {
                    $logFile = __DIR__ . '/install_debug.log';
                    $logMessage = "\n=== NETWORK CONFIG RETRIEVAL DURING TABLE CREATION ===\n";
                    file_put_contents($logFile, $logMessage, FILE_APPEND);
                    
                    // Get network configuration from nodes
                    $nodeUrls = array_filter(array_map('trim', explode("\n", $config['network_nodes'])));
                    $networkConfig = getNetworkConfiguration($nodeUrls, $logFile);
                    
                    if (!empty($networkConfig) && isset($networkConfig['network_name'])) {
                        $networkConfigData = $networkConfig;
                        
                        $logMessage = "✓ Retrieved network configuration during table creation:\n";
                        $logMessage .= "  Network: " . ($networkConfig['network_name'] ?? 'unknown') . "\n";
                        $logMessage .= "  Token: " . ($networkConfig['token_symbol'] ?? 'unknown') . "\n";
                        $logMessage .= "  Min stake: " . ($networkConfig['min_stake_amount'] ?? 'unknown') . "\n";
                        file_put_contents($logFile, $logMessage, FILE_APPEND);
                    }
                } catch (Exception $e) {
                    // Network config retrieval failed, continue with defaults
                    $logMessage = "⚠️  Failed to retrieve network config during table creation: " . $e->getMessage() . "\n";
                    file_put_contents($logFile, $logMessage, FILE_APPEND);
                }
            }
            
            // Insert initial configuration into config table
            $configValues = [
                // Basic blockchain settings
                'network.name' => $networkConfigData['network_name'] ?? $config['network_name'] ?? 'Default Network',
                'network.token_symbol' => $networkConfigData['token_symbol'] ?? $config['token_symbol'] ?? 'DFT',
                'network.token_name' => $networkConfigData['token_name'] ?? ($networkConfigData['token_symbol'] ?? $config['token_symbol'] ?? 'DFT') . ' Token',
                'network.initial_supply' => $networkConfigData['initial_supply'] ?? $config['initial_supply'] ?? 1000000,
                'network.total_supply' => $networkConfigData['total_supply'] ?? $networkConfigData['initial_supply'] ?? $config['initial_supply'] ?? 1000000,
                'network.decimals' => $networkConfigData['decimals'] ?? 8,
                'network.chain_id' => $networkConfigData['chain_id'] ?? 1,
                'network.nodes' => $config['network_nodes'] ?? '',
                
                // Consensus settings
                'consensus.algorithm' => 'pos',
                'consensus.min_stake_amount' => $networkConfigData['min_stake_amount'] ?? $config['min_stake_amount'] ?? 1000,
                'consensus.block_time' => $config['block_time'] ?? 10,
                'consensus.block_reward' => $config['block_reward'] ?? 10,
                
                // Node settings  
                'node.type' => $config['node_type'] ?? 'primary',
                'node.max_peers' => $config['max_peers'] ?? 10,
                'node.domain' => $config['node_domain'] ?? 'localhost',
                'node.protocol' => $config['protocol'] ?? 'http',
                'node.selection_strategy' => $config['node_selection_strategy'] ?? 'fastest_response',
                
                // Storage settings
                'storage.blockchain_data_dir' => $config['blockchain_data_dir'] ?? 'storage/blockchain',
                'storage.enable_binary_storage' => $config['enable_binary_storage'] ?? true,
                'storage.enable_encryption' => $config['enable_encryption'] ?? true,
                
                // System settings
                'system.installation_date' => date('Y-m-d H:i:s'),
                'system.version' => '1.0.0',
                'system.api_key' => $config['api_key'] ?? '',
            ];
            
            // Insert configuration values
            $insertConfigStmt = $pdo->prepare("
                INSERT INTO config (key_name, value, description, is_system) 
                VALUES (?, ?, ?, CAST(? AS UNSIGNED)) 
                ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = CURRENT_TIMESTAMP
            ");
            
            $configDescriptions = [
                'network.name' => 'Blockchain network name',
                'network.token_symbol' => 'Network token symbol',
                'network.token_name' => 'Network token full name',
                'network.initial_supply' => 'Initial token supply',
                'network.total_supply' => 'Total token supply',
                'network.decimals' => 'Token decimal places',
                'network.chain_id' => 'Blockchain chain identifier',
                'network.nodes' => 'Network nodes list for synchronization',
                'consensus.algorithm' => 'Consensus algorithm type',
                'consensus.min_stake_amount' => 'Minimum staking amount required',
                'consensus.block_time' => 'Block generation time in seconds',
                'consensus.block_reward' => 'Reward per block',
                'node.type' => 'Node type (primary/regular)',
                'node.max_peers' => 'Maximum peer connections',
                'node.domain' => 'Node domain or IP address',
                'node.protocol' => 'Node protocol (http/https)',
                'node.selection_strategy' => 'Node selection strategy',
                'storage.blockchain_data_dir' => 'Blockchain data directory',
                'storage.enable_binary_storage' => 'Enable binary blockchain storage',
                'storage.enable_encryption' => 'Enable data encryption',
                'system.installation_date' => 'Installation completion date',
                'system.version' => 'Platform version',
                'system.api_key' => 'API access key',
            ];
            
            foreach ($configValues as $key => $value) {
                $description = $configDescriptions[$key] ?? '';
                $isSystem = strpos($key, 'system.') === 0 ? 1 : 0;
                
                // Debug logging
                error_log("Config insert: key=$key, value=" . (string)$value . ", description=$description, isSystem=" . var_export($isSystem, true));
                
                try {
                    $insertConfigStmt->execute([$key, (string)$value, $description, (int)$isSystem]);
                    error_log("Config insert SUCCESS for key: $key");
                } catch (Exception $e) {
                    error_log("Config insert ERROR for key: $key - " . $e->getMessage());
                    throw $e;
                }
            }
            
            $configInserted = count($configValues);
            
            return [
                'message' => 'Database tables created successfully',
                'data' => [
                    'tables' => $tables,
                    'count' => count($tables),
                    'migration_output' => trim($migrationOutput),
                    'config_inserted' => $configInserted,
                    'network_config_retrieved' => !empty($networkConfigData),
                    'network_name' => $networkConfigData['network_name'] ?? $config['network_name'] ?? 'Default Network',
                    'token_symbol' => $networkConfigData['token_symbol'] ?? $config['token_symbol'] ?? 'DFT'
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

function initializeBinaryStorage(): array
{
    // Load configuration
    $configFile = '../config/install_config.json';
    if (!file_exists($configFile)) {
        throw new Exception('Installation configuration not found');
    }
    
    $config = json_decode(file_get_contents($configFile), true);
    $blockchainConfig = $config['blockchain'] ?? [];
    
    // Check if binary storage is enabled
    if (!($blockchainConfig['enable_binary_storage'] ?? true)) {
        return [
            'message' => 'Binary storage is disabled, skipping initialization',
            'data' => ['enabled' => false]
        ];
    }
    
    // Get blockchain data directory
    $dataDir = '../' . ($blockchainConfig['data_dir'] ?? 'storage/blockchain');
    
    // Ensure data directory exists
    if (!is_dir($dataDir)) {
        if (!mkdir($dataDir, 0755, true)) {
            throw new Exception("Failed to create blockchain data directory: $dataDir");
        }
    }
    
    // Initialize binary storage
    try {
        require_once '../vendor/autoload.php';
        require_once '../core/Storage/BlockchainBinaryStorage.php';
        
        // Create binary storage instance
        $binaryStorage = new \Blockchain\Core\Storage\BlockchainBinaryStorage(
            $dataDir,
            $blockchainConfig,
            false // not readonly
        );
        
        // Verify binary storage is properly initialized
        $stats = $binaryStorage->getStats();
        
        return [
            'message' => 'Binary blockchain storage initialized successfully',
            'data' => [
                'enabled' => true,
                'data_dir' => $dataDir,
                'encryption_enabled' => $blockchainConfig['enable_encryption'] ?? false,
                'binary_file' => $dataDir . '/blockchain.bin',
                'index_file' => $dataDir . '/blockchain.idx',
                'stats' => $stats
            ]
        ];
        
    } catch (Exception $e) {
        throw new Exception('Failed to initialize binary storage: ' . $e->getMessage());
    }
}

function generateGenesis(array $config = []): array
{
    // Add debug logging
    $logFile = __DIR__ . '/install_debug.log';
    $logMessage = "\n=== GENERATE GENESIS DEBUG " . date('Y-m-d H:i:s') . " ===\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND);
    
    // Load configuration for blockchain parameters
    $configFile = '../config/install_config.json';
    $logMessage = "Looking for config file: $configFile\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND);
    
    if (!file_exists($configFile)) {
        $logMessage = "ERROR: Installation configuration not found at: $configFile\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        throw new Exception('Installation configuration not found');
    }
    
    $config = json_decode(file_get_contents($configFile), true);
    if (!$config) {
        $logMessage = "ERROR: Failed to parse configuration file\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        throw new Exception('Failed to parse installation configuration');
    }
    
    $logMessage = "Configuration loaded successfully with keys: " . implode(', ', array_keys($config)) . "\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND);
    
    // Include required classes
    $autoloadFile = '../vendor/autoload.php';
    $logMessage = "Looking for autoload file: $autoloadFile\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND);
    
    if (!file_exists($autoloadFile)) {
        $logMessage = "ERROR: Composer autoload not found at: $autoloadFile\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        throw new Exception('Composer autoload not found');
    }
    
    require_once $autoloadFile;
    $logMessage = "Autoload included successfully\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND);
    
    // Check if core files exist
    $coreFiles = [
        '../core/Blockchain/Blockchain.php',
        '../core/Blockchain/Block.php', 
        '../core/Storage/BlockStorage.php'
    ];
    
    foreach ($coreFiles as $file) {
        if (file_exists($file)) {
            $logMessage = "Core file exists: $file\n";
            file_put_contents($logFile, $logMessage, FILE_APPEND);
        } else {
            $logMessage = "ERROR: Core file missing: $file\n";
            file_put_contents($logFile, $logMessage, FILE_APPEND);
        }
    }
    
    // Try to include core files manually if autoload fails
    $coreFilesToInclude = [
        '../core/Contracts/BlockInterface.php',
        '../core/Contracts/TransactionInterface.php', 
        '../core/Crypto/Hash.php',
        '../core/Cryptography/MerkleTree.php',
        '../core/Blockchain/Block.php',
        '../core/Storage/BlockStorage.php',
        '../core/Blockchain/Blockchain.php'
    ];
    
    foreach ($coreFilesToInclude as $file) {
        if (file_exists($file)) {
            try {
                require_once $file;
                $logMessage = "Successfully included: $file\n";
                file_put_contents($logFile, $logMessage, FILE_APPEND);
            } catch (ParseError $e) {
                $logMessage = "PARSE ERROR in $file: " . $e->getMessage() . "\n";
                file_put_contents($logFile, $logMessage, FILE_APPEND);
                throw new Exception("Parse error in $file: " . $e->getMessage());
            } catch (Error $e) {
                $logMessage = "FATAL ERROR in $file: " . $e->getMessage() . "\n";
                file_put_contents($logFile, $logMessage, FILE_APPEND);
                throw new Exception("Fatal error in $file: " . $e->getMessage());
            } catch (Exception $e) {
                $logMessage = "EXCEPTION in $file: " . $e->getMessage() . "\n";
                file_put_contents($logFile, $logMessage, FILE_APPEND);
                throw new Exception("Exception in $file: " . $e->getMessage());
            }
        } else {
            $logMessage = "ERROR: Required file not found: $file\n";
            file_put_contents($logFile, $logMessage, FILE_APPEND);
            throw new Exception("Required file not found: $file");
        }
    }
    
    // Get database connection
    $host = $config['db_host'] ?? 'localhost';
    $port = $config['db_port'] ?? 3306;
    $username = $config['db_username'] ?? '';
    $password = $config['db_password'] ?? '';
    $database = $config['db_name'] ?? '';
    
    $logMessage = "Database params: host=$host, port=$port, username=$username, database=$database\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND);
    
    $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
    
    try {
        $pdo = createInstallerDatabaseConnection([
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'username' => $username,
            'password' => $password
        ]);
        $logMessage = "Database connected successfully\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
    } catch (PDOException $e) {
        $logMessage = "ERROR: Database connection failed: " . $e->getMessage() . "\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        throw new Exception('Database connection failed: ' . $e->getMessage());
    }
    
    // Get wallet address from database if exists
    $walletAddress = null;
    try {
        $stmt = $pdo->query("SELECT address FROM wallets ORDER BY created_at DESC LIMIT 1");
        $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($wallet) {
            $walletAddress = $wallet['address'];
            $logMessage = "Found existing wallet address: $walletAddress\n";
            file_put_contents($logFile, $logMessage, FILE_APPEND);
        } else {
            $logMessage = "No existing wallet found\n";
            file_put_contents($logFile, $logMessage, FILE_APPEND);
        }
    } catch (Exception $e) {
        $logMessage = "Warning: Could not check for existing wallet: " . $e->getMessage() . "\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        // Continue without wallet address
    }
    
    // Prepare genesis configuration
    $genesisConfig = [
        'initial_supply' => $config['initial_supply'] ?? 1000000,
        'network_name' => $config['network_name'] ?? 'Blockchain Network',
        'token_symbol' => $config['token_symbol'] ?? 'TOKEN',
        'consensus_algorithm' => $config['consensus_algorithm'] ?? 'pos',
        'wallet_address' => $walletAddress,
        'primary_wallet_amount' => $config['primary_wallet_amount'] ?? 0,
        'staking_amount' => $config['staking_amount'] ?? 1000,
        'min_stake_amount' => $config['min_stake_amount'] ?? 1000,
        'enable_binary_storage' => $config['enable_binary_storage'] ?? false,
        'enable_encryption' => $config['enable_encryption'] ?? false,
        'node_domain' => $config['node_domain'] ?? 'localhost',
        'protocol' => $config['protocol'] ?? 'https'
    ];
    
    $logMessage = "Genesis config prepared: " . json_encode($genesisConfig) . "\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND);
    
    try {
        $logMessage = "Testing class availability after manual includes...\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        
        // Test if we can instantiate the classes
        $testReflection = new ReflectionClass('\\Blockchain\\Core\\Blockchain\\Block');
        $logMessage = "Block class instantiable: YES\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        
        $testReflection = new ReflectionClass('\\Blockchain\\Core\\Storage\\BlockStorage');
        $logMessage = "BlockStorage class instantiable: YES\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        
        $testReflection = new ReflectionClass('\\Blockchain\\Core\\Blockchain\\Blockchain');
        $logMessage = "Blockchain class instantiable: YES\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        
        $logMessage = "Attempting to create genesis block using Blockchain class...\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        
        // Check if Blockchain class exists
        if (!class_exists('\\Blockchain\\Core\\Blockchain\\Blockchain')) {
            $logMessage = "ERROR: Blockchain class not found\n";
            file_put_contents($logFile, $logMessage, FILE_APPEND);
            throw new Exception('Blockchain class not found');
        }
        
        // Check if Block class exists
        if (!class_exists('\\Blockchain\\Core\\Blockchain\\Block')) {
            $logMessage = "ERROR: Block class not found\n";
            file_put_contents($logFile, $logMessage, FILE_APPEND);
            throw new Exception('Block class not found');
        }
        
        // Check if BlockStorage class exists
        if (!class_exists('\\Blockchain\\Core\\Storage\\BlockStorage')) {
            $logMessage = "ERROR: BlockStorage class not found\n";
            file_put_contents($logFile, $logMessage, FILE_APPEND);
            throw new Exception('BlockStorage class not found');
        }
        
        $logMessage = "All required classes found\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        
        // Check if createGenesisWithDatabase method exists
        if (!method_exists('\\Blockchain\\Core\\Blockchain\\Blockchain', 'createGenesisWithDatabase')) {
            $logMessage = "ERROR: createGenesisWithDatabase method not found\n";
            file_put_contents($logFile, $logMessage, FILE_APPEND);
            throw new Exception('createGenesisWithDatabase method not found in Blockchain class');
        }
        
        $logMessage = "createGenesisWithDatabase method found\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        
        // Create genesis block using Blockchain class method
        $logMessage = "Calling createGenesisWithDatabase...\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        
        $genesisBlock = \Blockchain\Core\Blockchain\Blockchain::createGenesisWithDatabase($pdo, $genesisConfig);
        
        $logMessage = "Genesis block created successfully with hash: " . $genesisBlock->getHash() . "\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        
        $genesisData = [
            'index' => $genesisBlock->getIndex(),
            'timestamp' => $genesisBlock->getTimestamp(),
            'transactions' => $genesisBlock->getTransactions(),
            'parent_hash' => $genesisBlock->getPreviousHash(),
            'hash' => $genesisBlock->getHash(),
            'nonce' => $genesisBlock->getNonce(),
            'merkle_root' => $genesisBlock->getMerkleRoot()
        ];
        
        $logMessage = "Genesis data extracted successfully\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        
        // Save network configuration to database
        try {
            $configInserts = [
                ['network.name', $genesisConfig['network_name'] ?? 'Blockchain Network'],
                ['network.token_symbol', $genesisConfig['token_symbol'] ?? 'COIN'],
                ['network.token_name', ($genesisConfig['token_symbol'] ?? 'COIN') . ' Token'],
                ['network.initial_supply', $genesisConfig['initial_supply'] ?? 1000000],
                ['blockchain.genesis_block', $genesisBlock->getHash()],
                ['network.chain_id', '1'],
                ['network.decimals', '8'],
                ['consensus.algorithm', $genesisConfig['consensus_algorithm'] ?? 'pos'],
                ['consensus.min_stake', $genesisConfig['min_stake_amount'] ?? 1000],
                ['network.protocol_version', '1.0.0']
            ];
            
            $stmt = $pdo->prepare("
                INSERT INTO config (key_name, value, description, is_system) VALUES (?, ?, ?, CAST(? AS UNSIGNED))
                ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = CURRENT_TIMESTAMP
            ");
            
            foreach ($configInserts as [$key, $value]) {
                $description = match($key) {
                    'network.name' => 'Network display name',
                    'network.token_symbol' => 'Token symbol (ticker)',
                    'network.token_name' => 'Token full name',
                    'network.initial_supply' => 'Initial token supply',
                    'blockchain.genesis_block' => 'Genesis block hash',
                    'network.chain_id' => 'Network chain identifier',
                    'network.decimals' => 'Token decimal places',
                    'consensus.algorithm' => 'Consensus algorithm type',
                    'consensus.min_stake' => 'Minimum staking amount',
                    'network.protocol_version' => 'Network protocol version',
                    default => 'Network configuration'
                };
                
                $isSystem = in_array($key, ['blockchain.genesis_block', 'network.chain_id', 'network.protocol_version']) ? 1 : 0;
                
                // Debug logging
                error_log("Network config insert: key=$key, value=" . (string)$value . ", description=$description, isSystem=$isSystem");
                
                $stmt->execute([$key, (string)$value, $description, $isSystem]);
            }
            
            $logMessage = "Network configuration saved to database successfully\n";
            file_put_contents($logFile, $logMessage, FILE_APPEND);
            
        } catch (Exception $e) {
            $logMessage = "WARNING: Failed to save network configuration: " . $e->getMessage() . "\n";
            file_put_contents($logFile, $logMessage, FILE_APPEND);
        }
        
        // Save to file storage as well
        $storageDir = '../storage/blockchain';
        if (!is_dir($storageDir)) {
            if (!mkdir($storageDir, 0755, true)) {
                $logMessage = "ERROR: Failed to create storage directory: $storageDir\n";
                file_put_contents($logFile, $logMessage, FILE_APPEND);
                throw new Exception("Failed to create storage directory: $storageDir");
            }
            $logMessage = "Created storage directory: $storageDir\n";
            file_put_contents($logFile, $logMessage, FILE_APPEND);
        }
        
        $genesisFile = $storageDir . '/genesis.json';
        $chainFile = $storageDir . '/chain.json';
        
        $result1 = file_put_contents($genesisFile, json_encode($genesisData, JSON_PRETTY_PRINT));
        $result2 = file_put_contents($chainFile, json_encode([$genesisData], JSON_PRETTY_PRINT));
        
        if ($result1 === false || $result2 === false) {
            $logMessage = "ERROR: Failed to save genesis files\n";
            file_put_contents($logFile, $logMessage, FILE_APPEND);
            throw new Exception('Failed to save genesis files');
        }
        
        $logMessage = "Genesis files saved successfully\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        
        return [
            'message' => 'Genesis block created successfully using Blockchain class',
            'data' => [
                'genesis' => $genesisData,
                'files_created' => [
                    'genesis' => $genesisFile,
                    'chain' => $chainFile
                ],
                'block_hash' => $genesisData['hash'],
                'using_block_class' => true,
                'database_saved' => true,
                'wallet_funded' => !empty($walletAddress)
            ]
        ];
        
    } catch (Exception $e) {
        $logMessage = "ERROR in genesis creation: " . $e->getMessage() . "\n";
        $logMessage .= "Stack trace: " . $e->getTraceAsString() . "\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        throw new Exception('Failed to create genesis block: ' . $e->getMessage());
    }
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
        'parent_hash' => '0',
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
        'database' => $config['database'] ?? [],
        'blockchain' => array_merge($config['blockchain'] ?? [], [
            'genesis_created' => true,
            'last_block_check' => time(),
            'binary_storage' => [
                'enabled' => $config['blockchain']['enable_binary_storage'] ?? true,
                'data_dir' => $config['blockchain']['data_dir'] ?? 'storage/blockchain',
                'encryption_enabled' => $config['blockchain']['enable_encryption'] ?? false,
                'encryption_key' => $config['blockchain']['encryption_key'] ?? '',
                'backup_enabled' => true,
                'backup_interval' => 86400, // 24 hours
                'max_backups' => 10
            ],
            'sync' => [
                'db_to_binary' => true,
                'binary_to_db' => true,
                'auto_sync' => true,
                'sync_interval' => 3600 // 1 hour
            ]
        ]),
        'network' => $config['network'] ?? [],
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
    
    // Create environment file with flexible database config handling
    $envPath = '../config/.env';
    $envContent = "# Auto-generated environment file\n";
    $envContent .= "APP_KEY={$appKey}\n";
    $envContent .= "JWT_SECRET={$jwtSecret}\n";
    
    // Handle flexible database configuration format
    $dbHost = $config['database']['host'] ?? $config['db_host'] ?? 'localhost';
    $dbPort = $config['database']['port'] ?? $config['db_port'] ?? 3306;
    $dbDatabase = $config['database']['database'] ?? $config['db_name'] ?? '';
    $dbUsername = $config['database']['username'] ?? $config['db_username'] ?? '';
    $dbPassword = $config['database']['password'] ?? $config['db_password'] ?? '';
    
    $envContent .= "DB_HOST={$dbHost}\n";
    $envContent .= "DB_PORT={$dbPort}\n";
    $envContent .= "DB_DATABASE={$dbDatabase}\n";
    $envContent .= "DB_USERNAME={$dbUsername}\n";
    $envContent .= "DB_PASSWORD={$dbPassword}\n";
    
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
    
    // Handle admin config in flexible format
    $adminConfig = [];
    if (isset($config['admin'])) {
        $adminConfig = $config['admin'];
    } else {
        // Create admin config from direct keys
        $adminConfig = [
            'email' => $config['admin_email'] ?? '',
            'password' => $config['admin_password'] ?? '',
            'api_key' => $config['api_key'] ?? ''
        ];
    }
    
    if (empty($adminConfig['email']) || empty($adminConfig['password'])) {
        throw new Exception('Admin email and password are required');
    }
    
    // Connect to database - handle multiple config formats like createWallet does
    $host = $config['database']['host'] ?? $config['db_host'] ?? 'localhost';
    $port = $config['database']['port'] ?? $config['db_port'] ?? 3306;
    $username = $config['database']['username'] ?? $config['db_username'] ?? '';
    $password = $config['database']['password'] ?? $config['db_password'] ?? '';
    $database = $config['database']['database'] ?? $config['db_name'] ?? '';
    
    if (empty($username) || empty($database)) {
        throw new Exception('Database configuration incomplete for admin setup');
    }
    
    $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
    
    try {
        $pdo = createInstallerDatabaseConnection([
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'username' => $username,
            'password' => $password
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
    // Use the new blockchain initialization script
    $initScript = __DIR__ . '/initialize_blockchain.php';
    
    try {
        // Capture output from the initialization script
        ob_start();
        $result = include $initScript;
        $output = ob_get_clean();
        
        // If the script returned data directly, use it
        if (is_array($result)) {
            return $result;
        }
        
        // Otherwise, parse the JSON output
        if (!empty($output)) {
            $jsonResult = json_decode($output, true);
            if ($jsonResult && $jsonResult['status'] === 'success') {
                return [
                    'message' => 'Blockchain initialized with binary storage successfully',
                    'data' => $jsonResult['data']
                ];
            } else {
                throw new Exception($jsonResult['message'] ?? 'Unknown error during blockchain initialization');
            }
        }
        
        throw new Exception('Empty response from blockchain initialization');
        
    } catch (Exception $e) {
        throw new Exception('Failed to initialize blockchain: ' . $e->getMessage());
    }
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
    
    // Check database connection using flexible config format
    try {
        $configFile = '../config/install_config.json';
        $config = json_decode(file_get_contents($configFile), true);
        
        // Handle flexible database configuration format
        $host = $config['database']['host'] ?? $config['db_host'] ?? 'localhost';
        $port = $config['database']['port'] ?? $config['db_port'] ?? 3306;
        $username = $config['database']['username'] ?? $config['db_username'] ?? '';
        $password = $config['database']['password'] ?? $config['db_password'] ?? '';
        $database = $config['database']['database'] ?? $config['db_name'] ?? '';
        
        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
        $pdo = createInstallerDatabaseConnection([
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'username' => $username,
            'password' => $password
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
    $nodeRegistrationResult = null;
    
    // Setup logging
    $logFile = __DIR__ . '/install_debug.log';
    $logMessage = "\n=== FINALIZATION DEBUG " . date('Y-m-d H:i:s') . " ===\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND);
    
    // Load configuration before cleanup
    $configFile = '../config/install_config.json';
    $config = [];
    if (file_exists($configFile)) {
        $config = json_decode(file_get_contents($configFile), true) ?? [];
        $logMessage = "✓ Configuration loaded from $configFile\n";
        $logMessage .= "  Node type: " . ($config['node_type'] ?? 'unknown') . "\n";
        $logMessage .= "  Node domain: " . ($config['node_domain'] ?? 'unknown') . "\n";
        $logMessage .= "  Network nodes: " . substr($config['network_nodes'] ?? 'none', 0, 50) . "...\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
    } else {
        $logMessage = "❌ Configuration file not found: $configFile\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
    }
    
    // Register node with network if this is a regular node
    if (!empty($config) && ($config['node_type'] ?? 'regular') === 'regular') {
        $logMessage = "✓ Starting node registration for regular node\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        
        try {
            $logMessage = "✓ Using built-in node notification function\n";
            file_put_contents($logFile, $logMessage, FILE_APPEND);
            
            $nodeRegistrationResult = notifyNetworkAboutNewNode($config);
            
            $logMessage = "✓ Node registration completed with status: " . $nodeRegistrationResult['status'] . "\n";
            $logMessage .= "  Message: " . $nodeRegistrationResult['message'] . "\n";
            file_put_contents($logFile, $logMessage, FILE_APPEND);
            
            if ($nodeRegistrationResult['status'] === 'error') {
                $errors[] = 'Node registration failed: ' . $nodeRegistrationResult['message'];
                $logMessage = "❌ Node registration failed\n";
                file_put_contents($logFile, $logMessage, FILE_APPEND);
            } else {
                $logMessage = "✓ Node registration successful\n";
                if (isset($nodeRegistrationResult['data']['success_count'])) {
                    $logMessage .= "  Success rate: " . $nodeRegistrationResult['data']['success_rate'] . "%\n";
                    $logMessage .= "  Notified nodes: " . $nodeRegistrationResult['data']['success_count'] . "/" . $nodeRegistrationResult['data']['total_count'] . "\n";
                }
                file_put_contents($logFile, $logMessage, FILE_APPEND);
            }
        } catch (Exception $e) {
            $errors[] = 'Node registration exception: ' . $e->getMessage();
            $logMessage = "❌ Node registration exception: " . $e->getMessage() . "\n";
            $logMessage .= "  Stack trace: " . $e->getTraceAsString() . "\n";
            file_put_contents($logFile, $logMessage, FILE_APPEND);
        }
    } else {
        $nodeType = $config['node_type'] ?? 'unknown';
        $logMessage = "⏭️  Skipping node registration (node type: $nodeType)\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
    }
    
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
    

    
    // Prepare next steps based on node type
    $nextSteps = [
        'Access admin panel',
        'Configure network settings',
        'Start blockchain node'
    ];
    
    if ($nodeRegistrationResult && $nodeRegistrationResult['status'] === 'success') {
        $nextSteps[] = 'Node registered successfully with network';
    } elseif ($nodeRegistrationResult && $nodeRegistrationResult['status'] === 'error') {
        $nextSteps[] = 'Manual node registration may be required';
    }
    
    $nextSteps[] = 'Create first wallet';
    
    return [
        'message' => empty($errors) ? 'Installation completed successfully' : 'Installation completed with warnings',
        'data' => [
            'installation_complete' => true,
            'installation_date' => $installationInfo['installation_date'],
            'cleanup_files' => $cleanupFiles,
            'errors' => $errors,
            'protected_directories' => count($protectedDirs),
            'marker_file' => $markerFile,
            'node_registration' => $nodeRegistrationResult,
            'next_steps' => $nextSteps
        ]
    ];
}

function createWallet(array $passedConfig = []): array
{
    try {
        // Setup logging to file
        $logFile = __DIR__ . '/install_debug.log';
        $logMessage = "\n=== WALLET CREATION DEBUG " . date('Y-m-d H:i:s') . " ===\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        
        // Log start
        $logMessage = "Starting wallet creation step\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        
        // Debug: log all passed config
        $logMessage = "Passed config keys: " . implode(', ', array_keys($passedConfig)) . "\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        $logMessage = "Full passed config: " . json_encode($passedConfig, JSON_PRETTY_PRINT) . "\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        
        // Try to get configuration from multiple sources
        $config = null;
        
        // First try: from passed config parameter
        if (!empty($passedConfig)) {
            $config = $passedConfig;
            $logMessage = "Using config from parameter\n";
            file_put_contents($logFile, $logMessage, FILE_APPEND);
        }
        // Second try: from install_config.json file
        else {
            $configPath = '../config/install_config.json';
            if (!file_exists($configPath)) {
                throw new Exception('Installation configuration not found at: ' . $configPath);
            }
            
            $config = json_decode(file_get_contents($configPath), true);
            if (!$config) {
                throw new Exception('Failed to parse installation configuration');
            }
            $logMessage = "Using config from file\n";
            file_put_contents($logFile, $logMessage, FILE_APPEND);
        }
        
        $logMessage = "Config loaded with keys: " . implode(', ', array_keys($config)) . "\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        
        // Debug: Check database configuration keys
        $dbConfig = [];
        if (isset($config['database'])) {
            $dbConfig = $config['database'];
            $logMessage = "Found database config with keys: " . implode(', ', array_keys($dbConfig)) . "\n";
            file_put_contents($logFile, $logMessage, FILE_APPEND);
        } else {
            $logMessage = "No 'database' key found in config\n";
            file_put_contents($logFile, $logMessage, FILE_APPEND);
            
            // Check for direct keys
            $directKeys = ['db_host', 'db_port', 'db_username', 'db_password', 'db_name'];
            foreach ($directKeys as $key) {
                if (isset($config[$key])) {
                    $logMessage = "Found direct key '$key': " . $config[$key] . "\n";
                    file_put_contents($logFile, $logMessage, FILE_APPEND);
                }
            }
        }
        
        // Include required classes
        if (!file_exists('../vendor/autoload.php')) {
            throw new Exception('Composer autoload not found');
        }
        require_once '../vendor/autoload.php';
        
        // Get amounts from config
        $isPrimaryNode = ($config['node_type'] ?? 'primary') === 'primary';
        $walletAmount = 0;
        $stakingAmount = 0;
        
        if ($isPrimaryNode) {
            $walletAmount = (int)($config['primary_wallet_amount'] ?? 100000);
            $stakingAmount = (int)($config['min_stake_amount'] ?? 1000);
        } else {
            // Regular nodes import existing wallets - no initial funding needed
            $walletAmount = 0;
            // For regular nodes, staking amount will be determined from network config
            $stakingAmount = 0; // Will be set from network configuration
        }
        
        $logMessage = "Node type: " . ($isPrimaryNode ? 'primary' : 'regular') . "\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        $logMessage = "Wallet amounts - wallet: $walletAmount, staking: $stakingAmount\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        
        // Connect to database - try multiple config formats
        $host = $config['database']['host'] ?? $config['db_host'] ?? 'localhost';
        $port = $config['database']['port'] ?? $config['db_port'] ?? 3306;
        $username = $config['database']['username'] ?? $config['db_username'] ?? '';
        $password = $config['database']['password'] ?? $config['db_password'] ?? '';
        $database = $config['database']['database'] ?? $config['db_name'] ?? '';
        
        $logMessage = "Database connection params:\n";
        $logMessage .= "  host: '$host'\n";
        $logMessage .= "  port: '$port'\n";
        $logMessage .= "  username: '$username'\n";
        $logMessage .= "  password: " . (empty($password) ? 'EMPTY' : 'SET') . "\n";
        $logMessage .= "  database: '$database'\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        
        if (empty($username)) {
            throw new Exception('Database username is empty');
        }
        
        if (empty($database)) {
            throw new Exception('Database name is empty');
        }
        
        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
        $logMessage = "DSN: $dsn\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        
        $pdo = createInstallerDatabaseConnection([
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'username' => $username,
            'password' => $password
        ]);
        
        $logMessage = "Database connected successfully\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        
        // Create WalletManager instance
        $walletManager = new \Blockchain\Wallet\WalletManager($pdo, $config);
        
        // Initialize transaction and block variables
        $fundingTransaction = null;
        $fundingBlock = null;
        $walletData = [];
        
        // Handle wallet creation or import based on node type
        if (!$isPrimaryNode) {
            // Regular nodes must import existing wallets
            $logMessage = "Importing existing wallet for regular node\n";
            file_put_contents($logFile, $logMessage, FILE_APPEND);
            
            $existingAddress = $config['existing_wallet_address'] ?? '';
            $existingPrivateKey = $config['existing_wallet_private_key'] ?? '';
            // Wallet verification is always required for security
            $verifyWallet = true;
            
            if (empty($existingAddress) || empty($existingPrivateKey)) {
                throw new Exception('Wallet address and private key are required for regular nodes');
            }
            
            // Validate and import wallet
            try {
                // Step 1: Validate private key format and derive address
                $logMessage = "Step 1: Validating private key and deriving address\n";
                file_put_contents($logFile, $logMessage, FILE_APPEND);
                
                // For regular nodes: validate private key WITHOUT creating database record
                require_once '../vendor/autoload.php';
                
                // Determine if input is mnemonic phrase or private key and get the KeyPair
                $words = explode(' ', trim($existingPrivateKey));
                
                if (count($words) >= 12 && count($words) <= 24) {
                    // It's a mnemonic phrase
                    $mnemonic = array_map('trim', $words);
                    $keyPair = \Blockchain\Core\Cryptography\KeyPair::fromMnemonic($mnemonic);
                } else {
                    // Treat as private key (hex string)
                    $privateKey = $existingPrivateKey;
                    
                    // Clean up private key (remove 0x prefix if present)
                    if (strpos($privateKey, '0x') === 0) {
                        $privateKey = substr($privateKey, 2);
                    }
                    
                    $keyPair = \Blockchain\Core\Cryptography\KeyPair::fromPrivateKey($privateKey);
                }
                
                // Get wallet data without creating database record
                $importedWalletData = [
                    'address' => $keyPair->getAddress(),
                    'public_key' => $keyPair->getPublicKey(),
                    'private_key' => $keyPair->getPrivateKey(),
                    'imported' => true
                ];
                
                $derivedAddress = $importedWalletData['address'];
                
                $logMessage = "Derived address: $derivedAddress\n";
                $logMessage .= "Provided address: $existingAddress\n";
                file_put_contents($logFile, $logMessage, FILE_APPEND);
                
                // Verify that derived address matches provided address
                if (strtolower($derivedAddress) !== strtolower($existingAddress)) {
                    throw new Exception("Private key does not match provided wallet address. Expected: $existingAddress, Got: $derivedAddress");
                }
                
                $logMessage = "✓ Private key validation successful\n";
                file_put_contents($logFile, $logMessage, FILE_APPEND);
                
                // Step 2: Get network nodes and fetch network configuration (always required for security)
                $logMessage = "Step 2: Getting network nodes and configuration\n";
                file_put_contents($logFile, $logMessage, FILE_APPEND);
                    
                    $networkNodes = $config['network_nodes'] ?? '';
                    $nodeSelectionStrategy = $config['node_selection_strategy'] ?? 'fastest_response';
                    
                    if (empty($networkNodes)) {
                        throw new Exception('Network nodes list is required for wallet verification');
                    }
                    
                    // Parse and validate network nodes - ONLY USE CONFIG NODES FOR REGULAR NODES
                    $nodeUrls = array_filter(array_map('trim', explode("\n", $networkNodes)));
                    if (empty($nodeUrls)) {
                        throw new Exception('No valid network nodes provided for wallet verification');
                    }
                    
                    // Step 2.1: Get network configuration from existing nodes
                    $logMessage = "Step 2.1: Fetching network configuration from existing nodes\n";
                    file_put_contents($logFile, $logMessage, FILE_APPEND);
                    
                    $networkConfig = getNetworkConfiguration($nodeUrls, $logFile);
                    
                    // Update local config with network configuration
                    if ($networkConfig['network_name']) {
                        $config['network_name'] = $networkConfig['network_name'];
                        $logMessage = "✓ Network name updated: " . $networkConfig['network_name'] . "\n";
                        file_put_contents($logFile, $logMessage, FILE_APPEND);
                    }
                    
                    if ($networkConfig['token_symbol']) {
                        $config['token_symbol'] = $networkConfig['token_symbol'];
                        $logMessage = "✓ Token symbol updated: " . $networkConfig['token_symbol'] . "\n";
                        file_put_contents($logFile, $logMessage, FILE_APPEND);
                    }
                    
                    // Always use network requirements for staking amount
                    $networkMinStake = $networkConfig['min_stake_amount'];
                    
                    $logMessage = "✓ Using network minimum staking requirement: $networkMinStake tokens\n";
                    file_put_contents($logFile, $logMessage, FILE_APPEND);
                    
                    $stakingAmount = $networkMinStake;
                    
                    $walletData['network_config'] = $networkConfig;
                    $walletData['required_staking_amount'] = $stakingAmount;
                    
                    // FOR REGULAR NODES: Don't check database (it's empty), use only config nodes
                    $logMessage = "Network nodes from config: " . count($nodeUrls) . "\n";
                    $logMessage .= "Using only config nodes for regular node setup (database is empty)\n";
                    file_put_contents($logFile, $logMessage, FILE_APPEND);
                    
                    // Select best node for verification using only config nodes
                    $bestNode = selectBestNode($nodeUrls, $nodeSelectionStrategy, $logFile);
                    
                    // Step 3: Verify wallet ownership through cryptographic proof
                    $logMessage = "Step 3: Verifying wallet ownership through cryptographic proof\n";
                    file_put_contents($logFile, $logMessage, FILE_APPEND);
                    
                    $ownershipVerification = verifyWalletOwnership($existingAddress, $existingPrivateKey, $bestNode, $logFile);
                    
                    if (!$ownershipVerification['verified']) {
                        $errorMsg = $ownershipVerification['error'] ?? 'Wallet ownership verification failed';
                        throw new Exception("SECURITY: Cannot verify wallet ownership. $errorMsg");
                    }
                    
                    $logMessage = "✓ Wallet ownership verified using " . $ownershipVerification['verification_method'] . "\n";
                    file_put_contents($logFile, $logMessage, FILE_APPEND);
                    
                    $importedWalletData['ownership_verified'] = true;
                    $importedWalletData['verification_method'] = $ownershipVerification['verification_method'];
                    
                    // Step 4: Check wallet balance on selected node
                    $logMessage = "Step 4: Checking wallet balance on node: $bestNode\n";
                    file_put_contents($logFile, $logMessage, FILE_APPEND);
                    
                    $walletBalance = checkWalletBalanceOnNode($existingAddress, $bestNode, $logFile);
                    $importedWalletData['balance'] = $walletBalance['balance'];
                    $importedWalletData['verified_on_node'] = $bestNode;
                    
                    $logMessage = "✓ Wallet balance verified: " . $walletBalance['balance'] . " tokens\n";
                    file_put_contents($logFile, $logMessage, FILE_APPEND);
                    
                    // Step 5: Check if wallet is already bound to another node - USE API ONLY
                    $logMessage = "Step 5: Checking wallet node bindings via API\n";
                    file_put_contents($logFile, $logMessage, FILE_APPEND);
                    
                    $nodeBindings = checkWalletNodeBindings($existingAddress, $nodeUrls, $logFile);
                    
                    if (!empty($nodeBindings)) {
                        $boundNodes = array_keys($nodeBindings);
                        $logMessage = "WARNING: Wallet is bound to " . count($boundNodes) . " nodes: " . implode(', ', $boundNodes) . "\n";
                        file_put_contents($logFile, $logMessage, FILE_APPEND);
                        
                        // Allow binding but warn about potential issues
                        $importedWalletData['existing_bindings'] = $nodeBindings;
                        $importedWalletData['binding_warning'] = "This wallet is already bound to other nodes in the network";
                    } else {
                        $logMessage = "✓ Wallet is not bound to other nodes\n";
                        file_put_contents($logFile, $logMessage, FILE_APPEND);
                    }
                    
                    // Step 6: Verify minimum balance for staking (MANDATORY - block installation if insufficient)
                    // Use the updated staking amount from network configuration
                    if ($stakingAmount > 0) {
                        $logMessage = "Step 6: Verifying mandatory staking requirements\n";
                        $logMessage .= "  Required staking amount: $stakingAmount tokens (from network config)\n";
                        $logMessage .= "  Available wallet balance: " . $walletBalance['balance'] . " tokens\n";
                        file_put_contents($logFile, $logMessage, FILE_APPEND);
                        
                        if ($walletBalance['balance'] < $stakingAmount) {
                            $errorMsg = "INSUFFICIENT FUNDS: Node installation blocked. " .
                                       "Network requires minimum staking balance: $stakingAmount tokens, " .
                                       "but wallet only has: " . $walletBalance['balance'] . " tokens. " .
                                       "Please fund your wallet with at least " . ($stakingAmount - $walletBalance['balance']) . " more tokens and try again.";
                            
                            $logMessage = "❌ $errorMsg\n";
                            file_put_contents($logFile, $logMessage, FILE_APPEND);
                            
                            throw new Exception($errorMsg);
                        } else {
                            $logMessage = "✓ Sufficient balance for staking: " . $walletBalance['balance'] . " >= $stakingAmount\n";
                            file_put_contents($logFile, $logMessage, FILE_APPEND);
                            $importedWalletData['staking_enabled'] = true;
                            $importedWalletData['staking_amount'] = $stakingAmount;
                            $importedWalletData['network_staking_requirement'] = $stakingAmount;
                        }
                    } else {
                        $logMessage = "Staking disabled (amount = 0)\n";
                        file_put_contents($logFile, $logMessage, FILE_APPEND);
                        $importedWalletData['staking_enabled'] = false;
                    }
                
                $logMessage = "✓ Wallet import completed successfully: " . $existingAddress . "\n";
                file_put_contents($logFile, $logMessage, FILE_APPEND);
                
                // For regular nodes: DO NOT create database records
                // But store wallet data for response (without DB insertion)
                $walletData = [
                    'address' => $existingAddress,
                    'public_key' => $importedWalletData['public_key'] ?? null,
                    'private_key' => $existingPrivateKey,
                    'imported' => true,
                    'network_config' => $importedWalletData['network_config'] ?? null,
                    'required_staking_amount' => $stakingAmount,
                    'ownership_verified' => $importedWalletData['ownership_verified'] ?? true,
                    'verification_method' => $importedWalletData['verification_method'] ?? 'private_key_ownership_verification',
                    'balance' => $importedWalletData['balance'] ?? 0,
                    'verified_on_node' => $bestNode,
                    'staking_enabled' => $stakingAmount > 0,
                    'staking_amount' => $stakingAmount,
                    'network_staking_requirement' => $stakingAmount
                ];
                
                $logMessage = "Wallet record will be created automatically when blockchain is synced from network\n";
                file_put_contents($logFile, $logMessage, FILE_APPEND);
                
            } catch (Exception $e) {
                throw new Exception('Failed to import and verify wallet: ' . $e->getMessage());
            }
            
        } else {
            // Primary nodes create new wallets
            $logMessage = "Creating new wallet for primary node\n";
            file_put_contents($logFile, $logMessage, FILE_APPEND);
            
            $walletData = $walletManager->createWallet();
            $logMessage = "Wallet created via WalletManager: " . $walletData['address'] . "\n";
            $logMessage .= "Wallet data fields: " . implode(', ', array_keys($walletData)) . "\n";
            $logMessage .= "Has mnemonic: " . (isset($walletData['mnemonic']) ? 'YES' : 'NO') . "\n";
            if (isset($walletData['mnemonic'])) {
                $logMessage .= "Mnemonic type: " . gettype($walletData['mnemonic']) . "\n";
                if (is_array($walletData['mnemonic'])) {
                    $logMessage .= "Mnemonic words count: " . count($walletData['mnemonic']) . "\n";
                    $logMessage .= "Mnemonic: " . implode(' ', $walletData['mnemonic']) . "\n";
                } else {
                    $logMessage .= "Mnemonic: " . $walletData['mnemonic'] . "\n";
                }
            }
            file_put_contents($logFile, $logMessage, FILE_APPEND);
        }
        
        // Only primary nodes create database records and funding transactions
        if ($isPrimaryNode) {
            // Skip manual balance update - it will be updated automatically when genesis block transactions are processed
            $logMessage = "Wallet balance will be updated automatically when genesis block transactions are processed\n";
            file_put_contents($logFile, $logMessage, FILE_APPEND);
            
            // Skip node_id update - field doesn't exist in current schema
            $logMessage = "Skipping node_id update (field not in schema)\n";
            file_put_contents($logFile, $logMessage, FILE_APPEND);

            // Create validator record in database for primary node
            try {
                // Check if validators table exists
                $stmt = $pdo->query("SHOW TABLES LIKE 'validators'");
                $validatorsTableExists = $stmt->rowCount() > 0;
                
                if ($validatorsTableExists) {
                    // Check if validator already exists
                    $stmt = $pdo->prepare("SELECT id FROM validators WHERE address = ?");
                    $stmt->execute([$walletData['address']]);
                    $existingValidator = $stmt->fetch();
                    
                    if (!$existingValidator) {
                        // Create genesis validator record using current database schema
                        $stmt = $pdo->prepare("
                            INSERT INTO validators (
                                address, public_key, stake, status, 
                                commission_rate, blocks_produced, 
                                blocks_missed, last_active_block, 
                                created_at
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                        ");
                        
                        $result = $stmt->execute([
                            $walletData['address'],
                            $walletData['public_key'],
                            $stakingAmount,
                            'active', // status = active
                            0.1000, // commission_rate = 10%
                            1, // blocks_produced = 1 (genesis)
                            0, // blocks_missed = 0
                            0 // last_active_block = genesis
                        ]);
                        
                        if ($result) {
                            $validatorId = $pdo->lastInsertId();
                            $logMessage = "Created genesis validator record with ID: $validatorId\n";
                            file_put_contents($logFile, $logMessage, FILE_APPEND);
                        } else {
                            $logMessage = "Warning: Failed to create validator record\n";
                            file_put_contents($logFile, $logMessage, FILE_APPEND);
                        }
                    } else {
                        $logMessage = "Validator record already exists for address: " . $walletData['address'] . "\n";
                        file_put_contents($logFile, $logMessage, FILE_APPEND);
                    }
                } else {
                    $logMessage = "Validators table not found, skipping validator record creation\n";
                    file_put_contents($logFile, $logMessage, FILE_APPEND);
                }
            } catch (Exception $e) {
                $logMessage = "Warning: Could not create validator record: " . $e->getMessage() . "\n";
                file_put_contents($logFile, $logMessage, FILE_APPEND);
                // Don't throw exception, just log warning
            }
            
            // Staking records will be created automatically when genesis block transactions are processed
            $logMessage = "Staking records will be created automatically when genesis block transactions are processed\n";
            file_put_contents($logFile, $logMessage, FILE_APPEND);
            
            // Create blockchain transaction for initial wallet funding
            $fundingTransaction = null;
            if ($walletAmount > 0) {
                try {
                    // Get genesis block hash from database
                    $stmt = $pdo->query("SELECT hash FROM blocks WHERE height = 0 LIMIT 1");
                    $genesisBlock = $stmt->fetch();
                    
                    if (!$genesisBlock) {
                        $logMessage = "Warning: Genesis block not found, cannot create funding transaction\n";
                        file_put_contents($logFile, $logMessage, FILE_APPEND);
                    } else {
                        $genesisHash = $genesisBlock['hash'];
                        
                        // Create funding transaction using existing Transaction class
                        $transaction = new \Blockchain\Core\Transaction\Transaction(
                            'genesis_address',
                            $walletData['address'],
                            (float)$walletAmount,
                            0, // No fee for funding transaction
                            0, // Nonce 0 for funding
                            json_encode([
                                'purpose' => 'initial_wallet_funding',
                                'node_type' => 'primary'
                            ])
                        );
                        
                        // Set transaction as system transaction (no signature needed for genesis)
                        $transaction->setSignature('system_funding_signature');
                        $transaction->setStatus('confirmed');
                        
                        // Get transaction data
                        $transactionData = $transaction->toArray();
                        $txHash = $transaction->getHash();
                        
                        // Store transaction in database with genesis block hash
                        $stmt = $pdo->prepare("
                            INSERT INTO transactions (
                                hash, block_hash, block_height, from_address, to_address, 
                                amount, fee, gas_limit, gas_used, gas_price, nonce, 
                                data, signature, status, timestamp
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        
                        $result = $stmt->execute([
                            $txHash,
                            $genesisHash, // Use genesis block hash
                            0, // Genesis block height
                            $transactionData['from_address'],
                            $transactionData['to_address'],
                            $transactionData['amount'],
                            $transactionData['fee'],
                            $transactionData['gas_limit'],
                            $transactionData['gas_used'],
                            $transactionData['gas_price'],
                            $transactionData['nonce'],
                            $transactionData['data'],
                            $transactionData['signature'],
                            $transactionData['status'],
                            $transactionData['timestamp']
                        ]);
                        
                        if ($result) {
                            $fundingTransaction = $transactionData;
                            $logMessage = "Created funding transaction with genesis block: $txHash for amount: $walletAmount\n";
                            file_put_contents($logFile, $logMessage, FILE_APPEND);
                        } else {
                            $logMessage = "Warning: Failed to store funding transaction in database\n";
                            file_put_contents($logFile, $logMessage, FILE_APPEND);
                        }
                    }
                    
                } catch (Exception $e) {
                    $logMessage = "Warning: Could not create funding transaction: " . $e->getMessage() . "\n";
                    file_put_contents($logFile, $logMessage, FILE_APPEND);
                    // Don't throw exception, just log warning
                }
            }
            
            // Return wallet data from WalletManager for primary nodes
            $completeWalletData = array_merge($walletData, [
                'balance' => $walletAmount,
                'staking_amount' => $stakingAmount
            ]);
            
            // Ensure mnemonic is a string for frontend display
            if (isset($completeWalletData['mnemonic']) && is_array($completeWalletData['mnemonic'])) {
                $completeWalletData['mnemonic'] = implode(' ', $completeWalletData['mnemonic']);
            }
            
            $logMessage = "Wallet creation completed successfully\n";
            $logMessage .= "Final wallet data fields: " . implode(', ', array_keys($completeWalletData)) . "\n";
            file_put_contents($logFile, $logMessage, FILE_APPEND);
            
        } else {
            // For regular nodes: Don't create any database records, just return wallet data for response
            $completeWalletData = $walletData;
            
            $logMessage = "Wallet import completed successfully for regular node\n";
            $logMessage .= "No database records created - wallet will be synced from network\n";
            file_put_contents($logFile, $logMessage, FILE_APPEND);
        }
        
        $responseData = [
            'wallet_data' => $completeWalletData,
            'is_primary' => $isPrimaryNode
        ];
        
        // Add different info based on node type
        if ($isPrimaryNode) {
            // Add transaction info if created (primary nodes only)
            if (isset($fundingTransaction) && $fundingTransaction) {
                $responseData['funding_transaction'] = [
                    'hash' => $fundingTransaction['hash'],
                    'amount' => $fundingTransaction['amount'],
                    'from' => $fundingTransaction['from_address'],
                    'to' => $fundingTransaction['to_address'],
                    'timestamp' => $fundingTransaction['timestamp'],
                    'fee' => $fundingTransaction['fee'],
                ];
            }
        } else {
            // Add network connection info for regular nodes
            $responseData['network_status'] = 'Connected to VitaFlow Network';
            $responseData['synced_nodes'] = 'Data synchronized from network peers';
            $responseData['wallet_status'] = 'Wallet verified and ready';
        }
        
        // Log final response data for debugging
        $logMessage = "Response data being returned:\n";
        $logMessage .= "wallet_data fields: " . implode(', ', array_keys($responseData['wallet_data'])) . "\n";
        $logMessage .= "wallet_data values preview: address=" . ($responseData['wallet_data']['address'] ?? 'MISSING') . 
                      ", public_key=" . (substr($responseData['wallet_data']['public_key'] ?? 'MISSING', 0, 20) . '...') . 
                      ", private_key=" . (substr($responseData['wallet_data']['private_key'] ?? 'MISSING', 0, 20) . '...') . 
                      ", mnemonic=" . (substr($responseData['wallet_data']['mnemonic'] ?? 'MISSING', 0, 20) . '...') . "\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        
        // Save updated configuration with network settings (for regular nodes)
        if (!$isPrimaryNode && isset($walletData['network_config']) && !empty($walletData['network_config'])) {
            try {
                $configPath = '../config/install_config.json';
                
                // Update the config with network-derived values
                $networkConfigFields = [
                    'network_name', 'token_symbol', 'token_name', 'initial_supply', 
                    'total_supply', 'decimals', 'chain_id', 'protocol_version', 
                    'block_time', 'reward_rate'
                ];
                
                foreach ($networkConfigFields as $field) {
                    if (isset($walletData['network_config'][$field]) && $walletData['network_config'][$field] !== null) {
                        $config[$field] = $walletData['network_config'][$field];
                    }
                }
                
                // Save the updated staking requirement
                $config['network_min_stake_amount'] = $walletData['network_config']['min_stake_amount'];
                $config['actual_staking_amount'] = $walletData['required_staking_amount'];
                
                // Save updated config
                $configJson = json_encode($config, JSON_PRETTY_PRINT);
                if (file_put_contents($configPath, $configJson)) {
                    $logMessage = "✓ Updated configuration saved with network settings\n";
                    $logMessage .= "  Network name: " . ($config['network_name'] ?? 'unchanged') . "\n";
                    $logMessage .= "  Token symbol: " . ($config['token_symbol'] ?? 'unchanged') . "\n";
                    $logMessage .= "  Token name: " . ($config['token_name'] ?? 'unchanged') . "\n";
                    $logMessage .= "  Initial supply: " . ($config['initial_supply'] ?? 'unchanged') . "\n";
                    $logMessage .= "  Network min stake: " . $config['network_min_stake_amount'] . "\n";
                    $logMessage .= "  Actual staking amount: " . $config['actual_staking_amount'] . "\n";
                    $logMessage .= "  Decimals: " . ($config['decimals'] ?? 'unchanged') . "\n";
                    $logMessage .= "  Chain ID: " . ($config['chain_id'] ?? 'unchanged') . "\n";
                    file_put_contents($logFile, $logMessage, FILE_APPEND);
                } else {
                    $logMessage = "⚠️  Warning: Could not save updated configuration\n";
                    file_put_contents($logFile, $logMessage, FILE_APPEND);
                }
            } catch (Exception $e) {
                $logMessage = "⚠️  Warning: Failed to save updated configuration: " . $e->getMessage() . "\n";
                file_put_contents($logFile, $logMessage, FILE_APPEND);
            }
        }
        
        return [
            'message' => 'Wallet ' . ($isPrimaryNode ? 'created' : 'imported') . ' successfully' . (($isPrimaryNode && isset($fundingTransaction) && $fundingTransaction) ? ' with blockchain transaction' : ''),
            'data' => $responseData
        ];
        
    } catch (Exception $e) {
        $logFile = __DIR__ . '/install_debug.log';
        $logMessage = "WALLET CREATION FAILED: " . $e->getMessage() . "\n";
        $logMessage .= "Stack trace: " . $e->getTraceAsString() . "\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        throw new Exception('Failed to create wallet: ' . $e->getMessage());
    }
}

/**
 * Sync wallet data with genesis node
 */
function syncWalletWithGenesisNode(string $walletAddress, string $genesisNodeUrl, string $logFile): array {
    // Remove trailing slash from URL
    $genesisNodeUrl = rtrim($genesisNodeUrl, '/');
    
    // Construct API endpoint for wallet sync
    $apiUrl = $genesisNodeUrl . '/api/sync/wallet.php';
    
    file_put_contents($logFile, "Attempting to sync wallet $walletAddress with genesis node: $apiUrl\n", FILE_APPEND);
    
    // Prepare sync request
    $requestData = [
        'action' => 'get_wallet_sync_status',
        'address' => $walletAddress
    ];
    
    // Make HTTP request to genesis node
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $apiUrl,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($requestData),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Node-ID: regular_node_' . uniqid()
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false, // For development/testing
        CURLOPT_FOLLOWLOCATION => true
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        throw new Exception("cURL error: " . $curlError);
    }
    
    if ($httpCode !== 200) {
        throw new Exception("HTTP error: " . $httpCode . " Response: " . substr($response, 0, 200));
    }
    
    $responseData = json_decode($response, true);
    if (!$responseData) {
        throw new Exception("Invalid JSON response from genesis node");
    }
    
    if (!isset($responseData['success']) || !$responseData['success']) {
        $error = $responseData['error'] ?? 'Unknown error from genesis node';
        throw new Exception("Genesis node error: " . $error);
    }
    
    $syncData = $responseData['data'] ?? [];
    
    file_put_contents($logFile, "Successfully synced with genesis node. Data: " . json_encode($syncData) . "\n", FILE_APPEND);
    
    return $syncData;
}

/**
 * Sync blockchain data with genesis node for regular nodes
 */
function syncBlockchainWithGenesisNode(array $config = []): array {
    $logFile = __DIR__ . '/install_debug.log';
    
    // Load configuration from file if config is empty
    if (empty($config)) {
        $configFile = '../config/install_config.json';
        if (file_exists($configFile)) {
            $config = json_decode(file_get_contents($configFile), true);
        }
    }
    
    $isPrimaryNode = ($config['node_type'] ?? 'primary') === 'primary';
    
    if ($isPrimaryNode) {
        return [
            'message' => 'Primary node does not need blockchain sync',
            'data' => ['synced' => false, 'reason' => 'primary_node']
        ];
    }
    
    // Use our new sync service
    require_once '../sync-service/SyncManager.php';
    
    try {
        echo json_encode(['type' => 'progress', 'percent' => 5, 'message' => 'Initializing sync service...']) . "\n";
        flush();
        
    $syncManager = new \Blockchain\SyncService\SyncManager(true); // Web mode for progress output (namespaced)
        
        echo json_encode(['type' => 'progress', 'percent' => 10, 'message' => 'Starting intelligent synchronization...']) . "\n";
        flush();
        
        $logMessage = "Starting blockchain sync using new service\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        
        // Run synchronization
        $result = $syncManager->syncAll();
        
        $logMessage = "Sync completed: " . json_encode($result) . "\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        
        return [
            'message' => 'Blockchain synchronization completed successfully',
            'data' => $result
        ];
        
    } catch (Exception $e) {
        $logMessage = "Sync failed: " . $e->getMessage() . "\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        
        // Fallback to minimal sync result for installation to continue
        echo json_encode(['type' => 'progress', 'percent' => 100, 'message' => 'Sync service unavailable, continuing installation...']) . "\n";
        flush();
        
        return [
            'message' => 'Sync service unavailable, but installation can continue',
            'data' => [
                'synced' => false,
                'error' => $e->getMessage(),
                'fallback' => true
            ]
        ];
    }
}

/**
 * Sync genesis block from genesis node
 */
function syncGenesisBlockFromNode(string $genesisNodeUrl, PDO $pdo, string $logFile): array {
    $genesisNodeUrl = rtrim($genesisNodeUrl, '/');
    $apiUrl = $genesisNodeUrl . '/api/explorer/index.php?action=get_block&block_id=0';
    
    file_put_contents($logFile, "Fetching genesis block from: $apiUrl\n", FILE_APPEND);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        throw new Exception("Failed to fetch genesis block: HTTP $httpCode");
    }
    
    $blockData = json_decode($response, true);
    if (!$blockData || !isset($blockData['success']) || !$blockData['success']) {
        throw new Exception("Invalid genesis block data received");
    }
    
    $genesisBlock = $blockData['data'];
    
    // Insert genesis block into local database
    $stmt = $pdo->prepare("
        INSERT INTO blocks (hash, parent_hash, height, timestamp, validator, signature, merkle_root, transactions_count, metadata, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE timestamp = VALUES(timestamp)
    ");
    
    $result = $stmt->execute([
        $genesisBlock['hash'],
        $genesisBlock['parent_hash'] ?? $genesisBlock['previous_hash'] ?? '0',
        0, // Genesis block height
        $genesisBlock['timestamp'],
        $genesisBlock['validator'] ?? 'genesis',
        $genesisBlock['signature'] ?? 'genesis_signature',
        $genesisBlock['merkle_root'] ?? '',
        $genesisBlock['transactions_count'] ?? 0,
        json_encode($genesisBlock['metadata'] ?? $genesisBlock['data'] ?? []),
        date('Y-m-d H:i:s')
    ]);
    
    if ($result) {
        file_put_contents($logFile, "Genesis block synced successfully: " . $genesisBlock['hash'] . "\n", FILE_APPEND);
    }
    
    return $genesisBlock;
}

/**
 * Sync network configuration from genesis node
 */
function syncNetworkConfigFromNode(string $genesisNodeUrl, PDO $pdo, string $logFile): array {
    $genesisNodeUrl = rtrim($genesisNodeUrl, '/');
    $apiUrl = $genesisNodeUrl . '/api/explorer/index.php?action=get_network_stats';
    
    file_put_contents($logFile, "Fetching network config from: $apiUrl\n", FILE_APPEND);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        throw new Exception("Failed to fetch network config: HTTP $httpCode");
    }
    
    $networkData = json_decode($response, true);
    if (!$networkData) {
        throw new Exception("Invalid JSON response received from network stats API");
    }
    
    // Handle both wrapped and direct response formats
    if (isset($networkData['success'])) {
        // Wrapped format: {"success": true, "data": {...}}
        if (!$networkData['success']) {
            throw new Exception("Network API returned error: " . ($networkData['error'] ?? 'Unknown error'));
        }
        $networkStats = $networkData['data'];
    } else {
        // Direct format: {...}
        $networkStats = $networkData;
    }
    
    // Update local config table with network parameters
    $configUpdates = [
        'network_name' => $networkStats['network_name'] ?? 'Blockchain Network',
        'token_symbol' => $networkStats['token_symbol'] ?? 'TOKEN',
        'consensus_algorithm' => $networkStats['consensus_algorithm'] ?? 'pos',
        'total_supply' => $networkStats['total_supply'] ?? 0,
        'block_time' => $networkStats['block_time'] ?? 10,
        'block_reward' => $networkStats['block_reward'] ?? 10
    ];
    
    foreach ($configUpdates as $key => $value) {
        $stmt = $pdo->prepare("
            INSERT INTO config (key_name, value, updated_at) 
            VALUES (?, ?, NOW()) 
            ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW()
        ");
        $stmt->execute([$key, (string)$value]);
    }
    
    file_put_contents($logFile, "Network config synced successfully\n", FILE_APPEND);
    
    return $networkStats;
}

/**
 * Select the best node from a list based on the specified strategy
 */
function selectBestNode(array $nodeUrls, string $strategy, string $logFile): string {
    $logMessage = "=== NODE SELECTION DEBUG ===\n";
    $logMessage .= "Strategy: $strategy\n";
    $logMessage .= "Nodes to check: " . count($nodeUrls) . "\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND);
    
    $nodeStats = [];
    
    // Test each node and collect statistics
    foreach ($nodeUrls as $nodeUrl) {
        $nodeUrl = rtrim($nodeUrl, '/');
        $logMessage = "Testing node: $nodeUrl\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        
        $stats = testNodeConnectivity($nodeUrl, $logFile);
        $nodeStats[$nodeUrl] = $stats;
        
        $logMessage = "Node $nodeUrl results: " . json_encode($stats) . "\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
    }
    
    // Filter out failed nodes
    $validNodes = array_filter($nodeStats, function($stats) {
        return $stats['accessible'] === true;
    });
    
    if (empty($validNodes)) {
        throw new Exception('No accessible nodes found in the network list');
    }
    
    $logMessage = "Found " . count($validNodes) . " accessible nodes out of " . count($nodeUrls) . "\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND);
    
    // Select best node based on strategy
    $bestNode = null;
    
    switch ($strategy) {
        case 'fastest_response':
            $bestNode = selectFastestNode($validNodes, $logFile);
            break;
            
        case 'highest_block':
            $bestNode = selectHighestBlockNode($validNodes, $logFile);
            break;
            
        case 'most_peers':
            $bestNode = selectMostPeersNode($validNodes, $logFile);
            break;
            
        case 'consensus_majority':
        default:
            $bestNode = selectConsensusMajorityNode($validNodes, $logFile);
            break;
    }
    
    if (!$bestNode) {
        throw new Exception('Failed to select best node using strategy: ' . $strategy);
    }
    
    $logMessage = "Selected best node: $bestNode (strategy: $strategy)\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND);
    
    return $bestNode;
}

/**
 * Test node connectivity and gather basic statistics
 */
function testNodeConnectivity(string $nodeUrl, string $logFile): array {
    $stats = [
        'accessible' => false,
        'response_time' => null,
        'block_height' => null,
        'peer_count' => null,
        'network_hash' => null,
        'error' => null
    ];
    
    try {
        $startTime = microtime(true);
        
        // Test basic API endpoint
        $apiUrl = $nodeUrl . '/api/explorer/transactions?limit=1';
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'BlockchainNode/1.0'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $responseTime = microtime(true) - $startTime;
        curl_close($ch);
        
        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            if ($data && is_array($data)) {
                $stats['accessible'] = true;
                $stats['response_time'] = round($responseTime * 1000, 2); // milliseconds
                
                // Try to get additional stats
                $additionalStats = getNodeAdditionalStats($nodeUrl, $logFile);
                $stats = array_merge($stats, $additionalStats);
            }
        } else {
            $stats['error'] = "HTTP $httpCode";
        }
        
    } catch (Exception $e) {
        $stats['error'] = $e->getMessage();
    }
    
    return $stats;
}

/**
 * Get additional node statistics (block height, peers, etc.)
 */
function getNodeAdditionalStats(string $nodeUrl, string $logFile): array {
    $stats = [
        'block_height' => null,
        'peer_count' => null,
        'network_hash' => null
    ];
    
    try {
        // Try to get network stats
        $statsUrl = $nodeUrl . '/api/explorer/index.php?action=get_network_stats';
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $statsUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            if ($data && isset($data['success']) && $data['success']) {
                $networkData = $data['data'] ?? [];
                $stats['block_height'] = $networkData['block_height'] ?? $networkData['latest_block'] ?? null;
                $stats['peer_count'] = $networkData['peer_count'] ?? $networkData['connected_peers'] ?? null;
                $stats['network_hash'] = $networkData['network_hash'] ?? $networkData['chain_hash'] ?? null;
            }
        }
        
    } catch (Exception $e) {
        // Ignore errors in additional stats gathering
        file_put_contents($logFile, "Warning: Failed to get additional stats from $nodeUrl: " . $e->getMessage() . "\n", FILE_APPEND);
    }
    
    return $stats;
}

/**
 * Select node with fastest response time
 */
function selectFastestNode(array $validNodes, string $logFile): string {
    $fastestNode = null;
    $fastestTime = PHP_FLOAT_MAX;
    
    foreach ($validNodes as $nodeUrl => $stats) {
        if ($stats['response_time'] !== null && $stats['response_time'] < $fastestTime) {
            $fastestTime = $stats['response_time'];
            $fastestNode = $nodeUrl;
        }
    }
    
    $logMessage = "Fastest node: $fastestNode ({$fastestTime}ms)\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND);
    
    return $fastestNode;
}

/**
 * Select node with highest block height
 */
function selectHighestBlockNode(array $validNodes, string $logFile): string {
    $highestNode = null;
    $highestBlock = -1;
    
    foreach ($validNodes as $nodeUrl => $stats) {
        $blockHeight = $stats['block_height'] ?? 0;
        if ($blockHeight > $highestBlock) {
            $highestBlock = $blockHeight;
            $highestNode = $nodeUrl;
        }
    }
    
    // Fallback to fastest if no block heights available
    if ($highestNode === null) {
        $logMessage = "No block heights available, falling back to fastest node\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        return selectFastestNode($validNodes, $logFile);
    }
    
    $logMessage = "Highest block node: $highestNode (block: $highestBlock)\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND);
    
    return $highestNode;
}

/**
 * Select node with most connected peers
 */
function selectMostPeersNode(array $validNodes, string $logFile): string {
    $mostPeersNode = null;
    $mostPeers = -1;
    
    foreach ($validNodes as $nodeUrl => $stats) {
        $peerCount = $stats['peer_count'] ?? 0;
        if ($peerCount > $mostPeers) {
            $mostPeers = $peerCount;
            $mostPeersNode = $nodeUrl;
        }
    }
    
    // Fallback to highest block if no peer counts available
    if ($mostPeersNode === null) {
        $logMessage = "No peer counts available, falling back to highest block node\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        return selectHighestBlockNode($validNodes, $logFile);
    }
    
    $logMessage = "Most peers node: $mostPeersNode (peers: $mostPeers)\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND);
    
    return $mostPeersNode;
}

/**
 * Select node based on consensus majority (most common block height and network hash)
 */
function selectConsensusMajorityNode(array $validNodes, string $logFile): string {
    $blockHeights = [];
    $networkHashes = [];
    
    // Collect block heights and network hashes
    foreach ($validNodes as $nodeUrl => $stats) {
        $blockHeight = $stats['block_height'] ?? null;
        $networkHash = $stats['network_hash'] ?? null;
        
        if ($blockHeight !== null) {
            $blockHeights[$blockHeight][] = $nodeUrl;
        }
        if ($networkHash !== null) {
            $networkHashes[$networkHash][] = $nodeUrl;
        }
    }
    
    $logMessage = "Block height distribution: " . json_encode(array_map('count', $blockHeights)) . "\n";
    $logMessage .= "Network hash distribution: " . json_encode(array_map('count', $networkHashes)) . "\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND);
    
    // Find consensus block height (most common)
    $consensusHeight = null;
    $maxBlockCount = 0;
    foreach ($blockHeights as $height => $nodes) {
        if (count($nodes) > $maxBlockCount) {
            $maxBlockCount = count($nodes);
            $consensusHeight = $height;
        }
    }
    
    // Find consensus network hash (most common)
    $consensusHash = null;
    $maxHashCount = 0;
    foreach ($networkHashes as $hash => $nodes) {
        if (count($nodes) > $maxHashCount) {
            $maxHashCount = count($nodes);
            $consensusHash = $hash;
        }
    }
    
    // Find nodes that match both consensus values
    $consensusNodes = [];
    foreach ($validNodes as $nodeUrl => $stats) {
        $matchesHeight = ($consensusHeight === null || $stats['block_height'] === $consensusHeight);
        $matchesHash = ($consensusHash === null || $stats['network_hash'] === $consensusHash);
        
        if ($matchesHeight && $matchesHash) {
            $consensusNodes[$nodeUrl] = $stats;
        }
    }
    
    if (empty($consensusNodes)) {
        $logMessage = "No consensus found, falling back to highest block node\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        return selectHighestBlockNode($validNodes, $logFile);
    }
    
    // From consensus nodes, select the fastest
    $bestNode = selectFastestNode($consensusNodes, $logFile);
    
    $logMessage = "Consensus majority node: $bestNode (height: $consensusHeight, hash: " . substr($consensusHash ?? 'null', 0, 16) . "...)\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND);
    
    return $bestNode;
}

/**
 * Get known nodes from database
 */
function getKnownNodesFromDatabase(PDO $pdo, string $logFile): array {
    $nodes = [];
    
    try {
        // Check if nodes table exists and get its structure
        $stmt = $pdo->query("SHOW TABLES LIKE 'nodes'");
        if ($stmt->rowCount() === 0) {
            $logMessage = "Nodes table not found in database\n";
            file_put_contents($logFile, $logMessage, FILE_APPEND);
            return [];
        }
        
        // Get table structure to determine available columns
        $stmt = $pdo->query("DESCRIBE nodes");
        $columns = $stmt->fetchAll();
        $availableColumns = array_column($columns, 'Field');
        
        $logMessage = "Available columns in nodes table: " . implode(', ', $availableColumns) . "\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        
        // Build query based on available columns
        $selectColumns = [];
        $columnMapping = [
            'domain' => 'domain',
            'host' => 'domain', // fallback
            'url' => 'url',
            'address' => 'url', // fallback
            'protocol' => 'protocol',
            'port' => 'port',
            'status' => 'status',
            'last_seen' => 'last_seen',
            'updated_at' => 'last_seen' // fallback
        ];
        
        foreach ($columnMapping as $preferred => $fallback) {
            if (in_array($preferred, $availableColumns)) {
                $selectColumns[$fallback] = $preferred;
                break;
            } elseif (in_array($fallback, $availableColumns)) {
                $selectColumns[$fallback] = $fallback;
                break;
            }
        }
        
        if (empty($selectColumns)) {
            $logMessage = "No usable columns found in nodes table\n";
            file_put_contents($logFile, $logMessage, FILE_APPEND);
            return [];
        }
        
        // Build SELECT clause
        $selectClause = [];
        foreach ($selectColumns as $alias => $column) {
            $selectClause[] = $alias !== $column ? "$column AS $alias" : $column;
        }
        
        $sql = "SELECT " . implode(', ', $selectClause) . " FROM nodes";
        
        // Add WHERE clause if status column exists
        if (isset($selectColumns['status'])) {
            $sql .= " WHERE status IN ('active', 'online', 'synced')";
        }
        
        // Add ORDER BY clause if last_seen column exists
        if (isset($selectColumns['last_seen'])) {
            $sql .= " ORDER BY " . $selectColumns['last_seen'] . " DESC";
        }
        
        $logMessage = "Executing query: $sql\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        
        $stmt = $pdo->query($sql);
        
        $dbNodes = $stmt->fetchAll();
        
        foreach ($dbNodes as $node) {
            $nodeUrl = ($node['protocol'] ?? 'https') . '://' . $node['domain'];
            if (!empty($node['port']) && $node['port'] != 80 && $node['port'] != 443) {
                $nodeUrl .= ':' . $node['port'];
            }
            
            $nodes[] = $nodeUrl;
        }
        
        $logMessage = "Found " . count($nodes) . " active nodes in database\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        
    } catch (Exception $e) {
        $logMessage = "Warning: Failed to get nodes from database: " . $e->getMessage() . "\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
    }
    
    return $nodes;
}

/**
 * Check wallet balance on a specific node
 */
function checkWalletBalanceOnNode(string $walletAddress, string $nodeUrl, string $logFile): array {
    $nodeUrl = rtrim($nodeUrl, '/');
    
    // Try multiple API endpoints for better compatibility
    $apiEndpoints = [
        $nodeUrl . '/api/explorer/index.php?action=get_wallet_balance&address=' . urlencode($walletAddress),
        $nodeUrl . '/api/explorer/wallet/' . urlencode($walletAddress),
        $nodeUrl . '/api/wallet/' . urlencode($walletAddress) . '/balance',
        $nodeUrl . '/wallet/' . urlencode($walletAddress) . '/balance.json'
    ];
    
    $logMessage = "Checking wallet balance for address: $walletAddress\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND);
    
    foreach ($apiEndpoints as $index => $apiUrl) {
        $logMessage = "Attempt " . ($index + 1) . ": $apiUrl\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        
        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $apiUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT => 'BlockchainNode/1.0'
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            $logMessage = "Response code: $httpCode\n";
            file_put_contents($logFile, $logMessage, FILE_APPEND);
            
            if ($httpCode !== 200) {
                $logMessage = "HTTP error $httpCode, trying next endpoint\n";
                file_put_contents($logFile, $logMessage, FILE_APPEND);
                continue;
            }
            
            if (empty($response)) {
                $logMessage = "Empty response, trying next endpoint\n";
                file_put_contents($logFile, $logMessage, FILE_APPEND);
                continue;
            }
            
            $logMessage = "Response (first 200 chars): " . substr($response, 0, 200) . "\n";
            file_put_contents($logFile, $logMessage, FILE_APPEND);
            
            $data = json_decode($response, true);
            
            if (!$data) {
                $logMessage = "Invalid JSON response, trying next endpoint\n";
                file_put_contents($logFile, $logMessage, FILE_APPEND);
                continue;
            }
            
            // Handle different response formats
            $balance = 0;
            $success = false;
            
            // Format 1: {"success": true, "data": {"balance": 123}}
            if (isset($data['success']) && $data['success'] && isset($data['data']['balance'])) {
                $balance = (float)$data['data']['balance'];
                $success = true;
            }
            // Format 2: {"balance": 123, "status": "ok"}
            elseif (isset($data['balance'])) {
                $balance = (float)$data['balance'];
                $success = true;
            }
            // Format 3: {"address": "0x...", "amount": 123}
            elseif (isset($data['amount'])) {
                $balance = (float)$data['amount'];
                $success = true;
            }
            // Format 4: Direct array with transactions (calculate balance)
            elseif (isset($data['transactions']) && is_array($data['transactions'])) {
                $logMessage = "Calculating balance from transactions\n";
                file_put_contents($logFile, $logMessage, FILE_APPEND);
                
                foreach ($data['transactions'] as $tx) {
                    if (isset($tx['to']) && strtolower($tx['to']) === strtolower($walletAddress)) {
                        $balance += (float)($tx['amount'] ?? 0);
                    }
                    if (isset($tx['from']) && strtolower($tx['from']) === strtolower($walletAddress)) {
                        $balance -= (float)($tx['amount'] ?? 0);
                    }
                }
                $success = true;
            }
            
            if ($success) {
                $logMessage = "✓ Wallet balance retrieved successfully: $balance\n";
                file_put_contents($logFile, $logMessage, FILE_APPEND);
                
                return [
                    'balance' => $balance,
                    'node_url' => $nodeUrl,
                    'api_endpoint' => $apiUrl,
                    'additional_data' => $data
                ];
            } else {
                $logMessage = "Unable to parse balance from response, trying next endpoint\n";
                file_put_contents($logFile, $logMessage, FILE_APPEND);
            }
            
        } catch (Exception $e) {
            $logMessage = "Error with endpoint " . ($index + 1) . ": " . $e->getMessage() . "\n";
            file_put_contents($logFile, $logMessage, FILE_APPEND);
        }
    }
    
    // If all endpoints failed, return 0 balance but don't throw error
    $logMessage = "⚠️  Could not retrieve wallet balance from any endpoint, defaulting to 0\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND);
    
    return [
        'balance' => 0,
        'node_url' => $nodeUrl,
        'api_endpoint' => 'none_available',
        'warning' => 'Could not retrieve balance from any API endpoint'
    ];
}

/**
 * Check wallet node bindings across multiple nodes
 */
function checkWalletNodeBindings(string $walletAddress, array $nodeUrls, string $logFile): array {
    $bindings = [];
    
    $logMessage = "Checking wallet node bindings for address: $walletAddress\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND);
    
    foreach ($nodeUrls as $nodeUrl) {
        $nodeUrl = rtrim($nodeUrl, '/');
        
        try {
            $apiUrl = $nodeUrl . '/api/explorer/index.php?action=get_wallet_node_info&address=' . urlencode($walletAddress);
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $apiUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_FOLLOWLOCATION => true
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200 && $response) {
                $data = json_decode($response, true);
                
                if ($data && isset($data['success']) && $data['success']) {
                    $nodeInfo = $data['data'] ?? [];
                    
                    // Check if wallet is bound to this node
                    if (isset($nodeInfo['bound_to_node']) && $nodeInfo['bound_to_node']) {
                        $bindings[$nodeUrl] = [
                            'bound_since' => $nodeInfo['bound_since'] ?? null,
                            'node_id' => $nodeInfo['node_id'] ?? null,
                            'last_activity' => $nodeInfo['last_activity'] ?? null,
                            'status' => $nodeInfo['status'] ?? 'active'
                        ];
                        
                        $logMessage = "Found binding on node: $nodeUrl\n";
                        file_put_contents($logFile, $logMessage, FILE_APPEND);
                    }
                }
            }
            
        } catch (Exception $e) {
            // Ignore individual node errors when checking bindings
            $logMessage = "Warning: Could not check binding on $nodeUrl: " . $e->getMessage() . "\n";
            file_put_contents($logFile, $logMessage, FILE_APPEND);
        }
    }
    
    $logMessage = "Found " . count($bindings) . " wallet bindings\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND);
    
    return $bindings;
}

/**
 * Get network configuration from existing nodes
 */
function getNetworkConfiguration(array $nodeUrls, string $logFile): array {
    $logMessage = "Getting network configuration from nodes...\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND);
    
    $networkConfig = [
        'min_stake_amount' => 1000, // Default fallback
        'network_name' => null,
        'token_symbol' => null,
        'token_name' => null,
        'consensus_type' => 'pos',
        'total_supply' => null,
        'initial_supply' => null,
        'decimals' => 8,
        'chain_id' => 1,
        'protocol_version' => '1.0.0',
        'block_time' => 10,
        'reward_rate' => 0.05,
        'found_configs' => []
    ];
    
    foreach ($nodeUrls as $nodeUrl) {
        $nodeUrl = rtrim($nodeUrl, '/');
        
        // Try multiple API endpoints for network stats/config
        $configEndpoints = [
            $nodeUrl . '/api/explorer/index.php?action=get_network_config',
            $nodeUrl . '/api/explorer/index.php?action=get_network_stats',
            $nodeUrl . '/api/explorer/stats',
            $nodeUrl . '/api/stats',
            $nodeUrl . '/config/network.json'
        ];
        
        $logMessage = "Checking node: $nodeUrl\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        
        foreach ($configEndpoints as $apiUrl) {
            try {
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $apiUrl,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_CONNECTTIMEOUT => 3,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_USERAGENT => 'BlockchainNode/1.0'
                ]);
                
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                if ($httpCode === 200 && !empty($response)) {
                    $data = json_decode($response, true);
                    
                    if ($data) {
                        $logMessage = "✓ Got config from: $apiUrl\n";
                        file_put_contents($logFile, $logMessage, FILE_APPEND);
                        
                        // Parse network configuration
                        $nodeConfig = [];
                        
                        // Handle different response formats
                        if (isset($data['data'])) {
                            $stats = $data['data'];
                        } else {
                            $stats = $data;
                        }
                        
                        // Extract network information from different response formats
                        if (isset($stats['network_name'])) {
                            $nodeConfig['network_name'] = $stats['network_name'];
                        } elseif (isset($stats['network'])) {
                            $nodeConfig['network_name'] = $stats['network'];
                        }
                        
                        if (isset($stats['token_symbol'])) {
                            $nodeConfig['token_symbol'] = $stats['token_symbol'];
                        }
                        
                        if (isset($stats['token_name'])) {
                            $nodeConfig['token_name'] = $stats['token_name'];
                        }
                        
                        if (isset($stats['consensus_type'])) {
                            $nodeConfig['consensus_type'] = $stats['consensus_type'];
                        } elseif (isset($stats['consensus'])) {
                            $nodeConfig['consensus_type'] = $stats['consensus'];
                        }
                        
                        if (isset($stats['total_supply'])) {
                            $nodeConfig['total_supply'] = (int)$stats['total_supply'];
                        } elseif (isset($stats['initial_supply'])) {
                            $nodeConfig['total_supply'] = (int)$stats['initial_supply'];
                        }
                        
                        if (isset($stats['initial_supply'])) {
                            $nodeConfig['initial_supply'] = (int)$stats['initial_supply'];
                        }
                        
                        if (isset($stats['circulating_supply'])) {
                            $nodeConfig['circulating_supply'] = (int)$stats['circulating_supply'];
                        }
                        
                        if (isset($stats['decimals'])) {
                            $nodeConfig['decimals'] = (int)$stats['decimals'];
                        }
                        
                        if (isset($stats['chain_id'])) {
                            $nodeConfig['chain_id'] = (int)$stats['chain_id'];
                        }
                        
                        if (isset($stats['protocol_version'])) {
                            $nodeConfig['protocol_version'] = $stats['protocol_version'];
                        }
                        
                        if (isset($stats['block_time'])) {
                            $nodeConfig['block_time'] = (int)$stats['block_time'];
                        }
                        
                        if (isset($stats['reward_rate'])) {
                            $nodeConfig['reward_rate'] = (float)$stats['reward_rate'];
                        }
                        
                        // Try to get staking configuration
                        if (isset($stats['min_stake_amount'])) {
                            $nodeConfig['min_stake_amount'] = (int)$stats['min_stake_amount'];
                        } elseif (isset($stats['staking']) && isset($stats['staking']['min_amount'])) {
                            $nodeConfig['min_stake_amount'] = (int)$stats['staking']['min_amount'];
                        }
                        
                        $networkConfig['found_configs'][] = [
                            'node_url' => $nodeUrl,
                            'endpoint' => $apiUrl,
                            'config' => $nodeConfig
                        ];
                        
                        // Update network config with found values
                        $configFields = [
                            'network_name', 'token_symbol', 'token_name', 'consensus_type', 
                            'total_supply', 'initial_supply', 'decimals', 'chain_id', 
                            'protocol_version', 'block_time', 'reward_rate', 'min_stake_amount'
                        ];
                        
                        foreach ($configFields as $key) {
                            if (isset($nodeConfig[$key]) && !empty($nodeConfig[$key])) {
                                $networkConfig[$key] = $nodeConfig[$key];
                            }
                        }
                        
                        $logMessage = "  Network: " . ($nodeConfig['network_name'] ?? 'unknown') . "\n";
                        $logMessage .= "  Token: " . ($nodeConfig['token_symbol'] ?? 'unknown') . "\n";
                        $logMessage .= "  Token name: " . ($nodeConfig['token_name'] ?? 'unknown') . "\n";
                        $logMessage .= "  Initial supply: " . ($nodeConfig['initial_supply'] ?? 'unknown') . "\n";
                        $logMessage .= "  Min stake: " . ($nodeConfig['min_stake_amount'] ?? 'unknown') . "\n";
                        $logMessage .= "  Decimals: " . ($nodeConfig['decimals'] ?? 'unknown') . "\n";
                        $logMessage .= "  Chain ID: " . ($nodeConfig['chain_id'] ?? 'unknown') . "\n";
                        file_put_contents($logFile, $logMessage, FILE_APPEND);
                        
                        break; // Found working endpoint for this node
                    }
                }
            } catch (Exception $e) {
                $logMessage = "  Failed endpoint $apiUrl: " . $e->getMessage() . "\n";
                file_put_contents($logFile, $logMessage, FILE_APPEND);
            }
        }
    }
    
        // Determine consensus values from all found configs
        if (!empty($networkConfig['found_configs'])) {
            $minStakeAmounts = [];
            $networkNames = [];
            $tokenSymbols = [];
            $tokenNames = [];
            $initialSupplies = [];
            
            foreach ($networkConfig['found_configs'] as $foundConfig) {
                $config = $foundConfig['config'];
                
                if (isset($config['min_stake_amount'])) {
                    $minStakeAmounts[] = (int)$config['min_stake_amount'];
                }
                
                if (isset($config['network_name'])) {
                    $networkNames[] = $config['network_name'];
                }
                
                if (isset($config['token_symbol'])) {
                    $tokenSymbols[] = $config['token_symbol'];
                }
                
                if (isset($config['token_name'])) {
                    $tokenNames[] = $config['token_name'];
                }
                
                if (isset($config['initial_supply'])) {
                    $initialSupplies[] = (int)$config['initial_supply'];
                }
            }
            
            // Use most common values or maximum for min_stake_amount
            if (!empty($minStakeAmounts)) {
                $networkConfig['min_stake_amount'] = max($minStakeAmounts); // Use highest requirement for security
            }
            
            if (!empty($networkNames)) {
                $nameCount = array_count_values($networkNames);
                $networkConfig['network_name'] = array_keys($nameCount, max($nameCount))[0];
            }
            
            if (!empty($tokenSymbols)) {
                $symbolCount = array_count_values($tokenSymbols);
                $networkConfig['token_symbol'] = array_keys($symbolCount, max($symbolCount))[0];
            }
            
            if (!empty($tokenNames)) {
                $nameCount = array_count_values($tokenNames);
                $networkConfig['token_name'] = array_keys($nameCount, max($nameCount))[0];
            }
            
            if (!empty($initialSupplies)) {
                $networkConfig['initial_supply'] = max($initialSupplies);
                $networkConfig['total_supply'] = $networkConfig['initial_supply']; // Set same value
            }
            
            $logMessage = "✓ Network configuration determined:\n";
            $logMessage .= "  Network: " . ($networkConfig['network_name'] ?? 'unknown') . "\n";
            $logMessage .= "  Token symbol: " . ($networkConfig['token_symbol'] ?? 'unknown') . "\n";
            $logMessage .= "  Token name: " . ($networkConfig['token_name'] ?? 'unknown') . "\n";
            $logMessage .= "  Initial supply: " . ($networkConfig['initial_supply'] ?? 'unknown') . "\n";
            $logMessage .= "  Min stake amount: " . $networkConfig['min_stake_amount'] . "\n";
            $logMessage .= "  Decimals: " . ($networkConfig['decimals'] ?? 8) . "\n";
            $logMessage .= "  Chain ID: " . ($networkConfig['chain_id'] ?? 1) . "\n";
            $logMessage .= "  Configs found from " . count($networkConfig['found_configs']) . " nodes\n";
            file_put_contents($logFile, $logMessage, FILE_APPEND);
        } else {
            $logMessage = "⚠️  No network configuration found, using defaults\n";
            file_put_contents($logFile, $logMessage, FILE_APPEND);
        }    return $networkConfig;
}

/**
 * Verify wallet ownership using private key cryptographic verification
 */
function verifyWalletOwnership(string $walletAddress, string $privateKeyOrMnemonic, string $nodeUrl, string $logFile): array {
    $nodeUrl = rtrim($nodeUrl, '/');
    $message = "verify_wallet_ownership_" . time();
    
    $logMessage = "Verifying wallet ownership for address: $walletAddress\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND);
    
    try {
        // Import autoloader for cryptographic classes
        require_once '../vendor/autoload.php';
        
        // Determine if input is mnemonic phrase or private key and get the private key
        $words = explode(' ', trim($privateKeyOrMnemonic));
        
        if (count($words) >= 12 && count($words) <= 24) {
            // It's a mnemonic phrase - convert to array format and get private key
            $logMessage = "Processing mnemonic phrase with " . count($words) . " words\n";
            file_put_contents($logFile, $logMessage, FILE_APPEND);
            
            $mnemonic = array_map('trim', $words);
            $keyPair = \Blockchain\Core\Cryptography\KeyPair::fromMnemonic($mnemonic);
            $privateKeyHex = $keyPair->getPrivateKey();
        } else {
            // Treat as private key (hex string)
            $logMessage = "Processing as private key\n";
            file_put_contents($logFile, $logMessage, FILE_APPEND);
            
            $privateKeyHex = $privateKeyOrMnemonic;
            
            // Clean up private key (remove 0x prefix if present)
            if (strpos($privateKeyHex, '0x') === 0) {
                $privateKeyHex = substr($privateKeyHex, 2);
            }
        }
        
        // Use the private key directly for API verification (API expects 64 hex chars for method 2)
        $logMessage = "Using private key for ownership verification (length: " . strlen($privateKeyHex) . ")\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        
        // Use our wallet verification API endpoint with private key
        $apiUrl = $nodeUrl . '/api/explorer/index.php?action=verify_wallet_ownership&address=' . 
                  urlencode($walletAddress) . '&signature=' . urlencode($privateKeyHex) . 
                  '&message=' . urlencode($message);
        
        $logMessage = "API URL: $apiUrl\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        
    } catch (Exception $e) {
        $logMessage = "Error processing private key/mnemonic: " . $e->getMessage() . "\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        throw new Exception("Failed to process wallet credentials: " . $e->getMessage());
    }
    
    try {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'BlockchainNode/1.0'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $logMessage = "Response code: $httpCode\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        
        if ($httpCode !== 200) {
            throw new Exception("HTTP error $httpCode from verification API");
        }
        
        if (empty($response)) {
            throw new Exception("Empty response from verification API");
        }
        
        $logMessage = "Response: " . substr($response, 0, 500) . "\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        
        $data = json_decode($response, true);
        
        if (!$data) {
            throw new Exception("Invalid JSON response from verification API");
        }
        
        // Check if verification was successful
        if (!isset($data['success']) || !$data['success']) {
            throw new Exception("Verification API returned error: " . ($data['error'] ?? 'Unknown error'));
        }
        
        $verificationData = $data['data'] ?? [];
        
        if (!isset($verificationData['verified']) || !$verificationData['verified']) {
            $error = $verificationData['error'] ?? 'Wallet ownership verification failed';
            throw new Exception("Wallet ownership verification failed: $error");
        }
        
        $logMessage = "✓ Wallet ownership verified successfully\n";
        $logMessage .= "  Verification method: " . ($verificationData['verification_method'] ?? 'unknown') . "\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        
        return [
            'verified' => true,
            'verification_method' => $verificationData['verification_method'] ?? 'private_key_ownership_verification',
            'verified_address' => $verificationData['verified_address'] ?? $walletAddress,
            'node_url' => $nodeUrl
        ];
        
    } catch (Exception $e) {
        $logMessage = "Wallet ownership verification failed: " . $e->getMessage() . "\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        
        return [
            'verified' => false,
            'error' => $e->getMessage(),
            'node_url' => $nodeUrl
        ];
    }
}

/**
 * Sync nodes list from network node
 */
function syncNodesFromNode(string $nodeUrl, PDO $pdo, string $logFile): array {
    $nodeUrl = rtrim($nodeUrl, '/');
    $apiUrl = $nodeUrl . '/api/explorer/index.php?action=get_nodes_list';
    
    file_put_contents($logFile, "Fetching nodes list from: $apiUrl\n", FILE_APPEND);
    
    try {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            file_put_contents($logFile, "⚠️  Failed to fetch nodes list: HTTP $httpCode\n", FILE_APPEND);
            return ['synced' => false, 'reason' => 'http_error', 'code' => $httpCode];
        }
        
        $nodesData = json_decode($response, true);
        if (!$nodesData || !isset($nodesData['success']) || !$nodesData['success']) {
            file_put_contents($logFile, "⚠️  Invalid nodes data received\n", FILE_APPEND);
            return ['synced' => false, 'reason' => 'invalid_data'];
        }
        
        $nodes = $nodesData['data'] ?? [];
        if (empty($nodes)) {
            file_put_contents($logFile, "ℹ️  No nodes to sync\n", FILE_APPEND);
            return ['synced' => true, 'nodes_count' => 0];
        }
        
        // Insert/update nodes in local database
        $syncedCount = 0;
        $stmt = $pdo->prepare("
            INSERT INTO nodes (node_id, ip_address, port, public_key, version, status, last_seen, blocks_synced, ping_time, reputation_score, metadata) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
            status = VALUES(status), 
            last_seen = VALUES(last_seen), 
            blocks_synced = VALUES(blocks_synced),
            ping_time = VALUES(ping_time),
            reputation_score = VALUES(reputation_score),
            metadata = VALUES(metadata)
        ");
        
        foreach ($nodes as $node) {
            try {
                $stmt->execute([
                    $node['node_id'] ?? '',
                    $node['ip_address'] ?? '',
                    $node['port'] ?? 8080,
                    $node['public_key'] ?? '',
                    $node['version'] ?? '1.0.0',
                    $node['status'] ?? 'active',
                    $node['last_seen'] ?? date('Y-m-d H:i:s'),
                    $node['blocks_synced'] ?? 0,
                    $node['ping_time'] ?? 0,
                    $node['reputation_score'] ?? 100,
                    json_encode($node['metadata'] ?? [])
                ]);
                $syncedCount++;
            } catch (Exception $e) {
                file_put_contents($logFile, "⚠️  Failed to sync node {$node['node_id']}: " . $e->getMessage() . "\n", FILE_APPEND);
            }
        }
        
        file_put_contents($logFile, "✓ Synced $syncedCount nodes from network\n", FILE_APPEND);
        return ['synced' => true, 'nodes_count' => $syncedCount];
        
    } catch (Exception $e) {
        file_put_contents($logFile, "❌ Nodes sync failed: " . $e->getMessage() . "\n", FILE_APPEND);
        return ['synced' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Sync validators from network node
 */
function syncValidatorsFromNode(string $nodeUrl, PDO $pdo, string $logFile): array {
    $nodeUrl = rtrim($nodeUrl, '/');
    $apiUrl = $nodeUrl . '/api/explorer/index.php?action=get_validators_list';
    
    file_put_contents($logFile, "Fetching validators list from: $apiUrl\n", FILE_APPEND);
    
    try {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            file_put_contents($logFile, "⚠️  Failed to fetch validators list: HTTP $httpCode\n", FILE_APPEND);
            return ['synced' => false, 'reason' => 'http_error', 'code' => $httpCode];
        }
        
        $validatorsData = json_decode($response, true);
        if (!$validatorsData || !isset($validatorsData['success']) || !$validatorsData['success']) {
            file_put_contents($logFile, "⚠️  Invalid validators data received\n", FILE_APPEND);
            return ['synced' => false, 'reason' => 'invalid_data'];
        }
        
        $validators = $validatorsData['data'] ?? [];
        if (empty($validators)) {
            file_put_contents($logFile, "ℹ️  No validators to sync\n", FILE_APPEND);
            return ['synced' => true, 'validators_count' => 0];
        }
        
        // Insert/update validators in local database
        $syncedCount = 0;
        $stmt = $pdo->prepare("
            INSERT INTO validators (address, public_key, stake, delegated_stake, commission_rate, status, blocks_produced, blocks_missed, last_active_block, metadata) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
            stake = VALUES(stake), 
            delegated_stake = VALUES(delegated_stake), 
            commission_rate = VALUES(commission_rate),
            status = VALUES(status),
            blocks_produced = VALUES(blocks_produced),
            blocks_missed = VALUES(blocks_missed),
            last_active_block = VALUES(last_active_block),
            metadata = VALUES(metadata)
        ");
        
        foreach ($validators as $validator) {
            try {
                $stmt->execute([
                    $validator['address'] ?? '',
                    $validator['public_key'] ?? '',
                    $validator['stake'] ?? 0,
                    $validator['delegated_stake'] ?? 0,
                    $validator['commission_rate'] ?? 0.1,
                    $validator['status'] ?? 'inactive',
                    $validator['blocks_produced'] ?? 0,
                    $validator['blocks_missed'] ?? 0,
                    $validator['last_active_block'] ?? 0,
                    json_encode($validator['metadata'] ?? [])
                ]);
                $syncedCount++;
            } catch (Exception $e) {
                file_put_contents($logFile, "⚠️  Failed to sync validator {$validator['address']}: " . $e->getMessage() . "\n", FILE_APPEND);
            }
        }
        
        file_put_contents($logFile, "✓ Synced $syncedCount validators from network\n", FILE_APPEND);
        return ['synced' => true, 'validators_count' => $syncedCount];
        
    } catch (Exception $e) {
        file_put_contents($logFile, "❌ Validators sync failed: " . $e->getMessage() . "\n", FILE_APPEND);
        return ['synced' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Sync additional blocks beyond genesis from network node
 */
function syncAdditionalBlocksFromNode(string $nodeUrl, PDO $pdo, string $logFile): array {
    $nodeUrl = rtrim($nodeUrl, '/');
    
    file_put_contents($logFile, "Starting additional blocks sync from: $nodeUrl\n", FILE_APPEND);
    
    try {
        // Check current local height
        $stmt = $pdo->query("SELECT MAX(height) as max_height FROM blocks");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $localHeight = (int)($result['max_height'] ?? -1);
        
        file_put_contents($logFile, "Local blockchain height: $localHeight\n", FILE_APPEND);
        
        // Fetch blocks in batches
        $syncedBlocks = 0;
        $page = 0;
        $limit = 100;
        $hasMore = true;
        
        while ($hasMore && $syncedBlocks < 1000) { // Limit to 1000 blocks for initial sync
            $apiUrl = $nodeUrl . '/api/explorer/index.php?action=get_all_blocks&page=' . $page . '&limit=' . $limit;
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $apiUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_FOLLOWLOCATION => true
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode !== 200) {
                file_put_contents($logFile, "⚠️  Failed to fetch blocks page $page: HTTP $httpCode\n", FILE_APPEND);
                break;
            }
            
            $blocksData = json_decode($response, true);
            if (!$blocksData || !isset($blocksData['success']) || !$blocksData['success']) {
                file_put_contents($logFile, "⚠️  Invalid blocks data received for page $page\n", FILE_APPEND);
                break;
            }
            
            $blocks = $blocksData['data'] ?? [];
            $pagination = $blocksData['pagination'] ?? [];
            $hasMore = $pagination['has_more'] ?? false;
            
            if (empty($blocks)) {
                break;
            }
            
            // Insert blocks that are newer than our local height
            $stmt = $pdo->prepare("
                INSERT IGNORE INTO blocks (hash, parent_hash, height, timestamp, validator, signature, merkle_root, transactions_count, metadata) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            foreach ($blocks as $block) {
                $blockHeight = (int)($block['height'] ?? 0);
                
                // Only sync blocks newer than our current height
                if ($blockHeight > $localHeight) {
                    try {
                        $stmt->execute([
                            $block['hash'] ?? '',
                            $block['parent_hash'] ?? '',
                            $blockHeight,
                            $block['timestamp'] ?? time(),
                            $block['validator'] ?? '',
                            $block['signature'] ?? '',
                            $block['merkle_root'] ?? '',
                            $block['transactions_count'] ?? 0,
                            $block['metadata'] ?? json_encode([])
                        ]);
                        $syncedBlocks++;
                    } catch (Exception $e) {
                        file_put_contents($logFile, "⚠️  Failed to sync block {$block['hash']}: " . $e->getMessage() . "\n", FILE_APPEND);
                    }
                }
            }
            
            $page++;
            file_put_contents($logFile, "Processed page $page, synced $syncedBlocks blocks so far\n", FILE_APPEND);
        }
        
        file_put_contents($logFile, "✓ Additional blocks sync completed: $syncedBlocks new blocks\n", FILE_APPEND);
        return ['synced' => true, 'blocks_count' => $syncedBlocks];
        
    } catch (Exception $e) {
        file_put_contents($logFile, "❌ Additional blocks sync failed: " . $e->getMessage() . "\n", FILE_APPEND);
        return ['synced' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Sync smart contracts from network node
 */
function syncSmartContractsFromNode(string $nodeUrl, PDO $pdo, string $logFile): array {
    $nodeUrl = rtrim($nodeUrl, '/');
    
    file_put_contents($logFile, "Starting smart contracts sync from: $nodeUrl\n", FILE_APPEND);
    
    try {
        // Sync smart contracts in batches
        $syncedContracts = 0;
        $page = 0;
        $limit = 100;
        $hasMore = true;
        
        while ($hasMore && $syncedContracts < 1000) { // Limit to 1000 contracts for initial sync
            $apiUrl = $nodeUrl . '/api/explorer/index.php?action=get_smart_contracts&page=' . $page . '&limit=' . $limit;
            
            file_put_contents($logFile, "Fetching smart contracts page $page from: $apiUrl\n", FILE_APPEND);
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $apiUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_FOLLOWLOCATION => true
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode !== 200) {
                file_put_contents($logFile, "⚠️  Failed to fetch smart contracts page $page: HTTP $httpCode\n", FILE_APPEND);
                break;
            }
            
            $contractsData = json_decode($response, true);
            if (!$contractsData || !isset($contractsData['success']) || !$contractsData['success']) {
                file_put_contents($logFile, "⚠️  Invalid smart contracts data received for page $page\n", FILE_APPEND);
                break;
            }
            
            $contracts = $contractsData['data'] ?? [];
            $pagination = $contractsData['pagination'] ?? [];
            $hasMore = $pagination['has_more'] ?? false;
            
            if (empty($contracts)) {
                break;
            }
            
            // Check if smart_contracts table exists
            $stmt = $pdo->query("SHOW TABLES LIKE 'smart_contracts'");
            if ($stmt->rowCount() === 0) {
                file_put_contents($logFile, "ℹ️  Smart contracts table not found, skipping sync\n", FILE_APPEND);
                return ['synced' => true, 'contracts_count' => 0, 'message' => 'Table not found'];
            }
            
            // Insert smart contracts
            $stmt = $pdo->prepare("
                INSERT IGNORE INTO smart_contracts (address, creator, name, version, bytecode, abi, source_code, deployment_tx, deployment_block, gas_used, status, storage, metadata) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            foreach ($contracts as $contract) {
                try {
                    $stmt->execute([
                        $contract['address'] ?? '',
                        $contract['creator'] ?? '',
                        $contract['name'] ?? '',
                        $contract['version'] ?? '',
                        $contract['bytecode'] ?? '',
                        json_encode($contract['abi'] ?? []),
                        $contract['source_code'] ?? '',
                        $contract['deployment_tx'] ?? '',
                        $contract['deployment_block'] ?? 0,
                        $contract['gas_used'] ?? 0,
                        $contract['status'] ?? 'active',
                        json_encode($contract['storage'] ?? []),
                        json_encode($contract['metadata'] ?? [])
                    ]);
                    $syncedContracts++;
                } catch (Exception $e) {
                    file_put_contents($logFile, "⚠️  Failed to sync contract {$contract['address']}: " . $e->getMessage() . "\n", FILE_APPEND);
                }
            }
            
            $page++;
            file_put_contents($logFile, "Processed smart contracts page $page, synced $syncedContracts contracts so far\n", FILE_APPEND);
        }
        
        if ($syncedContracts === 0) {
            file_put_contents($logFile, "ℹ️  No smart contracts to sync\n", FILE_APPEND);
        } else {
            file_put_contents($logFile, "✓ Smart contracts sync completed: $syncedContracts contracts\n", FILE_APPEND);
        }
        
        return ['synced' => true, 'contracts_count' => $syncedContracts];
        
    } catch (Exception $e) {
        file_put_contents($logFile, "❌ Smart contracts sync failed: " . $e->getMessage() . "\n", FILE_APPEND);
        return ['synced' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Sync staking records from network node
 */
function syncStakingFromNode(string $nodeUrl, PDO $pdo, string $logFile): array {
    $nodeUrl = rtrim($nodeUrl, '/');
    
    file_put_contents($logFile, "Starting staking records sync from: $nodeUrl\n", FILE_APPEND);
    
    try {
        // Sync staking records in batches
        $syncedRecords = 0;
        $page = 0;
        $limit = 100;
        $hasMore = true;
        
        while ($hasMore && $syncedRecords < 1000) { // Limit to 1000 records for initial sync
            $apiUrl = $nodeUrl . '/api/explorer/index.php?action=get_staking_records&page=' . $page . '&limit=' . $limit;
            
            file_put_contents($logFile, "Fetching staking records page $page from: $apiUrl\n", FILE_APPEND);
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $apiUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_FOLLOWLOCATION => true
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode !== 200) {
                file_put_contents($logFile, "⚠️  Failed to fetch staking records page $page: HTTP $httpCode\n", FILE_APPEND);
                break;
            }
            
            $stakingData = json_decode($response, true);
            if (!$stakingData || !isset($stakingData['success']) || !$stakingData['success']) {
                file_put_contents($logFile, "⚠️  Invalid staking data received for page $page\n", FILE_APPEND);
                break;
            }
            
            $stakingRecords = $stakingData['data'] ?? [];
            $pagination = $stakingData['pagination'] ?? [];
            $hasMore = $pagination['has_more'] ?? false;
            
            if (empty($stakingRecords)) {
                break;
            }
            
            // Check if staking table exists
            $stmt = $pdo->query("SHOW TABLES LIKE 'staking'");
            if ($stmt->rowCount() === 0) {
                file_put_contents($logFile, "ℹ️  Staking table not found, skipping sync\n", FILE_APPEND);
                return ['synced' => true, 'records_count' => 0, 'message' => 'Table not found'];
            }
            
            // Insert staking records
            $stmt = $pdo->prepare("
                INSERT IGNORE INTO staking (validator, staker, amount, reward_rate, start_block, end_block, status, rewards_earned, last_reward_block) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            foreach ($stakingRecords as $record) {
                try {
                    $stmt->execute([
                        $record['validator'] ?? '',
                        $record['staker'] ?? '',
                        $record['amount'] ?? 0,
                        $record['reward_rate'] ?? 0.0,
                        $record['start_block'] ?? 0,
                        $record['end_block'] ?? null,
                        $record['status'] ?? 'active',
                        $record['rewards_earned'] ?? 0,
                        $record['last_reward_block'] ?? null
                    ]);
                    $syncedRecords++;
                } catch (Exception $e) {
                    file_put_contents($logFile, "⚠️  Failed to sync staking record {$record['validator']}-{$record['staker']}: " . $e->getMessage() . "\n", FILE_APPEND);
                }
            }
            
            $page++;
            file_put_contents($logFile, "Processed staking records page $page, synced $syncedRecords records so far\n", FILE_APPEND);
        }
        
        if ($syncedRecords === 0) {
            file_put_contents($logFile, "ℹ️  No staking records to sync\n", FILE_APPEND);
        } else {
            file_put_contents($logFile, "✓ Staking records sync completed: $syncedRecords records\n", FILE_APPEND);
        }
        
        return ['synced' => true, 'records_count' => $syncedRecords];
        
    } catch (Exception $e) {
        file_put_contents($logFile, "❌ Staking records sync failed: " . $e->getMessage() . "\n", FILE_APPEND);
        return ['synced' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Notify network about new node registration
 * Simple function to send notification to all network nodes
 */
function notifyNetworkAboutNewNode(array $config): array
{
    $logFile = __DIR__ . '/install_debug.log';
    $logMessage = "\n=== NETWORK NODE NOTIFICATION " . date('Y-m-d H:i:s') . " ===\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND);
    
    try {
        // Check if this is a regular node
        $nodeType = $config['node_type'] ?? 'regular';
        if ($nodeType !== 'regular') {
            $logMessage = "✓ Skipping notification for $nodeType node\n";
            file_put_contents($logFile, $logMessage, FILE_APPEND);
            return ['status' => 'skipped', 'message' => 'Not a regular node'];
        }
        
        // Get network nodes
        $networkNodes = array_filter(array_map('trim', explode("\n", $config['network_nodes'] ?? '')));
        if (empty($networkNodes)) {
            throw new Exception('No network nodes configured');
        }
        
        $logMessage = "✓ Found " . count($networkNodes) . " network nodes to notify\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        
        // Get public key from wallet configuration
        $publicKey = '';
        if (isset($config['existing_wallet_private_key'])) {
            try {
                require_once '../vendor/autoload.php';
                
                // Determine if input is mnemonic phrase or private key
                $privateKeyInput = $config['existing_wallet_private_key'];
                $words = explode(' ', trim($privateKeyInput));
                
                if (count($words) >= 12 && count($words) <= 24) {
                    // It's a mnemonic phrase
                    $mnemonic = array_map('trim', $words);
                    $keyPair = \Blockchain\Core\Cryptography\KeyPair::fromMnemonic($mnemonic);
                } else {
                    // Treat as private key (hex string)
                    $privateKey = $privateKeyInput;
                    
                    // Clean up private key (remove 0x prefix if present)
                    if (strpos($privateKey, '0x') === 0) {
                        $privateKey = substr($privateKey, 2);
                    }
                    
                    $keyPair = \Blockchain\Core\Cryptography\KeyPair::fromPrivateKey($privateKey);
                }
                
                $publicKey = $keyPair->getPublicKey();
            } catch (Exception $e) {
                $logMessage = "⚠️  Could not derive public key: " . $e->getMessage() . "\n";
                file_put_contents($logFile, $logMessage, FILE_APPEND);
            }
        }
        
        // Determine port based on protocol if not explicitly set
        $protocol = $config['protocol'] ?? 'http';
        $defaultPort = ($protocol === 'https') ? 443 : 80;
        $port = (int)($config['port'] ?? $defaultPort);
        
        // Prepare registration data using correct field names from API
        $nodeId = hash('sha256', ($config['node_domain'] ?? 'localhost') . ':' . $protocol);
        $registrationData = [
            'node_id' => $nodeId,
            'domain' => $config['node_domain'] ?? 'localhost',
            'protocol' => $protocol,
            'port' => $port,
            'public_key' => $publicKey,
            'version' => '1.0.0',
            'node_type' => 'regular'
        ];
        
        $logMessage = "✓ Registration data prepared:\n";
        $logMessage .= "  Node ID: " . $registrationData['node_id'] . "\n";
        $logMessage .= "  Domain: " . $registrationData['domain'] . "\n";
        $logMessage .= "  Protocol: " . $registrationData['protocol'] . "\n";
        $logMessage .= "  Port: " . $registrationData['port'] . "\n";
        $logMessage .= "  Public Key: " . (empty($registrationData['public_key']) ? 'NOT_SET' : substr($registrationData['public_key'], 0, 20) . '...') . "\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        
        // Send multi-curl requests to all nodes
        $results = [];
        $multiHandle = curl_multi_init();
        $curlHandles = [];
        
        // Prepare curl handles
        foreach ($networkNodes as $index => $nodeUrl) {
            $nodeUrl = rtrim($nodeUrl, '/');
            $apiUrl = $nodeUrl . '/api/nodes/register';
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $apiUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($registrationData),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'User-Agent: Blockchain-Node-Installer/1.0'
                ],
                CURLOPT_TIMEOUT => 30,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false
            ]);
            
            $curlHandles[$index] = [
                'handle' => $ch,
                'url' => $apiUrl,
                'node_url' => $nodeUrl
            ];
            
            curl_multi_add_handle($multiHandle, $ch);
            $logMessage = "  → Prepared request to: $apiUrl\n";
            file_put_contents($logFile, $logMessage, FILE_APPEND);
        }
        
        // Execute all requests
        $running = null;
        do {
            curl_multi_exec($multiHandle, $running);
            curl_multi_select($multiHandle);
        } while ($running > 0);
        
        // Collect results
        $successCount = 0;
        foreach ($curlHandles as $index => $handleData) {
            $ch = $handleData['handle'];
            $response = curl_multi_getcontent($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            
            $success = false;
            $message = 'Unknown error';
            
            if ($error) {
                $message = "cURL error: $error";
            } elseif ($httpCode === 200) {
                $responseData = json_decode($response, true);
                if ($responseData && isset($responseData['status']) && $responseData['status'] === 'success') {
                    $success = true;
                    $successCount++;
                    $message = $responseData['message'] ?? 'Registered successfully';
                } else {
                    $message = $responseData['message'] ?? 'Registration failed';
                }
            } else {
                $message = "HTTP error: $httpCode";
            }
            
            $status = $success ? '✓' : '❌';
            $logMessage = "  $status {$handleData['node_url']}: $message\n";
            file_put_contents($logFile, $logMessage, FILE_APPEND);
            
            $results[] = [
                'node_url' => $handleData['node_url'],
                'success' => $success,
                'message' => $message,
                'http_code' => $httpCode
            ];
            
            curl_multi_remove_handle($multiHandle, $ch);
            curl_close($ch);
        }
        
        curl_multi_close($multiHandle);
        
        $totalCount = count($networkNodes);
        $logMessage = "✓ Notification complete: $successCount/$totalCount nodes notified successfully\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        
        return [
            'status' => 'success',
            'message' => "Notified $successCount/$totalCount network nodes",
            'data' => [
                'success_count' => $successCount,
                'total_count' => $totalCount,
                'success_rate' => $totalCount > 0 ? round(($successCount / $totalCount) * 100, 2) : 0,
                'results' => $results
            ]
        ];
        
    } catch (Exception $e) {
        $logMessage = "❌ Network notification failed: " . $e->getMessage() . "\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        
        return [
            'status' => 'error',
            'message' => 'Network notification failed: ' . $e->getMessage(),
            'data' => ['error' => $e->getMessage()]
        ];
    }
}
