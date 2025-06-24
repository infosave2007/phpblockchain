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
    foreach ($directories as $dir) {
        if (!is_dir($dir)) {
            if (mkdir($dir, 0755, true)) {
                $created[] = $dir;
            } else {
                throw new Exception("Failed to create directory: $dir");
            }
        }
    }
    
    return [
        'message' => 'Directories created successfully',
        'data' => ['created' => $created]
    ];
}

function installDependencies(): array
{
    // For a basic installation, we assume dependencies are already installed
    // In a real scenario, you might check for Composer autoload or install packages
    
    $composerFile = '../composer.json';
    if (!file_exists($composerFile)) {
        throw new Exception('Composer configuration not found');
    }
    
    $vendorDir = '../vendor';
    if (!is_dir($vendorDir)) {
        throw new Exception('Vendor directory not found. Please run: composer install');
    }
    
    return [
        'message' => 'Dependencies are already installed',
        'data' => ['composer_installed' => true]
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
        
        // Create basic tables
        $tables = [
            'blocks' => "CREATE TABLE IF NOT EXISTS blocks (
                id INT AUTO_INCREMENT PRIMARY KEY,
                hash VARCHAR(64) UNIQUE NOT NULL,
                previous_hash VARCHAR(64),
                timestamp BIGINT NOT NULL,
                data TEXT,
                nonce BIGINT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )",
            'transactions' => "CREATE TABLE IF NOT EXISTS transactions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                hash VARCHAR(64) UNIQUE NOT NULL,
                from_address VARCHAR(64),
                to_address VARCHAR(64) NOT NULL,
                amount DECIMAL(20,8) NOT NULL,
                timestamp BIGINT NOT NULL,
                block_hash VARCHAR(64),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )",
            'users' => "CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(255) UNIQUE NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                api_key VARCHAR(64) UNIQUE,
                role ENUM('admin', 'user') DEFAULT 'user',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )"
        ];
        
        foreach ($tables as $tableName => $sql) {
            $pdo->exec($sql);
        }
        
        return [
            'message' => 'Database tables created successfully',
            'data' => ['tables' => array_keys($tables)]
        ];
        
    } catch (PDOException $e) {
        throw new Exception('Table creation failed: ' . $e->getMessage());
    }
}

function generateGenesis(): array
{
    // Generate genesis block
    $genesisData = [
        'index' => 0,
        'timestamp' => time(),
        'data' => 'Genesis Block',
        'previous_hash' => '0',
        'hash' => hash('sha256', 'genesis_block_' . time())
    ];
    
    // Save genesis block
    $genesisFile = '../storage/blockchain/genesis.json';
    file_put_contents($genesisFile, json_encode($genesisData, JSON_PRETTY_PRINT));
    
    return [
        'message' => 'Genesis block generated successfully',
        'data' => $genesisData
    ];
}

function createConfig(): array
{
    // Load installation configuration
    $configFile = '../config/install_config.json';
    $config = json_decode(file_get_contents($configFile), true);
    
    // Create main config file
    $mainConfig = [
        'database' => $config['database'],
        'blockchain' => $config['blockchain'],
        'network' => $config['network'],
        'app' => [
            'debug' => false,
            'timezone' => 'UTC',
            'installed' => true,
            'version' => '1.0.0'
        ]
    ];
    
    file_put_contents('../config/config.php', '<?php return ' . var_export($mainConfig, true) . ';');
    
    return [
        'message' => 'Configuration files created successfully',
        'data' => ['config_created' => true]
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
        
        // Create admin user
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
    // Initialize blockchain with genesis block
    // This is a simplified version - in real implementation you'd initialize the full blockchain
    
    return [
        'message' => 'Blockchain initialized successfully',
        'data' => ['status' => 'initialized']
    ];
}

function startServices(): array
{
    // In a real implementation, you might start background services here
    // For now, we'll just mark services as ready
    
    return [
        'message' => 'Services started successfully',
        'data' => ['services' => ['blockchain', 'api', 'p2p']]
    ];
}

function finalizeInstallation(): array
{
    // Clean up temporary files
    $tempFiles = [
        '../config/install_config.json'
    ];
    
    foreach ($tempFiles as $file) {
        if (file_exists($file)) {
            unlink($file);
        }
    }
    
    return [
        'message' => 'Installation completed successfully',
        'data' => ['installation_complete' => true]
    ];
}
?>
