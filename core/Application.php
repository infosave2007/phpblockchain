<?php
declare(strict_types=1);

namespace Blockchain\Core;

// Define version constant if not already defined
if (!defined('BLOCKCHAIN_VERSION')) {
    define('BLOCKCHAIN_VERSION', '2.0.0');
}

use Blockchain\API\BlockchainAPI;
use Blockchain\Core\Blockchain\Blockchain;
use Blockchain\Core\Storage\BlockStorage;
use Blockchain\Core\Events\EventDispatcher;
use Blockchain\Core\Consensus\ValidatorManager;
use Blockchain\Nodes\NodeManager;
use Blockchain\Core\Network\MultiCurl;
use Blockchain\Contracts\SmartContractManager;
use Blockchain\Core\Consensus\ProofOfStake;
use Blockchain\Core\Logging\NullLogger;
use Blockchain\Core\Cryptography\MessageEncryption;
use Blockchain\Core\Recovery\BlockchainRecoveryManager;
use Blockchain\Wallet\WalletManager;
use Exception;
use PDO;

/**
 * Main Application Class
 * 
 * Central orchestrator for Modern Blockchain Platform
 */
class Application
{
    private array $config;
    private ?PDO $database = null;
    private ?Blockchain $blockchain = null;
    private ?NodeManager $nodeManager = null;
    private ?SmartContractManager $contractManager = null;
    private ?ProofOfStake $consensus = null;
    private ?BlockchainAPI $api = null;
    private ?BlockchainRecoveryManager $recoveryManager = null;
    private ?ValidatorManager $validatorManager = null;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->initializeDatabase();
        $this->initializeValidatorManager();
        $this->initializeConsensus();
        $this->initializeNodeManager();
        $this->initializeBlockchain();
        $this->initializeSmartContracts();
        $this->initializeAPI();
    }

    /**
     * Initialize database connection
     */
    private function initializeDatabase(): void
    {
        try {
            require_once __DIR__ . '/Database/DatabaseManager.php';
            $this->database = \Blockchain\Core\Database\DatabaseManager::getConnection();
        } catch (Exception $e) {
            throw new Exception("Database connection failed: " . $e->getMessage());
        }
    }

    /**
     * Initialize validator manager
     */
    private function initializeValidatorManager(): void
    {
        $this->validatorManager = new ValidatorManager($this->database, $this->config);
    }

    /**
     * Initialize blockchain
     */
    private function initializeBlockchain(): void
    {
        // Create required components for blockchain
        $storage = new BlockStorage('blockchain.json', $this->database, $this->validatorManager);
        $eventDispatcher = new EventDispatcher();
        
        // Create blockchain with proper parameters
        $this->blockchain = new Blockchain($storage, $this->consensus, $this->nodeManager, $eventDispatcher, $this->validatorManager);
    }

    /**
     * Initialize consensus mechanism
     */
    private function initializeConsensus(): void
    {
    $this->consensus = new ProofOfStake(new NullLogger());
    }

    /**
     * Initialize node manager
     */
    private function initializeNodeManager(): void
    {
        $multiCurl = new MultiCurl();
        $eventDispatcher = new EventDispatcher();
        
    $this->nodeManager = new NodeManager($multiCurl, $eventDispatcher, new NullLogger(), $this->config);
    }

    /**
     * Initialize smart contracts
     */
    private function initializeSmartContracts(): void
    {
    // Real SmartContractManager wiring (no mocks)
    $logger = new NullLogger();
        $vm = new \Blockchain\Core\SmartContract\VirtualMachine(3000000);
        $stateStorage = new \Blockchain\Core\Storage\StateStorage($this->database);
        $this->contractManager = new SmartContractManager($vm, $stateStorage, $logger, $this->config);

        // Optional: deploy standard contracts only if explicitly enabled
        try {
            $stakingAddr = $this->config['staking']['contract_address'] ?? '';
            $autoDeploy = (bool)($this->config['contracts']['auto_deploy']['enabled'] ?? false);
            if (empty($stakingAddr) && $autoDeploy) {
                $results = $this->contractManager->deployStandardContracts($this->config['admin']['deployer'] ?? '');
                if (!empty($results['staking']['success']) && !empty($results['staking']['address'])) {
                    $cachePath = dirname(__DIR__) . '/../storage/contract_addresses.json';
                    $existing = [];
                    if (is_file($cachePath)) {
                        $json = @file_get_contents($cachePath);
                        $decoded = json_decode((string)$json, true);
                        if (is_array($decoded)) $existing = $decoded;
                    }
                    $existing['staking_contract'] = $results['staking']['address'];
                    @file_put_contents($cachePath, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                }
            }
        } catch (\Throwable $e) {
            // Non-fatal: contracts can be deployed later via API
        }
    }

    /**
     * Initialize API layer
     */
    private function initializeAPI(): void
    {
    // Create a simple logger for API
    $logger = new NullLogger();
        // Create a simple WalletManager instance
        $walletManager = new WalletManager($this->database, $this->config);
        
        $this->api = new BlockchainAPI(
            $this->blockchain,
            $this->contractManager,
            $this->nodeManager,
            $this->consensus,
            $walletManager,
            $logger,
            $this->config
        );
    }

    /**
     * Run the blockchain node
     */
    public function run(): void
    {
        echo "Starting blockchain node...\n";
        echo "Network: " . ($this->config['blockchain']['network_name'] ?? 'Modern Blockchain Platform') . "\n";
        echo "Token: " . ($this->config['blockchain']['token_symbol'] ?? 'MBC') . "\n";
        
        // Start consensus engine
        $this->consensus->start();
        
        // Start node manager
        $this->nodeManager->start();
        
        // Keep the application running
        while (true) {
            sleep(1);
            $this->processBlockchain();
        }
    }

    /**
     * Process blockchain operations
     */
    private function processBlockchain(): void
    {
        // Process pending transactions
        // Validate and add new blocks
        // Sync with network
    }

    /**
     * Handle CLI commands
     */
    public function handleCommand(array $args): void
    {
        $command = $args[1] ?? 'help';
        
        switch ($command) {
            case 'start':
                $this->run();
                break;
                
            case 'status':
                $this->showStatus();
                break;
                
            case 'balance':
                $address = $args[2] ?? null;
                if ($address) {
                    $balance = $this->blockchain->getBalance($address);
                    echo "Balance: {$balance} " . ($this->config['blockchain']['token_symbol'] ?? 'MBC') . "\n";
                } else {
                    echo "Please provide an address\n";
                }
                break;
                
            case 'send':
                $from = $args[2] ?? null;
                $to = $args[3] ?? null;
                $amount = $args[4] ?? null;
                
                if ($from && $to && $amount) {
                    // This would create and broadcast a transaction
                    echo "Sending {$amount} " . ($this->config['blockchain']['token_symbol'] ?? 'MBC') . " from {$from} to {$to}\n";
                } else {
                    echo "Usage: send <from> <to> <amount>\n";
                }
                break;
                
            case 'send-message':
                $from = $args[2] ?? null;
                $to = $args[3] ?? null;
                $message = $args[4] ?? null;
                $encrypt = ($args[5] ?? 'false') === 'true';
                
                if ($from && $to && $message) {
                    $this->sendMessage($from, $to, $message, $encrypt);
                } else {
                    echo "Usage: send-message <from> <to> <message> [encrypt=true/false]\n";
                }
                break;
                
            case 'read-messages':
                $address = $args[2] ?? null;
                $privateKey = $args[3] ?? null;
                
                if ($address) {
                    $this->readMessages($address, $privateKey);
                } else {
                    echo "Usage: read-messages <address> [private_key_for_decryption]\n";
                }
                break;
                
            case 'generate-keys':
                $this->generateKeyPair();
                break;
                
            case 'create-wallet':
                $name = $args[2] ?? null;
                if ($name) {
                    $this->createWallet($name);
                } else {
                    echo "Usage: create-wallet <wallet_name>\n";
                }
                break;
                
            case 'help':
            default:
                $this->showHelp();
                break;
        }
    }

    /**
     * Show system status
     */
    private function showStatus(): void
    {
        echo ($this->config['blockchain']['network_name'] ?? 'Modern Blockchain Platform') . " Status\n";
        echo "====================\n";
        echo "Version: " . BLOCKCHAIN_VERSION . "\n";
        echo "Blockchain height: " . $this->blockchain->getHeight() . "\n";
        echo "Connected nodes: " . count($this->nodeManager->getConnectedNodes()) . "\n";
        echo "Consensus: " . $this->config['blockchain']['consensus_algorithm'] . "\n";
        echo "Token Symbol: " . ($this->config['blockchain']['token_symbol'] ?? 'MBC') . "\n";
        echo "Total Supply: " . $this->blockchain->getTotalSupply() . " " . ($this->config['blockchain']['token_symbol'] ?? 'MBC') . "\n";
    }

    /**
     * Show help information
     */
    private function showHelp(): void
    {
        echo "Modern Blockchain Platform v" . BLOCKCHAIN_VERSION . "\n";
        echo "================================\n";
        echo "Available commands:\n";
        echo "  start                           Start the blockchain node\n";
        echo "  status                          Show system status\n";  
        echo "  balance <addr>                  Show balance for address\n";
        echo "  send <from> <to> <amount>       Send tokens\n";
        echo "  send-message <from> <to> <msg> [encrypt]  Send encrypted message\n";
        echo "  read-messages <addr> [priv_key] Read messages for address\n";
        echo "  generate-keys                   Generate new RSA key pair\n";
        echo "  create-wallet <name>            Create new wallet with keys\n";
        echo "  help                            Show this help\n";
    }

    /**
     * Render dashboard web interface
     */
    public function renderDashboard(): void
    {
        header('Content-Type: text/html');
        echo "<!DOCTYPE html><html><head><title>" . ($this->config['blockchain']['network_name'] ?? 'Modern Blockchain Platform') . " Dashboard</title></head><body>";
        echo "<h1>" . ($this->config['blockchain']['network_name'] ?? 'Modern Blockchain Platform') . " Dashboard</h1>";
        echo "<p>Blockchain Height: " . $this->blockchain->getHeight() . "</p>";
        echo "<p>Token: " . ($this->config['blockchain']['token_symbol'] ?? 'MBC') . "</p>";
        echo "<p>Network: " . ($this->config['blockchain']['network_name'] ?? 'Modern Blockchain Platform') . "</p>";
        echo "</body></html>";
    }

    /**
     * Render wallet web interface
     */
    public function renderWallet(): void
    {
        header('Content-Type: text/html');
        echo "<!DOCTYPE html><html><head><title>" . ($this->config['blockchain']['network_name'] ?? 'Modern Blockchain Platform') . " Wallet</title></head><body>";
        echo "<h1>" . ($this->config['blockchain']['network_name'] ?? 'Modern Blockchain Platform') . " Wallet</h1>";
        echo "<p>Token Symbol: " . ($this->config['blockchain']['token_symbol'] ?? 'MBC') . "</p>";
        echo "</body></html>";
    }

    /**
     * Render explorer web interface
     */
    public function renderExplorer(): void
    {
        header('Content-Type: text/html');
        echo "<!DOCTYPE html><html><head><title>" . ($this->config['blockchain']['network_name'] ?? 'Modern Blockchain Platform') . " Explorer</title></head><body>";
        echo "<h1>" . ($this->config['blockchain']['network_name'] ?? 'Modern Blockchain Platform') . " Explorer</h1>";
        echo "<p>Explore the " . ($this->config['blockchain']['network_name'] ?? 'Modern Blockchain Platform') . " blockchain</p>";
        echo "</body></html>";
    }

    /**
     * Render admin web interface
     */
    public function renderAdmin(): void
    {
        header('Content-Type: text/html');
        echo "<!DOCTYPE html><html><head><title>" . ($this->config['blockchain']['network_name'] ?? 'Modern Blockchain Platform') . " Admin</title></head><body>";
        echo "<h1>" . ($this->config['blockchain']['network_name'] ?? 'Modern Blockchain Platform') . " Admin</h1>";
        echo "<p>Administrative interface</p>";
        echo "</body></html>";
    }

    /**
     * Handle API requests
     */
    public function handleApiRequest(string $path, string $method): void
    {
        if ($this->api) {
            $this->api->handleRequest($method, $path);
        } else {
            http_response_code(503);
            echo json_encode(['error' => 'API not available']);
        }
    }

    /**
     * Create database tables
     */
    public function createTables(): void
    {
        try {
            $dbConfig = $this->config['database'];
            
            // Check if database already exists, if not create it
            \Blockchain\Core\Database\DatabaseManager::createDatabaseIfNotExists($dbConfig['database']);
            
            // Now create tables in the main database
            $this->database->exec("
                CREATE TABLE IF NOT EXISTS blocks (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    hash VARCHAR(64) UNIQUE NOT NULL,
                    previous_hash VARCHAR(64),
                    merkle_root VARCHAR(64),
                    timestamp INT NOT NULL,
                    nonce INT,
                    difficulty INT,
                    data TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");

            $this->database->exec("
                CREATE TABLE IF NOT EXISTS transactions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    hash VARCHAR(64) UNIQUE NOT NULL,
                    from_address VARCHAR(42),
                    to_address VARCHAR(42) NOT NULL,
                    amount DECIMAL(20,8) NOT NULL,
                    fee DECIMAL(20,8) DEFAULT 0,
                    data TEXT,
                    signature TEXT,
                    block_hash VARCHAR(64),
                    timestamp INT NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (block_hash) REFERENCES blocks(hash)
                )
            ");

            $this->database->exec("
                CREATE TABLE IF NOT EXISTS wallets (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    address VARCHAR(42) UNIQUE NOT NULL,
                    public_key TEXT NOT NULL,
                    private_key TEXT,
                    balance DECIMAL(20,8) DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_address (address)
                )
            ");

            echo "Database tables created successfully\n";
            
        } catch (Exception $e) {
            throw new Exception("Failed to create tables: " . $e->getMessage());
        }
    }
    
    /**
     * Send a message (optionally encrypted) via blockchain transaction
     */
    private function sendMessage(string $from, string $to, string $message, bool $encrypt = false): void
    {
        try {
            $messageData = [
                'type' => 'message',
                'content' => $message,
                'encrypted' => $encrypt,
                'timestamp' => time(),
                'from' => $from,
                'to' => $to
            ];
            
            if ($encrypt) {
                // Get recipient's public key and sender's private key
                $recipientPublicKey = $this->getPublicKey($to);
                $senderPrivateKey = $this->getPrivateKey($from);
                
                if (!$recipientPublicKey) {
                    echo "Error: Could not find public key for recipient address: {$to}\n";
                    return;
                }
                
                if (!$senderPrivateKey) {
                    echo "Error: Could not find private key for sender address: {$from}\n";
                    return;
                }
                
                // Create secure encrypted message
                $secureMessage = MessageEncryption::createSecureMessage(
                    $message,
                    $recipientPublicKey,
                    $senderPrivateKey
                );
                
                $messageData['content'] = $secureMessage;
                $messageData['encryption_method'] = 'hybrid_rsa_aes';
            }
            
            // Create transaction with message data
            $messageJson = json_encode($messageData);
            
            // Store message transaction
            $this->storeMessageTransaction($from, $to, $messageJson);
            
            echo "Message sent successfully!\n";
            echo "From: {$from}\n";
            echo "To: {$to}\n";
            echo "Encrypted: " . ($encrypt ? 'Yes (Hybrid RSA+AES)' : 'No') . "\n";
            echo "Signed: " . ($encrypt ? 'Yes' : 'No') . "\n";
            echo "Size: " . strlen($messageJson) . " bytes\n";
            
        } catch (Exception $e) {
            echo "Error sending message: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * Read messages for a specific address
     */
    private function readMessages(string $address, ?string $privateKey = null): void
    {
        try {
            $messages = $this->getMessagesForAddress($address);
            
            if (empty($messages)) {
                echo "No messages found for address: {$address}\n";
                return;
            }
            
            echo "Messages for address: {$address}\n";
            echo "================================\n";
            
            foreach ($messages as $message) {
                echo "\nMessage ID: {$message['id']}\n";
                echo "From: {$message['from_address']}\n";
                echo "Timestamp: " . date('Y-m-d H:i:s', $message['timestamp']) . "\n";
                
                $messageData = json_decode($message['data'], true);
                
                if ($messageData['encrypted'] && $privateKey) {
                    try {
                        // Get sender's public key for signature verification
                        $senderPublicKey = $this->getPublicKey($message['from_address']);
                        
                        if ($senderPublicKey) {
                            $decryptedContent = MessageEncryption::decryptSecureMessage(
                                $messageData['content'],
                                $privateKey,
                                $senderPublicKey
                            );
                            echo "Content: {$decryptedContent}\n";
                            echo "Status: Decrypted & Verified\n";
                        } else {
                            echo "Content: [ENCRYPTED - Cannot verify sender signature]\n";
                            echo "Status: Encrypted (signature verification failed)\n";
                        }
                    } catch (Exception $e) {
                        echo "Content: [ENCRYPTED - Decryption failed: " . $e->getMessage() . "]\n";
                        echo "Status: Encrypted (decryption/verification failed)\n";
                    }
                } elseif ($messageData['encrypted']) {
                    echo "Content: [ENCRYPTED - Private key required for decryption]\n";
                    echo "Status: Encrypted\n";
                } else {
                    echo "Content: {$messageData['content']}\n";
                    echo "Status: Plain text\n";
                }
                
                echo "Encryption: " . ($messageData['encrypted'] ? ($messageData['encryption_method'] ?? 'Unknown') : 'None') . "\n";
                echo "Transaction Hash: {$message['hash']}\n";
                echo "---\n";
            }
            
        } catch (Exception $e) {
            echo "Error reading messages: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * Get private key for an address (placeholder implementation)
     */
    private function getPrivateKey(string $address): ?string
    {
        try {
            $stmt = $this->database->prepare("SELECT private_key FROM wallets WHERE address = ?");
            $stmt->execute([$address]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $result ? $result['private_key'] : null;
        } catch (Exception $e) {
            return null;
        }
    }
    
    /**
     * Generate new RSA key pair
     */
    private function generateKeyPair(): void
    {
        try {
            $keyPair = MessageEncryption::generateRSAKeyPair(2048);
            
            echo "RSA Key Pair Generated Successfully!\n";
            echo "=====================================\n";
            echo "Public Key:\n";
            echo $keyPair['public_key'] . "\n";
            echo "\nPrivate Key:\n";
            echo $keyPair['private_key'] . "\n";
            echo "\nIMPORTANT: Save your private key securely! It cannot be recovered if lost.\n";
            echo "The public key can be shared with others to receive encrypted messages.\n";
            
        } catch (Exception $e) {
            echo "Error generating key pair: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * Create new wallet with RSA keys
     */
    private function createWallet(string $name): void
    {
        try {
            // Generate key pair
            $keyPair = MessageEncryption::generateRSAKeyPair(2048);
            
            // Generate address from public key
            $address = $this->generateAddressFromPublicKey($keyPair['public_key']);
            
            // Store wallet in database
            $stmt = $this->database->prepare("
                INSERT INTO wallets (address, public_key, private_key, balance) 
                VALUES (?, ?, ?, 0.0)
                ON DUPLICATE KEY UPDATE public_key = VALUES(public_key), private_key = VALUES(private_key)
            ");
            
            $stmt->execute([$address, $keyPair['public_key'], $keyPair['private_key']]);
            
            echo "Wallet Created Successfully!\n";
            echo "=============================\n";
            echo "Name: {$name}\n";
            echo "Address: {$address}\n";
            echo "Public Key: " . substr($keyPair['public_key'], 0, 64) . "...\n";
            echo "Private Key: " . substr($keyPair['private_key'], 0, 64) . "...\n";
            echo "\nWALLET SAVED TO DATABASE\n";
            echo "IMPORTANT: Backup your private key securely!\n";
            
        } catch (Exception $e) {
            echo "Error creating wallet: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * Generate address from public key
     */
    private function generateAddressFromPublicKey(string $publicKey): string
    {
        $hash = hash('sha256', $publicKey);
        return '0x' . substr($hash, 0, 40);
    }
    
    /**
     * Store message transaction in database
     */
    private function storeMessageTransaction(string $from, string $to, string $messageData): void
    {
        $hash = hash('sha256', $from . $to . $messageData . time());
        
        $stmt = $this->database->prepare("
            INSERT INTO transactions (hash, from_address, to_address, amount, fee, data, timestamp) 
            VALUES (?, ?, ?, 0, 0.001, ?, ?)
        ");
        
        $stmt->execute([$hash, $from, $to, $messageData, time()]);
    }
    
    /**
     * Get messages for a specific address
     */
    private function getMessagesForAddress(string $address): array
    {
        $stmt = $this->database->prepare("
            SELECT id, hash, from_address, to_address, data, timestamp 
            FROM transactions 
            WHERE to_address = ? AND data IS NOT NULL AND data LIKE '%\"type\":\"message\"%'
            ORDER BY timestamp DESC
        ");
        
        $stmt->execute([$address]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Set recovery manager
     */
    public function setRecoveryManager(BlockchainRecoveryManager $recoveryManager): void
    {
        $this->recoveryManager = $recoveryManager;
    }
    
    /**
     * Get recovery manager
     */
    public function getRecoveryManager(): ?BlockchainRecoveryManager
    {
        return $this->recoveryManager;
    }
}
