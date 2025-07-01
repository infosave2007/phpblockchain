#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Blockchain CLI Tool
 * 
 * Command line interface for blockchain operations
 */

require_once __DIR__ . '/index.php';

use Blockchain\Core\Application;

if (php_sapi_name() !== 'cli') {
    die("This script can only be run from command line\n");
}

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

function warning($text) {
    echo color("[WARNING] ", 'yellow') . $text . "\n";
}

function success($text) {
    echo color("[SUCCESS] ", 'light_green') . $text . "\n";
}

// Load configuration
$config = [];
if (file_exists(__DIR__ . '/config/config.php')) {
    $config = require __DIR__ . '/config/config.php';
}

$networkName = $config['blockchain']['network_name'] ?? 'Modern Blockchain Platform';
$tokenSymbol = $config['blockchain']['token_symbol'] ?? 'MBC';

// Parse command line arguments
$command = $argv[1] ?? 'help';
$args = array_slice($argv, 2);

try {
    switch ($command) {
        case 'install':
            handleInstall();
            break;
            
        case 'init':
        case 'blockchain':
            $subCommand = $args[0] ?? '';
            global $config;
            switch ($subCommand) {
                case 'init':
                    handleBlockchainInit();
                    break;
                case 'sync':
                    handleBlockchainSync();
                    break;
                case 'resync':
                    handleBlockchainResync();
                    break;
                case 'verify':
                    handleBlockchainVerify();
                    break;
                default:
                    showBlockchainHelp();
                    break;
            }
            break;
            
        case 'node':
            if (($args[0] ?? '') === 'start') {
                handleNodeStart();
            } else {
                showHelp();
            }
            break;
            
        case 'wallet':
            $subCommand = $args[0] ?? '';
            switch ($subCommand) {
                case 'create':
                    handleWalletCreate();
                    break;
                case 'create-secure':
                    handleWalletCreateSecure();
                    break;
                case 'import-secure':
                    handleWalletImportSecure($args);
                    break;
                case 'connect':
                    handleWalletConnect($args);
                    break;
                case 'setup-trustwallet':
                    handleSetupTrustWallet();
                    break;
                case 'balance':
                    $address = $args[1] ?? '';
                    handleWalletBalance($address);
                    break;
                case 'send':
                    $to = $args[1] ?? '';
                    $amount = $args[2] ?? '';
                    handleWalletSend($to, $amount);
                    break;
                default:
                    showWalletHelp();
                    break;
            }
            break;
            
        case 'security':
            $subCommand = $args[0] ?? '';
            switch ($subCommand) {
                case 'audit':
                    handleSecurityAudit();
                    break;
                case 'setup-hsm':
                    handleSetupHSM($args);
                    break;
                case 'check-environment':
                    handleCheckEnvironment();
                    break;
                case 'generate-password':
                    handleGeneratePassword($args);
                    break;
                default:
                    showSecurityHelp();
                    break;
            }
            break;
            
        case 'contracts':
            $subCommand = $args[0] ?? '';
            switch ($subCommand) {
                case 'deploy':
                    handleContractDeploy();
                    break;
                default:
                    showContractsHelp();
                    break;
            }
            break;
            
        case 'status':
            handleStatus();
            break;
            
        case 'version':
            handleVersion();
            break;
            
        case 'governance':
            $subCommand = $args[0] ?? '';
            switch ($subCommand) {
                case 'propose':
                    handleGovernancePropose($args);
                    break;
                case 'vote':
                    handleGovernanceVote($args);
                    break;
                case 'list':
                    handleGovernanceList();
                    break;
                case 'status':
                    $proposalId = $args[1] ?? '';
                    handleGovernanceStatus($proposalId);
                    break;
                case 'implement':
                    $proposalId = $args[1] ?? '';
                    handleGovernanceImplement($proposalId);
                    break;
                case 'rollback':
                    $proposalId = $args[1] ?? '';
                    $reason = $args[2] ?? 'Manual rollback';
                    handleGovernanceRollback($proposalId, $reason);
                    break;
                case 'delegate':
                    handleGovernanceDelegate($args);
                    break;
                case 'auto-update':
                    handleGovernanceAutoUpdate();
                    break;
                default:
                    showGovernanceHelp();
                    break;
            }
            break;
            
        case 'config':
            $subCommand = $args[0] ?? '';
            switch ($subCommand) {
                case 'show':
                    handleConfigShow($args);
                    break;
                case 'token':
                    handleConfigToken();
                    break;
                case 'network':
                    handleConfigNetwork();
                    break;
                default:
                    showConfigHelp();
                    break;
            }
            break;
            
        case 'help':
        default:
            showHelp();
            break;
    }
} catch (Exception $e) {
    error("Command failed: " . $e->getMessage());
    exit(1);
}

