<?php
declare(strict_types=1);

namespace Blockchain\Contracts;

use Blockchain\Core\Contracts\SmartContractInterface;
use Blockchain\Core\Contracts\TransactionInterface;
use Blockchain\Core\SmartContract\VirtualMachine;
use Blockchain\Core\Storage\StateStorage;
use Blockchain\Core\Logging\LoggerInterface;

/**
 * Smart contract manager
 */
class SmartContractManager
{
    private VirtualMachine $vm;
    private StateStorage $stateStorage;
    private LoggerInterface $logger;
    private array $deployedContracts;
    private int $gasPrice;
    private int $maxGasPerTransaction;
    private array $config;
    private ?\PDO $database;

    public function __construct(
        VirtualMachine $vm,
        StateStorage $stateStorage,
        LoggerInterface $logger,
        array $config = [],
        int $gasPrice = 1,
        int $maxGasPerTransaction = 1000000,
        ?\PDO $database = null
    ) {
        $this->vm = $vm;
        $this->stateStorage = $stateStorage;
        $this->logger = $logger;
        $this->deployedContracts = [];
        $this->gasPrice = $gasPrice;
        $this->maxGasPerTransaction = $maxGasPerTransaction;
        $this->config = $config;
        $this->database = $database;
    }

    /**
     * Deploy smart contract
     */
    public function deployContract(
        string $code,
        array $constructorArgs = [],
        string $deployer = '',
        int $gasLimit = 100000,
        ?string $name = null
    ): array {
        try {
            // Generate contract address
            $contractAddress = $this->generateContractAddress($deployer, $code);
            
            // Check that contract is not yet deployed
            if (isset($this->deployedContracts[$contractAddress])) {
                throw new \Exception('Contract already deployed at this address');
            }

            // Compile contract (bytecode + abi)
            $compiled = $this->compileContract($code);
            $compiledCode = $compiled['bytecode'];
            $compiledAbi = $compiled['abi'];
            
            // Create initial contract state
            $initialState = [
                'code' => $compiledCode,
                'storage' => [],
                'balance' => 0,
                'deployer' => $deployer,
                'deployed_at' => time(),
                'constructor_args' => $constructorArgs,
                // Persist helpful metadata so explorer/API can query by contract name
                'name' => $name ?? 'Contract',
                'abi' => $compiledAbi,
                'source_code' => $code,
            ];

            // Execute constructor if exists
            if ($this->hasConstructor($compiledCode)) {
                $constructorResult = $this->executeConstructor(
                    $contractAddress,
                    $compiledCode,
                    $constructorArgs,
                    $gasLimit
                );
                
                if (!$constructorResult['success']) {
                    throw new \Exception('Constructor execution failed: ' . $constructorResult['error']);
                }
                
                $initialState['storage'] = $constructorResult['storage'];
                $gasUsed = $constructorResult['gasUsed'];
            } else {
                $gasUsed = 21000; // Base deployment cost
            }

            // Save contract
            $this->deployedContracts[$contractAddress] = $initialState;
            $this->stateStorage->saveContractState($contractAddress, $initialState);

            $this->logger->info("Smart contract deployed", [
                'address' => $contractAddress,
                'deployer' => $deployer,
                'gas_used' => $gasUsed
            ]);

            return [
                'success' => true,
                'address' => $contractAddress,
                'gasUsed' => $gasUsed,
                'transaction_hash' => hash('sha256', $contractAddress . time())
            ];

        } catch (\Exception $e) {
            $this->logger->error("Contract deployment failed", [
                'error' => $e->getMessage(),
                'deployer' => $deployer
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'gasUsed' => $gasLimit // Burn all gas on error
            ];
        }
    }

    /**
     * Call smart contract function
     */
    public function callContract(
        string $contractAddress,
        string $functionName,
        array $args = [],
        string $caller = '',
        int $gasLimit = 50000,
        int $value = 0
    ): array {
        try {
            // Check contract existence
            if (!isset($this->deployedContracts[$contractAddress])) {
                $contractState = $this->stateStorage->getContractState($contractAddress);
                if (!$contractState) {
                    throw new \Exception('Contract not found');
                }
                $this->deployedContracts[$contractAddress] = $contractState;
            }

            $contract = $this->deployedContracts[$contractAddress];
            
            // Prepare execution context
            $context = [
                'contract_address' => $contractAddress,
                'caller' => $caller,
                'value' => $value,
                'gas_limit' => $gasLimit,
                'gas_price' => $this->gasPrice,
                'timestamp' => time(),
                'block_number' => $this->getCurrentBlockNumber()
            ];

            // Execute function
            $result = $this->vm->executeFunction(
                $contract['code'],
                $functionName,
                $args,
                $contract['storage'],
                $context
            );

            if ($result['success']) {
                // Update contract state
                $this->deployedContracts[$contractAddress]['storage'] = $result['storage'];
                $this->deployedContracts[$contractAddress]['balance'] += $value;
                
                // Save changes
                $this->stateStorage->saveContractState(
                    $contractAddress,
                    $this->deployedContracts[$contractAddress]
                );

                $this->logger->info("Contract function executed", [
                    'address' => $contractAddress,
                    'function' => $functionName,
                    'caller' => $caller,
                    'gas_used' => $result['gasUsed']
                ]);
            }

            return $result;

        } catch (\Exception $e) {
            $this->logger->error("Contract call failed", [
                'address' => $contractAddress,
                'function' => $functionName,
                'caller' => $caller,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'gasUsed' => $gasLimit
            ];
        }
    }

    /**
     * Get contract state
     */
    public function getContractState(string $contractAddress): ?array
    {
        if (isset($this->deployedContracts[$contractAddress])) {
            return $this->deployedContracts[$contractAddress];
        }

        return $this->stateStorage->getContractState($contractAddress);
    }

    /**
     * Check balance contract
     */
    public function getContractBalance(string $contractAddress): int
    {
        $state = $this->getContractState($contractAddress);
        return $state['balance'] ?? 0;
    }

    /**
     * Get value from contract storage
     */
    public function getStorageValue(string $contractAddress, string $key): mixed
    {
        $state = $this->getContractState($contractAddress);
        return $state['storage'][$key] ?? null;
    }

    /**
     * Check contract existence
     */
    public function contractExists(string $contractAddress): bool
    {
        return $this->getContractState($contractAddress) !== null;
    }

    /**
     * Check code contract
     */
    public function getContractCode(string $contractAddress): ?string
    {
        $state = $this->getContractState($contractAddress);
        return $state['code'] ?? null;
    }

    /**
     * Estimate function execution cost
     */
    public function estimateGas(
        string $contractAddress,
        string $functionName,
        array $args = [],
        string $caller = ''
    ): int {
        try {
            // Execute dry run without state changes
            $result = $this->callContract(
                $contractAddress,
                $functionName,
                $args,
                $caller,
                $this->maxGasPerTransaction,
                0
            );

            return $result['gasUsed'] ?? $this->maxGasPerTransaction;

        } catch (\Exception $e) {
            return $this->maxGasPerTransaction;
        }
    }

    /**
     * Check events contract
     */
    public function getContractEvents(string $contractAddress, int $fromBlock = 0, int $toBlock = -1): array
    {
        return $this->stateStorage->getContractEvents($contractAddress, $fromBlock, $toBlock);
    }

    /**
     * Check address contract
     */
    private function generateContractAddress(string $deployer, string $code): string
    {
        $data = $deployer . $code . time() . mt_rand();
        $hash = hash('sha256', $data);
        return '0x' . substr($hash, 0, 40);
    }

    /**
     * Compile smart contract using professional compiler
     */
    private function compileContract(string $code): array
    {
        $compiler = new \Blockchain\Core\SmartContract\Compiler();
        $result = $compiler->compile($code);
        
        if (!$result['success']) {
            throw new \Exception("Contract compilation failed: " . $result['error']);
        }

        // Return full compile data (bytecode + abi) for persistence
        return [
            'bytecode' => (string)$result['bytecode'],
            'abi' => (array)($result['abi'] ?? []),
        ];
    }

    /**
     * Check for constructor presence
     */
    private function hasConstructor(string $compiledCode): bool
    {
        // Check for constructor presence in code
        return strpos($compiledCode, 'constructor') !== false;
    }

    /**
     * Execute contract constructor
     */
    private function executeConstructor(
        string $contractAddress,
        string $compiledCode,
        array $args,
        int $gasLimit
    ): array {
        $context = [
            'contract_address' => $contractAddress,
            'caller' => '',
            'value' => 0,
            'gas_limit' => $gasLimit,
            'gas_price' => $this->gasPrice,
            'timestamp' => time(),
            'block_number' => $this->getCurrentBlockNumber()
        ];

        return $this->vm->executeFunction(
            $compiledCode,
            'constructor',
            $args,
            [],
            $context
        );
    }

    /**
     * Check current block number
     */
    private function getCurrentBlockNumber(): int
    {
        // TODO: Implement logic to obtain the current block number
        return 1;
    }

    /**
     * Deploy standard contracts
     */
    public function deployStandardContracts(string $deployer): array
    {
        $results = [];

        // Get network and token configuration from database
        $tokenConfig = $this->getTokenConfigFromDatabase();
        $networkConfig = $this->getNetworkConfigFromDatabase();

        // Main network token (from database settings)
        $tokenCode = $this->getMainTokenTemplate();
        $tokenSupply = $this->calculateTokenSupplyWithDecimals(
            $tokenConfig['initial_supply'] ?? $networkConfig['total_supply'] ?? '2100000000',
            $tokenConfig['decimals'] ?? 18
        );
        
        $results['main_token'] = $this->deployContract(
            $tokenCode,
            [
                'deployer' => $deployer, 
                'totalSupply' => $tokenSupply,
                'name' => $tokenConfig['token_name'] ?? 'Blockchain Token',
                'symbol' => $tokenConfig['token_symbol'] ?? 'COIN'
            ],
            $deployer,
            100000,
            $tokenConfig['token_name'] ?? 'MainToken'
        );

        // Uniswap V2 Factory
        $factoryCode = $this->getUniswapV2FactoryTemplate();
        $results['uniswap_factory'] = $this->deployContract(
            $factoryCode,
            ['feeToSetter' => $deployer],
            $deployer,
            100000,
            'UniswapV2Factory'
        );

        // WETH Token (Wrapped version of main token)
        $wethCode = $this->getWETHTemplate();
        $wethName = 'Wrapped ' . ($tokenConfig['token_name'] ?? 'Token');
        $wethSymbol = 'W' . ($tokenConfig['token_symbol'] ?? 'COIN');
        
        $results['weth'] = $this->deployContract(
            $wethCode,
            ['name' => $wethName, 'symbol' => $wethSymbol],
            $deployer,
            100000,
            'WETH'
        );

        // Uniswap V2 Router
        $routerCode = $this->getUniswapV2RouterTemplate();
        $factoryAddress = $results['uniswap_factory']['address'] ?? '';
        $wethAddress = $results['weth']['address'] ?? '';
        $results['uniswap_router'] = $this->deployContract(
            $routerCode,
            ['factory' => $factoryAddress, 'WETH' => $wethAddress],
            $deployer,
            100000,
            'UniswapV2Router'
        );

        // Legacy ERC-20 token (for compatibility)
        $erc20Code = $this->getERC20Template();
        $legacyTokenName = ($networkConfig['name'] ?? 'Blockchain') . ' Legacy Token';
        $legacyTokenSymbol = ($tokenConfig['token_symbol'] ?? 'COIN') . 'L';
        $legacyTokenSupply = (int)($tokenConfig['initial_supply'] ?? 1000000);

        $results['erc20'] = $this->deployContract(
            $erc20Code,
            [$legacyTokenName, $legacyTokenSymbol, 18, $legacyTokenSupply],
            $deployer,
            100000,
            'ERC20'
        );

        // Staking contract with configurable settings from database
        $stakingCode = $this->getStakingTemplateInternal();
        $stakingConfig = $this->getStakingConfigFromDatabase();
        $blockTime = (int)($networkConfig['block_time'] ?? 10); // seconds per block
        $defaultDurationDays = 30;
        $stakingDuration = $stakingConfig['default_duration'] ?? ($defaultDurationDays * 24 * 60 * 60 / $blockTime);
        
        $results['staking'] = $this->deployContract(
            $stakingCode,
            [
                $stakingConfig['minimum_stake'] ?? 1000,
                $stakingConfig['reward_per_block'] ?? 10,
                $stakingDuration
            ],
            $deployer,
            100000,
            'Staking'
        );

        // Governance contract
        $governanceCode = $this->getGovernanceTemplate();
        $results['governance'] = $this->deployContract(
            $governanceCode,
            [$deployer], // initial administrator
            $deployer,
            100000,
            'Governance'
        );

        return $results;
    }

    /**
     * Get staking template for testing
     */
    public function getStakingTemplate(): string
    {
        return $this->getStakingTemplateInternal();
    }



    /**
     * Uniswap V2 Factory template
     */
    private function getUniswapV2FactoryTemplate(): string
    {
        return '
        contract UniswapV2Factory {
            address public feeTo;
            address public feeToSetter;
            mapping(address => mapping(address => address)) public getPair;
            address[] public allPairs;

            event PairCreated(address indexed token0, address indexed token1, address pair, uint);

            constructor(address _feeToSetter) {
                feeToSetter = _feeToSetter;
            }

            function createPair(address tokenA, address tokenB) external returns (address pair) {
                require(tokenA != tokenB, "UniswapV2: IDENTICAL_ADDRESSES");
                (address token0, address token1) = tokenA < tokenB ? (tokenA, tokenB) : (tokenB, tokenA);
                require(token0 != address(0), "UniswapV2: ZERO_ADDRESS");
                require(getPair[token0][token1] == address(0), "UniswapV2: PAIR_EXISTS");

                bytes memory bytecode = type(UniswapV2Pair).creationCode;
                bytes32 salt = keccak256(abi.encodePacked(token0, token1));
                assembly {
                    pair := create2(0, add(bytecode, 32), mload(bytecode), salt)
                }

                getPair[token0][token1] = pair;
                getPair[token1][token0] = pair;
                allPairs.push(pair);

                emit PairCreated(token0, token1, pair, allPairs.length);
            }

            function setFeeTo(address _feeTo) external {
                require(msg.sender == feeToSetter, "UniswapV2: FORBIDDEN");
                feeTo = _feeTo;
            }

            function setFeeToSetter(address _feeToSetter) external {
                require(msg.sender == feeToSetter, "UniswapV2: FORBIDDEN");
                feeToSetter = _feeToSetter;
            }

            function allPairsLength() external view returns (uint) {
                return allPairs.length;
            }
        }

        contract UniswapV2Pair {
            address public factory;
            address public token0;
            address public token1;

            uint112 private reserve0;
            uint112 private reserve1;
            uint32 private blockTimestampLast;

            uint256 public price0CumulativeLast;
            uint256 public price1CumulativeLast;
            uint256 public kLast;

            constructor() {
                factory = msg.sender;
            }

            function initialize(address _token0, address _token1) external {
                require(msg.sender == factory, "UniswapV2: FORBIDDEN");
                token0 = _token0;
                token1 = _token1;
            }
        }';
    }

    /**
     * Uniswap V2 Router template
     */
    private function getUniswapV2RouterTemplate(): string
    {
        return '
        contract UniswapV2Router {
            address public immutable factory;
            address public immutable WETH;

            modifier ensure(uint deadline) {
                require(deadline >= block.timestamp, "UniswapV2Router: EXPIRED");
                _;
            }

            constructor(address _factory, address _WETH) {
                factory = _factory;
                WETH = _WETH;
            }

            function addLiquidity(
                address tokenA,
                address tokenB,
                uint amountADesired,
                uint amountBDesired,
                uint amountAMin,
                uint amountBMin,
                address to,
                uint deadline
            ) external ensure(deadline) returns (uint amountA, uint amountB, uint liquidity) {
                (amountA, amountB) = _addLiquidity(tokenA, tokenB, amountADesired, amountBDesired, amountAMin, amountBMin);
                address pair = UniswapV2Factory(factory).getPair(tokenA, tokenB);

                _safeTransferFrom(tokenA, msg.sender, pair, amountA);
                _safeTransferFrom(tokenB, msg.sender, pair, amountB);

                liquidity = UniswapV2Pair(pair).mint(to);
            }

            function swapExactTokensForTokens(
                uint amountIn,
                uint amountOutMin,
                address[] calldata path,
                address to,
                uint deadline
            ) external ensure(deadline) returns (uint[] memory amounts) {
                amounts = UniswapV2Library.getAmountsOut(factory, amountIn, path);
                require(amounts[amounts.length - 1] >= amountOutMin, "UniswapV2Router: INSUFFICIENT_OUTPUT_AMOUNT");

                _safeTransferFrom(path[0], msg.sender, UniswapV2Library.pairFor(factory, path[0], path[1]), amounts[0]);

                _swap(amounts, path, to);
            }

            function _addLiquidity(
                address tokenA,
                address tokenB,
                uint amountADesired,
                uint amountBDesired,
                uint amountAMin,
                uint amountBMin
            ) internal returns (uint amountA, uint amountB) {
                if (UniswapV2Factory(factory).getPair(tokenA, tokenB) == address(0)) {
                    UniswapV2Factory(factory).createPair(tokenA, tokenB);
                }

                (uint reserveA, uint reserveB) = UniswapV2Library.getReserves(factory, tokenA, tokenB);

                if (reserveA == 0 && reserveB == 0) {
                    (amountA, amountB) = (amountADesired, amountBDesired);
                } else {
                    uint amountBOptimal = UniswapV2Library.quote(amountADesired, reserveA, reserveB);
                    if (amountBOptimal <= amountBDesired) {
                        require(amountBOptimal >= amountBMin, "UniswapV2Router: INSUFFICIENT_B_AMOUNT");
                        (amountA, amountB) = (amountADesired, amountBOptimal);
                    } else {
                        uint amountAOptimal = UniswapV2Library.quote(amountBDesired, reserveB, reserveA);
                        require(amountAOptimal <= amountADesired);
                        require(amountAOptimal >= amountAMin, "UniswapV2Router: INSUFFICIENT_A_AMOUNT");
                        (amountA, amountB) = (amountAOptimal, amountBDesired);
                    }
                }
            }

            function _swap(uint[] memory amounts, address[] memory path, address _to) internal {
                for (uint i; i < path.length - 1; i++) {
                    (address input, address output) = (path[i], path[i + 1]);
                    (address token0,) = UniswapV2Library.sortTokens(input, output);
                    uint amountOut = amounts[i + 1];

                    (uint amount0Out, uint amount1Out) = input == token0 ? (uint(0), amountOut) : (amountOut, uint(0));

                    address to = i < path.length - 2 ? UniswapV2Library.pairFor(factory, output, path[i + 2]) : _to;
                    UniswapV2Pair(UniswapV2Library.pairFor(factory, input, output)).swap(amount0Out, amount1Out, to, new bytes(0));
                }
            }

            function _safeTransferFrom(address token, address from, address to, uint value) private {
                (bool success, bytes memory data) = token.call(abi.encodeWithSelector(0x23b872dd, from, to, value));
                require(success && (data.length == 0 || abi.decode(data, (bool))), "TransferHelper: TRANSFER_FROM_FAILED");
            }
        }

        library UniswapV2Library {
            function sortTokens(address tokenA, address tokenB) internal pure returns (address token0, address token1) {
                require(tokenA != tokenB, "UniswapV2Library: IDENTICAL_ADDRESSES");
                (token0, token1) = tokenA < tokenB ? (tokenA, tokenB) : (tokenB, tokenA);
                require(token0 != address(0), "UniswapV2Library: ZERO_ADDRESS");
            }

            function pairFor(address factory, address tokenA, address tokenB) internal pure returns (address pair) {
                (address token0, address token1) = sortTokens(tokenA, tokenB);
                pair = address(uint160(uint256(keccak256(abi.encodePacked(
                        hex"ff",
                        factory,
                        keccak256(abi.encodePacked(token0, token1)),
                        hex"96e8ac4277198ff8b6f785478aa9a39f403cb768dd02cbee326c3e7da348845f
                )))));
            }

            function getReserves(address factory, address tokenA, address tokenB) internal view returns (uint reserveA, uint reserveB) {
                (address token0,) = sortTokens(tokenA, tokenB);
                (uint reserve0, uint reserve1,) = UniswapV2Pair(pairFor(factory, tokenA, tokenB)).getReserves();
                (reserveA, reserveB) = tokenA == token0 ? (reserve0, reserve1) : (reserve1, reserve0);
            }

            function quote(uint amountA, uint reserveA, uint reserveB) internal pure returns (uint amountB) {
                require(amountA > 0, "UniswapV2Library: INSUFFICIENT_AMOUNT");
                require(reserveA > 0 && reserveB > 0, "UniswapV2Library: INSUFFICIENT_LIQUIDITY");
                amountB = amountA * reserveB / reserveA;
            }

            function getAmountOut(uint amountIn, uint reserveIn, uint reserveOut) internal pure returns (uint amountOut) {
                require(amountIn > 0, "UniswapV2Library: INSUFFICIENT_INPUT_AMOUNT");
                require(reserveIn > 0 && reserveOut > 0, "UniswapV2Library: INSUFFICIENT_LIQUIDITY");
                uint amountInWithFee = amountIn * 997;
                uint numerator = amountInWithFee * reserveOut;
                uint denominator = reserveIn * 1000 + amountInWithFee;
                amountOut = numerator / denominator;
            }

            function getAmountsOut(address factory, uint amountIn, address[] memory path) internal view returns (uint[] memory amounts) {
                require(path.length >= 2, "UniswapV2Library: INVALID_PATH");
                amounts = new uint[](path.length);
                amounts[0] = amountIn;
                for (uint i; i < path.length - 1; i++) {
                    (uint reserveIn, uint reserveOut) = getReserves(factory, path[i], path[i + 1]);
                    amounts[i + 1] = getAmountOut(amounts[i], reserveIn, reserveOut);
                }
            }
        }';
    }

    /**
     * WETH (Wrapped Token) template - universal version
     */
    private function getWETHTemplate(): string
    {
        return '
        contract WETH {
            string public name;
            string public symbol;
            uint8 public decimals = 18;

            event Deposit(address indexed dst, uint wad);
            event Withdrawal(address indexed src, uint wad);

            mapping(address => uint) public balanceOf;
            mapping(address => mapping(address => uint)) public allowance;

            constructor(string memory _name, string memory _symbol) {
                name = _name;
                symbol = _symbol;
            }

            function deposit() public payable {
                balanceOf[msg.sender] += msg.value;
                emit Deposit(msg.sender, msg.value);
            }

            function withdraw(uint wad) public {
                require(balanceOf[msg.sender] >= wad);
                balanceOf[msg.sender] -= wad;
                payable(msg.sender).transfer(wad);
                emit Withdrawal(msg.sender, wad);
            }

            function totalSupply() public view returns (uint) {
                return address(this).balance;
            }

            function approve(address guy, uint wad) public returns (bool) {
                allowance[msg.sender][guy] = wad;
                return true;
            }

            function transfer(address dst, uint wad) public returns (bool) {
                return transferFrom(msg.sender, dst, wad);
            }

            function transferFrom(address src, address dst, uint wad) public returns (bool) {
                require(balanceOf[src] >= wad);

                if (src != msg.sender && allowance[src][msg.sender] != type(uint).max) {
                    require(allowance[src][msg.sender] >= wad);
                    allowance[src][msg.sender] -= wad;
                }

                balanceOf[src] -= wad;
                balanceOf[dst] += wad;

                return true;
            }
        }';
    }

    /**
     * ERC-20 token template
     */
    private function getERC20Template(): string
    {
        return '
        contract ERC20Token {
            string public name;
            string public symbol;
            uint8 public decimals;
            uint256 public totalSupply;

            mapping(address => uint256) public balanceOf;
            mapping(address => mapping(address => uint256)) public allowance;

            constructor(string _name, string _symbol, uint8 _decimals, uint256 _totalSupply) {
                name = _name;
                symbol = _symbol;
                decimals = _decimals;
                totalSupply = _totalSupply;
                balanceOf[msg.sender] = _totalSupply;
            }

            function transfer(address _to, uint256 _value) public returns (bool) {
                require(balanceOf[msg.sender] >= _value);
                balanceOf[msg.sender] -= _value;
                balanceOf[_to] += _value;
                return true;
            }

            function approve(address _spender, uint256 _value) public returns (bool) {
                allowance[msg.sender][_spender] = _value;
                return true;
            }

            function transferFrom(address _from, address _to, uint256 _value) public returns (bool) {
                require(balanceOf[_from] >= _value);
                require(allowance[_from][msg.sender] >= _value);
                balanceOf[_from] -= _value;
                balanceOf[_to] += _value;
                allowance[_from][msg.sender] -= _value;
                return true;
            }
        }';
    }

    /**
     * Staking contract template
     */
    private function getStakingTemplateInternal(): string
    {
        return '
        contract Staking {
            // Configuration
            uint256 public minimumStake;
            uint256 public rewardPerBlock;
            uint256 public earlyWithdrawalPenalty; // Percentage (e.g., 10 = 10%)
            uint256 public stakingDuration; // Duration in blocks
            
            // State tracking
            mapping(address => uint256) public stakes;
            mapping(address => uint256) public rewards;
            mapping(address => uint256) public stakingStartBlock;
            mapping(address => bool) public isCompleted;
            mapping(address => uint256) public lastRewardBlock;
            mapping(address => uint256) public penaltyAmount;
            
            // Events
            event Staked(address indexed user, uint256 amount, uint256 startBlock);
            event Unstaked(address indexed user, uint256 amount, uint256 penalty);
            event RewardsClaimed(address indexed user, uint256 amount);
            event StakingCompleted(address indexed user, uint256 totalRewards);
            event EarlyWithdrawal(address indexed user, uint256 amount, uint256 penalty);
            
            constructor(uint256 _minimumStake, uint256 _rewardPerBlock, uint256 _stakingDuration) {
                minimumStake = _minimumStake;
                rewardPerBlock = _rewardPerBlock;
                earlyWithdrawalPenalty = 10; // 10% penalty
                stakingDuration = _stakingDuration; // Use provided duration instead of hardcoded 30 days
            }
            
            function stake() public payable {
                require(msg.value >= minimumStake, "Staking amount below minimum");
                require(!isCompleted[msg.sender], "Staking already completed");
                
                stakes[msg.sender] += msg.value;
                stakingStartBlock[msg.sender] = block.timestamp;
                lastRewardBlock[msg.sender] = block.timestamp;
                
                emit Staked(msg.sender, msg.value, block.timestamp);
            }
            
            function unstake(uint256 _amount) public {
                require(stakes[msg.sender] >= _amount, "Insufficient staked amount");
                require(!isCompleted[msg.sender], "Staking already completed");
                
                uint256 currentTimestamp = block.timestamp;
                uint256 stakingTime = currentTimestamp - stakingStartBlock[msg.sender];
                uint256 expectedDuration = stakingDuration;
                
                // Calculate rewards based on actual staking time
                uint256 calculatedRewards = calculateRewards(msg.sender, stakingTime);
                
                // Check for early withdrawal
                uint256 penalty = 0;
                if (stakingTime < expectedDuration) {
                    penalty = (_amount * earlyWithdrawalPenalty) / 100;
                    penaltyAmount[msg.sender] += penalty;
                    emit EarlyWithdrawal(msg.sender, _amount, penalty);
                }
                
                // Update stakes
                stakes[msg.sender] -= _amount;
                
                // Mark as completed if unstaking full amount
                if (stakes[msg.sender] == 0) {
                    isCompleted[msg.sender] = true;
                    emit StakingCompleted(msg.sender, calculatedRewards);
                }
                
                emit Unstaked(msg.sender, _amount, penalty);
                
                // Send funds back (minus penalty)
                payable(msg.sender).transfer(_amount - penalty);
            }
            
            function claimRewards() public {
                require(!isCompleted[msg.sender], "Staking already completed");
                
                uint256 currentTimestamp = block.timestamp;
                uint256 stakingTime = currentTimestamp - stakingStartBlock[msg.sender];
                uint256 calculatedRewards = calculateRewards(msg.sender, stakingTime);
                
                require(calculatedRewards > 0, "No rewards to claim");
                
                rewards[msg.sender] = 0;
                lastRewardBlock[msg.sender] = currentTimestamp;
                
                emit RewardsClaimed(msg.sender, calculatedRewards);
                
                // Send rewards
                payable(msg.sender).transfer(calculatedRewards);
            }
            
            function calculateRewards(address _user, uint256 _stakingTime) internal view returns (uint256) {
                if (_stakingTime == 0) return 0;
                
                uint256 blocksStaked = _stakingTime / 15; // Assuming 15-second blocks
                uint256 baseRewards = (stakes[_user] * blocksStaked * rewardPerBlock) / 1e18;
                
                // Bonus for completing full duration
                uint256 expectedDuration = stakingDuration;
                if (_stakingTime >= expectedDuration) {
                    baseRewards = (baseRewards * 120) / 100; // 20% bonus
                }
                
                return baseRewards;
            }
            
            function checkAndCompleteExpiredStakings() public {
                // This function can be called by anyone to check for expired stakings
                for (uint256 i = 0; i < 100; i++) { // Limit to prevent gas issues
                    address user = address(i); // Simplified - in real implementation would need proper iteration
                    if (stakes[user] > 0 && !isCompleted[user]) {
                        uint256 currentTimestamp = block.timestamp;
                        uint256 stakingTime = currentTimestamp - stakingStartBlock[user];
                        uint256 expectedDuration = stakingDuration;
                        
                        if (stakingTime >= expectedDuration) {
                            // Auto-complete staking
                            uint256 calculatedRewards = calculateRewards(user, stakingTime);
                            isCompleted[user] = true;
                            
                            emit StakingCompleted(user, calculatedRewards);
                            
                            // Send rewards
                            payable(user).transfer(calculatedRewards);
                        }
                    }
                }
            }
            
            function getStakingInfo(address _user) public view returns (
                uint256 stakeAmount,
                uint256 startTime,
                uint256 rewards,
                bool completed,
                uint256 timeRemaining,
                uint256 totalRewards
            ) {
                stakeAmount = stakes[_user];
                startTime = stakingStartBlock[_user];
                rewards = rewards[_user];
                completed = isCompleted[_user];
                
                if (!completed && startTime > 0) {
                    uint256 currentTimestamp = block.timestamp;
                    uint256 stakingTime = currentTimestamp - startTime;
                    uint256 expectedDuration = stakingDuration;
                    
                    if (stakingTime < expectedDuration) {
                        timeRemaining = expectedDuration - stakingTime;
                    } else {
                        timeRemaining = 0;
                    }
                    
                    totalRewards = calculateRewards(_user, stakingTime);
                }
            }
        }';
    }

    /**
     * Governance contract template
     */
    private function getGovernanceTemplate(): string
    {
        return '
        contract Governance {
            address public admin;
            mapping(address => bool) public voters;
            
            struct Proposal {
                string description;
                uint256 votesFor;
                uint256 votesAgainst;
                bool executed;
                uint256 deadline;
            }
            
            mapping(uint256 => Proposal) public proposals;
            uint256 public proposalCount;
            
            constructor(address _admin) {
                admin = _admin;
                voters[_admin] = true;
            }
            
            function addVoter(address _voter) public {
                require(msg.sender == admin);
                voters[_voter] = true;
            }
            
            function createProposal(string _description, uint256 _duration) public {
                require(voters[msg.sender]);
                proposals[proposalCount] = Proposal({
                    description: _description,
                    votesFor: 0,
                    votesAgainst: 0,
                    executed: false,
                    deadline: block.timestamp + _duration
                });
                proposalCount++;
            }
            
            function vote(uint256 _proposalId, bool _support) public {
                require(voters[msg.sender]);
                require(block.timestamp <= proposals[_proposalId].deadline);
                
                if (_support) {
                    proposals[_proposalId].votesFor++;
                } else {
                    proposals[_proposalId].votesAgainst++;
                }
            }
        }';
    }

    /**
     * Check smart contract statistics
     */
    public function getContractStats(): array
    {
        $totalContracts = count($this->deployedContracts);
        $totalGasUsed = 0;
        $contractsByType = [];

        foreach ($this->deployedContracts as $address => $contract) {
            // Detect contract type by code
            $type = $this->detectContractType($contract['code']);
            $contractsByType[$type] = ($contractsByType[$type] ?? 0) + 1;
        }

        return [
            'total_contracts' => $totalContracts,
            'contracts_by_type' => $contractsByType,
            'gas_price' => $this->gasPrice,
            'max_gas_per_transaction' => $this->maxGasPerTransaction
        ];
    }

    /**
     * Check contract type
     */
    private function detectContractType(string $code): string
    {
        if (strpos($code, 'ERC20Token') !== false) {
            return 'ERC20';
        } elseif (strpos($code, 'Staking') !== false) {
            return 'Staking';
        } elseif (strpos($code, 'Governance') !== false) {
            return 'Governance';
        } else {
            return 'Custom';
        }
    }

    /**
     * Get token configuration from database
     */
    private function getTokenConfigFromDatabase(): array
    {
        if (!$this->database) {
            // Fallback to config if no database connection
            return [
                'token_name' => $this->config['blockchain']['token_name'] ?? 'Blockchain Token',
                'token_symbol' => $this->config['blockchain']['token_symbol'] ?? 'COIN',
                'initial_supply' => $this->config['blockchain']['initial_supply'] ?? '2100000000',
                'decimals' => $this->config['blockchain']['decimals'] ?? 18
            ];
        }

        try {
            $stmt = $this->database->prepare("
                SELECT key_name, value 
                FROM config 
                WHERE key_name IN ('network.token_name', 'network.token_symbol', 'network.initial_supply', 'network.total_supply', 'network.decimals')
            ");
            $stmt->execute();
            $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $config = [];
            foreach ($results as $row) {
                $key = str_replace('network.', '', $row['key_name']);
                $config[$key] = $row['value'];
            }

            return [
                'token_name' => $config['token_name'] ?? 'Blockchain Token',
                'token_symbol' => $config['token_symbol'] ?? 'COIN',
                'initial_supply' => $config['initial_supply'] ?? $config['total_supply'] ?? '2100000000',
                'decimals' => (int)($config['decimals'] ?? 18)
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to load token config from database: ' . $e->getMessage());
            return [
                'token_name' => 'Blockchain Token',
                'token_symbol' => 'COIN',
                'initial_supply' => '2100000000',
                'decimals' => 18
            ];
        }
    }

    /**
     * Get network configuration from database
     */
    private function getNetworkConfigFromDatabase(): array
    {
        if (!$this->database) {
            return $this->config['network'] ?? [];
        }

        try {
            $stmt = $this->database->prepare("
                SELECT key_name, value 
                FROM config 
                WHERE key_name LIKE 'network.%'
            ");
            $stmt->execute();
            $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $config = [];
            foreach ($results as $row) {
                $key = str_replace('network.', '', $row['key_name']);
                $config[$key] = $row['value'];
            }

            return $config;
        } catch (\Exception $e) {
            $this->logger->error('Failed to load network config from database: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Calculate token supply with decimals
     */
    private function calculateTokenSupplyWithDecimals(string $supply, int $decimals): string
    {
        // Convert supply to wei (smallest unit)
        // Supply * 10^decimals
        if (function_exists('bcmul') && function_exists('bcpow')) {
            $multiplier = bcpow('10', (string)$decimals);
            return bcmul($supply, $multiplier);
        } else {
            // Fallback without bcmath
            $multiplier = str_repeat('0', $decimals);
            return $supply . $multiplier;
        }
    }

    /**
     * Get staking configuration from database
     */
    private function getStakingConfigFromDatabase(): array
    {
        if (!$this->database) {
            return $this->config['staking'] ?? [];
        }

        try {
            $stmt = $this->database->prepare("
                SELECT key_name, value 
                FROM config 
                WHERE key_name LIKE 'staking.%'
            ");
            $stmt->execute();
            $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $config = [];
            foreach ($results as $row) {
                $key = str_replace('staking.', '', $row['key_name']);
                $config[$key] = $row['value'];
            }

            return $config;
        } catch (\Exception $e) {
            $this->logger->error('Failed to load staking config from database: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get main token template (universal version)
     */
    private function getMainTokenTemplate(): string
    {
        return '
        contract MainToken {
            string public name;
            string public symbol;
            uint8 public decimals = 18;
            uint256 public totalSupply;

            mapping(address => uint256) public balanceOf;
            mapping(address => mapping(address => uint256)) public allowance;

            event Transfer(address indexed from, address indexed to, uint256 value);
            event Approval(address indexed owner, address indexed spender, uint256 value);

            constructor(string memory _name, string memory _symbol, uint256 _totalSupply) {
                name = _name;
                symbol = _symbol;
                totalSupply = _totalSupply;
                balanceOf[msg.sender] = _totalSupply;
                emit Transfer(address(0), msg.sender, _totalSupply);
            }

            function transfer(address _to, uint256 _value) public returns (bool) {
                require(balanceOf[msg.sender] >= _value, "Insufficient balance");
                require(_to != address(0), "Cannot transfer to zero address");

                balanceOf[msg.sender] -= _value;
                balanceOf[_to] += _value;
                emit Transfer(msg.sender, _to, _value);
                return true;
            }

            function approve(address _spender, uint256 _value) public returns (bool) {
                allowance[msg.sender][_spender] = _value;
                emit Approval(msg.sender, _spender, _value);
                return true;
            }

            function transferFrom(address _from, address _to, uint256 _value) public returns (bool) {
                require(balanceOf[_from] >= _value, "Insufficient balance");
                require(allowance[_from][msg.sender] >= _value, "Insufficient allowance");
                require(_to != address(0), "Cannot transfer to zero address");

                balanceOf[_from] -= _value;
                balanceOf[_to] += _value;
                allowance[_from][msg.sender] -= _value;
                
                emit Transfer(_from, _to, _value);
                return true;
            }

            function mint(address _to, uint256 _value) public returns (bool) {
                require(msg.sender == deployer, "Only deployer can mint");
                require(_to != address(0), "Cannot mint to zero address");

                totalSupply += _value;
                balanceOf[_to] += _value;
                emit Transfer(address(0), _to, _value);
                return true;
            }

            function burn(uint256 _value) public returns (bool) {
                require(balanceOf[msg.sender] >= _value, "Insufficient balance");

                balanceOf[msg.sender] -= _value;
                totalSupply -= _value;
                emit Transfer(msg.sender, address(0), _value);
                return true;
            }
        }';
    }
}
