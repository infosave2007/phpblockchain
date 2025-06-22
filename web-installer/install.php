<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../core/security/RateLimiter.php';

use Blockchain\Config\Security;
use Blockchain\Core\Security\RateLimiter;

// Start secure session
session_start([
    'cookie_secure' => isset($_SERVER['HTTPS']),
    'cookie_httponly' => true,
    'cookie_samesite' => 'Strict',
    'use_strict_mode' => true
]);

// Enforce HTTPS and set security headers
Security::enforceHTTPS();
Security::setSecureHeaders();

header('Content-Type: application/json');

// Rate limiting
$rateLimiter = new RateLimiter();
$clientId = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

if (!$rateLimiter->isAllowed($clientId, 'install')) {
    http_response_code(429);
    echo json_encode([
        'status' => 'error',
        'message' => 'Too many installation attempts. Please try again later.',
        'retry_after' => $rateLimiter->getResetTime($clientId, 'install')
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

// Validate CSRF token
$csrfToken = $_POST['csrf_token'] ?? '';
if (!Security::validateCSRF($csrfToken)) {
    http_response_code(403);
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid CSRF token'
    ]);
    exit;
}

// Record the installation attempt
$rateLimiter->recordRequest($clientId, 'install');

try {
    // Get form data
    $config = [
        'database' => [
            'host' => $_POST['db_host'] ?? 'localhost',
            'port' => (int)($_POST['db_port'] ?? 3306),
            'username' => $_POST['db_username'] ?? '',
            'password' => $_POST['db_password'] ?? '',
            'database' => $_POST['db_name'] ?? 'blockchain_modern'
        ],
        'blockchain' => [
            'network_name' => $_POST['network_name'] ?? 'My Blockchain Network',
            'token_symbol' => $_POST['token_symbol'] ?? 'MBC',
            'consensus_algorithm' => $_POST['consensus_algorithm'] ?? 'pos',
            'initial_supply' => (float)($_POST['initial_supply'] ?? 1000000),
            'block_time' => (int)($_POST['block_time'] ?? 10),
            'block_reward' => (float)($_POST['block_reward'] ?? 10)
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
    validateConfig($config);

    // Define installation steps
    $steps = [
        ['id' => 'create_directories', 'description' => 'Creating directories...'],
        ['id' => 'install_dependencies', 'description' => 'Installing dependencies...'],
        ['id' => 'create_database', 'description' => 'Creating database...'],
        ['id' => 'create_tables', 'description' => 'Creating tables...'],
        ['id' => 'generate_genesis', 'description' => 'Generating genesis block...'],
        ['id' => 'create_config', 'description' => 'Creating configuration...'],
        ['id' => 'setup_admin', 'description' => 'Setting up administrator...'],
        ['id' => 'initialize_blockchain', 'description' => 'Initializing blockchain...'],
        ['id' => 'start_services', 'description' => 'Starting services...'],
        ['id' => 'finalize', 'description' => 'Completing installation...']
    ];

    // Save configuration for subsequent steps
    file_put_contents('../config/install_config.json', json_encode($config, JSON_PRETTY_PRINT));

    echo json_encode([
        'status' => 'success',
        'message' => 'Installation initiated',
        'steps' => $steps
    ]);

} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}

function validateConfig(array $config): void
{
    // Database check
    if (empty($config['database']['username'])) {
        throw new Exception('Database username not specified');
    }

    if (empty($config['database']['database'])) {
        throw new Exception('Database name not specified');
    }

    // Blockchain check
    if (empty($config['blockchain']['network_name'])) {
        throw new Exception('Network name not specified');
    }

    if (empty($config['blockchain']['token_symbol'])) {
        throw new Exception('Token symbol not specified');
    }

    if (!in_array($config['blockchain']['consensus_algorithm'], ['pos', 'pow', 'poa'])) {
        throw new Exception('Unsupported consensus algorithm');
    }

    // Network check
    if ($config['network']['p2p_port'] < 1024 || $config['network']['p2p_port'] > 65535) {
        throw new Exception('Invalid P2P port');
    }

    if ($config['network']['rpc_port'] < 1024 || $config['network']['rpc_port'] > 65535) {
        throw new Exception('Invalid RPC port');
    }

    // Administrator check
    if (empty($config['admin']['email']) || !filter_var($config['admin']['email'], FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Invalid administrator email');
    }

    if (strlen($config['admin']['password']) < 8) {
        throw new Exception('Administrator password must contain at least 8 characters');
    }

    if (empty($config['admin']['api_key']) || strlen($config['admin']['api_key']) < 16) {
        throw new Exception('API key must contain at least 16 characters');
    }
}
?>