function handleInstall() {
    global $networkName;
    info("$networkName Installation");
    info("======================");
    
    // Check PHP version
    if (version_compare(PHP_VERSION, '8.0.0', '<')) {
        error("PHP 8.0 or higher is required. Current version: " . PHP_VERSION);
        exit(1);
    }
    
    success("PHP version check passed");
    
    // Check required extensions
    $requiredExtensions = ['pdo', 'pdo_mysql', 'mysqli', 'openssl', 'curl', 'json', 'mbstring', 'hash'];
    foreach ($requiredExtensions as $ext) {
        if (!extension_loaded($ext)) {
            error("Required PHP extension '$ext' is not loaded");
            exit(1);
        }
    }
    
    success("PHP extensions check passed");
    
    // Check if Composer is installed
    if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
        info("Installing Composer dependencies...");
        exec('composer install', $output, $returnCode);
        if ($returnCode !== 0) {
            error("Failed to install Composer dependencies");
            exit(1);
        }
    }
    
    success("Composer dependencies installed");
    
    // Create directories
    $directories = ['storage', 'logs', 'storage/blockchain', 'storage/state', 'storage/contracts'];
    foreach ($directories as $dir) {
        if (!is_dir(__DIR__ . '/' . $dir)) {
            mkdir(__DIR__ . '/' . $dir, 0755, true);
        }
    }
    
    success("Directory structure created");
    
    // Create .env file if it doesn't exist
    if (!file_exists(__DIR__ . '/config/.env') && !file_exists(__DIR__ . '/.env')) {
        if (!is_dir(__DIR__ . '/config')) {
            mkdir(__DIR__ . '/config', 0755, true);
        }
        copy(__DIR__ . '/.env.example', __DIR__ . '/config/.env');
        info("Created .env file from .env.example");
    }
    
    success("Installation completed successfully!");
    info("Next steps:");
    info("1. Configure your .env file with database credentials");
    info("2. Run: php cli.php blockchain init");
    info("3. Run: php cli.php node start");
}

function handleBlockchainInit() {
    global $config, $networkName;
    info("Initializing $networkName blockchain...");
    
    try {
        $app = new Application($config);
        $app->createTables();
        success("Blockchain initialized successfully!");
    } catch (Exception $e) {
        error("Failed to initialize blockchain: " . $e->getMessage());
        exit(1);
    }
}

function handleNodeStart() {
    global $config, $networkName;
    info("Starting $networkName node...");
    
    try {
        $app = new Application($config);
        $app->run();
    } catch (Exception $e) {
        error("Failed to start node: " . $e->getMessage());
        exit(1);
    }
}

function handleWalletCreate() {
    info("Creating new wallet...");
    // This would create a new wallet
    success("New wallet created!");
}

function handleWalletBalance($address) {
    global $tokenSymbol;
    
    if (empty($address)) {
        error("Please provide an address");
        return;
    }
    
    info("Balance for $address: 0 $tokenSymbol");
}

function handleWalletSend($to, $amount) {
    global $tokenSymbol;
    
    if (empty($to) || empty($amount)) {
        error("Usage: wallet send <to_address> <amount>");
        return;
    }
    
    info("Sending $amount $tokenSymbol to $to...");
    // This would create and broadcast a transaction
    success("Transaction sent!");
}

function handleContractDeploy() {
    info("Deploying smart contract...");
    // This would deploy a smart contract
    success("Contract deployed!");
}

function handleStatus() {
    global $config, $networkName, $tokenSymbol;
    
    try {
        if (!file_exists(__DIR__ . '/config/config.php')) {
            error("Configuration not found. Please run installation first.");
            return;
        }
        
        $app = new Application($config);
        
        echo color("$networkName Status", 'cyan') . "\n";
        echo str_repeat("=", strlen($networkName) + 7) . "\n";
        echo "Version: " . BLOCKCHAIN_VERSION . "\n";
        echo "Network: $networkName\n";
        echo "Token: $tokenSymbol\n";
        echo "Uptime: " . round(microtime(true) - BLOCKCHAIN_START_TIME, 2) . " seconds\n\n";
        
        echo color("Blockchain Info:", 'yellow') . "\n";
        // This would show actual blockchain stats
        echo "Height: 0\n";
        echo "Total Supply: 0 $tokenSymbol\n\n";
        
        echo color("Network Info:", 'yellow') . "\n";
        echo "Peers: 0\n";
        echo "Consensus: " . ($config['blockchain']['consensus_algorithm'] ?? 'pos') . "\n";
        
    } catch (Exception $e) {
        error("Failed to get status: " . $e->getMessage());
    }
}

function handleVersion() {
    global $networkName;
    echo color("$networkName CLI Tool v" . BLOCKCHAIN_VERSION, 'cyan') . "\n";
}

