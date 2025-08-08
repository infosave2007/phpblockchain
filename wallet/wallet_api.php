<?php
/**
 * Wallet API
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, Accept, Origin');

// Handle CORS preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Include logger
require_once __DIR__ . '/WalletLogger.php';

/**
 * Log helper wrapper (kept for backward compatibility)
 */
function writeLog($message, $level = 'INFO') {
    WalletLogger::log($message, $level);
}

// Fatal error handler
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
        WalletLogger::error("FATAL ERROR: " . $error['message']);
        WalletLogger::error("File: " . $error['file']);
        WalletLogger::error("Line: " . $error['line']);
        
    // Try to output JSON error response if possible
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => 'Fatal error occurred: ' . $error['message'],
                'debug_info' => [
                    'file' => $error['file'],
                    'line' => $error['line'],
                    'type' => 'fatal_error'
                ]
            ]);
        }
    }
});

try {
    // Determine project base directory
    $baseDir = dirname(__DIR__);
    
    // Include Composer autoloader
    $autoloader = $baseDir . '/vendor/autoload.php';
    if (!file_exists($autoloader)) {
        throw new Exception('Composer autoloader not found. Please run "composer install"');
    }
    require_once $autoloader;
    
    // Load environment variables
    require_once $baseDir . '/core/Environment/EnvironmentLoader.php';
    \Blockchain\Core\Environment\EnvironmentLoader::load($baseDir);
    
    // Load config
    $configFile = $baseDir . '/config/config.php';
    $config = [];
    if (file_exists($configFile)) {
        $config = require $configFile;
    }
    
    // Add debug_mode if not set
    if (!isset($config['debug_mode'])) {
        $config['debug_mode'] = true; // Default: debug enabled
    }
    
    // Initialize logger with configuration
    WalletLogger::init($config);
    
    // Build database config with priority: config.php -> .env -> defaults
    $dbConfig = $config['database'] ?? [];
    
    // If empty, fallback to environment variables
    if (empty($dbConfig) || !isset($dbConfig['host'])) {
        $dbConfig = [
            'host' => \Blockchain\Core\Environment\EnvironmentLoader::get('DB_HOST', 'localhost'),
            'port' => (int)\Blockchain\Core\Environment\EnvironmentLoader::get('DB_PORT', 3306),
            'database' => \Blockchain\Core\Environment\EnvironmentLoader::get('DB_DATABASE', 'blockchain'),
            'username' => \Blockchain\Core\Environment\EnvironmentLoader::get('DB_USERNAME', 'root'),
            'password' => \Blockchain\Core\Environment\EnvironmentLoader::get('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'options' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]
        ];
    }
    
    // Log connection attempt
    writeLog("Attempting database connection using DatabaseManager");
    
    // Connect to database via DatabaseManager
    require_once $baseDir . '/core/Database/DatabaseManager.php';
    $pdo = \Blockchain\Core\Database\DatabaseManager::getConnection();
    
    writeLog("Database connection successful");
    
    // Include Wallet classes
    require_once $baseDir . '/wallet/WalletManager.php';
    require_once $baseDir . '/wallet/WalletBlockchainManager.php';
    require_once $baseDir . '/core/Config/NetworkConfig.php';
    require_once $baseDir . '/core/Cryptography/MessageEncryption.php';
    require_once $baseDir . '/core/Cryptography/KeyPair.php';
    
    // Instantiate WalletManager with full config
    $fullConfig = array_merge($config, ['database' => $dbConfig]);
    $walletManager = new \Blockchain\Wallet\WalletManager($pdo, $fullConfig);
    
    // Instantiate WalletBlockchainManager for blockchain integration
    $blockchainManager = new \Blockchain\Wallet\WalletBlockchainManager($pdo, $fullConfig);
    
    // Instantiate NetworkConfig to fetch network settings
    $networkConfig = new \Blockchain\Core\Config\NetworkConfig($pdo);
    
    // Parse input payload
    $rawBody = file_get_contents('php://input');
    $input = json_decode($rawBody, true);
    
    // For GET requests use $_GET
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $input = $_GET;
    }
    
    // Support clean /rpc alias via PATH_INFO or URL ending with /rpc
    $pathInfo = $_SERVER['PATH_INFO'] ?? '';
    $reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    $isRpcAlias = ($pathInfo === '/rpc') || (substr($reqPath, -4) === '/rpc');

    // If request is standard JSON-RPC (or /rpc alias), handle and exit early
    if ($isRpcAlias || (isset($input['jsonrpc']) && isset($input['method']))) {
        $rpcId = $input['id'] ?? 1;
        $rpcMethod = $input['method'] ?? '';
        $rpcParams = $input['params'] ?? [];

        $rpcResult = handleRpcRequest($pdo, $walletManager, $networkConfig, $rpcMethod, $rpcParams);
        echo json_encode([
            'jsonrpc' => '2.0',
            'id' => $rpcId,
            'result' => $rpcResult
        ]);
        exit;
    }

    $action = $input['action'] ?? '';
    
    switch ($action) {
        case 'dapp_config':
            // Return EIP-3085 compatible chain parameters for wallet_addEthereumChain
            $result = getDappConfig($networkConfig);
            break;
        case 'create_wallet':
            $result = createWallet($walletManager, $blockchainManager);
            break;
            
        case 'list_wallets':
            $result = listWallets($walletManager);
            break;
            
        case 'get_balance':
            $address = $input['address'] ?? '';
            if (!$address) {
                throw new Exception('Address is required');
            }
            $result = getBalance($walletManager, $address);
            break;
            
        case 'get_wallet_info':
            $address = $input['address'] ?? '';
            if (!$address) {
                throw new Exception('Address is required');
            }
            $result = getWalletInfo($walletManager, $address);
            break;
            
        case 'stake_tokens':
            $address = $input['address'] ?? '';
            $amount = $input['amount'] ?? 0;
            $period = $input['period'] ?? 30;
            $privateKey = $input['private_key'] ?? '';
            if (!$address || !$amount || !$privateKey) {
                throw new Exception('Address, amount and private key are required');
            }
            $result = stakeTokens($walletManager, $address, $amount, $period, $privateKey);
            break;
            
        case 'generate_mnemonic':
            $result = generateMnemonic($walletManager);
            break;
            
        case 'get_config':
            $result = getConfigInfo($config, $networkConfig);
            break;
            
        case 'create_wallet_from_mnemonic':
            $mnemonic = $input['mnemonic'] ?? [];
            if (empty($mnemonic)) {
                throw new Exception('Mnemonic phrase is required');
            }
            $result = createWalletFromMnemonic($walletManager, $blockchainManager, $mnemonic);
            break;
            
        case 'validate_mnemonic':
            $mnemonic = $input['mnemonic'] ?? [];
            if (empty($mnemonic)) {
                throw new Exception('Mnemonic phrase is required');
            }
            $result = validateMnemonic($mnemonic);
            break;
            
        case 'restore_wallet_from_mnemonic':
            $mnemonic = $input['mnemonic'] ?? [];
            if (empty($mnemonic)) {
                throw new Exception('Mnemonic phrase is required');
            }
            $result = restoreWalletFromMnemonic($walletManager, $blockchainManager, $mnemonic);
            break;
            
        case 'sync_binary_to_db':
            $result = syncBinaryToDatabase($walletManager);
            break;
            
        case 'sync_db_to_binary':
            $result = syncDatabaseToBinary($walletManager);
            break;
            
        case 'validate_blockchain':
            $result = validateBlockchain($walletManager);
            break;
            
        case 'blockchain_stats':
            $result = getBlockchainStats($walletManager);
            break;
            
        case 'create_backup':
            $backupPath = $input['backup_path'] ?? null;
            $result = createBackup($walletManager, $backupPath);
            break;
            
        case 'get_wallet_transaction_history':
            $address = $input['address'] ?? '';
            if (!$address) {
                throw new Exception('Wallet address is required');
            }
            $result = getWalletTransactionHistory($blockchainManager, $address);
            break;
            
        case 'verify_wallet_in_blockchain':
            $address = $input['address'] ?? '';
            if (!$address) {
                throw new Exception('Wallet address is required');
            }
            $result = verifyWalletInBlockchain($blockchainManager, $address);
            break;
            
        case 'transfer_tokens':
            $fromAddress = $input['from_address'] ?? '';
            $toAddress = $input['to_address'] ?? '';
            $amount = $input['amount'] ?? 0;
            $privateKey = $input['private_key'] ?? '';
            $memo = $input['memo'] ?? '';
            
            if (!$fromAddress || !$toAddress || !$amount || !$privateKey) {
                throw new Exception('From address, to address, amount and private key are required');
            }
            
            $result = transferTokens($walletManager, $blockchainManager, $fromAddress, $toAddress, $amount, $privateKey, $memo);
            break;
            
        case 'decrypt_message':
            $encryptedMessage = $input['encrypted_message'] ?? '';
            $privateKey = $input['private_key'] ?? '';
            $senderPublicKey = $input['sender_public_key'] ?? '';
            
            if (!$encryptedMessage || !$privateKey) {
                throw new Exception('Encrypted message and private key are required');
            }
            
            $result = decryptMessage($encryptedMessage, $privateKey, $senderPublicKey);
            break;
            
        case 'get_transaction_history':
            $address = $input['address'] ?? '';
            if (!$address) {
                throw new Exception('Address is required');
            }
            $result = getTransactionHistory($walletManager, $address);
            break;
            
        case 'decrypt_transaction_message':
            $txHash = $input['tx_hash'] ?? '';
            $walletAddress = $input['wallet_address'] ?? '';
            $privateKey = $input['private_key'] ?? '';
            
            if (!$txHash || !$walletAddress || !$privateKey) {
                throw new Exception('Transaction hash, wallet address and private key are required');
            }
            
            $result = decryptTransactionMessage($walletManager, $txHash, $walletAddress, $privateKey);
            break;
            
        case 'get_transaction':
            $hash = $input['hash'] ?? '';
            if (!$hash) {
                throw new Exception('Transaction hash is required');
            }
            
            $transaction = $walletManager->getTransactionByHash($hash);
            if (!$transaction) {
                throw new Exception('Transaction not found');
            }
            
            $result = [
                'success' => true,
                'transaction' => $transaction
            ];
            break;
            
        case 'stake_tokens_new':
            $address = $input['address'] ?? '';
            $amount = $input['amount'] ?? 0;
            $period = $input['period'] ?? 30;
            $privateKey = $input['private_key'] ?? '';
            
            if (!$address || !$amount || !$privateKey) {
                throw new Exception('Address, amount and private key are required');
            }
            
            $result = stakeTokensWithBlockchain($walletManager, $blockchainManager, $address, $amount, $period, $privateKey);
            break;
            
        case 'unstake_tokens':
            $address = $input['address'] ?? '';
            $amount = $input['amount'] ?? 0;
            $privateKey = $input['private_key'] ?? '';
            
            if (!$address || !$amount || !$privateKey) {
                throw new Exception('Address, amount and private key are required');
            }
            
            $result = unstakeTokens($walletManager, $blockchainManager, $address, $amount, $privateKey);
            break;

        case 'get_staking_contract':
            // Resolve or auto-deploy staking contract and return its address
            $addr = getOrDeployStakingContract($pdo, $input['deployer'] ?? '0x0000000000000000000000000000000000000000');
            if (!$addr) {
                throw new Exception('Staking contract not available');
            }
            $result = ['staking_contract' => $addr];
            break;
            
        case 'get_staking_info':
            $address = $input['address'] ?? '';
            if (!$address) {
                throw new Exception('Wallet address is required');
            }
            $result = getStakingInfo($walletManager, $address);
            break;
            
        case 'get_blockchain_wallet_info':
            $address = $input['address'] ?? '';
            if (!$address) {
                throw new Exception('Wallet address is required');
            }
            $result = getBlockchainWalletInfo($blockchainManager, $walletManager, $address);
            break;
            
        case 'activate_restored_wallet':
            $address = $input['address'] ?? '';
            $publicKey = $input['public_key'] ?? '';
            if (!$address || !$publicKey) {
                throw new Exception('Address and public_key are required');
            }
            $result = activateRestoredWallet($walletManager, $blockchainManager, $address, $publicKey);
            break;
            
        case 'delete_wallet':
            $address = $input['address'] ?? '';
            if (!$address) {
                throw new Exception('Wallet address is required');
            }
            $result = deleteWallet($walletManager, $address);
            break;

        case 'rpc':
            // Minimal JSON-RPC over query/body: ?action=rpc&method=eth_chainId or JSON body handled above
            $rpcMethod = $input['method'] ?? ($_GET['method'] ?? '');
            // params[] can be passed via query string or JSON body
            $rpcParams = $input['params'] ?? ($_GET['params'] ?? []);
            if (is_string($rpcParams)) {
                // Allow params to be JSON string in query
                $decoded = json_decode($rpcParams, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $rpcParams = $decoded;
                } else {
                    $rpcParams = [$rpcParams];
                }
            }
            $rpcResult = handleRpcRequest($pdo, $walletManager, $networkConfig, $rpcMethod, $rpcParams);
            $result = ['rpc' => $rpcResult];
            break;
            
        default:
            throw new Exception('Unknown action: ' . $action);
    }
    
    echo json_encode([
        'success' => true,
        ...$result
    ]);
    
} catch (Exception $e) {
    // Log full error info
    $errorInfo = [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString(),
        'action' => $input['action'] ?? 'unknown',
        'timestamp' => date('Y-m-d H:i:s'),
        'input_data' => $input ?? []
    ];
    
    // Write to logs
    writeLog("Wallet API Error: " . json_encode($errorInfo), 'ERROR');
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'debug_info' => [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'action' => $input['action'] ?? 'unknown',
            'trace' => explode("\n", $e->getTraceAsString())
        ]
    ]);
}

