<?php

namespace Blockchain\Core\WalletConnect;

use Exception;

/**
 * Integration with mobile wallets via WalletConnect
 */
class WalletConnectBridge 
{
    private array $clientMeta;
    private ?string $bridge;
    private ?array $session = null;
    private bool $connected = false;
    private array $accounts = [];
    private array $config;
    
    public function __construct(array $config = []) 
    {
        // Load configuration
        $this->config = $this->loadConfig();
        
        $this->bridge = $config['bridge'] ?? 'https://bridge.walletconnect.org';
        $this->clientMeta = $config['clientMeta'] ?? [
            'name' => $this->config['blockchain']['network_name'],
            'description' => $this->config['token']['description'],
            'url' => $this->config['token']['website'],
            'icons' => [$this->config['token']['logo_uri']]
        ];
    }
    
    /**
     * Load configuration
     */
    private function loadConfig(): array 
    {
        $configFile = __DIR__ . '/../../config/config.php';
        return file_exists($configFile) ? require $configFile : [];
    }
    
    /**
     * Create new WalletConnect session
     */
    public function createSession(): array 
    {
        $key = bin2hex(random_bytes(32));
        $bridge = $this->bridge;
        
        $sessionData = [
            'key' => $key,
            'bridge' => $bridge,
            'clientId' => uniqid(),
            'clientMeta' => $this->clientMeta,
            'chainId' => $this->config['blockchain']['chain_id'] ?? 1337,
            'accounts' => []
        ];
        
        // Generate URI for connection
        $uri = $this->generateConnectionURI($sessionData);
        
        // Save session
        $this->session = $sessionData;
        
        return [
            'uri' => $uri,
            'key' => $key,
            'bridge' => $bridge,
            'qr' => $this->generateQRCode($uri),
            'token_info' => $this->getTokenInfo()
        ];
    }
    
    /**
     * Generate connection URI
     */
    private function generateConnectionURI(array $sessionData): string 
    {
        $params = [
            'bridge' => urlencode($sessionData['bridge']),
            'key' => $sessionData['key']
        ];
        
        return 'wc:' . $sessionData['clientId'] . '@1?' . http_build_query($params);
    }
    
    /**
     * Generate QR code for connection
     */
    private function generateQRCode(string $uri): string 
    {
        // Simple QR code implementation for demonstration
        // In production use library like endroid/qr-code
        return base64_encode("QR Code for: " . $uri);
    }
    
    /**
     * Send transaction through connected wallet
     */
    public function sendTransaction(array $transaction): array 
    {
        if (!$this->connected) {
            throw new Exception("Wallet not connected");
        }
        
        $request = [
            'id' => uniqid(),
            'jsonrpc' => '2.0',
            'method' => 'eth_sendTransaction',
            'params' => [$this->formatTransaction($transaction)]
        ];
        
        return $this->sendCustomRequest($request);
    }
    
    /**
     * Sign message via connected wallet
     */
    public function signMessage(string $message): array 
    {
        if (!$this->connected) {
            throw new Exception("Wallet not connected");
        }
        
        $request = [
            'id' => uniqid(),
            'jsonrpc' => '2.0',
            'method' => 'personal_sign',
            'params' => [
                '0x' . bin2hex($message),
                $this->accounts[0] ?? ''
            ]
        ];
        
        return $this->sendCustomRequest($request);
    }
    