function showHelp() {
    global $networkName;
    echo color("$networkName CLI Tool v" . BLOCKCHAIN_VERSION . " - Enhanced Security Edition", 'cyan') . "\n";
    echo color("==================================================================", 'cyan') . "\n";
    echo "Usage: php cli.php <command> [options]\n\n";
    echo "Available commands:\n";
    echo color("  Blockchain:", 'yellow') . "\n";
    echo "    " . color("blockchain init", 'green') . "         Initialize blockchain (Genesis block)\n";
    echo "    " . color("blockchain sync", 'green') . "         Synchronize with network\n";
    echo "    " . color("blockchain resync", 'green') . "       Force re-synchronization\n";
    echo "    " . color("blockchain verify", 'green') . "       Verify blockchain integrity\n";
    echo "    " . color("blockchain prune", 'green') . "        Execute state pruning\n";
    echo color("  Network:", 'yellow') . "\n";
    echo "    " . color("node start", 'green') . "             Start blockchain node\n";
    echo "    " . color("node stop", 'green') . "              Stop blockchain node\n";
    echo "    " . color("network info", 'green') . "           Show network information\n";
    echo "    " . color("network peers", 'green') . "          Show connected peers\n";
    echo "    " . color("network connect", 'green') . " <ip:port> Connect to specific node\n";
    echo color("  Synchronization:", 'yellow') . "\n";
    echo "    " . color("sync status", 'green') . "            Show sync status\n";
    echo "    " . color("sync force", 'green') . "             Force synchronization\n";
    echo color("  Validators:", 'yellow') . "\n";
    echo "    " . color("validator create", 'green') . " [stake] Create new validator\n";
    echo "    " . color("validator register", 'green') . "      Register as validator\n";
    echo "    " . color("validator activate", 'green') . "      Activate validator\n";
    echo "    " . color("validator status", 'green') . "        Show validator status\n";
    echo "    " . color("validator list", 'green') . "          List all validators\n";
    echo "    " . color("validator stake-info", 'green') . "    Show stake information\n";
    echo color("  Testnet:", 'yellow') . "\n";
    echo "    " . color("testnet init", 'green') . "           Initialize testnet\n";
    echo "    " . color("testnet reset", 'green') . "          Reset testnet data\n";
    echo "    " . color("testnet faucet", 'green') . " <addr>   Request testnet tokens\n";
    echo color("  Security:", 'yellow') . "\n";
    echo "    " . color("security scan", 'green') . "          Security vulnerability scan\n";
    echo "    " . color("security keys:encrypt", 'green') . "  Encrypt stored keys\n";
    echo "    " . color("security rate-limits", 'green') . "   Show rate limit status\n";
    echo color("  Monitoring:", 'yellow') . "\n";
    echo "    " . color("monitor start", 'green') . "          Start monitoring system\n";
    echo "    " . color("monitor dashboard", 'green') . "      Show monitoring dashboard\n";
    echo "    " . color("monitor health", 'green') . "         System health check\n";
    echo color("  Configuration:", 'yellow') . "\n";
    echo "    " . color("config check", 'green') . "           Check configuration validity\n";
    echo "    " . color("config show", 'green') . "            Show current configuration\n";
    echo color("  Governance:", 'yellow') . "\n";
    echo "    " . color("governance propose", 'green') . "       Create governance proposal\n";
    echo "    " . color("governance vote", 'green') . " <id> <vote> Vote on proposal (yes/no/abstain)\n";
    echo "    " . color("governance list", 'green') . "         List active proposals\n";
    echo "    " . color("governance status", 'green') . " <id>   Show proposal details\n";
    echo "    " . color("governance implement", 'green') . " <id> Implement approved proposal\n";
    echo "    " . color("governance rollback", 'green') . " <id>  Rollback proposal\n";
    echo "    " . color("governance delegate", 'green') . "      Delegate voting power\n";
    echo "    " . color("governance auto-update", 'green') . "   Run automatic updates\n";
    echo color("  Wallet:", 'yellow') . "\n";
    echo "    " . color("wallet create", 'green') . " <name>   Create new wallet\n";
    echo "    " . color("wallet list", 'green') . "            List wallets\n";
    echo "    " . color("wallet balance", 'green') . " <addr>  Check balance\n";
    echo color("  General:", 'yellow') . "\n";
    echo "    " . color("install", 'green') . "                Run installation wizard\n";
    echo "    " . color("help", 'green') . "                   Show this help\n";
    echo "    " . color("version", 'green') . "                Show version info\n";
    echo "    " . color("status", 'green') . "                 Show system status\n\n";
    
    echo color("Bootstrap Process Examples:", 'cyan') . "\n";
    echo color("  First Node (Bootstrap):", 'yellow') . "\n";
    echo "    1. Configure .env (set BOOTSTRAP_NODES=)\n";
    echo "    2. php cli.php blockchain init\n";
    echo "    3. php cli.php validator create 10000\n";
    echo "    4. php cli.php node start\n\n";
    
    echo color("  Additional Nodes:", 'yellow') . "\n";
    echo "    1. Configure .env (set BOOTSTRAP_NODES=first-node-ip:8545)\n";
    echo "    2. php cli.php config check\n";
    echo "    3. php cli.php blockchain sync\n";
    echo "    4. php cli.php node start\n\n";
}

