<?php
declare(strict_types=1);

namespace Blockchain\Contracts;

use Blockchain\Core\Contracts\SmartContractInterface;
use Blockchain\Core\Contracts\TransactionInterface;
use Blockchain\Core\SmartContract\VirtualMachine;
use Blockchain\Core\Storage\StateStorage;
use Psr\Log\LoggerInterface;

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

    public function __construct(
        VirtualMachine $vm,
        StateStorage $stateStorage,
        LoggerInterface $logger,
        array $config = [],
        int $gasPrice = 1,
        int $maxGasPerTransaction = 1000000
    ) {
        $this->vm = $vm;
        $this->stateStorage = $stateStorage;
        $this->logger = $logger;
        $this->deployedContracts = [];
        $this->gasPrice = $gasPrice;
        $this->maxGasPerTransaction = $maxGasPerTransaction;
        $this->config = $config;
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

    // ERC-20 token
        $erc20Code = $this->getERC20Template();
        $tokenName = $this->config['blockchain']['network_name'] ?? 'My Blockchain Token';
        $tokenSymbol = $this->config['blockchain']['token_symbol'] ?? 'MBC';
        $tokenSupply = $this->config['blockchain']['initial_supply'] ?? 1000000;
        
        $results['erc20'] = $this->deployContract(
            $erc20Code,
            [$tokenName, $tokenSymbol, 18, $tokenSupply],
            $deployer,
            100000,
            'ERC20'
        );

        // Staking contract
        $stakingCode = $this->getStakingTemplate();
        $results['staking'] = $this->deployContract(
            $stakingCode,
            [1000, 10], // minimum stake, reward per block
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
    private function getStakingTemplate(): string
    {
        return '
        contract Staking {
            uint256 public minimumStake;
            uint256 public rewardPerBlock;
            
            mapping(address => uint256) public stakes;
            mapping(address => uint256) public rewards;
            
            constructor(uint256 _minimumStake, uint256 _rewardPerBlock) {
                minimumStake = _minimumStake;
                rewardPerBlock = _rewardPerBlock;
            }
            
            function stake() public payable {
                require(msg.value >= minimumStake);
                stakes[msg.sender] += msg.value;
            }
            
            function unstake(uint256 _amount) public {
                require(stakes[msg.sender] >= _amount);
                stakes[msg.sender] -= _amount;
                // Send funds back
            }
            
            function claimRewards() public {
                uint256 reward = rewards[msg.sender];
                rewards[msg.sender] = 0;
                // Send reward
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
}
