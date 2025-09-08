<?php
declare(strict_types=1);

namespace Blockchain\Contracts;

/**
 * Universal Token Contract - Main network token for DEX integration
 * ERC20 compatible with Uniswap V2 support
 * Uses database configuration for token parameters
 */
class Token
{
    private string $name;
    private string $symbol;
    private int $decimals;
    private string $totalSupply;
    private array $balances = [];
    private array $allowances = [];
    private ?\PDO $database = null;

    // DEX Router and Factory addresses (to be set after deployment)
    private string $uniswapRouter = '';
    private string $uniswapFactory = '';

    // Fee configuration for DEX operations
    private string $liquidityFee = '3'; // 0.3%
    private string $burnFee = '1'; // 0.1%
    private string $reflectionFee = '2'; // 0.2%

    /**
     * Constructor - initialize with token configuration from database
     */
    public function __construct(?\PDO $database = null)
    {
        $this->database = $database;
        // Load token configuration from database
        $this->loadTokenConfig();
    }

    /**
     * Load token configuration from database
     */
    private function loadTokenConfig(): void
    {
        try {
            // Use provided database connection or get standard one
            if ($this->database) {
                $pdo = $this->database;
            } else {
                $pdo = $this->getDatabaseConnection();
            }

            // Get token configuration
            $stmt = $pdo->prepare("SELECT key_name, value FROM config WHERE key_name IN (?, ?, ?, ?)");
            $stmt->execute(['network.token_name', 'network.token_symbol', 'network.decimals', 'network.initial_supply']);
            
            $config = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $config[$row['key_name']] = $row['value'];
            }

            // Set token parameters from database or use defaults
            $this->name = $config['network.token_name'] ?? 'Universal Token';
            $this->symbol = $config['network.token_symbol'] ?? 'UNI';
            $this->decimals = (int)($config['network.decimals'] ?? 18);
            
            // Calculate total supply with decimals
            $initialSupply = $config['network.initial_supply'] ?? '1000000000';
            $this->totalSupply = bcmul($initialSupply, bcpow('10', (string)$this->decimals), 0);

        } catch (\Exception $e) {
            // Fallback to default values if database is not available
            $this->name = 'Universal Token';
            $this->symbol = 'UNI';
            $this->decimals = 18;
            $this->totalSupply = '1000000000000000000000000000'; // 1 billion tokens with 18 decimals
        }
    }

    /**
     * Get standard database connection - reusing project's database helper
     */
    private function getDatabaseConnection(): \PDO
    {
        static $pdo = null;
        
        if ($pdo instanceof \PDO) {
            return $pdo;
        }
        
        // Try to get database config from global config
        $config = $GLOBALS['config'] ?? [];
        
        if (!empty($config['database']['host'])) {
            $host = $config['database']['host'];
            $port = $config['database']['port'] ?? 3306;
            $username = $config['database']['username'] ?? 'root';
            $password = $config['database']['password'] ?? '';
            $database = $config['database']['database'] ?? 'blockchain';
        } else {
            // Fallback to environment variables from config/.env
            $host = getenv('DB_HOST') ?: 'database';
            $port = (int)(getenv('DB_PORT') ?: 3306);
            $username = getenv('DB_USERNAME') ?: 'blockchain';
            $password = getenv('DB_PASSWORD') ?: 'blockchain123';
            $database = getenv('DB_DATABASE') ?: 'blockchain';
        }
        
        try {
            $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
            $pdo = new \PDO($dsn, $username, $password, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false
            ]);
            return $pdo;
        } catch (\PDOException $e) {
            throw new \Exception('Database connection failed: ' . $e->getMessage());
        }
    }

    /**
     * Contract initialization with initial supply
     */
    public function constructor(array $params = []): array
    {
        if (!empty($params['totalSupply'])) {
            $this->totalSupply = $params['totalSupply'];
        }

        if (!empty($params['deployer'])) {
            // Deployer gets initial supply
            $this->balances[$params['deployer']] = $this->totalSupply;
        }

        return [
            'success' => true,
            'name' => $this->name,
            'symbol' => $this->symbol,
            'decimals' => $this->decimals,
            'totalSupply' => $this->totalSupply
        ];
    }

    /**
     * Transfer tokens
     */
    public function transfer(array $params, array $context): array
    {
        $from = $context['caller'] ?? '';
        $to = $params['to'] ?? '';
        $amount = $params['amount'] ?? '0';

        if (empty($from) || empty($to) || $amount === '0') {
            return ['success' => false, 'error' => 'Invalid parameters'];
        }

        // Check balance
        if (!isset($this->balances[$from]) || $this->balances[$from] < $amount) {
            return ['success' => false, 'error' => 'Insufficient balance'];
        }

        // Apply DEX fees if transfer involves DEX operations
        $isDexOperation = $this->isDexOperation($from, $to);
        if ($isDexOperation) {
            $fees = $this->calculateFees($amount);
            $finalAmount = bcsub($amount, $fees['total'], 18);

            // Process fees
            $this->_burn($fees['burn'], $context);
            $this->_reflect($fees['reflection'], $context);

            $amount = $finalAmount;
        }

        // Execute transfer
        $this->balances[$from] = bcsub($this->balances[$from], $amount, 18);

        if (!isset($this->balances[$to])) {
            $this->balances[$to] = '0';
        }
        $this->balances[$to] = bcadd($this->balances[$to], $amount, 18);

        return [
            'success' => true,
            'from' => $from,
            'to' => $to,
            'amount' => $amount,
            'fees_deducted' => $isDexOperation ? $fees['total'] : '0'
        ];
    }

    /**
     * Approve token spending
     */
    public function approve(array $params, array $context): array
    {
        $owner = $context['caller'] ?? '';
        $spender = $params['spender'] ?? '';
        $amount = $params['amount'] ?? '0';

        if (empty($owner) || empty($spender)) {
            return ['success' => false, 'error' => 'Invalid parameters'];
        }

        if (!isset($this->allowances[$owner])) {
            $this->allowances[$owner] = [];
        }

        $this->allowances[$owner][$spender] = $amount;

        return [
            'success' => true,
            'owner' => $owner,
            'spender' => $spender,
            'amount' => $amount
        ];
    }

    /**
     * Transfer from approved amount
     */
    public function transferFrom(array $params, array $context): array
    {
        $spender = $context['caller'] ?? '';
        $from = $params['from'] ?? '';
        $to = $params['to'] ?? '';
        $amount = $params['amount'] ?? '0';

        if (empty($from) || empty($to) || empty($spender) || $amount === '0') {
            return ['success' => false, 'error' => 'Invalid parameters'];
        }

        // Check allowance
        if (!isset($this->allowances[$from][$spender]) ||
            $this->allowances[$from][$spender] < $amount) {
            return ['success' => false, 'error' => 'Insufficient allowance'];
        }

        // Check balance
        if (!isset($this->balances[$from]) || $this->balances[$from] < $amount) {
            return ['success' => false, 'error' => 'Insufficient balance'];
        }

        // Execute transfer
        $this->balances[$from] = bcsub($this->balances[$from], $amount, 18);
        $this->balances[$to] = bcadd($this->balances[$to] ?? '0', $amount, 18);

        // Reduce allowance
        $this->allowances[$from][$spender] = bcsub($this->allowances[$from][$spender], $amount, 18);

        return [
            'success' => true,
            'from' => $from,
            'to' => $to,
            'spender' => $spender,
            'amount' => $amount
        ];
    }

    /**
     * Get balance
     */
    public function balanceOf(array $params): array
    {
        $address = $params['address'] ?? '';
        if (empty($address)) {
            return ['success' => false, 'error' => 'Address required'];
        }

        $balance = $this->balances[$address] ?? '0';

        return [
            'success' => true,
            'address' => $address,
            'balance' => $balance
        ];
    }

    /**
     * Get allowance
     */
    public function allowance(array $params): array
    {
        $owner = $params['owner'] ?? '';
        $spender = $params['spender'] ?? '';

        if (empty($owner) || empty($spender)) {
            return ['success' => false, 'error' => 'Owner and spender required'];
        }

        $allowance = $this->allowances[$owner][$spender] ?? '0';

        return [
            'success' => true,
            'owner' => $owner,
            'spender' => $spender,
            'allowance' => $allowance
        ];
    }

    /**
     * Set DEX contract addresses
     */
    public function setDexContracts(array $params, array $context): array
    {
        // Only contract owner can set DEX contracts
        if (!isset($context['sender']) || $context['sender'] !== $this->balances[array_key_first($this->balances)] ?? '') {
            return ['success' => false, 'error' => 'Only owner can set DEX contracts'];
        }

        if (!empty($params['router'])) {
            $this->uniswapRouter = $params['router'];
        }

        if (!empty($params['factory'])) {
            $this->uniswapFactory = $params['factory'];
        }

        return [
            'success' => true,
            'router' => $this->uniswapRouter,
            'factory' => $this->uniswapFactory
        ];
    }

    /**
     * Check if transaction involves DEX operations
     */
    private function isDexOperation(string $from, string $to): bool
    {
        return ($from === $this->uniswapRouter || $to === $this->uniswapRouter ||
                $from === $this->uniswapFactory || $to === $this->uniswapFactory);
    }

    /**
     * Calculate DEX fees
     */
    private function calculateFees(string $amount): array
    {
        $liquidityFeeAmount = bcmul($amount, bcdiv($this->liquidityFee, '1000', 18), 18);
        $burnFeeAmount = bcmul($amount, bcdiv($this->burnFee, '1000', 18), 18);
        $reflectionFeeAmount = bcmul($amount, bcdiv($this->reflectionFee, '1000', 18), 18);

        $totalFees = bcadd(bcadd($liquidityFeeAmount, $burnFeeAmount, 18), $reflectionFeeAmount, 18);

        return [
            'liquidity' => $liquidityFeeAmount,
            'burn' => $burnFeeAmount,
            'reflection' => $reflectionFeeAmount,
            'total' => $totalFees
        ];
    }

    /**
     * Burn tokens (reduce total supply)
     */
    private function _burn(string $amount, array $context): void
    {
        if (isset($context['caller']) && isset($this->balances[$context['caller']])) {
            $burnAddress = '0x000000000000000000000000000000000000dEaD';
            if (!isset($this->balances[$burnAddress])) {
                $this->balances[$burnAddress] = '0';
            }
            $this->balances[$burnAddress] = bcadd($this->balances[$burnAddress], $amount, 18);
            $this->totalSupply = bcsub($this->totalSupply, $amount, 18);
        }
    }

    /**
     * Reflection fee distribution (redistribute to holders)
     */
    private function _reflect(string $amount, array $context): void
    {
        // Simple reflection: redistribute proportionally to all holders
        if (!empty($this->balances)) {
            $totalHoldersBalance = array_sum(array_map('floatval', $this->balances));
            if ($totalHoldersBalance > 0) {
                $reflectionMultiplier = floatval($amount) / $totalHoldersBalance;

                foreach ($this->balances as $address => $balance) {
                    if ($address !== ($context['caller'] ?? '')) {
                        $reflectionAmount = bcmul($balance, (string)$reflectionMultiplier, 18);
                        $this->balances[$address] = bcadd($this->balances[$address], $reflectionAmount, 18);
                    }
                }
            }
        }
    }

    /**
     * Get contract information
     */
    public function getInfo(): array
    {
        return [
            'name' => $this->name,
            'symbol' => $this->symbol,
            'decimals' => $this->decimals,
            'totalSupply' => $this->totalSupply,
            'uniswapRouter' => $this->uniswapRouter,
            'uniswapFactory' => $this->uniswapFactory,
            'fees' => [
                'liquidity' => $this->liquidityFee,
                'burn' => $this->burnFee,
                'reflection' => $this->reflectionFee
            ]
        ];
    }
}