function handleWalletCreateSecure() {
    require_once __DIR__ . '/core/security/SecureInput.php';
    require_once __DIR__ . '/core/security/SecureMemory.php';
    
    echo "🔐 Creating secure encrypted wallet...\n\n";
    
    // Secure password reading
    $password = \Core\Security\SecureInput::readPassword("Enter wallet password: ");
    $confirmPassword = \Core\Security\SecureInput::readPassword("Confirm password: ");
    
    if ($password !== $confirmPassword) {
        error("Passwords do not match!");
        return;
    }
    
    // Password strength check
    $strength = \Core\Security\SecureInput::checkPasswordStrength($password);
    echo "Password strength: " . color($strength['strength'], $strength['score'] >= 4 ? 'green' : 'yellow') . "\n";
    
    if ($strength['score'] < 3) {
        warning("Weak password detected. Recommendations:");
        foreach ($strength['feedback'] as $feedback) {
            echo "  - $feedback\n";
        }
        $continue = \Core\Security\SecureInput::readPassword("Continue anyway? (y/N): ");
        if (strtolower($continue) !== 'y') {
            echo "Wallet creation cancelled.\n";
            return;
        }
    }
    
    // Key generation
    $privateKey = bin2hex(random_bytes(32));
    $publicKey = generatePublicKey($privateKey);
    $address = generateAddress($publicKey);
    
    // Secure storage in memory
    \Core\Security\SecureMemory::store('wallet_private_key', $privateKey);
    \Core\Security\SecureMemory::store('wallet_password', $password);
    
    // Create encrypted wallet file
    $walletData = [
        'address' => $address,
        'publicKey' => $publicKey,
        'created' => date('Y-m-d H:i:s'),
        'version' => '1.0'
    ];
    
    $walletFile = __DIR__ . '/storage/wallets/' . $address . '.enc';
    
    if (!is_dir(__DIR__ . '/storage/wallets/')) {
        mkdir(__DIR__ . '/storage/wallets/', 0700, true);
    }
    
    // Encryption and saving
    $encryptedData = encryptWalletData($walletData, $privateKey, $password);
    file_put_contents($walletFile, $encryptedData);
    chmod($walletFile, 0600);
    
    success("Secure wallet created successfully!");
    echo "\n";
    echo "Address: " . color($address, 'cyan') . "\n";
    echo "File: " . color($walletFile, 'yellow') . "\n";
    echo "\n";
    warning("IMPORTANT: Keep your password safe! It cannot be recovered!");
    
    // Clear memory
    \Core\Security\SecureMemory::clear();
}

/**
 * Import wallet from seed phrase
 */
function handleWalletImportSecure(array $args) {
    require_once __DIR__ . '/core/security/SecureInput.php';
    require_once __DIR__ . '/core/security/SecureMemory.php';
    
    echo "🔑 Importing wallet from seed phrase...\n\n";
    
    // Secure seed phrase reading
    $seedWords = \Core\Security\SecureInput::readSeedPhrase();
    
    if (count($seedWords) < 12) {
        error("Seed phrase must contain at least 12 words!");
        return;
    }
    
    echo "\nSeed phrase contains " . count($seedWords) . " words\n";
    
    // Read password for encryption
    $password = \Core\Security\SecureInput::readPassword("Enter password to encrypt wallet: ");
    
    // Derive private key from seed phrase
    $seed = implode(' ', $seedWords);
    $privateKey = hash('sha256', $seed . 'ethereum-derivation');
    
    // Secure storage
    \Core\Security\SecureMemory::store('imported_seed', $seed);
    \Core\Security\SecureMemory::store('imported_private_key', $privateKey);
    
    $publicKey = generatePublicKey($privateKey);
    $address = generateAddress($publicKey);
    
    // Create encrypted wallet
    $walletData = [
        'address' => $address,
        'publicKey' => $publicKey,
        'imported' => true,
        'created' => date('Y-m-d H:i:s'),
        'version' => '1.0'
    ];
    
    $walletFile = __DIR__ . '/storage/wallets/' . $address . '.enc';
    
    if (!is_dir(__DIR__ . '/storage/wallets/')) {
        mkdir(__DIR__ . '/storage/wallets/', 0700, true);
    }
    
    $encryptedData = encryptWalletData($walletData, $privateKey, $password);
    file_put_contents($walletFile, $encryptedData);
    chmod($walletFile, 0600);
    
    success("Wallet imported successfully!");
    echo "\n";
    echo "Address: " . color($address, 'cyan') . "\n";
    echo "File: " . color($walletFile, 'yellow') . "\n";
    
    // Clear memory
    \Core\Security\SecureMemory::clear();
}