/**
 * Create a new wallet via WalletManager
 */
function createWallet($walletManager, $blockchainManager) {
    try {
        writeLog("Creating new wallet with blockchain integration", 'INFO');
        
    // 1. Create wallet using WalletManager
        $walletData = $walletManager->createWallet();
        writeLog("Wallet created successfully: " . $walletData['address'], 'INFO');
        
    // 2. Record wallet creation in blockchain
        $blockchainResult = $blockchainManager->createWalletWithBlockchain($walletData);
        writeLog("Blockchain recording result: " . json_encode($blockchainResult['blockchain_recorded']), 'INFO');
        
    // 3. Return combined result
        return [
            'wallet' => $walletData,
            'blockchain' => [
                'recorded' => $blockchainResult['blockchain_recorded'],
                'transaction_hash' => $blockchainResult['transaction']['hash'] ?? null,
                'block_hash' => $blockchainResult['block']['hash'] ?? null,
                'block_height' => $blockchainResult['block']['height'] ?? null
            ]
        ];
    } catch (Exception $e) {
        writeLog("Error creating wallet: " . $e->getMessage(), 'ERROR');
        throw new Exception('Failed to create wallet: ' . $e->getMessage());
    }
}

/**
 * List wallets via WalletManager
 */
function listWallets($walletManager) {
    try {
        $wallets = $walletManager->listWallets(20);
        return [
            'wallets' => $wallets
        ];
    } catch (Exception $e) {
        throw new Exception('Failed to list wallets: ' . $e->getMessage());
    }
}

/**
 * Get wallet balance via WalletManager
 */
function getBalance($walletManager, $address) {
    try {
        $availableBalance = $walletManager->getAvailableBalance($address);
        $stakedBalance = $walletManager->getStakedBalance($address);
        $totalBalance = $availableBalance + $stakedBalance;
        
        return [
            'balance' => [
                'available' => $availableBalance,
                'staked' => $stakedBalance,
                'total' => $totalBalance
            ]
        ];
    } catch (Exception $e) {
        throw new Exception('Failed to get balance: ' . $e->getMessage());
    }
}

/**
 * Get wallet information via WalletManager
 */
function getWalletInfo($walletManager, $address) {
    try {
        $walletInfo = $walletManager->getWalletInfo($address);
        $stats = $walletManager->getWalletStats($address);
        
        return [
            'wallet' => $walletInfo,
            'stats' => $stats
        ];
    } catch (Exception $e) {
        throw new Exception('Failed to get wallet info: ' . $e->getMessage());
    }
}

/**
 * Stake tokens via WalletManager
 */
function stakeTokens($walletManager, $address, $amount, $period, $privateKey) {
    try {
        $result = $walletManager->stake($address, $amount, $privateKey);
        
        if ($result) {
            // Fetch updated balances
            $availableBalance = $walletManager->getAvailableBalance($address);
            $stakedBalance = $walletManager->getStakedBalance($address);
            
            return [
                'staked' => [
                    'success' => true,
                    'amount' => $amount,
                    'period' => $period,
                    'new_balances' => [
                        'available' => $availableBalance,
                        'staked' => $stakedBalance,
                        'total' => $availableBalance + $stakedBalance
                    ]
                ]
            ];
        } else {
            throw new Exception('Staking operation failed');
        }
    } catch (Exception $e) {
        throw new Exception('Failed to stake tokens: ' . $e->getMessage());
    }
}

/**
 * Generate a new mnemonic phrase
 */
function generateMnemonic($walletManager) {
    try {
        $mnemonic = $walletManager->generateMnemonic();
        return [
            'mnemonic' => $mnemonic
        ];
    } catch (Exception $e) {
        throw new Exception('Failed to generate mnemonic: ' . $e->getMessage());
    }
}

/**
 * Create a wallet from a mnemonic phrase
 */
function createWalletFromMnemonic($walletManager, $blockchainManager, array $mnemonic) {
    try {
        writeLog("Creating wallet from mnemonic with blockchain integration", 'INFO');
        writeLog("Mnemonic word count: " . count($mnemonic), 'DEBUG');
        writeLog("Mnemonic words: " . implode(' ', $mnemonic), 'DEBUG');
        
        // 1. First, derive address from mnemonic WITHOUT creating wallet record
        writeLog("Deriving address from mnemonic to check if wallet already exists", 'DEBUG');
        try {
            // Use KeyPair to derive address without creating database record
            $keyPair = \Blockchain\Core\Cryptography\KeyPair::fromMnemonic($mnemonic);
            $derivedAddress = $keyPair->getAddress();
            writeLog("Address derived from mnemonic: " . $derivedAddress, 'DEBUG');
            
            // Check if this address already exists in database
            $existingWallet = $walletManager->getWalletInfo($derivedAddress);
            if ($existingWallet) {
                writeLog("Wallet already exists in database, rejecting create request", 'INFO');
                throw new Exception("Wallet with this mnemonic already exists. Please use 'Restore Wallet' instead of 'Create Wallet'. Address: " . $derivedAddress);
            }
            writeLog("Wallet does not exist in database, proceeding with creation", 'DEBUG');
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'already exists') !== false) {
                throw $e; // Re-throw our custom message
            }
            writeLog("Error deriving address from mnemonic: " . $e->getMessage(), 'ERROR');
            throw new Exception("Invalid mnemonic phrase: " . $e->getMessage());
        }
        
        // 2. Create wallet using WalletManager
        writeLog("Calling WalletManager::createWalletFromMnemonic", 'DEBUG');
        $walletData = $walletManager->createWalletFromMnemonic($mnemonic);
        writeLog("Wallet created from mnemonic: " . $walletData['address'], 'INFO');
        
        // 3. Record wallet creation in blockchain (non-blocking)
        writeLog("Recording wallet creation in blockchain", 'DEBUG');
        $blockchainRecorded = false;
        $blockchainError = null;
        try {
            $blockchainResult = $blockchainManager->createWalletWithBlockchain($walletData);
            $blockchainRecorded = $blockchainResult['blockchain_recorded'];
            writeLog("Blockchain recording result: " . json_encode($blockchainRecorded), 'INFO');
        } catch (Exception $blockchainException) {
            writeLog("Blockchain recording failed: " . $blockchainException->getMessage(), 'WARNING');
            $blockchainError = $blockchainException->getMessage();
            // Don't throw - wallet should still be created even if blockchain fails
        }
        
        // 4. Return combined result
        $result = [
            'wallet' => $walletData,
            'blockchain' => [
                'recorded' => $blockchainRecorded,
                'error' => $blockchainError,
                'transaction_hash' => isset($blockchainResult) ? ($blockchainResult['transaction']['hash'] ?? null) : null,
                'block_hash' => isset($blockchainResult) ? ($blockchainResult['block']['hash'] ?? null) : null,
                'block_height' => isset($blockchainResult) ? ($blockchainResult['block']['height'] ?? null) : null
            ]
        ];
        
        writeLog("Wallet creation from mnemonic completed successfully", 'INFO');
        return $result;
        
    } catch (Exception $e) {
        writeLog("Error creating wallet from mnemonic: " . $e->getMessage(), 'ERROR');
        writeLog("Exception trace: " . $e->getTraceAsString(), 'DEBUG');
        throw new Exception('Failed to create wallet from mnemonic: ' . $e->getMessage());
    }
}

/**
 * Validate a mnemonic phrase
 */
function validateMnemonic(array $mnemonic) {
    try {
        $isValid = \Blockchain\Core\Cryptography\Mnemonic::validate($mnemonic);
        return [
            'valid' => $isValid
        ];
    } catch (Exception $e) {
        throw new Exception('Failed to validate mnemonic: ' . $e->getMessage());
    }
}

/**
 * Restore a wallet from a mnemonic phrase
 */
function restoreWalletFromMnemonic($walletManager, $blockchainManager, array $mnemonic) {
    try {
        writeLog("Starting wallet restoration from mnemonic", 'INFO');
        writeLog("Mnemonic word count: " . count($mnemonic), 'DEBUG');
        writeLog("Mnemonic words: " . implode(' ', $mnemonic), 'DEBUG');
        
        // 1. Restore wallet using WalletManager
        writeLog("Calling WalletManager::restoreWalletFromMnemonic", 'DEBUG');
        $walletData = $walletManager->restoreWalletFromMnemonic($mnemonic);
        writeLog("Wallet restored: " . $walletData['address'] . " from: " . ($walletData['restored_from'] ?? 'unknown'), 'INFO');
        
    // 2. Check transaction history for additional context
        writeLog("Getting wallet transaction history", 'DEBUG');
        $transactionHistory = $blockchainManager->getWalletTransactionHistory($walletData['address']);
        writeLog("Verifying wallet in blockchain", 'DEBUG');
        $isVerified = $blockchainManager->verifyWalletInBlockchain($walletData['address']);
        
        writeLog("Wallet verification in blockchain: " . ($isVerified ? 'FOUND' : 'NOT_FOUND'), 'INFO');
        writeLog("Transaction history count: " . count($transactionHistory), 'INFO');
        
    // 3. If the wallet needs blockchain registration - register it
        $blockchainRegistered = false;
        $blockchainError = null;
        
        if (isset($walletData['needs_blockchain_registration']) && $walletData['needs_blockchain_registration'] && !$isVerified) {
            writeLog("Wallet needs blockchain registration, registering now", 'INFO');
            try {
                $blockchainResult = $blockchainManager->createWalletWithBlockchain($walletData);
                $blockchainRegistered = $blockchainResult['blockchain_recorded'];
                writeLog("Blockchain registration result: " . json_encode($blockchainRegistered), 'INFO');
            } catch (Exception $blockchainException) {
                writeLog("Blockchain registration failed: " . $blockchainException->getMessage(), 'WARNING');
                $blockchainError = $blockchainException->getMessage();
                // Don't throw - wallet is still restored in database
            }
        } else {
            $blockchainRegistered = $isVerified; // Already verified
        }
        
        // 4. Return result with blockchain registration status
        $result = [
            'wallet' => $walletData,
            'public_key' => $walletData['public_key'] ?? null,
            'private_key' => $walletData['private_key'] ?? null,
            'restored' => true,
            'blockchain' => [
                'registered' => $blockchainRegistered,
                'error' => $blockchainError,
                'was_already_verified' => $isVerified
            ],
            'verification' => [
                'exists_in_blockchain' => $blockchainRegistered,
                'transaction_count' => count($transactionHistory),
                'last_activity' => !empty($transactionHistory) ? $transactionHistory[0]['block_timestamp'] ?? null : null
            ],
            'note' => $blockchainRegistered ? 
                     'Wallet restored and registered in blockchain successfully.' : 
                     'Wallet restored in database. Blockchain registration may be needed.'
        ];
        
        writeLog("Wallet restoration completed successfully", 'INFO');
        return $result;
        
    } catch (Exception $e) {
        writeLog("Exception in restoreWalletFromMnemonic: " . $e->getMessage(), 'ERROR');
        writeLog("Exception trace: " . $e->getTraceAsString(), 'DEBUG');
        throw new Exception('Failed to restore wallet: ' . $e->getMessage());
    }
}

/**
 * Get configuration information
 */
