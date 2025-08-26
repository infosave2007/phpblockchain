#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Crypto Messages CLI Tool
 * 
 * Command line interface for blockchain crypto messaging operations
 */

if (php_sapi_name() !== 'cli') {
    die("This script can only be run from command line\n");
}

// Define constants
define('BLOCKCHAIN_VERSION', '2.0.0');

// Autoload
require_once __DIR__ . '/vendor/autoload.php';

use Blockchain\Core\Application;
use Blockchain\Core\Cryptography\MessageEncryption;

// Color output functions
function color($text, $color = null) {
    $colors = [
        'black' => '0;30',
        'dark_gray' => '1;30',
        'blue' => '0;34',
        'light_blue' => '1;34',
        'green' => '0;32',
        'light_green' => '1;32',
        'cyan' => '0;36',
        'light_cyan' => '1;36',
        'red' => '0;31',
        'light_red' => '1;31',
        'purple' => '0;35',
        'light_purple' => '1;35',
        'brown' => '0;33',
        'yellow' => '1;33',
        'light_gray' => '0;37',
        'white' => '1;37'
    ];
    
    if (!$color || !isset($colors[$color])) {
        return $text;
    }
    
    return "\033[" . $colors[$color] . "m" . $text . "\033[0m";
}

function info($text) {
    echo color("[INFO] ", 'green') . $text . "\n";
}

function error($text) {
    echo color("[ERROR] ", 'red') . $text . "\n";
}

// Load configuration
$config = [
    'database' => [
        'host' => 'localhost',
        'port' => 3306,
        'database' => 'blockchain_test',
        'username' => 'root',
        'password' => '',
        'charset' => 'utf8mb4',
        'options' => [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    ],
    'blockchain' => [
        'network_name' => 'Crypto Messages Network',
        'token_symbol' => 'CMN',
        'consensus_algorithm' => 'proof_of_stake'
    ],
    'consensus' => [
        'pos' => []
    ]
];

// Handle command line arguments
$command = $argv[1] ?? 'help';

try {
    if ($command === 'generate-keys') {
        // Special case - generate keys without database connection
        echo color("Generating RSA Key Pair...", 'cyan') . "\n";
        $keyPair = MessageEncryption::generateRSAKeyPair(2048);
        
        echo color("RSA Key Pair Generated Successfully!", 'green') . "\n";
        echo color("=====================================", 'green') . "\n";
        echo "Public Key:\n";
        echo $keyPair['public_key'] . "\n";
        echo "\nPrivate Key:\n";
        echo $keyPair['private_key'] . "\n";
        echo color("\nIMPORTANT: Save your private key securely! It cannot be recovered if lost.", 'yellow') . "\n";
        echo color("The public key can be shared with others to receive encrypted messages.", 'yellow') . "\n";
        exit(0);
    }
    
    if ($command === 'encrypt-test') {
        // Test encryption without database
        $message = $argv[2] ?? "Hello, this is a test message!";
        
        echo color("Testing Message Encryption...", 'cyan') . "\n";
        
        // Generate test keys
        $keyPair = MessageEncryption::generateRSAKeyPair(1024); // Smaller for demo
        
        echo "Original message: " . color($message, 'yellow') . "\n";
        
        // Test hybrid encryption
        $encrypted = MessageEncryption::encryptHybrid($message, $keyPair['public_key']);
        echo color("Message encrypted successfully!", 'green') . "\n";
        echo "Encrypted size: " . strlen(json_encode($encrypted)) . " bytes\n";
        
        // Test decryption
        $decrypted = MessageEncryption::decryptHybrid($encrypted, $keyPair['private_key']);
        echo "Decrypted message: " . color($decrypted, 'green') . "\n";
        
        if ($message === $decrypted) {
            echo color("✓ Encryption/Decryption test PASSED!", 'green') . "\n";
        } else {
            echo color("✗ Encryption/Decryption test FAILED!", 'red') . "\n";
        }
        exit(0);
    }
    
    if ($command === 'help' || $command === '--help' || $command === '-h') {
        echo color("Crypto Messages CLI Tool v" . BLOCKCHAIN_VERSION, 'cyan') . "\n";
        echo color("=====================================", 'cyan') . "\n";
        echo "Available commands:\n";
        echo color("  generate-keys", 'light_blue') . "                    Generate new RSA key pair\n";
        echo color("  encrypt-test [message]", 'light_blue') . "          Test encryption/decryption\n";
        echo color("  create-wallet <name>", 'light_blue') . "            Create new wallet with keys\n";
        echo color("  send-message <from> <to> <msg> [encrypt]", 'light_blue') . " Send message\n";
        echo color("  read-messages <addr> [priv_key]", 'light_blue') . "  Read messages for address\n";
        echo color("  help", 'light_blue') . "                           Show this help\n";
        echo "\nNote: Commands requiring database will need proper database configuration.\n";
        exit(0);
    }
    
    // For other commands, try to initialize the application
    $app = new Application($config);
    $app->handleCommand($argv);
    
} catch (Exception $e) {
    error("Error: " . $e->getMessage());
    
    // If database connection failed, show available offline commands
    if (strpos($e->getMessage(), 'Database connection failed') !== false) {
        echo "\n" . color("Available offline commands:", 'yellow') . "\n";
        echo "  php crypto-cli.php generate-keys\n";
        echo "  php crypto-cli.php encrypt-test\n";
        echo "  php crypto-cli.php help\n";
    }
    exit(1);
}