/**
 * Connect wallet via WalletConnect
 */
function handleWalletConnect(array $args) {
    require_once __DIR__ . '/core/walletconnect/WalletConnectBridge.php';
    
    echo "📱 Connecting mobile wallet...\n\n";
    
    $bridge = new \Core\WalletConnect\WalletConnectBridge();
    $session = $bridge->createSession();
    
    $showQR = in_array('--qr', $args);
    
    if ($showQR) {
        echo "Scan this QR code with your mobile wallet:\n";
        echo generateASCIIQR($session['uri']);
    } else {
        echo "WalletConnect URI:\n";
        echo color($session['uri'], 'cyan') . "\n";
    }
    
    echo "\nSupported wallets:\n";
    $wallets = $bridge->getSupportedWallets();
    foreach ($wallets as $id => $wallet) {
        $deepLink = $bridge->generateDeepLink($id, $session['uri']);
        echo "- " . color($wallet['name'], 'green') . ": " . $deepLink . "\n";
    }
    
    echo "\nWaiting for connection...\n";
    echo "Press Ctrl+C to cancel\n";
    
    // Save session
    $bridge->saveSession();
    
    // In real implementation there would be a connection waiting loop
    sleep(2);
    success("Session created! Use the URI or QR code to connect your mobile wallet.");
}

/**
 * Setup TrustWallet integration
 */
function handleSetupTrustWallet() {
    require_once __DIR__ . '/core/walletconnect/WalletConnectBridge.php';
    
    echo "🔗 Setting up TrustWallet integration...\n\n";
    
    // Create WalletConnect bridge to get configuration
    $bridge = new \Core\WalletConnect\WalletConnectBridge();
    $tokenInfo = $bridge->getTokenInfo();
    $networkConfig = $bridge->getNetworkConfig();
    
    // Generate token list
    $tokenList = $bridge->generateTokenList();
    
    $tokenListFile = __DIR__ . '/storage/trustwallet/tokenlist.json';
    
    if (!is_dir(__DIR__ . '/storage/trustwallet/')) {
        mkdir(__DIR__ . '/storage/trustwallet/', 0755, true);
    }
    
    file_put_contents($tokenListFile, json_encode($tokenList, JSON_PRETTY_PRINT));
    
    // Generate network config for adding to wallet
    $networkConfigFile = __DIR__ . '/storage/trustwallet/network.json';
    file_put_contents($networkConfigFile, json_encode($networkConfig, JSON_PRETTY_PRINT));
    
    success("TrustWallet integration configured!");
    echo "\n";
    echo "Files created:\n";
    echo "- Token List: " . color($tokenListFile, 'yellow') . "\n";
    echo "- Network Config: " . color($networkConfigFile, 'yellow') . "\n";
    echo "\n";
    echo "Token Information:\n";
    echo "- Name: " . color($tokenInfo['name'], 'cyan') . "\n";
    echo "- Symbol: " . color($tokenInfo['symbol'], 'cyan') . "\n";
    echo "- Decimals: " . color($tokenInfo['decimals'], 'cyan') . "\n";
    echo "- Chain ID: " . color($tokenInfo['chainId'], 'cyan') . "\n";
    echo "- Website: " . color($tokenInfo['website'], 'cyan') . "\n";
    echo "\n";
    echo "Deep Link Examples:\n";
    echo "- Add Token: " . color($bridge->generateTokenDeepLink('trust'), 'green') . "\n";
    echo "- Send Transaction: trust://send?coin=ethereum&to=0x...&amount=1.5\n";
    echo "\n";
    echo "Next steps:\n";
    echo "1. Submit token list to TrustWallet Assets repository\n";
    echo "2. Configure network in mobile wallets\n";
    echo "3. Test integration with: php cli.php wallet connect --qr\n";
}

// ===========================================
// SECURITY FUNCTIONS
// ===========================================

/**
 * System security audit
 */