function getConfigInfo(array $config, ?\Blockchain\Core\Config\NetworkConfig $networkConfig = null) {
    // Load settings from DB when available
    if ($networkConfig) {
        $tokenInfo = $networkConfig->getTokenInfo();
        $networkInfo = $networkConfig->getNetworkInfo();
        
        return [
            'config' => [
                'crypto_symbol' => $tokenInfo['symbol'],
                'crypto_name' => $tokenInfo['name'],
                'crypto_decimals' => $tokenInfo['decimals'],
                'initial_supply' => $tokenInfo['initial_supply'],
                'network_name' => $networkInfo['name'],
                'chain_id' => $networkInfo['chain_id'],
                'consensus_algorithm' => $networkInfo['consensus_algorithm'],
                'min_stake_amount' => $networkInfo['min_stake'],
                'block_time' => $networkInfo['block_time'],
                'protocol_version' => $networkInfo['protocol_version']
            ]
        ];
    }
    
    // Fallback to static configuration
    return [
        'config' => [
            'crypto_symbol' => $config['crypto']['symbol'] ?? 'COIN',
            'crypto_name' => $config['crypto']['name'] ?? 'Blockchain',
            'network' => $config['crypto']['network'] ?? 'mainnet'
        ]
    ];
}

/**
 * Synchronize binary blockchain to database
 */
function syncBinaryToDatabase($walletManager) {
    try {
        writeLog("Starting binary to database synchronization", 'INFO');
        
        $result = $walletManager->syncBinaryToDatabase();
        
        writeLog("Binary to database sync completed: {$result['exported']} exported, {$result['errors']} errors", 'INFO');
        
        return [
            'sync_result' => $result,
            'success' => $result['errors'] === 0
        ];
    } catch (Exception $e) {
        writeLog("Binary to database sync failed: " . $e->getMessage(), 'ERROR');
        throw new Exception('Failed to sync binary to database: ' . $e->getMessage());
    }
}

/**
 * Synchronize database to binary blockchain
 */
function syncDatabaseToBinary($walletManager) {
    try {
        writeLog("Starting database to binary synchronization", 'INFO');
        
        $result = $walletManager->syncDatabaseToBinary();
        
        writeLog("Database to binary sync completed: {$result['imported']} imported, {$result['errors']} errors", 'INFO');
        
        return [
            'sync_result' => $result,
            'success' => $result['errors'] === 0
        ];
    } catch (Exception $e) {
        writeLog("Database to binary sync failed: " . $e->getMessage(), 'ERROR');
        throw new Exception('Failed to sync database to binary: ' . $e->getMessage());
    }
}

/**
 * Validate blockchain integrity
 */
function validateBlockchain($walletManager) {
    try {
        writeLog("Starting blockchain validation", 'INFO');
        
        $validation = $walletManager->validateBlockchain();
        
        $status = $validation['valid'] ? 'valid' : 'invalid';
        writeLog("Blockchain validation completed: $status", $validation['valid'] ? 'INFO' : 'ERROR');
        
        return [
            'validation' => $validation,
            'is_valid' => $validation['valid'],
            'errors_count' => count($validation['errors'] ?? []),
            'warnings_count' => count($validation['warnings'] ?? [])
        ];
    } catch (Exception $e) {
        writeLog("Blockchain validation failed: " . $e->getMessage(), 'ERROR');
        throw new Exception('Failed to validate blockchain: ' . $e->getMessage());
    }
}

/**
 * Get comprehensive blockchain statistics
 */
function getBlockchainStats($walletManager) {
    try {
        writeLog("Retrieving blockchain statistics", 'DEBUG');
        
        $stats = $walletManager->getBlockchainStats();
        
        return [
            'stats' => $stats,
            'summary' => [
                'total_blocks' => $stats['binary']['total_blocks'] ?? 0,
                'total_transactions' => $stats['binary']['total_transactions'] ?? 0,
                'blockchain_size' => $stats['binary']['size_formatted'] ?? '0 B',
                'database_blocks' => $stats['database']['blocks'] ?? 0,
                'database_transactions' => $stats['database']['transactions'] ?? 0,
                'database_wallets' => $stats['database']['wallets'] ?? 0
            ]
        ];
    } catch (Exception $e) {
        writeLog("Failed to get blockchain stats: " . $e->getMessage(), 'ERROR');
        throw new Exception('Failed to get blockchain statistics: ' . $e->getMessage());
    }
}

/**
 * Create blockchain backup
 */
function createBackup($walletManager, ?string $customPath = null) {
    try {
        $backupPath = $customPath ?? dirname(__DIR__) . '/storage/backups/blockchain_backup_' . date('Y-m-d_H-i-s') . '.zip';
        
        writeLog("Creating blockchain backup at: $backupPath", 'INFO');
        
        // Ensure backup directory exists
        $backupDir = dirname($backupPath);
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        
        $success = $walletManager->createBackup($backupPath);
        
        if ($success) {
            $backupSize = file_exists($backupPath) ? filesize($backupPath) : 0;
            writeLog("Backup created successfully: " . number_format($backupSize) . " bytes", 'INFO');
            
            return [
                'backup_created' => true,
                'backup_path' => $backupPath,
                'backup_size' => $backupSize,
                'backup_size_formatted' => formatBytes($backupSize)
            ];
        } else {
            throw new Exception('Backup creation returned false');
        }
        
    } catch (Exception $e) {
        writeLog("Backup creation failed: " . $e->getMessage(), 'ERROR');
        throw new Exception('Failed to create backup: ' . $e->getMessage());
    }
}

/**
 * Format bytes to human readable format
 */
function formatBytes(int $bytes): string {
    if ($bytes === 0) return '0 B';
    
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $factor = floor(log($bytes, 1024));
    return sprintf('%.1f %s', $bytes / (1024 ** $factor), $units[$factor] ?? 'TB');
}

/**
 * Get wallet transaction history from blockchain
 */
function getWalletTransactionHistory($blockchainManager, string $address) {
    try {
        writeLog("Getting transaction history for wallet: " . $address, 'INFO');
        
        $transactions = $blockchainManager->getWalletTransactionHistory($address);
        
        writeLog("Found " . count($transactions) . " transactions for wallet: " . $address, 'INFO');
        
        return [
            'address' => $address,
            'transactions' => $transactions,
            'transaction_count' => count($transactions)
        ];
    } catch (Exception $e) {
        writeLog("Error getting wallet transaction history: " . $e->getMessage(), 'ERROR');
        throw new Exception('Failed to get transaction history: ' . $e->getMessage());
    }
}

/**
 * Verify wallet exists in blockchain
 */
function verifyWalletInBlockchain($blockchainManager, string $address) {
    try {
        writeLog("Verifying wallet in blockchain: " . $address, 'INFO');
        
        $exists = $blockchainManager->verifyWalletInBlockchain($address);
        
        writeLog("Wallet verification result for " . $address . ": " . ($exists ? 'EXISTS' : 'NOT_FOUND'), 'INFO');
        
        return [
            'address' => $address,
            'exists_in_blockchain' => $exists,
            'verified' => $exists
        ];
    } catch (Exception $e) {
        writeLog("Error verifying wallet in blockchain: " . $e->getMessage(), 'ERROR');
        throw new Exception('Failed to verify wallet: ' . $e->getMessage());
    }
}

/**
 * Get comprehensive wallet information from both database and blockchain
 */
function getBlockchainWalletInfo($blockchainManager, $walletManager, string $address) {
    try {
        writeLog("Getting comprehensive wallet info for: " . $address, 'INFO');
        
        // Get wallet info from database
        $walletInfo = $walletManager->getWalletInfo($address);
        
        // Get balance from wallet manager
        $balance = $walletManager->getBalance($address);
        $availableBalance = $walletManager->getAvailableBalance($address);
        $stakedBalance = $walletManager->getStakedBalance($address);
        
        // Verify wallet in blockchain
        $blockchainExists = $blockchainManager->verifyWalletInBlockchain($address);
        
        // Get transaction history
        $transactions = $blockchainManager->getWalletTransactionHistory($address);
        
        writeLog("Comprehensive wallet info gathered for: " . $address, 'INFO');
        
        return [
            'address' => $address,
            'database_info' => $walletInfo,
            'balances' => [
                'total' => $balance,
                'available' => $availableBalance,
                'staked' => $stakedBalance
            ],
            'blockchain' => [
                'exists' => $blockchainExists,
                'transaction_count' => count($transactions),
                'recent_transactions' => array_slice($transactions, 0, 5) // Last 5 transactions
            ],
            'synchronized' => $walletInfo && $blockchainExists
        ];
    } catch (Exception $e) {
        writeLog("Error getting comprehensive wallet info: " . $e->getMessage(), 'ERROR');
        throw new Exception('Failed to get wallet info: ' . $e->getMessage());
    }
}

/**
 * Activate a restored wallet in the blockchain
 */