    /**
     * Send custom request
     */
    public function sendCustomRequest(array $request): array 
    {
        // In real implementation this will send via WebSocket or HTTP
        // to WalletConnect bridge server
        
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $this->bridge . '/session/' . $this->session['clientId'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($request),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->session['key']
            ]
        ]);
        
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        
        if ($httpCode !== 200) {
            throw new Exception("WalletConnect request failed: " . $response);
        }
        
        return json_decode($response, true);
    }
    
    /**
     * Format transaction for wallet
     */
    private function formatTransaction(array $transaction): array 
    {
        return [
            'from' => $transaction['from'] ?? $this->accounts[0] ?? '',
            'to' => $transaction['to'],
            'value' => '0x' . dechex($transaction['value'] ?? 0),
            'data' => $transaction['data'] ?? '0x',
            'gas' => '0x' . dechex($transaction['gasLimit'] ?? 21000),
            'gasPrice' => '0x' . dechex($transaction['gasPrice'] ?? 20000000000)
        ];
    }
    
    /**
     * Translate к сессии
     */
    public function connect(string $sessionKey): bool 
    {
        // Load сессию по ключу
        $this->session = $this->loadSession($sessionKey);
        
        if (!$this->session) {
            return false;
        }
        
        $this->connected = true;
        $this->accounts = $this->session['accounts'] ?? [];
        
        return true;
    }
    
    /**
     * Translate от сессии
     */
    public function disconnect(): void 
    {
        if ($this->session) {
            // Send запрос на отключение
            $request = [
                'id' => uniqid(),
                'jsonrpc' => '2.0',
                'method' => 'wc_sessionKill',
                'params' => []
            ];
            
            try {
                $this->sendCustomRequest($request);
            } catch (Exception $e) {
                // Игнорируем ошибки при отключении
            }
        }
        
        $this->session = null;
        $this->connected = false;
        $this->accounts = [];
    }
    
    /**
     * Translate сессии из хранилища
     */
    private function loadSession(string $sessionKey): ?array 
    {
        $sessionFile = __DIR__ . '/../../storage/walletconnect/' . $sessionKey . '.json';
        
        if (!file_exists($sessionFile)) {
            return null;
        }
        
        $data = file_get_contents($sessionFile);
        return json_decode($data, true);
    }
    
    /**
     * Translate сессии в хранилище
     */
    public function saveSession(): void 
    {
        if (!$this->session) {
            return;
        }
        
        $sessionDir = __DIR__ . '/../../storage/walletconnect/';
        if (!is_dir($sessionDir)) {
            mkdir($sessionDir, 0755, true);
        }
        
        $sessionFile = $sessionDir . $this->session['key'] . '.json';
        file_put_contents($sessionFile, json_encode($this->session, JSON_PRETTY_PRINT));
    }
    
    /**
     * Translate поддерживаемых мобильных кошельков
     */
    public function getSupportedWallets(): array 
    {
        return [
            'trust' => [
                'name' => 'Trust Wallet',
                'scheme' => 'trust://',
                'universal_link' => 'https://link.trustwallet.com/open_url?coin_id=60&url=',
                'logo' => '/assets/wallets/trust.png',
                'features' => ['send', 'sign', 'walletconnect']
            ],
            'metamask' => [
                'name' => 'MetaMask',
                'scheme' => 'metamask://',
                'universal_link' => 'https://metamask.app.link/wc?uri=',
                'logo' => '/assets/wallets/metamask.png',
                'features' => ['send', 'sign', 'walletconnect']
            ],
            'rainbow' => [
                'name' => 'Rainbow',
                'scheme' => 'rainbow://',
                'universal_link' => 'https://rnbwapp.com/wc?uri=',
                'logo' => '/assets/wallets/rainbow.png',
                'features' => ['send', 'sign', 'walletconnect']
            ],
            'coinbase' => [
                'name' => 'Coinbase Wallet',
                'scheme' => 'cbwallet://',
                'universal_link' => 'https://go.cb-w.com/walletconnect?uri=',
                'logo' => '/assets/wallets/coinbase.png',
                'features' => ['send', 'sign', 'walletconnect']
            ]
        ];
    }
    
    /**
     * Translate deep link для конкретного кошелька
     */
    public function generateDeepLink(string $walletId, string $uri): string 
    {
        $wallets = $this->getSupportedWallets();
        
        if (!isset($wallets[$walletId])) {
            throw new Exception("Unsupported wallet: " . $walletId);
        }
        
        $wallet = $wallets[$walletId];
        
        // Используем universal link если доступен
        if (isset($wallet['universal_link'])) {
            return $wallet['universal_link'] . urlencode($uri);
        }
        
        // Иначе используем scheme
        return $wallet['scheme'] . 'wc?uri=' . urlencode($uri);
    }
    
    /**
     * Translate статуса подключения
     */
    public function isConnected(): bool 
    {
        return $this->connected && !empty($this->accounts);
    }
    
    /**
     * Translate подключенных аккаунтов
     */
    public function getAccounts(): array 
    {
        return $this->accounts;
    }
    
    /**
     * Translate информации о токене из конфигурации
     */
    public function getTokenInfo(): array 
    {
        return [
            'name' => $this->config['token']['name'] ?? 'Unknown Token',
            'symbol' => $this->config['token']['symbol'] ?? 'UNK',
            'decimals' => $this->config['token']['decimals'] ?? 18,
            'chainId' => $this->config['blockchain']['chain_id'] ?? 1337,
            'contractAddress' => $this->config['token']['contract_address'],
            'logoURI' => $this->config['token']['logo_uri'],
            'website' => $this->config['token']['website'],
            'description' => $this->config['token']['description'],
            'explorer' => $this->config['token']['explorer'],
            'social' => $this->config['token']['social'] ?? []
        ];
    }
    
    /**
     * Translate токен-листа для мобильных кошельков
     */
    public function generateTokenList(): array 
    {
        $tokenInfo = $this->getTokenInfo();
        
        return [
            'name' => $tokenInfo['name'] . ' Token List',
            'logoURI' => $tokenInfo['logoURI'],
            'keywords' => ['blockchain', 'defi', 'token', strtolower($tokenInfo['symbol'])],
            'version' => [
                'major' => 1,
                'minor' => 0,
                'patch' => 0
            ],
            'tokens' => [
                [
                    'chainId' => $tokenInfo['chainId'],
                    'address' => $tokenInfo['contractAddress'] ?? '0x0000000000000000000000000000000000000000',
                    'name' => $tokenInfo['name'],
                    'symbol' => $tokenInfo['symbol'],
                    'decimals' => $tokenInfo['decimals'],
                    'logoURI' => $tokenInfo['logoURI']
                ]
            ]
        ];
    }
    
    /**
     * Translate network-специфичных настроек
     */
    public function getNetworkConfig(): array 
    {
        return [
            'networkName' => $this->config['blockchain']['network_name'] ?? 'Unknown Network',
            'chainId' => $this->config['blockchain']['chain_id'] ?? 1337,
            'rpcUrl' => 'http://localhost:' . ($this->config['network']['rpc_port'] ?? 8546),
            'explorerUrl' => $this->config['token']['explorer'],
            'nativeCurrency' => [
                'name' => $this->config['token']['name'],
                'symbol' => $this->config['token']['symbol'],
                'decimals' => $this->config['token']['decimals']
            ]
        ];
    }
    
    /**
     * Translate deep link с информацией о токене
     */
    public function generateTokenDeepLink(string $walletId, ?array $tokenInfo = null): string 
    {
        $wallets = $this->getSupportedWallets();
        
        if (!isset($wallets[$walletId])) {
            throw new Exception("Unsupported wallet: " . $walletId);
        }
        
        $wallet = $wallets[$walletId];
        $tokenInfo = $tokenInfo ?? $this->getTokenInfo();
        
        switch ($walletId) {
            case 'trust':
                // Trust Wallet добавление токена
                $params = http_build_query([
                    'asset' => $tokenInfo['contractAddress'],
                    'type' => 'ERC20'
                ]);
                return 'trust://add_asset?' . $params;
                
            case 'metamask':
                // MetaMask добавление токена
                $params = http_build_query([
                    'address' => $tokenInfo['contractAddress'],
                    'symbol' => $tokenInfo['symbol'],
                    'decimals' => $tokenInfo['decimals'],
                    'image' => $tokenInfo['logoURI']
                ]);
                return 'metamask://wallet/addEthereumChain?' . $params;
                
            default:
                // Обычная WalletConnect ссылка
                return $this->generateDeepLink($walletId, $this->session['uri'] ?? '');
        }
    }
}