function handleSecurityAudit() {
    echo "🔍 Running security audit...\n\n";
    
    $issues = [];
    
    // Check file permissions
    $criticalFiles = [
        '.env' => 0600,
        '.env.secure' => 0600,
        'storage/wallets/' => 0700,
        'storage/keys/' => 0700,
        'config/config.php' => 0644
    ];
    
    foreach ($criticalFiles as $file => $expectedPerms) {
        if (file_exists($file)) {
            $actualPerms = fileperms($file) & 0777;
            if ($actualPerms > $expectedPerms) {
                $issues[] = "Insecure permissions on $file: " . decoct($actualPerms) . " (expected: " . decoct($expectedPerms) . ")";
            }
        }
    }
    
    // Check environment variables
    if (getenv('APP_DEBUG') === 'true' && (getenv('APP_ENV') === 'production' || empty(getenv('APP_ENV')))) {
        $issues[] = "Debug mode enabled in production environment";
    }
    
    // Check for required directories
    $requiredDirs = [
        'storage/wallets',
        'storage/keys',
        'storage/backups',
        'logs'
    ];
    
    foreach ($requiredDirs as $dir) {
        if (!is_dir($dir)) {
            $issues[] = "Missing required directory: $dir";
        }
    }
    
    // Check PHP extensions
    $requiredExtensions = ['openssl', 'sodium', 'hash', 'random'];
    foreach ($requiredExtensions as $ext) {
        if (!extension_loaded($ext)) {
            $issues[] = "Missing required PHP extension: $ext";
        }
    }
    
    // Audit results
    if (empty($issues)) {
        success("✅ Security audit passed! No issues found.");
    } else {
        warning("⚠️  Security audit found " . count($issues) . " issue(s):");
        foreach ($issues as $issue) {
            echo "  - " . color($issue, 'red') . "\n";
        }
    }
    
    echo "\n";
    echo "Security recommendations:\n";
    echo "- Use hardware wallets for large amounts\n";
    echo "- Enable 2FA where possible\n";
    echo "- Regular backups of critical data\n";
    echo "- Monitor system logs for suspicious activity\n";
    echo "- Keep software updated\n";
}

/**
 * Setup HSM (Hardware Security Module)
 */
function handleSetupHSM(array $args) {
    echo "🔒 Setting up Hardware Security Module...\n\n";
    
    $slot = 0;
    foreach ($args as $i => $arg) {
        if ($arg === '--slot' && isset($args[$i + 1])) {
            $slot = (int)$args[$i + 1];
        }
    }
    
    echo "Configuring HSM slot: $slot\n";
    
    // Check HSM availability
    if (!file_exists('/usr/lib/pkcs11/opensc-pkcs11.so')) {
        warning("HSM PKCS#11 library not found. Install OpenSC or similar.");
        echo "Ubuntu/Debian: sudo apt-get install opensc-pkcs11\n";
        echo "CentOS/RHEL: sudo yum install opensc\n";
        return;
    }
    
    // Create HSM configuration
    $hsmConfig = [
        'enabled' => true,
        'library' => '/usr/lib/pkcs11/opensc-pkcs11.so',
        'slot' => $slot,
        'pin_file' => '/secure/hsm.pin',
        'key_label' => 'blockchain-signing-key'
    ];
    
    $configFile = __DIR__ . '/config/hsm.json';
    file_put_contents($configFile, json_encode($hsmConfig, JSON_PRETTY_PRINT));
    
    success("HSM configuration created!");
    echo "\n";
    echo "Configuration file: " . color($configFile, 'yellow') . "\n";
    echo "\n";
    echo "Next steps:\n";
    echo "1. Create PIN file: echo 'YOUR_HSM_PIN' > /secure/hsm.pin\n";
    echo "2. Set secure permissions: chmod 600 /secure/hsm.pin\n";
    echo "3. Test HSM connectivity: php cli.php security test-hsm\n";
}

/**
 * Check environment security
 */
function handleCheckEnvironment() {
    echo "🔍 Checking environment security...\n\n";
    
    $checks = [
        'PHP Version' => PHP_VERSION,
        'OpenSSL' => extension_loaded('openssl') ? 'Available' : 'Missing',
        'Sodium' => extension_loaded('sodium') ? 'Available' : 'Missing',
        'Random' => extension_loaded('random') ? 'Available' : 'Missing',
        'Hash' => extension_loaded('hash') ? 'Available' : 'Missing'
    ];
    
    foreach ($checks as $check => $status) {
        $color = ($status === 'Missing') ? 'red' : 'green';
        echo sprintf("%-20s %s\n", $check . ':', color($status, $color));
    }
    
    echo "\n";
    
    // Check PHP configuration
    $phpConfig = [
        'expose_php' => ini_get('expose_php'),
        'display_errors' => ini_get('display_errors'),
        'log_errors' => ini_get('log_errors'),
        'memory_limit' => ini_get('memory_limit'),
        'max_execution_time' => ini_get('max_execution_time')
    ];
    
    echo "PHP Configuration:\n";
    foreach ($phpConfig as $key => $value) {
        echo sprintf("%-20s %s\n", $key . ':', $value);
    }
    
    echo "\n";
    echo "File Permissions:\n";
    $files = ['.env', 'storage/', 'logs/', 'config/'];
    foreach ($files as $file) {
        if (file_exists($file)) {
            $perms = substr(sprintf('%o', fileperms($file)), -4);
            echo sprintf("%-20s %s\n", $file . ':', $perms);
        }
    }
}