function activateRestoredWallet($walletManager, $blockchainManager, string $address, string $publicKey) {
    try {
        writeLog("Activating restored wallet in blockchain: $address", 'INFO');
        
    // Ensure the wallet has actually been restored
        writeLog("Checking if wallet exists in database", 'DEBUG');
        $walletInfo = $walletManager->getWalletInfo($address);
        writeLog("Wallet info check result: " . ($walletInfo ? 'FOUND' : 'NOT_FOUND'), 'DEBUG');
        
        if (!$walletInfo) {
            // Attempt automatic restoration if the wallet exists on the blockchain
            writeLog("Wallet not found in database, checking blockchain", 'INFO');
            $blockchainBalance = $walletManager->calculateBalanceFromBlockchain($address);
            $stakedBalance = $walletManager->calculateStakedBalanceFromBlockchain($address);
            
            writeLog("Blockchain balances - Available: $blockchainBalance, Staked: $stakedBalance", 'INFO');
            
            if ($blockchainBalance > 0 || $stakedBalance > 0) {
                // Wallet exists on blockchain but not in DB - restore the record
                writeLog("Wallet found in blockchain but not in database, creating record", 'INFO');
                
                $stmt = $walletManager->getDatabase()->prepare("
                    INSERT INTO wallets (address, public_key, balance, staked_balance, created_at, updated_at) 
                    VALUES (?, ?, ?, ?, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE 
                    public_key = VALUES(public_key),
                    balance = VALUES(balance),
                    staked_balance = VALUES(staked_balance),
                    updated_at = NOW()
                ");
                
                $stmt->execute([$address, $publicKey, $blockchainBalance, $stakedBalance]);
                writeLog("Wallet record created/updated in database", 'INFO');
                
                // Re-fetch wallet information
                $walletInfo = $walletManager->getWalletInfo($address);
                writeLog("Wallet info after creation: " . ($walletInfo ? 'FOUND' : 'STILL_NOT_FOUND'), 'DEBUG');
            } else {
                writeLog("Wallet not found in blockchain either", 'ERROR');
                throw new Exception('Wallet not found in database or blockchain. Please restore it first.');
            }
        }
        
        if (!$walletInfo) {
            throw new Exception('Wallet not found. Please restore it first.');
        }
        
    // Check if it's already activated
        $isInBlockchain = $blockchainManager->verifyWalletInBlockchain($address);
        if ($isInBlockchain) {
            return [
                'already_active' => true,
                'message' => 'Wallet is already active in blockchain',
                'address' => $address
            ];
        }
        
    // IMPORTANT: Recalculate balance from blockchain before activation
        $blockchainBalance = $walletManager->calculateBalanceFromBlockchain($address);
        $stakedBalance = $walletManager->calculateStakedBalanceFromBlockchain($address);
        
        writeLog("Calculated balances - Available: $blockchainBalance, Staked: $stakedBalance", 'INFO');
        
    // Update wallet balance
        writeLog("Updating wallet balances in database", 'INFO');
        if ($blockchainBalance > 0) {
            $walletManager->updateBalance($address, $blockchainBalance);
            writeLog("Updated available balance to: $blockchainBalance", 'INFO');
        }
        if ($stakedBalance > 0) {
            $walletManager->updateStakedBalance($address, $stakedBalance);
            writeLog("Updated staked balance to: $stakedBalance", 'INFO');
        }
        
    // Create activation transaction with correct balance
        $walletData = [
            'address' => $address,
            'public_key' => $publicKey,
            'balance' => $blockchainBalance,
            'staked_balance' => $stakedBalance,
            'restored' => true
        ];
        
        writeLog("Created wallet data for blockchain activation: " . json_encode($walletData), 'INFO');
        
    // Record activation on the blockchain (without changing balance)
        writeLog("Starting blockchain activation process", 'INFO');
        try {
            $blockchainResult = $blockchainManager->createWalletWithBlockchain($walletData);
            writeLog("Blockchain activation completed successfully", 'INFO');
        } catch (Exception $blockchainError) {
            writeLog("Blockchain activation failed: " . $blockchainError->getMessage(), 'ERROR');
            writeLog("Blockchain error trace: " . $blockchainError->getTraceAsString(), 'ERROR');
            throw $blockchainError;
        }
        
        writeLog("Wallet activated in blockchain with balance: $blockchainBalance", 'INFO');
        
        return [
            'activated' => true,
            'address' => $address,
            'balance' => $blockchainBalance,
            'staked_balance' => $stakedBalance,
            'blockchain' => [
                'recorded' => $blockchainResult['blockchain_recorded'],
                'transaction_hash' => $blockchainResult['transaction']['hash'] ?? null,
                'block_hash' => $blockchainResult['block']['hash'] ?? null,
                'block_height' => $blockchainResult['block']['height'] ?? null
            ],
            'message' => 'Wallet successfully activated in blockchain'
        ];
        
    } catch (Exception $e) {
        writeLog("Error activating restored wallet: " . $e->getMessage(), 'ERROR');
        throw new Exception('Failed to activate wallet: ' . $e->getMessage());
    }
}

/**
 * Transfer tokens between wallets with blockchain recording
 */
function transferTokens($walletManager, $blockchainManager, string $fromAddress, string $toAddress, float $amount, string $privateKey, string $memo = '') {
    try {
        writeLog("Starting token transfer: $fromAddress -> $toAddress, amount: $amount", 'INFO');
        
        // 0. Check and cleanup any active transactions
        $pdo = $walletManager->getDatabase();
        try {
            // Try to check if transaction is active
            if ($pdo->inTransaction()) {
                writeLog("Found active PDO transaction, rolling back to start fresh", 'WARNING');
                $pdo->rollBack();
            }
        } catch (Exception $e) {
            writeLog("PDO transaction state check failed: " . $e->getMessage(), 'DEBUG');
            // Continue anyway, error might be that there's no transaction
        }
        
        // 1. Validate addresses
        if ($fromAddress === $toAddress) {
            throw new Exception('Cannot transfer to the same address');
        }
        
        // 2. Check sender balance
        $senderBalance = $walletManager->getAvailableBalance($fromAddress);
        if ($senderBalance < $amount) {
            throw new Exception("Insufficient balance. Available: $senderBalance, Required: $amount");
        }
        
        // 3. Verify private key (simplified - in production use proper cryptographic verification)
        if (strlen($privateKey) < 32) {
            throw new Exception('Invalid private key format');
        }
        
        // 4. Handle memo encryption
        $finalMemo = '';
        $encryptedData = null;
        if (!empty($memo)) {
            try {
                // Check memo length
                if (strlen($memo) > 1000) {
                    throw new Exception('Message is too long. Maximum 1000 characters allowed.');
                }
                
                // Get recipient public key for encryption
                $recipientWallet = $walletManager->getWalletByAddress($toAddress);
                writeLog("Looking for recipient wallet: $toAddress", 'INFO');
                writeLog("Recipient wallet found: " . ($recipientWallet ? 'YES' : 'NO'), 'INFO');
                writeLog("Recipient wallet data: " . json_encode($recipientWallet), 'DEBUG');
                
                if ($recipientWallet && !empty($recipientWallet['public_key'])) {
                    writeLog("Recipient has public key, proceeding with encryption", 'INFO');
                    writeLog("Recipient public key: " . $recipientWallet['public_key'], 'DEBUG');
                    
                    // Encrypt messages using MessageEncryption with secp256k1 keys
                    $encryptedData = \Blockchain\Core\Cryptography\MessageEncryption::createSecureMessage(
                        $memo, 
                        $recipientWallet['public_key'], 
                        $privateKey
                    );
                    writeLog("Message encrypted and signed for recipient using ECIES", 'INFO');
                } else {
                    // Recipient wallet not found or no public key - this is a security issue
                    throw new Exception("Recipient public key not found. Cannot encrypt message for address: $toAddress. All messages must be encrypted.");
                }
            } catch (Exception $e) {
                writeLog("Encryption failed: " . $e->getMessage(), 'ERROR');
                throw $e; // Re-throw to maintain security
            }
        }
        
        // 5. Check if recipient wallet exists, create if needed
        $recipientInfo = $walletManager->getWalletInfo($toAddress);
        if (!$recipientInfo) {
            writeLog("Recipient wallet not found in database, checking if we can create it", 'WARNING');
            
            // Try to create recipient wallet entry if we have enough information
            // This can happen when someone restores a wallet but it wasn't properly saved
            
            // We can derive the public key from the address if needed, but for now
            // we'll require the recipient to be properly registered
            throw new Exception('Recipient wallet not found. Please ensure the recipient has created their wallet first.');
        }
        
        // 5. Create transfer transaction
        $transferTx = [
            'hash' => hash('sha256', 'transfer_' . $fromAddress . '_' . $toAddress . '_' . $amount . '_' . time()),
            'type' => 'transfer',
            'from' => $fromAddress,
            'to' => $toAddress,
            'amount' => $amount,
            'fee' => $amount * 0.001, // 0.1% fee
            'timestamp' => time(),
            'data' => [
                'action' => 'transfer_tokens',
                'memo' => $finalMemo, // This will be empty if message was encrypted
                'transfer_type' => 'wallet_to_wallet',
                'original_memo_length' => strlen($memo)
            ],
            'signature' => hash_hmac('sha256', $fromAddress . $toAddress . $amount, $privateKey),
            'status' => 'pending'
        ];
        
        // Add encrypted data to the transaction data if available
        if ($encryptedData !== null) {
            $transferTx['data']['memo'] = $encryptedData; // Store the full encrypted message structure
            $transferTx['data']['encrypted'] = true; // Mark explicitly only when encrypted
        }
        
        // 6. Update balances in database
        $pdo = $walletManager->getDatabase();
        
        // Ensure we can start a new transaction
        if ($pdo->inTransaction()) {
            writeLog("PDO already in transaction, committing previous transaction", 'WARNING');
            try {
                $pdo->commit();
            } catch (Exception $e) {
                writeLog("Failed to commit previous transaction: " . $e->getMessage(), 'WARNING');
                try {
                    $pdo->rollBack();
                } catch (Exception $e2) {
                    writeLog("Failed to rollback previous transaction: " . $e2->getMessage(), 'ERROR');
                }
            }
        }
        
        $pdo->beginTransaction();
        
        try {
            // Deduct from sender
            $stmt = $pdo->prepare("UPDATE wallets SET balance = balance - ?, updated_at = NOW() WHERE address = ?");
            $stmt->execute([$amount + $transferTx['fee'], $fromAddress]);
            
            // Add to recipient
            $stmt = $pdo->prepare("UPDATE wallets SET balance = balance + ?, updated_at = NOW() WHERE address = ?");
            $stmt->execute([$amount, $toAddress]);
            
            $pdo->commit();
            writeLog("Database balances updated successfully", 'INFO');
            
        } catch (Exception $e) {
            try {
                $pdo->rollback();
            } catch (Exception $rollbackError) {
                writeLog("Failed to rollback transaction: " . $rollbackError->getMessage(), 'ERROR');
            }
            throw new Exception('Failed to update balances: ' . $e->getMessage());
        }
        
        // 7. Record in blockchain
        $blockchainResult = $blockchainManager->recordTransactionInBlockchain($transferTx);
        
        // 8. Update transaction status
        $transferTx['status'] = 'confirmed';
        
        writeLog("Token transfer completed successfully", 'INFO');
        
        return [
            'transaction' => $transferTx,
            'blockchain' => $blockchainResult,
            'new_balances' => [
                'sender' => $walletManager->getBalance($fromAddress),
                'recipient' => $walletManager->getBalance($toAddress)
            ]
        ];
        
    } catch (Exception $e) {
        // Ensure any pending transaction is rolled back
        try {
            $pdo = $walletManager->getDatabase();
            if ($pdo->inTransaction()) {
                writeLog("Rolling back transaction due to error", 'INFO');
                $pdo->rollBack();
            }
        } catch (Exception $cleanupError) {
            writeLog("Error during cleanup: " . $cleanupError->getMessage(), 'ERROR');
        }
        
        writeLog("Error in token transfer: " . $e->getMessage(), 'ERROR');
        throw new Exception('Transfer failed: ' . $e->getMessage());
    }
}

/**
 * Stake tokens with blockchain recording
 */
function stakeTokensWithBlockchain($walletManager, $blockchainManager, string $address, float $amount, int $period, string $privateKey) {
    try {
        writeLog("Starting token staking: address=$address, amount=$amount, period=$period", 'INFO');
        
        // 0. Check and cleanup any active transactions
        $pdo = $walletManager->getDatabase();
        try {
            // Try to check if transaction is active
            if ($pdo->inTransaction()) {
                writeLog("Found active PDO transaction, rolling back to start fresh", 'WARNING');
                $pdo->rollBack();
            }
        } catch (Exception $e) {
            writeLog("PDO transaction state check failed: " . $e->getMessage(), 'DEBUG');
            // Continue anyway, error might be that there's no transaction
        }
        
        // 1. Check available balance
        $availableBalance = $walletManager->getAvailableBalance($address);
        if ($availableBalance < $amount) {
            throw new Exception("Insufficient balance for staking. Available: $availableBalance, Required: $amount");
        }
        
        // 2. Validate staking parameters
        $minStakeAmount = 100; // Minimum stake amount
        if ($amount < $minStakeAmount) {
            throw new Exception("Minimum staking amount is $minStakeAmount tokens");
        }
        
        if ($period < 7 || $period > 365) {
            throw new Exception("Staking period must be between 7 and 365 days");
        }
        
        // 3. Calculate staking rewards (APY based on period)
        $apy = calculateStakingAPY($period);
        $expectedRewards = $amount * ($apy / 100) * ($period / 365);
        
        // 4. Ensure staking smart contract exists (deploy on first use)
        $pdo = $walletManager->getDatabase();
        $stakingContract = getOrDeployStakingContract($pdo, $address);
        if (!$stakingContract) {
            throw new Exception('Failed to deploy or obtain staking contract address');
        }

        // 5. Create staking transaction
        $stakeTx = [
            'hash' => hash('sha256', 'stake_' . $address . '_' . $amount . '_' . time()),
            'type' => 'stake',
            'from' => $address,
            // Use real staking contract address
            'to' => $stakingContract,
            'amount' => $amount,
            'fee' => 0, // No fee for staking
            'timestamp' => time(),
            'data' => [
                'action' => 'stake_tokens',
                'period_days' => $period,
                'apy' => $apy,
                'expected_rewards' => $expectedRewards,
                'unlock_date' => time() + ($period * 24 * 60 * 60),
                'stake_type' => 'fixed_term'
            ],
            'signature' => hash_hmac('sha256', $address . 'stake' . $amount, $privateKey),
            'status' => 'pending'
        ];
        
        // 6. Update balances in database
        $pdo = $walletManager->getDatabase();
        
        // Ensure we can start a new transaction
        if ($pdo->inTransaction()) {
            writeLog("PDO already in transaction, committing previous transaction", 'WARNING');
            try {
                $pdo->commit();
            } catch (Exception $e) {
                writeLog("Failed to commit previous transaction: " . $e->getMessage(), 'WARNING');
                try {
                    $pdo->rollBack();
                } catch (Exception $e2) {
                    writeLog("Failed to rollback previous transaction: " . $e2->getMessage(), 'ERROR');
                }
            }
        }
        
        $pdo->beginTransaction();
        
        try {
            // Move from available to staked balance
            $stmt = $pdo->prepare("UPDATE wallets SET balance = balance - ?, staked_balance = staked_balance + ?, updated_at = NOW() WHERE address = ?");
            $stmt->execute([$amount, $amount, $address]);
            
            // Record staking details
            $stmt = $pdo->prepare("
                INSERT INTO staking (validator, staker, amount, reward_rate, start_block, rewards_earned, status) 
                VALUES (?, ?, ?, ?, ?, ?, 'active')
            ");
            $stmt->execute([$address, $address, $amount, $apy/100, 0, $expectedRewards]);
            
            $pdo->commit();
            writeLog("Staking record created successfully", 'INFO');
            
        } catch (Exception $e) {
            try {
                $pdo->rollback();
            } catch (Exception $rollbackError) {
                writeLog("Failed to rollback transaction: " . $rollbackError->getMessage(), 'ERROR');
            }
            throw new Exception('Failed to create staking record: ' . $e->getMessage());
        }
        
        // 7. Record in blockchain
        $blockchainResult = $blockchainManager->recordTransactionInBlockchain($stakeTx);
        
        // 8. Update transaction status
        $stakeTx['status'] = 'confirmed';
        
        writeLog("Token staking completed successfully", 'INFO');
        
        return [
            'transaction' => $stakeTx,
            'blockchain' => $blockchainResult,
            'staking_info' => [
                'amount' => $amount,
                'period' => $period,
                'apy' => $apy,
                'expected_rewards' => $expectedRewards,
                'unlock_date' => date('Y-m-d H:i:s', $stakeTx['data']['unlock_date'])
            ],
            'staking_contract' => $stakingContract,
            'new_balance' => $walletManager->getBalance($address)
        ];
        
    } catch (Exception $e) {
        // Ensure any pending transaction is rolled back
        try {
            $pdo = $walletManager->getDatabase();
            if ($pdo->inTransaction()) {
                writeLog("Rolling back staking transaction due to error", 'INFO');
                $pdo->rollBack();
            }
        } catch (Exception $cleanupError) {
            writeLog("Error during staking cleanup: " . $cleanupError->getMessage(), 'ERROR');
        }
        
        writeLog("Error in token staking: " . $e->getMessage(), 'ERROR');
        throw new Exception('Staking failed: ' . $e->getMessage());
    }
}

/**
 * Calculate staking APY based on period
 */
function calculateStakingAPY(int $periodDays): float {
    // Longer periods get better rates
    if ($periodDays >= 365) return 12.0; // 12% APY for 1+ year
    if ($periodDays >= 180) return 10.0; // 10% APY for 6+ months
    if ($periodDays >= 90) return 8.0;   // 8% APY for 3+ months
    if ($periodDays >= 30) return 6.0;   // 6% APY for 1+ month
    return 4.0; // 4% APY for less than 1 month
}

/**
 * Unstake tokens
 */
function unstakeTokens($walletManager, $blockchainManager, string $address, float $amount, string $privateKey) {
    try {
        writeLog("Starting token unstaking: address=$address, amount=$amount", 'INFO');
        
        // 1. Get staking records
        $pdo = $walletManager->getDatabase();
        $stmt = $pdo->prepare("
            SELECT * 
            FROM staking 
            WHERE staker = ? AND status = 'active' 
            AND (end_block IS NOT NULL OR status = 'pending_withdrawal')
            ORDER BY created_at ASC
        ");
        $stmt->execute([$address]);
        $stakingRecords = $stmt->fetchAll();
        
        if (empty($stakingRecords)) {
            throw new Exception('No unlocked staking records found');
        }
        
        // 2. Calculate available amount to unstake
        $availableToUnstake = array_sum(array_column($stakingRecords, 'amount'));
        if ($amount > $availableToUnstake) {
            throw new Exception("Insufficient staked amount. Available: $availableToUnstake, Requested: $amount");
        }
        
        // 3. Calculate rewards
        $totalRewards = 0;
        $amountRemaining = $amount;
        $recordsToProcess = [];
        
        foreach ($stakingRecords as $record) {
            if ($amountRemaining <= 0) break;
            
            $recordAmount = min($record['amount'], $amountRemaining);
            $recordRewards = $record['rewards_earned'] * ($recordAmount / $record['amount']);
            
            $recordsToProcess[] = [
                'id' => $record['id'],
                'amount' => $recordAmount,
                'rewards' => $recordRewards
            ];
            
            $totalRewards += $recordRewards;
            $amountRemaining -= $recordAmount;
        }
        
        // 4. Ensure staking smart contract exists (needed for from-address)
        $pdo = $walletManager->getDatabase();
        $stakingContract = getOrDeployStakingContract($pdo, $address);

        // 5. Create unstaking transaction
        $unstakeTx = [
            'hash' => hash('sha256', 'unstake_' . $address . '_' . $amount . '_' . time()),
            'type' => 'unstake',
            // Use real staking contract address
            'from' => $stakingContract,
            'to' => $address,
            'amount' => $amount + $totalRewards,
            'fee' => 0,
            'timestamp' => time(),
            'data' => [
                'action' => 'unstake_tokens',
                'principal' => $amount,
                'rewards' => $totalRewards,
                'records_processed' => count($recordsToProcess)
            ],
            'signature' => hash_hmac('sha256', $address . 'unstake' . $amount, $privateKey),
            'status' => 'pending'
        ];
        
        // 6. Update database
        $pdo->beginTransaction();
        
        try {
            // Update wallet balances
            $stmt = $pdo->prepare("UPDATE wallets SET balance = balance + ?, staked_balance = staked_balance - ?, updated_at = NOW() WHERE address = ?");
            $stmt->execute([$amount + $totalRewards, $amount, $address]);
            
            // Update staking records
            foreach ($recordsToProcess as $processRecord) {
                $stmt = $pdo->prepare("UPDATE staking SET status = 'withdrawn', updated_at = NOW() WHERE id = ?");
                $stmt->execute([$processRecord['id']]);
            }
            
            $pdo->commit();
            writeLog("Unstaking completed successfully", 'INFO');
            
        } catch (Exception $e) {
            $pdo->rollback();
            throw new Exception('Failed to process unstaking: ' . $e->getMessage());
        }
        
    // 7. Record in blockchain
        $blockchainResult = $blockchainManager->recordTransactionInBlockchain($unstakeTx);
        
        return [
            'transaction' => $unstakeTx,
            'blockchain' => $blockchainResult,
            'unstaked_amount' => $amount,
            'rewards_earned' => $totalRewards,
            'total_received' => $amount + $totalRewards,
            'new_balance' => $walletManager->getBalance($address)
        ];
        
    } catch (Exception $e) {
        writeLog("Error in token unstaking: " . $e->getMessage(), 'ERROR');
        throw new Exception('Unstaking failed: ' . $e->getMessage());
    }
}

/**
 * Get staking information for a wallet
 */
function getStakingInfo($walletManager, string $address) {
    try {
        writeLog("Getting staking info for: " . $address, 'INFO');
        
        $pdo = $walletManager->getDatabase();
        
        // Get active and pending withdrawal staking records
        $stmt = $pdo->prepare("
            SELECT *, 
                   amount as total_staked,
                   CASE 
                       WHEN status = 'active' THEN 'active'
                       WHEN status = 'pending_withdrawal' THEN 'pending'
                       ELSE 'completed'
                   END as lock_status,
                   COALESCE(rewards_earned, 0) as current_rewards,
                   reward_rate as apy,
                   start_block,
                   end_block
            FROM staking 
            WHERE staker = ? AND status IN ('active', 'pending_withdrawal')
            ORDER BY created_at DESC
        ");
        $stmt->execute([$address]);
        $activeStakes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get completed staking history
        $stmt = $pdo->prepare("
            SELECT * FROM staking 
            WHERE staker = ? AND status = 'withdrawn'
            ORDER BY updated_at DESC
            LIMIT 10
        ");
        $stmt->execute([$address]);
        $completedStakes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate totals
        $totalStaked = 0;
        $totalRewardsEarning = 0;
        $unlockedAmount = 0;
        
        if (is_array($activeStakes) && !empty($activeStakes)) {
            foreach ($activeStakes as $stake) {
                $stakeAmount = (float)($stake['amount'] ?? 0);
                $stakeRewards = (float)($stake['rewards_earned'] ?? 0);
                
                $totalStaked += $stakeAmount;
                $totalRewardsEarning += $stakeRewards;
                
                // Check if stake is unlocked (has end_block set or is pending withdrawal)
                if ($stake['status'] === 'pending_withdrawal' || 
                    (!empty($stake['end_block']) && $stake['end_block'] > 0)) {
                    $unlockedAmount += $stakeAmount + $stakeRewards;
                    $stake['lock_status'] = 'unlocked';
                }
            }
        }
        
        return [
            'staking_info' => [
                'address' => $address,
                'total_staked' => (float) $totalStaked,
                'total_rewards_earning' => (float) $totalRewardsEarning,
                'unlocked_amount' => (float) $unlockedAmount,
                'active_stakes' => $activeStakes ?: [],
                'completed_stakes' => $completedStakes ?: [],
                'staking_available' => (float) $walletManager->getAvailableBalance($address),
                'has_active_stakes' => !empty($activeStakes),
                'stake_count' => count($activeStakes ?: [])
            ]
        ];
        
    } catch (Exception $e) {
        writeLog("Error getting staking info: " . $e->getMessage(), 'ERROR');
        throw new Exception('Failed to get staking info: ' . $e->getMessage());
    }
}

/**
 * Decrypt an encrypted message
 */
function decryptMessage(string $encryptedMessage, string $privateKey, string $senderPublicKey = '') {
    try {
        // For new format, expect the encrypted message to be a JSON object
        $secureMessage = json_decode($encryptedMessage, true);
        
        if (!$secureMessage || !isset($secureMessage['encrypted_data'])) {
            return [
                'success' => true,
                'decrypted' => false,
                'message' => $encryptedMessage,
                'error' => 'Message is not encrypted or in old format'
            ];
        }
        
        // Decrypt message using new format
        if (!empty($senderPublicKey)) {
            // Use full verification if sender public key is provided
            $decryptedMessage = \Blockchain\Core\Cryptography\MessageEncryption::decryptSecureMessage(
                $secureMessage, 
                $privateKey, 
                $senderPublicKey
            );
            $verified = true;
        } else {
            // Use ECIES-only decryption without signature verification
            $decryptedMessage = \Blockchain\Core\Cryptography\MessageEncryption::decryptSecureMessageNoVerify(
                $secureMessage, 
                $privateKey
            );
            $verified = false;
        }
        
        return [
            'success' => true,
            'decrypted' => true,
            'message' => $decryptedMessage,
            'verified' => $verified
        ];
        
    } catch (Exception $e) {
        writeLog("Message decryption failed: " . $e->getMessage(), 'ERROR');
        return [
            'success' => false,
            'error' => 'Failed to decrypt message: ' . $e->getMessage()
        ];
    }
}

/**
 * Get transaction history for a wallet
 */
function getTransactionHistory($walletManager, string $address) {
    try {
        $transactions = $walletManager->getTransactionHistory($address);
        
        return [
            'success' => true,
            'transactions' => $transactions,
            'count' => count($transactions)
        ];
        
    } catch (Exception $e) {
        writeLog("Error getting transaction history: " . $e->getMessage(), 'ERROR');
        return [
            'success' => false,
            'error' => 'Failed to get transaction history: ' . $e->getMessage()
        ];
    }
}

/**
 * Decrypt transaction message
 */
function decryptTransactionMessage($walletManager, string $txHash, string $walletAddress, string $privateKey) {
    try {
        // Get transaction details
        $transaction = $walletManager->getTransactionByHash($txHash);
        
        if (!$transaction) {
            throw new Exception('Transaction not found');
        }
        
        // Verify wallet involvement
        if ($transaction['from_address'] !== $walletAddress && $transaction['to_address'] !== $walletAddress) {
            throw new Exception('Access denied: wallet not involved in this transaction');
        }

        // Memo can be string (legacy) or structured array with encrypted_data (new)
        $memo = $transaction['memo'] ?? '';

        if ($memo === '' || $memo === null) {
            return [
                'success' => true,
                'decrypted' => false,
                'message' => 'No message in this transaction'
            ];
        }

        // New format: structured encrypted memo as array/object
        if (is_array($memo) && isset($memo['encrypted_data'])) {
            // Get sender public key for verification
            $senderAddress = $transaction['from_address'];
            $senderWallet = $walletManager->getWalletByAddress($senderAddress);
            $senderPublicKey = $senderWallet['public_key'] ?? '';

            try {
                // Prefer full verification if sender public key is available
                if (!empty($senderPublicKey)) {
                    $decrypted = \Blockchain\Core\Cryptography\MessageEncryption::decryptSecureMessage(
                        $memo,
                        $privateKey,
                        $senderPublicKey
                    );
                    return [
                        'success' => true,
                        'decrypted' => true,
                        'message' => $decrypted,
                        'verified' => true
                    ];
                }

                // Fallback: decrypt without signature verification
                $decrypted = \Blockchain\Core\Cryptography\MessageEncryption::decryptSecureMessageNoVerify(
                    $memo,
                    $privateKey
                );
                return [
                    'success' => true,
                    'decrypted' => true,
                    'message' => $decrypted,
                    'verified' => false
                ];
            } catch (Exception $e) {
                writeLog("Structured memo decryption failed: " . $e->getMessage(), 'ERROR');
                return [
                    'success' => false,
                    'error' => 'Failed to decrypt structured message: ' . $e->getMessage()
                ];
            }
        }

        // Legacy format: JSON string or ENCRYPTED: prefix string
        if (is_string($memo)) {
            // If memo is JSON string, try decryptMessage helper which handles new format JSON
            $maybeJson = trim($memo);
            $isJsonLike = strlen($maybeJson) > 0 && ($maybeJson[0] === '{' || $maybeJson[0] === '[');

            if ($isJsonLike || str_starts_with($maybeJson, 'ENCRYPTED:')) {
                $senderAddress = $transaction['from_address'];
                $senderWallet = $walletManager->getWalletByAddress($senderAddress);
                $senderPublicKey = $senderWallet['public_key'] ?? '';

                $decryptResult = decryptMessage($maybeJson, $privateKey, $senderPublicKey);
                if ($decryptResult['success'] && ($decryptResult['decrypted'] ?? false)) {
                    return [
                        'success' => true,
                        'decrypted' => true,
                        'message' => $decryptResult['message'],
                        'verified' => $decryptResult['verified'] ?? false
                    ];
                }

                // If helper says not encrypted, treat it as plain memo (unlikely for strict server policy)
                return $decryptResult;
            }

            // Plain text memo (legacy, but our transfer API should not produce this for new transfers)
            return [
                'success' => true,
                'decrypted' => false,
                'message' => $memo
            ];
        }

        // Unknown memo format
        return [
            'success' => false,
            'error' => 'Unsupported memo format'
        ];
        
    } catch (Exception $e) {
        writeLog("Error decrypting transaction message: " . $e->getMessage(), 'ERROR');
        return [
            'success' => false,
            'error' => 'Failed to decrypt transaction message: ' . $e->getMessage()
        ];
    }
}

/**
 * Delete wallet from database
 */
function deleteWallet($walletManager, string $address) {
    try {
        writeLog("Deleting wallet: " . $address, 'INFO');
        
        $pdo = $walletManager->getDatabase();
        
        // Check if wallet exists
        $walletInfo = $walletManager->getWalletInfo($address);
        if (!$walletInfo) {
            throw new Exception('Wallet not found');
        }
        
        // Check if wallet has any balance or active staking
        $availableBalance = $walletManager->getAvailableBalance($address);
        
        $stmt = $pdo->prepare("SELECT COUNT(*) as active_stakes FROM staking WHERE staker = ? AND status = 'active'");
        $stmt->execute([$address]);
        $activeStakes = $stmt->fetchColumn();
        
        if ($availableBalance > 0) {
            throw new Exception('Cannot delete wallet with available balance. Please transfer funds first.');
        }
        
        if ($activeStakes > 0) {
            throw new Exception('Cannot delete wallet with active staking. Please unstake first.');
        }
        
        $pdo->beginTransaction();
        
        try {
            // Delete completed staking records
            $stmt = $pdo->prepare("DELETE FROM staking WHERE staker = ?");
            $stmt->execute([$address]);
            
            // Delete transaction history
            $stmt = $pdo->prepare("DELETE FROM transactions WHERE from_address = ? OR to_address = ?");
            $stmt->execute([$address, $address]);
            
            // Delete wallet
            $stmt = $pdo->prepare("DELETE FROM wallets WHERE address = ?");
            $stmt->execute([$address]);
            
            $pdo->commit();
            
            writeLog("Wallet deleted successfully: " . $address, 'INFO');
            
            return [
                'deleted' => true,
                'address' => $address,
                'message' => 'Wallet deleted successfully'
            ];
            
        } catch (Exception $e) {
            $pdo->rollback();
            throw new Exception('Failed to delete wallet from database: ' . $e->getMessage());
        }
        
    } catch (Exception $e) {
        writeLog("Error deleting wallet: " . $e->getMessage(), 'ERROR');
        throw new Exception('Failed to delete wallet: ' . $e->getMessage());
    }
}

/**
 * Minimal EVM-compatible JSON-RPC handler for Trust Wallet compatibility (read-only)
 * Supported methods: web3_clientVersion, net_version, eth_chainId, eth_blockNumber,
 * eth_getBalance, eth_getTransactionByHash, eth_getBlockByNumber (partial mapping)
 */
function handleRpcRequest(PDO $pdo, $walletManager, $networkConfig, string $method, array $params)
{
    try {
        switch ($method) {
            case 'web3_clientVersion':
                return 'phpblockchain/1.0 (wallet_api)';

            // dApp/browser convenience
            case 'eth_accounts':
                // Server doesn’t manage user keys; return empty -> dApp should prompt wallet
                return [];
            case 'eth_requestAccounts':
                // Triggering connect is client-side; returning empty array is acceptable default
                return [];
            case 'wallet_addEthereumChain': {
                // Validate provided params and return canonical chain config for client UIs
                $req = $params[0] ?? [];
                $providedId = is_array($req) ? ($req['chainId'] ?? null) : null;
                $info = method_exists($networkConfig, 'getNetworkInfo') ? $networkConfig->getNetworkInfo() : [];
                $ourId = (int)($info['chain_id'] ?? 0);
                $providedInt = null;
                if (is_string($providedId) && str_starts_with($providedId, '0x')) {
                    $providedInt = (int)hexdec($providedId);
                } elseif ($providedId !== null) {
                    $providedInt = (int)$providedId;
                }
                if ($providedInt !== null && $providedInt !== $ourId) {
                    writeLog('wallet_addEthereumChain chainId mismatch: provided=' . json_encode($providedId) . ' expected=' . $ourId, 'WARNING');
                    return rpcError(-32602, 'Invalid chainId for this RPC endpoint');
                }
                // Return our canonical dApp config (EIP-3085 shape)
                return getDappConfig($networkConfig);
            }
            case 'wallet_switchEthereumChain': {
                // Validate requested chain id; return null on success per spec guidance
                $req = $params[0] ?? [];
                $target = is_array($req) ? ($req['chainId'] ?? null) : null;
                $info = method_exists($networkConfig, 'getNetworkInfo') ? $networkConfig->getNetworkInfo() : [];
                $ourId = (int)($info['chain_id'] ?? 0);
                $targetInt = null;
                if (is_string($target) && str_starts_with($target, '0x')) {
                    $targetInt = (int)hexdec($target);
                } elseif ($target !== null) {
                    $targetInt = (int)$target;
                }
                if ($targetInt === null) {
                    writeLog('wallet_switchEthereumChain missing chainId', 'WARNING');
                    return rpcError(-32602, 'chainId is required');
                }
                if ($targetInt !== $ourId) {
                    writeLog('wallet_switchEthereumChain unknown chainId: ' . $targetInt . ' expected=' . $ourId, 'WARNING');
                    // 4902 is commonly used for unknown chain, but we keep JSON-RPC style here
                    return rpcError(-32602, 'Unknown chainId for this RPC endpoint');
                }
                return null; // success
            }

            case 'net_version': {
                $info = method_exists($networkConfig, 'getNetworkInfo') ? $networkConfig->getNetworkInfo() : [];
                return (string)($info['chain_id'] ?? 0);
            }

            case 'eth_chainId': {
                $info = method_exists($networkConfig, 'getNetworkInfo') ? $networkConfig->getNetworkInfo() : [];
                $cid = (int)($info['chain_id'] ?? 0);
                return '0x' . dechex($cid);
            }

            case 'eth_blockNumber': {
                $height = getCurrentBlockHeight($walletManager);
                return '0x' . dechex(max(0, (int)$height));
            }

            case 'net_listening':
                // Assume node is listening if API is up
                return true;

            case 'net_peerCount':
                // No P2P peers count exposed; return 0 as hex
                return '0x0';

            case 'eth_protocolVersion':
                // Arbitrary protocol version string
                return '0x1';

            case 'eth_getBalance': {
                $address = $params[0] ?? '';
                if (!$address) return '0x0';
                $balanceFloat = (float)$walletManager->getBalance($address);
                // Convert to smallest unit based on network decimals
                $decimals = getTokenDecimals($networkConfig);
                $multiplier = 10 ** $decimals;
                $balanceInt = (int)floor($balanceFloat * $multiplier);
                return '0x' . dechex($balanceInt);
            }

            case 'eth_getTransactionCount': {
                // params: [address, blockTag]
                $address = $params[0] ?? '';
                $blockTag = strtolower($params[1] ?? 'latest');
                if (!$address) return '0x0';
                // Use WalletManager nonce; treat it as next nonce, return as is for 'pending' and for 'latest'
                $next = 0;
                try { $next = (int)$walletManager->getNextNonce($address); } catch (Throwable $e) {}
                // If we consider 'latest' to be confirmed only, you might subtract 1 when next>0. Keep simple for now.
                return '0x' . dechex(max(0, $next));
            }

            case 'eth_gasPrice':
                // No gas market; return zero
                return '0x0';

            case 'eth_maxPriorityFeePerGas':
                return '0x0';

            case 'eth_estimateGas':
                // Return a fixed 21000 units as a placeholder
                return '0x5208';

            case 'eth_getTransactionByHash': {
                $hash = $params[0] ?? '';
                if (!$hash) return null;
                $tx = $walletManager->getTransactionByHash($hash);
                if (!$tx) return null;
                $blockNumberHex = isset($tx['block_height']) && $tx['block_height'] !== null
                    ? ('0x' . dechex((int)$tx['block_height']))
                    : null;
                $decimals = getTokenDecimals($networkConfig);
                $multiplier = 10 ** $decimals;
                $valueHex = '0x' . dechex((int)floor(((float)($tx['amount'] ?? 0)) * $multiplier));
                return [
                    'hash' => $tx['hash'],
                    'nonce' => '0x0',
                    'blockHash' => $tx['block_hash'] ?? null,
                    'blockNumber' => $blockNumberHex,
                    'transactionIndex' => '0x0',
                    'from' => $tx['from_address'] ?? null,
                    'to' => $tx['to_address'] ?? null,
                    'value' => $valueHex,
                    'gas' => '0x0',
                    'gasPrice' => '0x0',
                    'input' => '0x',
                ];
            }

            case 'eth_getTransactionReceipt': {
                $hash = $params[0] ?? '';
                if (!$hash) return null;
                return getTransactionReceipt($walletManager, $hash);
            }

            case 'eth_sendRawTransaction': {
                // Accept raw Ethereum transaction, queue for processing, return tx hash
                $raw = $params[0] ?? '';
                if (!is_string($raw) || strlen($raw) < 4 || !str_starts_with($raw, '0x')) {
                    return rpcError(-32602, 'Invalid raw transaction');
                }

                $rawHex = strtolower($raw);
                $bin = @hex2bin(substr($rawHex, 2));
                if ($bin === false) {
                    return rpcError(-32602, 'Raw transaction hex decode failed');
                }

                // Compute tx hash as keccak256 of raw bytes (Ethereum-style)
                $txHash = '0x' . \Blockchain\Core\Crypto\Hash::keccak256($bin);

                // Try to parse minimal fields from RLP for visibility (best-effort)
                $parsed = parseEthRawTransaction($rawHex);

                // Persist raw tx to local queue for asynchronous processing
                $queued = queueRawTransaction($txHash, $rawHex, $parsed);
                if (!$queued) {
                    // Still return hash so dApps have a handle; log the issue
                    writeLog('Failed to persist raw tx queue for ' . $txHash, 'ERROR');
                }

                return $txHash;
            }

            case 'eth_sendTransaction': {
                // Not supported (node does not hold private keys). Wallets should use eth_sendRawTransaction.
                writeLog('eth_sendTransaction called but not supported (use eth_sendRawTransaction)', 'WARNING');
                return null;
            }

            case 'eth_getBlockByHash': {
                $hash = $params[0] ?? '';
                $full = (bool)($params[1] ?? false);
                if (!$hash) return null;
                $block = getBlockByHash($walletManager, $hash);
                if (!$block) return null;
                $height = (int)($block['height'] ?? 0);
                $txs = [];
                $txList = $block['transactions'] ?? [];
                if ($full) {
                    foreach ($txList as $t) {
                        $decimals = getTokenDecimals($networkConfig);
                        $multiplier = 10 ** $decimals;
                        $txs[] = [
                            'hash' => $t['hash'],
                            'nonce' => '0x0',
                            'blockHash' => $block['hash'] ?? null,
                            'blockNumber' => '0x' . dechex((int)$height),
                            'transactionIndex' => '0x0',
                            'from' => $t['from_address'] ?? $t['from'] ?? null,
                            'to' => $t['to_address'] ?? $t['to'] ?? null,
                            'value' => '0x' . dechex((int)floor(((float)($t['amount'] ?? 0)) * $multiplier)),
                            'gas' => '0x0',
                            'gasPrice' => '0x0',
                            'input' => '0x',
                        ];
                    }
                } else {
                    foreach ($txList as $t) {
                        $txs[] = $t['hash'];
                    }
                }
                return [
                    'number' => '0x' . dechex($height),
                    'hash' => $block['hash'] ?? null,
                    'parentHash' => $block['parent_hash'] ?? $block['previous_hash'] ?? null,
                    'timestamp' => '0x' . dechex((int)($block['timestamp'] ?? time())),
                    'miner' => $block['validator'] ?? null,
                    'transactions' => $txs,
                ];
            }

            case 'eth_getCode': {
                // params: [address, blockTag]
                $address = $params[0] ?? '';
                $norm = normalizeHexAddress($address);
                if (!$norm) return '0x';
                $code = getContractCodeHex($pdo, $norm);
                return $code ?: '0x';
            }

            case 'eth_call': {
                // params: [callObject, blockTag]
                $call = $params[0] ?? [];
                if (!is_array($call)) {
                    writeLog('eth_call invalid params: not an object', 'WARNING');
                    return rpcError(-32602, 'Invalid params');
                }
                $to = $call['to'] ?? '';
                $norm = normalizeHexAddress($to);
                if (!$norm) {
                    writeLog('eth_call missing/invalid to address', 'WARNING');
                    return rpcError(-32602, 'Invalid to address');
                }
                $code = getContractCodeHex($pdo, $norm);
                if (!$code || $code === '0x') return '0x';

                // Prepare VM and context
                $gasHex = $call['gas'] ?? null;
                $gasLimit = is_string($gasHex) && str_starts_with($gasHex, '0x') ? (int)hexdec($gasHex) : 3000000;
                if ($gasLimit <= 0) $gasLimit = 3000000;

                try {
                    $vm = new \Blockchain\Core\SmartContract\VirtualMachine($gasLimit);
                } catch (\Throwable $e) {
                    writeLog('eth_call VM init failed: ' . $e->getMessage(), 'ERROR');
                    return rpcError(-32603, 'Internal error');
                }

                $from = normalizeHexAddress($call['from'] ?? '') ?: '0x0000000000000000000000000000000000000000';
                $valueHex = $call['value'] ?? '0x0';
                $value = 0;
                if (is_string($valueHex) && str_starts_with($valueHex, '0x')) {
                    $value = (int)hexdec($valueHex);
                }
                $dataHex = $call['data'] ?? ($call['input'] ?? '0x');
                $dataBin = '';
                if (is_string($dataHex) && str_starts_with(strtolower($dataHex), '0x')) {
                    $dataBin = @hex2bin(substr(strtolower($dataHex), 2)) ?: '';
                }

                $context = [
                    'caller' => $from,
                    'value' => $value,
                    'gasPrice' => 1,
                    'blockNumber' => getCurrentBlockHeight($walletManager),
                    'timestamp' => time(),
                    'calldata' => $dataBin,
                    'getBalance' => function($addr) use ($walletManager) {
                        try { return 0; } catch (\Throwable $e) { return 0; }
                    }
                ];

                // Execute contract bytecode
                $bytecode = substr($code, 0, 2) === '0x' ? substr($code, 2) : $code;
                try {
                    $result = $vm->execute($bytecode, $context);
                    if (!($result['success'] ?? false)) {
                        writeLog('eth_call execution failed: ' . ($result['error'] ?? 'unknown'), 'WARNING');
                        return '0x';
                    }
                    $out = $result['result'] ?? '';
                    if ($out === '' || $out === null) return '0x';
                    return '0x' . bin2hex($out);
                } catch (\Throwable $e) {
                    writeLog('eth_call execution error: ' . $e->getMessage(), 'ERROR');
                    return rpcError(-32603, 'Internal error');
                }
            }

            case 'eth_getBlockByNumber': {
                $tag = $params[0] ?? 'latest';
                $full = (bool)($params[1] ?? false);
                $height = null;
                if (is_string($tag) && str_starts_with($tag, '0x')) {
                    $height = hexdec($tag);
                } elseif ($tag === 'latest') {
                    $height = getCurrentBlockHeight($walletManager);
                }
                if ($height === null) return null;
                $block = getBlockByHeight($walletManager, (int)$height);
                if (!$block) return null;

                $txs = [];
                $txList = $block['transactions'] ?? [];
                if ($full) {
                    foreach ($txList as $t) {
                        $decimals = getTokenDecimals($networkConfig);
                        $multiplier = 10 ** $decimals;
                        $txs[] = [
                            'hash' => $t['hash'],
                            'nonce' => '0x0',
                            'blockHash' => $block['hash'] ?? null,
                            'blockNumber' => '0x' . dechex((int)$height),
                            'transactionIndex' => '0x0',
                            'from' => $t['from_address'] ?? $t['from'] ?? null,
                            'to' => $t['to_address'] ?? $t['to'] ?? null,
                            'value' => '0x' . dechex((int)floor(((float)($t['amount'] ?? 0)) * $multiplier)),
                            'gas' => '0x0',
                            'gasPrice' => '0x0',
                            'input' => '0x',
                        ];
                    }
                } else {
                    foreach ($txList as $t) {
                        $txs[] = $t['hash'];
                    }
                }

                return [
                    'number' => '0x' . dechex((int)$height),
                    'hash' => $block['hash'] ?? null,
                    'parentHash' => $block['parent_hash'] ?? $block['previous_hash'] ?? null,
                    'timestamp' => '0x' . dechex((int)($block['timestamp'] ?? time())),
                    'miner' => $block['validator'] ?? null,
                    'transactions' => $txs,
                ];
            }

            case 'web3_sha3': {
                $data = $params[0] ?? '';
                if (!is_string($data)) return '0x';
                // Accept hex string with 0x prefix or raw string
                if (str_starts_with($data, '0x')) {
                    $bin = @hex2bin(substr($data, 2));
                    if ($bin === false) $bin = '';
                } else {
                    $bin = $data;
                }
                $hash = \Blockchain\Core\Crypto\Hash::keccak256($bin);
                return '0x' . $hash;
            }

            case 'eth_getLogs': {
                // params: [filter]
                $filter = $params[0] ?? [];
                if (!is_array($filter)) {
                    writeLog('eth_getLogs invalid params', 'WARNING');
                    return rpcError(-32602, 'Invalid params');
                }
                $address = $filter['address'] ?? null;
                $fromTag = $filter['fromBlock'] ?? '0x0';
                $toTag = $filter['toBlock'] ?? 'latest';
                $from = is_string($fromTag) && str_starts_with($fromTag, '0x') ? (int)hexdec($fromTag) : (int)$fromTag;
                $to = ($toTag === 'latest') ? getCurrentBlockHeight($walletManager) : (is_string($toTag) && str_starts_with($toTag, '0x') ? (int)hexdec($toTag) : (int)$toTag);
                try {
                    $stateStorage = new \Blockchain\Core\Storage\StateStorage($pdo);
                    if ($address && is_string($address)) {
                        $addr = normalizeHexAddress($address);
                        if (!$addr) return rpcError(-32602, 'Invalid address');
                        return $stateStorage->getContractEvents($addr, $from, $to);
                    }
                    // If no address provided, return empty as we do not index global logs yet
                    return [];
                } catch (\Throwable $e) {
                    writeLog('eth_getLogs error: ' . $e->getMessage(), 'ERROR');
                    return rpcError(-32603, 'Internal error');
                }
            }

            case 'eth_getBlockTransactionCountByNumber': {
                $tag = $params[0] ?? 'latest';
                $height = null;
                if (is_string($tag) && str_starts_with($tag, '0x')) {
                    $height = hexdec($tag);
                } elseif ($tag === 'latest') {
                    $height = getCurrentBlockHeight($walletManager);
                }
                if ($height === null) return '0x0';
                $block = getBlockByHeight($walletManager, (int)$height);
                $count = $block ? count($block['transactions'] ?? []) : 0;
                return '0x' . dechex((int)$count);
            }

            case 'eth_getTransactionByBlockNumberAndIndex': {
                // params: [blockNumberTag, indexHex]
                $tag = $params[0] ?? 'latest';
                $indexHex = $params[1] ?? '0x0';
                $height = null;
                if (is_string($tag) && str_starts_with($tag, '0x')) {
                    $height = hexdec($tag);
                } elseif ($tag === 'latest') {
                    $height = getCurrentBlockHeight($walletManager);
                }
                if ($height === null) return null;
                $index = is_string($indexHex) && str_starts_with($indexHex, '0x') ? hexdec($indexHex) : (int)$indexHex;
                $block = getBlockByHeight($walletManager, (int)$height);
                if (!$block) return null;
                $txList = $block['transactions'] ?? [];
                if (!isset($txList[$index])) return null;
                $txHash = $txList[$index]['hash'] ?? null;
                if (!$txHash) return null;
                // Reuse existing method mapping
                return handleRpcRequest($pdo, $walletManager, $networkConfig, 'eth_getTransactionByHash', [$txHash]);
            }

            default:
                return null;
        }
    } catch (Throwable $e) {
        writeLog("RPC handler error: " . $e->getMessage(), 'ERROR');
        return null;
    }
}

// Helpers to access height and block details with current codebase
function getCurrentBlockHeight($walletManager): ?int
{
    // Try DB-based highest height from blocks table
    try {
        $pdo = $walletManager->getDatabase();
        $stmt = $pdo->query("SELECT MAX(height) AS h FROM blocks");
        $row = $stmt->fetch();
        if ($row && isset($row['h'])) {
            return (int)$row['h'];
        }
    } catch (Exception $e) {
        writeLog("getCurrentBlockHeight DB error: " . $e->getMessage(), 'DEBUG');
    }
    return 0;
}

function getBlockByHeight($walletManager, int $height): ?array
{
    try {
        $pdo = $walletManager->getDatabase();
        $stmt = $pdo->prepare("SELECT hash, parent_hash, height, timestamp, validator, merkle_root FROM blocks WHERE height = ? LIMIT 1");
        $stmt->execute([$height]);
        $block = $stmt->fetch();
        if (!$block) return null;

        // Load transactions for this block (minimal fields used by RPC)
        $txStmt = $pdo->prepare("SELECT hash, from_address, to_address, amount FROM transactions WHERE block_height = ? ORDER BY id ASC");
        $txStmt->execute([$height]);
        $block['transactions'] = $txStmt->fetchAll();
        return $block;
    } catch (Exception $e) {
        writeLog("getBlockByHeight DB error: " . $e->getMessage(), 'DEBUG');
        return null;
    }
}

function getBlockByHash($walletManager, string $hash): ?array
{
    try {
        $pdo = $walletManager->getDatabase();
        $stmt = $pdo->prepare("SELECT hash, parent_hash, height, timestamp, validator, merkle_root FROM blocks WHERE hash = ? LIMIT 1");
        $stmt->execute([$hash]);
        $block = $stmt->fetch();
        if (!$block) return null;
        $height = (int)($block['height'] ?? 0);
        $txStmt = $pdo->prepare("SELECT hash, from_address, to_address, amount FROM transactions WHERE block_height = ? ORDER BY id ASC");
        $txStmt->execute([$height]);
        $block['transactions'] = $txStmt->fetchAll();
        return $block;
    } catch (Exception $e) {
        writeLog("getBlockByHash DB error: " . $e->getMessage(), 'DEBUG');
        return null;
    }
}

function getTransactionReceipt($walletManager, string $hash): ?array
{
    try {
        $tx = $walletManager->getTransactionByHash($hash);
        if (!$tx) return null;
        $blockNumberHex = isset($tx['block_height']) && $tx['block_height'] !== null
            ? ('0x' . dechex((int)$tx['block_height']))
            : null;
        $statusHex = ($tx['status'] ?? '') === 'confirmed' ? '0x1' : '0x0';
        return [
            'transactionHash' => $tx['hash'],
            'transactionIndex' => '0x0',
            'blockHash' => $tx['block_hash'] ?? null,
            'blockNumber' => $blockNumberHex,
            'from' => $tx['from_address'] ?? null,
            'to' => $tx['to_address'] ?? null,
            'cumulativeGasUsed' => '0x0',
            'gasUsed' => '0x0',
            'contractAddress' => null,
            'logs' => [],
            'logsBloom' => '0x',
            'status' => $statusHex,
        ];
    } catch (Exception $e) {
        writeLog('getTransactionReceipt error: ' . $e->getMessage(), 'ERROR');
        return null;
    }
}

// Helper to read token decimals from network configuration
function getTokenDecimals($networkConfig): int
{
    try {
        if (method_exists($networkConfig, 'getTokenInfo')) {
            $info = $networkConfig->getTokenInfo();
            $d = (int)($info['decimals'] ?? 9);
            return $d > 0 ? $d : 9;
        }
    } catch (Throwable $e) {
        // ignore
    }
    return 9;
}

/**
 * dApp configuration helper
 * Returns EIP-3085 compatible chain parameters and minimal metadata
 */
function getDappConfig($networkConfig): array
{
    $token = method_exists($networkConfig, 'getTokenInfo') ? $networkConfig->getTokenInfo() : [];
    $net = method_exists($networkConfig, 'getNetworkInfo') ? $networkConfig->getNetworkInfo() : [];

    $chainId = (int)($net['chain_id'] ?? 1);
    $decimals = (int)($token['decimals'] ?? 9);
    $symbol = $token['symbol'] ?? 'COIN';
    $name = $net['name'] ?? ($token['name'] ?? 'Blockchain');

    // Note: rpcUrls will be the same endpoint; explorer is optional and can be configured separately
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/wallet/wallet_api.php'), '/');
    $rpcUrl = $scheme . $host . $basePath . '/wallet_api.php';

    // Explorer URL only if explorer frontend is present
    $baseDir = dirname(__DIR__);
    $explorerExists = is_file($baseDir . '/explorer/index.php');
    $explorerUrls = $explorerExists ? [$scheme . $host . '/explorer/'] : [];

    // Icon URL from assets (ordinary hosting) - set explicitly
    $iconUrl = $scheme . $host . '/public/assets/network-icon.svg';

    $config = [
        'chainId' => '0x' . dechex($chainId),
        'chainName' => $name,
        'nativeCurrency' => [
            'name' => $token['name'] ?? $symbol,
            'symbol' => $symbol,
            'decimals' => $decimals,
        ],
        'rpcUrls' => [$rpcUrl],
    ];

    if (!empty($explorerUrls)) {
        $config['blockExplorerUrls'] = $explorerUrls;
    }
    $config['iconUrls'] = [$iconUrl];

    // Optional: ENS registry address if configured
    if (!empty($net['ens_address'])) {
        $config['ensAddress'] = $net['ens_address'];
    }

    return $config;
}

/**
 * Build JSON-RPC error object (inline style)
 */
function rpcError(int $code, string $message)
{
    return [
        'code' => $code,
        'message' => $message
    ];
}

/**
 * Queue raw Ethereum transaction to local storage for later processing by a worker
 * Minimal, file-based queue to avoid DB schema changes.
 */
function queueRawTransaction(string $txHash, string $rawHex, array $parsed = []): bool
{
    try {
        $dir = dirname(__DIR__) . '/storage/raw_mempool';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $path = $dir . '/' . str_replace('0x', '', $txHash) . '.json';
        $payload = [
            'hash' => $txHash,
            'raw' => $rawHex,
            'parsed' => $parsed,
            'received_at' => time()
        ];
        return (bool)@file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    } catch (\Throwable $e) {
        writeLog('queueRawTransaction error: ' . $e->getMessage(), 'ERROR');
        return false;
    }
}

/**
 * Minimal RLP decoding for Ethereum legacy txs to extract from/to/value/nonce (best-effort).
 * This is intentionally permissive and does not validate chain IDs or signatures.
 */
function parseEthRawTransaction(string $rawHex): array
{
    try {
        if (str_starts_with($rawHex, '0x')) $rawHex = substr($rawHex, 2);
        $bin = @hex2bin($rawHex);
        if ($bin === false) return [];

        $offset = 0;
        $read = function() use ($bin, &$offset) {
            $len = strlen($bin);
            if ($offset >= $len) return null;
            $b0 = ord($bin[$offset]);
            if ($b0 <= 0x7f) { // single byte string
                $offset += 1;
                return $bin[$offset-1];
            } elseif ($b0 <= 0xb7) { // short string
                $l = $b0 - 0x80;
                $offset += 1;
                $val = substr($bin, $offset, $l);
                $offset += $l;
                return $val;
            } elseif ($b0 <= 0xbf) { // long string
                $ll = $b0 - 0xb7;
                $offset += 1;
                $lBytes = substr($bin, $offset, $ll);
                $offset += $ll;
                $l = intval(bin2hex($lBytes), 16);
                $val = substr($bin, $offset, $l);
                $offset += $l;
                return $val;
            } elseif ($b0 <= 0xf7) { // short list
                $l = $b0 - 0xc0;
                $offset += 1;
                $end = $offset + $l;
                $items = [];
                while ($offset < $end) $items[] = $this->readItem($bin, $offset);
                return $items;
            } else { // long list
                $ll = $b0 - 0xf7;
                $offset += 1;
                $lBytes = substr($bin, $offset, $ll);
                $offset += $ll;
                $l = intval(bin2hex($lBytes), 16);
                $end = $offset + $l;
                $items = [];
                while ($offset < $end) $items[] = $this->readItem($bin, $offset);
                return $items;
            }
        };

        // Local helper to read one item (closure-compatible)
        $readItem = function() use (&$read) { return $read(); };

        // Patch closures to call nested safely
        $reflect = function($bin, &$offset) use (&$readItem, &$read) {
            $len = strlen($bin);
            if ($offset >= $len) return null;
            $b0 = ord($bin[$offset]);
            if ($b0 <= 0x7f) { $offset += 1; return $bin[$offset-1]; }
            if ($b0 <= 0xb7) { $l = $b0 - 0x80; $offset += 1; $v = substr($bin, $offset, $l); $offset += $l; return $v; }
            if ($b0 <= 0xbf) { $ll = $b0 - 0xb7; $offset += 1; $lBytes = substr($bin, $offset, $ll); $offset += $ll; $l = intval(bin2hex($lBytes), 16); $v = substr($bin, $offset, $l); $offset += $l; return $v; }
            if ($b0 <= 0xf7) { $l = $b0 - 0xc0; $offset += 1; $end = $offset + $l; $arr = []; while ($offset < $end) { $arr[] = $reflect($bin, $offset); } return $arr; }
            $ll = $b0 - 0xf7; $offset += 1; $lBytes = substr($bin, $offset, $ll); $offset += $ll; $l = intval(bin2hex($lBytes), 16); $end = $offset + $l; $arr = []; while ($offset < $end) { $arr[] = $reflect($bin, $offset); } return $arr;
        };

        $list = $reflect($bin, $offset);
        if (!is_array($list)) return [];

        // Legacy tx fields: [nonce, gasPrice, gasLimit, to, value, data, v, r, s]
        $hex = fn($v) => '0x' . bin2hex($v ?? '');
        $toHex40 = function($v) use ($hex) {
            $h = substr($hex($v), 2);
            if ($h === '') return '0x';
            $h = ltrim($h, '0');
            $h = str_pad($h, 40, '0', STR_PAD_LEFT);
            return '0x' . $h;
        };
        $numHex = function($v) { $h = bin2hex($v ?? ''); $h = ltrim($h, '0'); return '0x' . ($h === '' ? '0' : $h); };

        $out = [
            'nonce' => $numHex($list[0] ?? ''),
            'gasPrice' => $numHex($list[1] ?? ''),
            'gas' => $numHex($list[2] ?? ''),
            'to' => $toHex40($list[3] ?? ''),
            'value' => $numHex($list[4] ?? ''),
            'input' => $hex($list[5] ?? ''),
        ];
        return $out;
    } catch (\Throwable $e) {
        writeLog('parseEthRawTransaction error: ' . $e->getMessage(), 'ERROR');
        return [];
    }
}

/**
 * Normalize 0x-prefixed 20-byte hex address; returns lowercased 0x... or empty string
 */
function normalizeHexAddress(?string $addr): string {
    if (!is_string($addr)) return '';
    $a = strtolower(trim($addr));
    if (!str_starts_with($a, '0x')) return '';
    if (strlen($a) !== 42) return '';
    if (!ctype_xdigit(substr($a, 2))) return '';
    return $a;
}

/**
 * Retrieve contract bytecode hex (0x...) from DB smart_contracts or filesystem storage/contracts
 */
function getContractCodeHex(PDO $pdo, string $address): string {
    try {
        // Try DB smart_contracts table
        $stmt = $pdo->query("SHOW TABLES LIKE 'smart_contracts'");
        if ($stmt && $stmt->rowCount() > 0) {
            $stmt2 = $pdo->prepare("SELECT bytecode FROM smart_contracts WHERE address = ? LIMIT 1");
            $stmt2->execute([$address]);
            $row = $stmt2->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['bytecode'])) {
                $code = $row['bytecode'];
                $code = is_string($code) ? trim($code) : '';
                if ($code !== '') {
                    if (str_starts_with($code, '0x')) return $code;
                    if (ctype_xdigit($code)) return '0x' . $code;
                }
            }
        }
    } catch (\Throwable $e) {
        // Ignore and try filesystem
    }
    // Filesystem fallback: storage/contracts/<address>.bin or .hex
    $baseDir = dirname(__DIR__);
    $contractDir = $baseDir . '/storage/contracts';
    // Optional mapping file created by Application during bootstrap deployments
    $mapFile = $baseDir . '/storage/contract_addresses.json';
    if (is_file($mapFile)) {
        $json = @file_get_contents($mapFile);
        $decoded = json_decode((string)$json, true);
        if (is_array($decoded)) {
            foreach ($decoded as $alias => $real) {
                if (strtolower((string)$real) === strtolower($address)) {
                    $address = strtolower($address);
                    break;
                }
            }
        }
    }
    $paths = [
        $contractDir . '/' . $address . '.bin',
        $contractDir . '/' . $address . '.hex',
    ];
    foreach ($paths as $p) {
        if (is_file($p)) {
            $data = @file_get_contents($p);
            if ($data === false) continue;
            $data = trim($data);
            if ($data === '') continue;
            if (str_starts_with($data, '0x')) return $data;
            if (ctype_xdigit($data)) return '0x' . $data;
        }
    }
    return '0x';
}

/**
 * Obtain or deploy a staking contract and return its 0x address.
 * Strategy:
 * 1) Try to read from DB smart_contracts by name 'Staking'.
 * 2) Try mapping cache storage/contract_addresses.json (key: staking_contract).
 * 3) Best-effort deploy via Contracts API if available in-process (Application/SmartContractManager not directly accessible here),
 *    so as a fallback we just return empty and let node bootstrap handle deployment.
 */
function getOrDeployStakingContract(PDO $pdo, string $deployerAddress): string {
    // 1) DB: try to find contract with name 'Staking'
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'smart_contracts'");
        if ($stmt && $stmt->rowCount() > 0) {
            $stmt = $pdo->prepare("SELECT address FROM smart_contracts WHERE name = 'Staking' AND status = 'active' ORDER BY deployment_block ASC LIMIT 1");
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['address'])) {
                return strtolower($row['address']);
            }
        }
    } catch (\Throwable $e) {
        // ignore
    }

    // 2) Mapping file
    $baseDir = dirname(__DIR__);
    $mapFile = $baseDir . '/storage/contract_addresses.json';
    if (is_file($mapFile)) {
        $json = @file_get_contents($mapFile);
        $decoded = json_decode((string)$json, true);
        if (is_array($decoded) && !empty($decoded['staking_contract'])) {
            $addr = strtolower((string)$decoded['staking_contract']);
            if (str_starts_with($addr, '0x') && strlen($addr) === 42) return $addr;
        }
    }

    // 3) Try to deploy now using SmartContractManager (no mocks)
    try {
        // Minimal logger implementation
        $logger = new class implements \Psr\Log\LoggerInterface {
            public function emergency($message, array $context = []) {}
            public function alert($message, array $context = []) {}
            public function critical($message, array $context = []) {}
            public function error($message, array $context = []) {}
            public function warning($message, array $context = []) {}
            public function notice($message, array $context = []) {}
            public function info($message, array $context = []) {}
            public function debug($message, array $context = []) {}
            public function log($level, $message, array $context = []) {}
        };

        $vm = new \Blockchain\Core\SmartContract\VirtualMachine(3000000);
        $stateStorage = new \Blockchain\Core\Storage\StateStorage($pdo);
        $cfg = $GLOBALS['config'] ?? [];
        $manager = new \Blockchain\Contracts\SmartContractManager($vm, $stateStorage, $logger, is_array($cfg) ? $cfg : []);

        // Deploy standard set and extract staking
        $res = $manager->deployStandardContracts($deployerAddress);
        if (is_array($res) && !empty($res['staking']['success']) && !empty($res['staking']['address'])) {
            $addr = strtolower((string)$res['staking']['address']);
            // Persist mapping to cache file
            $existing = [];
            if (is_file($mapFile)) {
                $json = @file_get_contents($mapFile);
                $decoded = json_decode((string)$json, true);
                if (is_array($decoded)) $existing = $decoded;
            }
            $existing['staking_contract'] = $addr;
            @file_put_contents($mapFile, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return $addr;
        }
    } catch (\Throwable $e) {
        // Swallow and fallback
        writeLog('Staking autodeploy failed: ' . $e->getMessage(), 'ERROR');
    }

    // If still not available
    return '';
}
