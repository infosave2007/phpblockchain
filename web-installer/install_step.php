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
        
        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 10, // Increase timeout for external hosting
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
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
        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 10
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

function generateGenesis(): array
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
        $pdo = new PDO($dsn, $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
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
            'previous_hash' => $genesisBlock->getPreviousHash(),
            'hash' => $genesisBlock->getHash(),
            'nonce' => $genesisBlock->getNonce(),
            'merkle_root' => $genesisBlock->getMerkleRoot()
        ];
        
        $logMessage = "Genesis data extracted successfully\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        
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
        $pdo = new PDO($dsn, $username, $password, [
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
        $pdo = new PDO($dsn, $username, $password, [
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
            $walletAmount = (int)($config['node_wallet_amount'] ?? 5000);
            $stakingAmount = (int)($config['staking_amount'] ?? 1000);
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
        
        $pdo = new PDO(
            $dsn,
            $username,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]
        );
        
        $logMessage = "Database connected successfully\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        
        // Create WalletManager instance
        $walletManager = new \Blockchain\Wallet\WalletManager($pdo, $config);
        
        // Initialize transaction and block variables
        $fundingTransaction = null;
        $fundingBlock = null;
        
        // Create wallet using existing WalletManager
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
        
        // Update wallet balance if needed
        if ($walletAmount > 0) {
            $stmt = $pdo->prepare("UPDATE wallets SET balance = ? WHERE address = ?");
            $result = $stmt->execute([$walletAmount, $walletData['address']]);
            
            if (!$result) {
                throw new Exception('Failed to update wallet balance');
            }
            
            $logMessage = "Updated wallet balance to: $walletAmount\n";
            file_put_contents($logFile, $logMessage, FILE_APPEND);
        }
        
        // Skip node_id update - field doesn't exist in current schema
        $logMessage = "Skipping node_id update (field not in schema)\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        
        // Add staking record if amount > 0
        if ($stakingAmount > 0 && $stakingAmount <= $walletAmount) {
            try {
                // Check if staking table exists
                $stmt = $pdo->query("SHOW TABLES LIKE 'staking'");
                $stakingTableExists = $stmt->rowCount() > 0;
                
                if ($stakingTableExists) {
                    // NOTE: Staking records should be created via blockchain transactions in genesis block
                    // not as direct database inserts. This maintains blockchain integrity.
                    $logMessage = "Staking will be handled via genesis block transactions\n";
                    file_put_contents($logFile, $logMessage, FILE_APPEND);
                    
                    /*
                    $stmt = $pdo->prepare("
                        INSERT INTO staking (validator, staker, amount, status, start_block, created_at) 
                        VALUES (?, ?, ?, 'active', 0, NOW())
                    ");
                    $result = $stmt->execute([$walletData['address'], $walletData['address'], $stakingAmount]);
                    
                    if ($result) {
                        $logMessage = "Added staking record: $stakingAmount tokens\n";
                        file_put_contents($logFile, $logMessage, FILE_APPEND);
                    }
                    */
                } else {
                    $logMessage = "Staking table not found, skipping staking record creation\n";
                    file_put_contents($logFile, $logMessage, FILE_APPEND);
                }
            } catch (Exception $e) {
                $logMessage = "Warning: Could not create staking record: " . $e->getMessage() . "\n";
                file_put_contents($logFile, $logMessage, FILE_APPEND);
                // Don't throw exception, just log warning
            }
        }
        
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
                            'node_type' => $isPrimaryNode ? 'primary' : 'regular'
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
        
        
        // Return wallet data from WalletManager (already includes all needed fields)
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
        
        $responseData = [
            'wallet_data' => $completeWalletData,
            'is_primary' => $isPrimaryNode
        ];
        
        // Add transaction info if created
        if ($fundingTransaction) {
            $responseData['funding_transaction'] = [
                'hash' => $fundingTransaction['hash'],
                'amount' => $fundingTransaction['amount'],
                'from' => $fundingTransaction['from_address'],
                'to' => $fundingTransaction['to_address'],
                'timestamp' => $fundingTransaction['timestamp'],
                'fee' => $fundingTransaction['fee'],
                'gas_limit' => $fundingTransaction['gas_limit'],
                'block_hash' => $fundingTransaction['block_hash'] ?? 'genesis'
            ];
        }
        
        // Log final response data for debugging
        $logMessage = "Response data being returned:\n";
        $logMessage .= "wallet_data fields: " . implode(', ', array_keys($responseData['wallet_data'])) . "\n";
        $logMessage .= "wallet_data values preview: address=" . ($responseData['wallet_data']['address'] ?? 'MISSING') . 
                      ", public_key=" . (substr($responseData['wallet_data']['public_key'] ?? 'MISSING', 0, 20) . '...') . 
                      ", private_key=" . (substr($responseData['wallet_data']['private_key'] ?? 'MISSING', 0, 20) . '...') . 
                      ", mnemonic=" . (substr($responseData['wallet_data']['mnemonic'] ?? 'MISSING', 0, 20) . '...') . "\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        
        return [
            'message' => 'Wallet created successfully' . ($fundingTransaction ? ' with blockchain transaction' : ''),
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

?>