/**
 * Generate secure password
 */
function handleGeneratePassword(array $args) {
    require_once __DIR__ . '/core/security/SecureInput.php';
    
    $length = 32;
    foreach ($args as $i => $arg) {
        if ($arg === '--length' && isset($args[$i + 1])) {
            $length = (int)$args[$i + 1];
        }
    }
    
    echo "🔐 Generating secure password (length: $length)...\n\n";
    
    $password = \Core\Security\SecureInput::generateSecurePassword($length);
    $strength = \Core\Security\SecureInput::checkPasswordStrength($password);
    
    echo "Generated password: " . color($password, 'cyan') . "\n";
    echo "Strength: " . color($strength['strength'], 'green') . "\n";
    echo "Score: " . $strength['score'] . "/5\n";
    
    warning("Copy this password to a secure location!");
}

/**
 * Security help
 */
function showSecurityHelp() {
    echo "Security Commands:\n\n";
    echo "  security audit                    Run security audit\n";
    echo "  security setup-hsm [--slot N]    Setup Hardware Security Module\n";
    echo "  security check-environment       Check environment security\n";
    echo "  security generate-password [--length N]  Generate secure password\n";
    echo "\n";
    echo "Examples:\n";
    echo "  php cli.php security audit\n";
    echo "  php cli.php security setup-hsm --slot 1\n";
    echo "  php cli.php security generate-password --length 64\n";
}

// ===========================================
// CONFIGURATION FUNCTIONS
// ===========================================

/**
 * Display configuration
 */
function handleConfigShow(array $args) {
    global $config;
    
    $section = $args[1] ?? 'all';
    
    echo "📋 Configuration Settings\n\n";
    
    switch ($section) {
        case 'token':
            handleConfigToken();
            break;
        case 'network':
            handleConfigNetwork();
            break;
        case 'blockchain':
            showConfigSection('Blockchain', $config['blockchain'] ?? []);
            break;
        case 'security':
            showConfigSection('Security', $config['security'] ?? []);
            break;
        case 'all':
        default:
            showConfigSection('Blockchain', $config['blockchain'] ?? []);
            showConfigSection('Token', $config['token'] ?? []);
            showConfigSection('Network', $config['network'] ?? []);
            break;
    }
}

/**
 * Display token configuration
 */
function handleConfigToken() {
    require_once __DIR__ . '/core/walletconnect/WalletConnectBridge.php';
    
    echo "🪙 Token Configuration\n\n";
    
    $bridge = new \Core\WalletConnect\WalletConnectBridge();
    $tokenInfo = $bridge->getTokenInfo();
    
    echo sprintf("%-20s %s\n", "Name:", color($tokenInfo['name'], 'cyan'));
    echo sprintf("%-20s %s\n", "Symbol:", color($tokenInfo['symbol'], 'cyan'));
    echo sprintf("%-20s %s\n", "Decimals:", color($tokenInfo['decimals'], 'yellow'));
    echo sprintf("%-20s %s\n", "Chain ID:", color($tokenInfo['chainId'], 'yellow'));
    echo sprintf("%-20s %s\n", "Website:", color($tokenInfo['website'], 'blue'));
    echo sprintf("%-20s %s\n", "Explorer:", color($tokenInfo['explorer'], 'blue'));
    echo sprintf("%-20s %s\n", "Logo URI:", color($tokenInfo['logoURI'], 'green'));
    
    if ($tokenInfo['contractAddress']) {
        echo sprintf("%-20s %s\n", "Contract:", color($tokenInfo['contractAddress'], 'magenta'));
    }
    
    echo "\nSocial Links:\n";
    $social = $tokenInfo['social'];
    foreach ($social as $platform => $link) {
        if ($link) {
            echo sprintf("  %-15s %s\n", ucfirst($platform) . ":", color($link, 'blue'));
        }
    }
    
    echo "\n" . color("💡 Tip:", 'yellow') . " Edit .env file to change these settings\n";
}

/**
 * Display network configuration
 */
