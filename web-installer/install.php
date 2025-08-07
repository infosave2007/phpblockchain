<?php
declare(strict_types=1);

// Disable error display and set JSON header
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Set JSON header early
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Basic session security
session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Strict',
    'use_strict_mode' => true
]);

// Simple rate limiting without external dependencies
$rateLimitFile = '/tmp/install_rate_limit_' . md5($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$maxAttempts = 5;
$timeWindow = 300; // 5 minutes

function checkRateLimit($file, $maxAttempts, $timeWindow): bool {
    if (!file_exists($file)) {
        file_put_contents($file, json_encode(['attempts' => 1, 'first_attempt' => time()]));
        return true;
    }
    
    $data = json_decode(file_get_contents($file), true);
    if (!$data) return true;
    
    $now = time();
    
    // Reset if time window passed
    if ($now - $data['first_attempt'] > $timeWindow) {
        file_put_contents($file, json_encode(['attempts' => 1, 'first_attempt' => $now]));
        return true;
    }
    
    // Check if limit exceeded
    if ($data['attempts'] >= $maxAttempts) {
        return false;
    }
    
    // Increment attempts
    $data['attempts']++;
    file_put_contents($file, json_encode($data));
    return true;
}

// Check rate limiting
if (!checkRateLimit($rateLimitFile, $maxAttempts, $timeWindow)) {
    http_response_code(429);
    echo json_encode([
        'status' => 'error',
        'message' => 'Too many installation attempts. Please try again later.',
        'retry_after' => $timeWindow
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

// Simple CSRF protection - for installer we can skip this or use a simple token
// For production installer, you might want to add proper CSRF protection

try {
    // Start session to save config as backup
    session_start();
    
    // Add debugging
    file_put_contents('install_debug.log', "=== New Installation Request ===\n", FILE_APPEND);
    file_put_contents('install_debug.log', "Timestamp: " . date('Y-m-d H:i:s') . "\n", FILE_APPEND);
    file_put_contents('install_debug.log', "POST Data: " . print_r($_POST, true) . "\n", FILE_APPEND);
    
    // Get form data
    $config = [
        'db_host' => $_POST['db_host'] ?? 'localhost',
        'db_port' => (int)($_POST['db_port'] ?? 3306),
        'db_username' => $_POST['db_username'] ?? '',
        'db_password' => $_POST['db_password'] ?? '',
        'db_name' => $_POST['db_name'] ?? 'blockchain_modern',
        'node_type' => $_POST['node_type'] ?? 'primary',
        'network_name' => $_POST['network_name'] ?? 'My Blockchain Network',
        'token_symbol' => $_POST['token_symbol'] ?? 'MBC',
        'consensus_algorithm' => 'pos', // Fixed to Proof of Stake
        'initial_supply' => (float)($_POST['initial_supply'] ?? 1000000),
        'primary_wallet_amount' => (float)($_POST['primary_wallet_amount'] ?? 100000),
        'node_wallet_amount' => (float)($_POST['node_wallet_amount'] ?? 5000),
        'staking_amount' => (float)($_POST['staking_amount'] ?? 1000),
        'min_stake_amount' => (float)($_POST['min_stake_amount'] ?? 1000),
        'block_time' => (int)($_POST['block_time'] ?? 10),
        'block_reward' => (float)($_POST['block_reward'] ?? 10),
        'network_nodes' => $_POST['network_nodes'] ?? '',
        'node_selection_strategy' => $_POST['node_selection_strategy'] ?? 'consensus_majority',
        'existing_wallet_address' => $_POST['existing_wallet_address'] ?? '',
        'existing_wallet_private_key' => $_POST['existing_wallet_private_key'] ?? '',
        'verify_wallet' => isset($_POST['verify_wallet']) && $_POST['verify_wallet'] === 'on',
        'node_domain' => $_POST['node_domain'] ?? '',
        'protocol' => $_POST['protocol'] ?? 'http',
        'max_peers' => (int)($_POST['max_peers'] ?? 10),
        'api_endpoint' => $_POST['api_endpoint'] ?? '/api',
        'enable_binary_storage' => isset($_POST['enable_binary_storage']) && $_POST['enable_binary_storage'] === 'on',
        'enable_encryption' => isset($_POST['enable_encryption']) && $_POST['enable_encryption'] === 'on',
        'blockchain_data_dir' => $_POST['blockchain_data_dir'] ?? 'storage/blockchain',
        'admin_email' => $_POST['admin_email'] ?? '',
        'admin_password' => $_POST['admin_password'] ?? '',
        'api_key' => $_POST['api_key'] ?? ''
    ];
    
    // Save config to session as backup
    $_SESSION['install_config'] = $config;
    
    // Legacy format for compatibility
    $legacyConfig = [
        'database' => [
            'host' => $config['db_host'],
            'port' => $config['db_port'],
            'username' => $config['db_username'],
            'password' => $config['db_password'],
            'database' => $config['db_name'],
            'options' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]
        ],
        'blockchain' => [
            'network_name' => $config['network_name'],
            'token_symbol' => $config['token_symbol'],
            'consensus_algorithm' => 'pos',
            'initial_supply' => $config['initial_supply'],
            'block_time' => $config['block_time'],
            'block_reward' => $config['block_reward'],
            'enable_binary_storage' => $config['enable_binary_storage'],
            'enable_encryption' => isset($_POST['enable_encryption']) && $_POST['enable_encryption'] === 'on',
            'data_dir' => $_POST['blockchain_data_dir'] ?? 'storage/blockchain',
            'encryption_key' => bin2hex(random_bytes(32)) // Generate random encryption key
        ],
        'crypto' => [
            'name' => $_POST['network_name'] ?? 'My Blockchain Network',
            'symbol' => $_POST['token_symbol'] ?? 'MBC',
            'network' => 'mainnet'
        ],
        'network' => [
            'node_type' => $_POST['node_type'] ?? 'full',
            'p2p_port' => (int)($_POST['p2p_port'] ?? 8545),
            'rpc_port' => (int)($_POST['rpc_port'] ?? 8546),
            'max_peers' => (int)($_POST['max_peers'] ?? 25),
            'bootstrap_nodes' => array_filter(array_map('trim', explode(',', $_POST['bootstrap_nodes'] ?? '')))
        ],
        'admin' => [
            'email' => $_POST['admin_email'] ?? '',
            'password' => $_POST['admin_password'] ?? '',
            'api_key' => $_POST['api_key'] ?? ''
        ]
    ];

    // Data validation
    file_put_contents('install_debug.log', "Config before validation: " . print_r($config, true) . "\n", FILE_APPEND);
    validateConfig($config);
    file_put_contents('install_debug.log', "Validation passed\n", FILE_APPEND);

    // Define installation steps based on node type
    $nodeType = $config['node_type'] ?? 'primary';
    
    if ($nodeType === 'primary') {
        // Primary node installation steps
        $steps = [
            ['id' => 'create_directories', 'description' => 'Creating directories...'],
            ['id' => 'install_dependencies', 'description' => 'Checking dependencies...'],
            ['id' => 'create_database', 'description' => 'Creating database...'],
            ['id' => 'create_tables', 'description' => 'Creating tables...'],
            ['id' => 'create_wallet', 'description' => 'Creating primary wallet...'],
            ['id' => 'generate_genesis', 'description' => 'Generating genesis block...'],
            ['id' => 'initialize_binary_storage', 'description' => 'Initializing binary blockchain storage...'],
            ['id' => 'create_config', 'description' => 'Creating configuration...'],
            ['id' => 'setup_admin', 'description' => 'Setting up administrator...'],
            ['id' => 'initialize_blockchain', 'description' => 'Initializing blockchain...'],
            ['id' => 'start_services', 'description' => 'Starting services...'],
            ['id' => 'finalize', 'description' => 'Completing installation...']
        ];
    } else {
        // Regular node installation steps
        $steps = [
            ['id' => 'create_directories', 'description' => 'Creating directories...'],
            ['id' => 'install_dependencies', 'description' => 'Checking dependencies...'],
            ['id' => 'create_database', 'description' => 'Creating database...'],
            ['id' => 'create_tables', 'description' => 'Creating tables...'],
            ['id' => 'create_wallet', 'description' => 'Setting up wallet...'],
            ['id' => 'sync_blockchain', 'description' => 'Syncing with genesis node...'],
            ['id' => 'initialize_binary_storage', 'description' => 'Initializing binary blockchain storage...'],
            ['id' => 'create_config', 'description' => 'Creating configuration...'],
            ['id' => 'setup_admin', 'description' => 'Setting up administrator...'],
            ['id' => 'initialize_blockchain', 'description' => 'Initializing blockchain...'],
            ['id' => 'start_services', 'description' => 'Starting services...'],
            ['id' => 'auto_sync_trigger', 'description' => 'Triggering network sync across nodes...'],
            ['id' => 'finalize', 'description' => 'Completing installation...']
        ];
    }
    
    // Save configuration for subsequent steps
    $configPath = '../config/install_config.json';
    $configDir = dirname($configPath);
    
    // Ensure config directory exists
    if (!is_dir($configDir)) {
        if (!mkdir($configDir, 0755, true)) {
            throw new Exception('Failed to create config directory: ' . $configDir);
        }
    }
    
    // Log configuration being saved
    error_log("Saving installation config to: " . $configPath);
    error_log("Config data: " . json_encode($config));
    
    // Save the simplified config format (not legacy format)
    $result = file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT));
    if ($result === false) {
        throw new Exception('Failed to save installation configuration');
    }
    
    error_log("Configuration saved successfully (" . $result . " bytes)");

    // Auto-start synchronization for regular node (non-primary)
    try {
        if (($nodeType ?? 'primary') !== 'primary') {
            // If a list of known nodes is provided – persist to .env as a simple config store
            if (!empty($config['network_nodes'])) {
                // Write NETWORK_NODES to .env (if template exists and is writable) as DB alternative
                $envPath = __DIR__ . '/../config/.env';
                if (file_exists($envPath) && is_writable($envPath)) {
                    $envContent = file_get_contents($envPath);
                    if (strpos($envContent, 'NETWORK_NODES=') === false) {
                        $envContent .= "\nNETWORK_NODES=\"" . trim($config['network_nodes']) . "\"\n";
                    } else {
                        $envContent = preg_replace('/^NETWORK_NODES=.*$/m', 'NETWORK_NODES="' . trim($config['network_nodes']) . '"', $envContent);
                    }
                    file_put_contents($envPath, $envContent);
                }
            }

            // Background sync trigger: write a flag-file and ping network_sync.php?action=sync
            $flagFile = __DIR__ . '/../storage/sync_autostart.flag';
            @file_put_contents($flagFile, date('c'));

            // Try to initiate a non-blocking web request to start sync
            $syncUrl = (isset($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME'] : 'http') . '://' .
                       ($_SERVER['HTTP_HOST'] ?? 'localhost') .
                       rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/../network_sync.php?action=sync';

            // Non-blocking HTTP call
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 1,
                    'ignore_errors' => true,
                    'header' => "User-Agent: InstallerAutoSync/1.0\r\n"
                ]
            ]);
            @fopen($syncUrl, 'r', false, $ctx);
        }
    } catch (\Throwable $e) {
        // Ignore auto-start errors; installation should still complete successfully
        file_put_contents(__DIR__ . '/install_debug.log', "Auto sync trigger failed: " . $e->getMessage() . "\n", FILE_APPEND);
    }

    echo json_encode([
        'status' => 'success',
        'message' => 'Installation initiated',
        'steps' => $steps
    ]);

} catch (Exception $e) {
    file_put_contents('install_debug.log', "Error: " . $e->getMessage() . "\n", FILE_APPEND);
    file_put_contents('install_debug.log', "Stack trace: " . $e->getTraceAsString() . "\n", FILE_APPEND);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}

function validateConfig(array $config): void
{
    // Database check - use the correct format
    if (empty($config['db_username'])) {
        throw new Exception('Database username not specified');
    }

    if (empty($config['db_name'])) {
        throw new Exception('Database name not specified');
    }

    // Network check
    if (empty($config['network_name'])) {
        throw new Exception('Network name not specified');
    }

    if (empty($config['token_symbol'])) {
        throw new Exception('Token symbol not specified');
    }

    // Administrator check
    if (empty($config['admin_email']) || !filter_var($config['admin_email'], FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Invalid administrator email');
    }

    if (strlen($config['admin_password']) < 8) {
        throw new Exception('Administrator password must contain at least 8 characters');
    }

    if (empty($config['api_key']) || strlen($config['api_key']) < 16) {
        throw new Exception('API key must contain at least 16 characters');
    }
}
?>
