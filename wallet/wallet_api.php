<?php
/**
 * API для работы с кошельком
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Обработка preflight запросов
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

/**
 * Функция для записи логов в файл
 */
function writeLog($message, $level = 'INFO') {
    $baseDir = dirname(__DIR__);
    $logDir = $baseDir . '/logs';
    
    // Создаем папку logs если её нет
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $logFile = $logDir . '/wallet_api.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;
    
    file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
}

try {
    // Определяем базовую директорию проекта
    $baseDir = dirname(__DIR__);
    
    // Подключаем автозагрузчик Composer
    $autoloader = $baseDir . '/vendor/autoload.php';
    if (!file_exists($autoloader)) {
        throw new Exception('Composer autoloader not found. Please run "composer install"');
    }
    require_once $autoloader;
    
    // Подключаем EnvironmentLoader для загрузки переменных окружения
    require_once $baseDir . '/core/Environment/EnvironmentLoader.php';
    \Blockchain\Core\Environment\EnvironmentLoader::load($baseDir);
    
    // Подключаем конфиг
    $configFile = $baseDir . '/config/config.php';
    $config = [];
    if (file_exists($configFile)) {
        $config = require $configFile;
    }
    
    // Создаем конфигурацию базы данных с приоритетом: config.php -> .env -> defaults
    $dbConfig = $config['database'] ?? [];
    
    // Если конфигурация пустая, используем переменные окружения
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
    
    // Логируем попытку подключения (без пароля)
    writeLog("Attempting DB connection to {$dbConfig['host']}:{$dbConfig['port']}/{$dbConfig['database']} as {$dbConfig['username']}");
    
    // Подключение к базе данных
    $dsn = "mysql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['database']};charset={$dbConfig['charset']}";
    $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], $dbConfig['options']);
    
    writeLog("Database connection successful");
    
    // Подключаем класс WalletManager
    require_once $baseDir . '/wallet/WalletManager.php';
    require_once $baseDir . '/wallet/WalletBlockchainManager.php';
    require_once $baseDir . '/core/Config/NetworkConfig.php';
    require_once $baseDir . '/core/Cryptography/MessageEncryption.php';
    
    // Создаём экземпляр WalletManager с полной конфигурацией
    $fullConfig = array_merge($config, ['database' => $dbConfig]);
    $walletManager = new \Blockchain\Wallet\WalletManager($pdo, $fullConfig);
    
    // Создаём экземпляр WalletBlockchainManager для интеграции с блокчейном
    $blockchainManager = new \Blockchain\Wallet\WalletBlockchainManager($pdo, $fullConfig);
    
    // Создаём экземпляр NetworkConfig для получения настроек сети
    $networkConfig = new \Blockchain\Core\Config\NetworkConfig($pdo);
    
    // Получение данных запроса
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    
    switch ($action) {
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
            $encryptMemo = $input['encrypt_memo'] ?? false;
            
            if (!$fromAddress || !$toAddress || !$amount || !$privateKey) {
                throw new Exception('From address, to address, amount and private key are required');
            }
            
            $result = transferTokens($walletManager, $blockchainManager, $fromAddress, $toAddress, $amount, $privateKey, $memo, $encryptMemo);
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
            
        case 'get_staking_info':
            $address = $input['address'] ?? '';
            if (!$address) {
                throw new Exception('Wallet address is required');
            }
            $result = getStakingInfo($walletManager, $address);
            break;
            if (!$address) {
                throw new Exception('Wallet address is required');
            }
            $result = verifyWalletInBlockchain($blockchainManager, $address);
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
            
        default:
            throw new Exception('Unknown action: ' . $action);
    }
    
    echo json_encode([
        'success' => true,
        ...$result
    ]);
    
} catch (Exception $e) {
    // Логирование полной информации об ошибке
    $errorInfo = [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString(),
        'action' => $input['action'] ?? 'unknown',
        'timestamp' => date('Y-m-d H:i:s'),
        'input_data' => $input ?? []
    ];
    
    // Записываем в файл логов
    writeLog("Wallet API Error: " . json_encode($errorInfo), 'ERROR');
    
    // Также выводим в PHP error log
    error_log("Wallet API Error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    
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
 * Создание нового кошелька через WalletManager
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
 * Получение списка кошельков через WalletManager
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
 * Получение баланса кошелька через WalletManager
 */
function getBalance($walletManager, $address) {
    try {
        $availableBalance = $walletManager->getAvailableBalance($address);
        
        // Получаем стейкинг баланс из таблицы staking
        $pdo = $walletManager->getDatabase();
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) as total_staked 
            FROM staking 
            WHERE staker = ? AND status = 'active'
        ");
        $stmt->execute([$address]);
        $result = $stmt->fetch();
        $stakedBalance = (float)$result['total_staked'];
        
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
 * Получение информации о кошельке через WalletManager
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
 * Стейкинг токенов через WalletManager
 */
function stakeTokens($walletManager, $address, $amount, $period, $privateKey) {
    try {
        $result = $walletManager->stake($address, $amount, $privateKey);
        
        if ($result) {
            // Получаем обновлённые балансы
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
 * Генерация новой мнемонической фразы
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
 * Создание кошелька из мнемонической фразы
 */
function createWalletFromMnemonic($walletManager, $blockchainManager, array $mnemonic) {
    try {
        writeLog("Creating wallet from mnemonic with blockchain integration", 'INFO');
        
        // 1. Create wallet using WalletManager
        $walletData = $walletManager->createWalletFromMnemonic($mnemonic);
        writeLog("Wallet created from mnemonic: " . $walletData['address'], 'INFO');
        
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
        writeLog("Error creating wallet from mnemonic: " . $e->getMessage(), 'ERROR');
        throw new Exception('Failed to create wallet from mnemonic: ' . $e->getMessage());
    }
}

/**
 * Валидация мнемонической фразы
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
 * Восстановление кошелька из мнемонической фразы
 */
function restoreWalletFromMnemonic($walletManager, $blockchainManager, array $mnemonic) {
    try {
        writeLog("Starting wallet restoration from mnemonic", 'INFO');
        
        // 1. Restore wallet using WalletManager (НЕ записываем в блокчейн!)
        $walletData = $walletManager->restoreWalletFromMnemonic($mnemonic);
        writeLog("Wallet restored: " . $walletData['address'] . " from: " . ($walletData['restored_from'] ?? 'unknown'), 'INFO');
        
        // 2. Проверяем историю транзакций для дополнительной информации
        $transactionHistory = $blockchainManager->getWalletTransactionHistory($walletData['address']);
        $isVerified = $blockchainManager->verifyWalletInBlockchain($walletData['address']);
        
        writeLog("Wallet verification in blockchain: " . ($isVerified ? 'FOUND' : 'NOT_FOUND'), 'INFO');
        writeLog("Transaction history count: " . count($transactionHistory), 'INFO');
        
        // 3. Return result WITHOUT blockchain recording
        return [
            'wallet' => $walletData,
            'restored' => true,
            'verification' => [
                'exists_in_blockchain' => $isVerified,
                'transaction_count' => count($transactionHistory),
                'last_activity' => !empty($transactionHistory) ? $transactionHistory[0]['block_timestamp'] ?? null : null
            ],
            'note' => $walletData['restored_from'] === 'mnemonic_only' ? 
                     'Wallet restored from seed phrase. No previous activity found.' : 
                     'Wallet restored successfully with transaction history.'
        ];
    } catch (Exception $e) {
        writeLog("Exception in restoreWalletFromMnemonic: " . $e->getMessage(), 'ERROR');
        throw new Exception('Failed to restore wallet: ' . $e->getMessage());
    }
}

/**
 * Получение информации о конфигурации
 */
function getConfigInfo(array $config, ?\Blockchain\Core\Config\NetworkConfig $networkConfig = null) {
    // Получаем настройки из БД если доступны
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
    
    // Fallback к статической конфигурации
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
 * Активация восстановленного кошелька в блокчейне
 */
function activateRestoredWallet($walletManager, $blockchainManager, string $address, string $publicKey) {
    try {
        writeLog("Activating restored wallet in blockchain: $address", 'INFO');
        
        // Проверяем, что кошелек действительно был восстановлен
        $walletInfo = $walletManager->getWalletInfo($address);
        if (!$walletInfo) {
            throw new Exception('Wallet not found. Please restore it first.');
        }
        
        // Проверяем, не активирован ли уже
        $isInBlockchain = $blockchainManager->verifyWalletInBlockchain($address);
        if ($isInBlockchain) {
            return [
                'already_active' => true,
                'message' => 'Wallet is already active in blockchain',
                'address' => $address
            ];
        }
        
        // Создаем транзакцию активации
        $walletData = [
            'address' => $address,
            'public_key' => $publicKey,
            'balance' => $walletInfo['balance'] ?? 0,
            'restored' => true
        ];
        
        // Записываем активацию в блокчейн
        $blockchainResult = $blockchainManager->createWalletWithBlockchain($walletData);
        
        writeLog("Wallet activated in blockchain: " . json_encode($blockchainResult['blockchain_recorded']), 'INFO');
        
        return [
            'activated' => true,
            'address' => $address,
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
function transferTokens($walletManager, $blockchainManager, string $fromAddress, string $toAddress, float $amount, string $privateKey, string $memo = '', bool $encryptMemo = false) {
    try {
        writeLog("Starting token transfer: $fromAddress -> $toAddress, amount: $amount", 'INFO');
        
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
        
        // 4. Encrypt memo if requested and message exists
        $finalMemo = $memo;
        if ($encryptMemo && !empty($memo)) {
            try {
                // Get recipient public key for encryption
                $recipientWallet = $walletManager->getWalletByAddress($toAddress);
                if ($recipientWallet && !empty($recipientWallet['public_key'])) {
                    // Get sender private key for signing
                    $senderWallet = $walletManager->getWalletByAddress($fromAddress);
                    if ($senderWallet && !empty($senderWallet['private_key'])) {
                        $encryptedData = \Blockchain\Core\Cryptography\MessageEncryption::createSecureMessage(
                            $memo, 
                            $recipientWallet['public_key'], 
                            $senderWallet['private_key']
                        );
                        $finalMemo = 'ENCRYPTED:' . base64_encode(json_encode($encryptedData));
                        writeLog("Message encrypted and signed for recipient", 'INFO');
                    } else {
                        writeLog("Sender private key not accessible for signing, sending unencrypted", 'WARNING');
                    }
                } else {
                    writeLog("Recipient public key not found, sending unencrypted", 'WARNING');
                }
            } catch (Exception $e) {
                writeLog("Encryption failed: " . $e->getMessage(), 'WARNING');
                // Continue with unencrypted message
            }
        }
        
        // 5. Check if recipient wallet exists
        $recipientInfo = $walletManager->getWalletInfo($toAddress);
        if (!$recipientInfo) {
            throw new Exception('Recipient wallet not found');
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
                'memo' => $finalMemo,
                'transfer_type' => 'wallet_to_wallet',
                'original_memo_length' => strlen($memo),
                'encrypted' => $encryptMemo && !empty($memo)
            ],
            'signature' => hash_hmac('sha256', $fromAddress . $toAddress . $amount, $privateKey),
            'status' => 'pending'
        ];
        
        // 6. Update balances in database
        $pdo = $walletManager->getDatabase();
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
            $pdo->rollback();
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
        
        // 4. Create staking transaction
        $stakeTx = [
            'hash' => hash('sha256', 'stake_' . $address . '_' . $amount . '_' . time()),
            'type' => 'stake',
            'from' => $address,
            'to' => 'staking_contract',
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
        
        // 5. Update balances in database
        $pdo = $walletManager->getDatabase();
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
            $pdo->rollback();
            throw new Exception('Failed to create staking record: ' . $e->getMessage());
        }
        
        // 6. Record in blockchain
        $blockchainResult = $blockchainManager->recordTransactionInBlockchain($stakeTx);
        
        // 7. Update transaction status
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
            'new_balance' => $walletManager->getBalance($address)
        ];
        
    } catch (Exception $e) {
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
        
        // 4. Create unstaking transaction
        $unstakeTx = [
            'hash' => hash('sha256', 'unstake_' . $address . '_' . $amount . '_' . time()),
            'type' => 'unstake',
            'from' => 'staking_contract',
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
        
        // 5. Update database
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
        
        // 6. Record in blockchain
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
        // Check if message is encrypted
        if (!str_starts_with($encryptedMessage, 'ENCRYPTED:')) {
            return [
                'success' => true,
                'decrypted' => false,
                'message' => $encryptedMessage
            ];
        }
        
        // Extract encrypted data
        $encryptedData = base64_decode(substr($encryptedMessage, 10)); // Remove 'ENCRYPTED:' prefix
        $secureMessage = json_decode($encryptedData, true);
        
        if (!$secureMessage) {
            throw new Exception('Invalid encrypted message format');
        }
        
        // Decrypt message
        $decryptedMessage = \Blockchain\Core\Cryptography\MessageEncryption::decryptSecureMessage(
            $secureMessage, 
            $privateKey, 
            $senderPublicKey
        );
        
        return [
            'success' => true,
            'decrypted' => true,
            'message' => $decryptedMessage,
            'verified' => !empty($senderPublicKey)
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
        
        $memo = $transaction['memo'] ?? '';
        
        if (empty($memo)) {
            return [
                'success' => true,
                'decrypted' => false,
                'message' => 'No message in this transaction'
            ];
        }
        
        // Try to decrypt if encrypted
        if (str_starts_with($memo, 'ENCRYPTED:')) {
            // Get sender public key for verification
            $senderAddress = $transaction['from_address'];
            $senderWallet = $walletManager->getWalletByAddress($senderAddress);
            $senderPublicKey = $senderWallet['public_key'] ?? '';
            
            $decryptResult = decryptMessage($memo, $privateKey, $senderPublicKey);
            
            if ($decryptResult['success']) {
                return [
                    'success' => true,
                    'decrypted' => true,
                    'message' => $decryptResult['message'],
                    'verified' => $decryptResult['verified'] ?? false
                ];
            } else {
                return $decryptResult;
            }
        } else {
            return [
                'success' => true,
                'decrypted' => false,
                'message' => $memo
            ];
        }
        
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