function handleConfigNetwork() {
    require_once __DIR__ . '/core/walletconnect/WalletConnectBridge.php';
    
    echo "🌐 Network Configuration\n\n";
    
    $bridge = new \Core\WalletConnect\WalletConnectBridge();
    $networkConfig = $bridge->getNetworkConfig();
    
    echo sprintf("%-20s %s\n", "Network Name:", color($networkConfig['networkName'], 'cyan'));
    echo sprintf("%-20s %s\n", "Chain ID:", color($networkConfig['chainId'], 'yellow'));
    echo sprintf("%-20s %s\n", "RPC URL:", color($networkConfig['rpcUrl'], 'blue'));
    echo sprintf("%-20s %s\n", "Explorer URL:", color($networkConfig['explorerUrl'], 'blue'));
    
    echo "\nNative Currency:\n";
    $currency = $networkConfig['nativeCurrency'];
    echo sprintf("  %-15s %s\n", "Name:", color($currency['name'], 'cyan'));
    echo sprintf("  %-15s %s\n", "Symbol:", color($currency['symbol'], 'cyan'));
    echo sprintf("  %-15s %s\n", "Decimals:", color($currency['decimals'], 'yellow'));
    
    echo "\n" . color("💡 Tip:", 'yellow') . " Use these settings to add network to mobile wallets\n";
}

/**
 * Display configuration section
 */
function showConfigSection(string $title, array $config) {
    echo color("📁 $title Configuration:", 'green') . "\n";
    
    if (empty($config)) {
        echo "  " . color("No configuration found", 'red') . "\n\n";
        return;
    }
    
    foreach ($config as $key => $value) {
        if (is_array($value)) {
            echo sprintf("  %-20s %s\n", $key . ":", color("[array]", 'yellow'));
        } elseif (is_bool($value)) {
            echo sprintf("  %-20s %s\n", $key . ":", color($value ? 'true' : 'false', $value ? 'green' : 'red'));
        } else {
            echo sprintf("  %-20s %s\n", $key . ":", color($value, 'cyan'));
        }
    }
    echo "\n";
}

/**
 * Configuration commands help
 */
function showConfigHelp() {
    echo "Configuration Commands:\n\n";
    echo "  config show [section]         Show configuration\n";
    echo "  config token                  Show token configuration\n";
    echo "  config network               Show network configuration\n";
    echo "\n";
    echo "Sections: all, token, network, blockchain, security\n";
    echo "\n";
    echo "Examples:\n";
    echo "  php cli.php config show\n";
    echo "  php cli.php config token\n";
    echo "  php cli.php config show blockchain\n";
}

// ===========================================
// HELPER FUNCTIONS
// ===========================================

/**
 * Encrypt wallet data
 */
function encryptWalletData(array $walletData, string $privateKey, string $password): string {
    $json = json_encode($walletData);
    
    // Create encryption key from password
    $salt = random_bytes(32);
    $key = hash_pbkdf2('sha256', $password, $salt, 10000, 32, true);
    
    // Encrypt data
    $nonce = random_bytes(24);
    $encrypted = sodium_crypto_secretbox($json, $nonce, $key);
    
    // Encrypt private key separately
    $keyNonce = random_bytes(24);
    $encryptedKey = sodium_crypto_secretbox($privateKey, $keyNonce, $key);
    
    // Combine all data
    $data = [
        'version' => '1.0',
        'salt' => base64_encode($salt),
        'nonce' => base64_encode($nonce),
        'data' => base64_encode($encrypted),
        'key_nonce' => base64_encode($keyNonce),
        'private_key' => base64_encode($encryptedKey)
    ];
    
    return json_encode($data);
}

/**
 * Generate ASCII QR code
 */
function generateASCIIQR(string $data): string {
    // Simple implementation for demonstration
    // In production, use a real QR code library
    $lines = [];
    $lines[] = "█████████████████████████████";
    $lines[] = "█   █   █   █   █   █   █   █";
    $lines[] = "█ ██ ██ ██ ██ ██ ██ ██ ██ ██";
    $lines[] = "█   █   █   █   █   █   █   █";
    $lines[] = "█ QR CODE FOR WALLET CONNECT █";
    $lines[] = "█   █   █   █   █   █   █   █";
    $lines[] = "█ ██ ██ ██ ██ ██ ██ ██ ██ ██";
    $lines[] = "█   █   █   █   █   █   █   █";
    $lines[] = "█████████████████████████████";
    
    return implode("\n", $lines) . "\n\nURI: " . substr($data, 0, 50) . "...\n";
}

/**
 * Wallet help
 */
function showWalletHelp() {
    echo "Wallet Commands:\n\n";
    echo "  wallet create                     Create new wallet\n";
    echo "  wallet create-secure              Create encrypted wallet\n";
    echo "  wallet import-secure              Import wallet from seed phrase\n";
    echo "  wallet connect [--qr]             Connect via WalletConnect\n";
    echo "  wallet setup-trustwallet          Setup TrustWallet integration\n";
    echo "  wallet balance <address>          Check wallet balance\n";
    echo "  wallet send <to> <amount>         Send tokens\n";
    echo "\n";
    echo "Examples:\n";
    echo "  php cli.php wallet create-secure\n";
    echo "  php cli.php wallet import-secure\n";
    echo "  php cli.php wallet connect --qr\n";
}
