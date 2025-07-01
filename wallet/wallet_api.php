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
    
    // Подключаем конфиг (без fallback паролей!)
    $configFile = $baseDir . '/config/config.php';
    if (!file_exists($configFile)) {
        throw new Exception('Configuration file not found. Please check config/config.php');
    }
    
    $config = require $configFile;
    
    // Подключение к базе данных из конфига (без fallback паролей!)
    $dbConfig = $config['database'];
    $pdo = new PDO(
        "mysql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['database']};charset=utf8mb4",
        $dbConfig['username'],
        $dbConfig['password'],
        $dbConfig['options'] ?? [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
    
    // Подключаем класс WalletManager
    require_once $baseDir . '/wallet/WalletManager.php';
    
    // Создаём экземпляр WalletManager
    $walletManager = new \Blockchain\Wallet\WalletManager($pdo, $config);
    
    // Получение данных запроса
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    
    switch ($action) {
        case 'create_wallet':
            $result = createWallet($walletManager);
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
            $result = getConfigInfo($config);
            break;
            
        case 'create_wallet_from_mnemonic':
            $mnemonic = $input['mnemonic'] ?? [];
            if (empty($mnemonic)) {
                throw new Exception('Mnemonic phrase is required');
            }
            $result = createWalletFromMnemonic($walletManager, $mnemonic);
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
            $result = restoreWalletFromMnemonic($walletManager, $mnemonic);
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
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    // Записываем в файл логов
    writeLog("Wallet API Error: " . json_encode($errorInfo), 'ERROR');
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'debug_info' => [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => explode("\n", $e->getTraceAsString())
        ]
    ]);
}

/**
 * Создание нового кошелька через WalletManager
 */
function createWallet($walletManager) {
    try {
        $walletData = $walletManager->createWallet();
        return [
            'wallet' => $walletData
        ];
    } catch (Exception $e) {
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
        $stakedBalance = $walletManager->getStakedBalance($address);
        $totalBalance = $walletManager->getBalance($address);
        
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
function createWalletFromMnemonic($walletManager, array $mnemonic) {
    try {
        $walletData = $walletManager->createWalletFromMnemonic($mnemonic);
        return [
            'wallet' => $walletData
        ];
    } catch (Exception $e) {
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
function restoreWalletFromMnemonic($walletManager, array $mnemonic) {
    try {
        writeLog("Starting wallet restoration from mnemonic", 'INFO');
        
        $walletData = $walletManager->restoreWalletFromMnemonic($mnemonic);
        
        writeLog("Wallet restored successfully: " . $walletData['address'], 'INFO');
        
        return [
            'wallet' => $walletData,
            'restored' => true
        ];
    } catch (Exception $e) {
        writeLog("Exception in restoreWalletFromMnemonic: " . $e->getMessage(), 'ERROR');
        throw new Exception('Failed to restore wallet: ' . $e->getMessage());
    }
}

/**
 * Получение информации о конфигурации
 */
function getConfigInfo(array $config) {
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
