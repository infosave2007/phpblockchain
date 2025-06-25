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
            
        default:
            throw new Exception('Unknown action: ' . $action);
    }
    
    echo json_encode([
        'success' => true,
        ...$result
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
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
        // Сначала проверяем валидность
        if (!\Blockchain\Core\Cryptography\Mnemonic::validate($mnemonic)) {
            throw new Exception('Invalid mnemonic phrase');
        }

        // Получаем ключи из мнемоники
        require_once __DIR__ . '/../core/cryptography/KeyPair.php';
        $keyPair = \Blockchain\Core\Cryptography\KeyPair::fromMnemonic($mnemonic);
        $address = $keyPair->getAddress();

        // Попытаемся создать кошелек (или найти существующий)
        try {
            $walletData = $walletManager->createWalletFromMnemonic($mnemonic);
            return [
                'wallet' => $walletData,
                'restored' => true
            ];
        } catch (Exception $e) {
            // Если кошелек уже существует, это нормально для восстановления
            if (strpos($e->getMessage(), 'already exists') !== false) {
                $walletInfo = $walletManager->getWalletInfo($address);
                if ($walletInfo) {
                    return [
                        'wallet' => [
                            'address' => $address,
                            'public_key' => $keyPair->getPublicKey(),
                            'private_key' => $keyPair->getPrivateKey()
                        ],
                        'restored' => true,
                        'existing' => true
                    ];
                }
            }
            throw $e;
        }
    } catch (Exception $e) {
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
?>
