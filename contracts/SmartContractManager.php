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

        // Staking contract with configurable duration
        $stakingCode = $this->getStakingTemplateInternal();
        $stakingDuration = $this->config['staking']['default_duration'] ?? 30 * 24 * 60 * 60 / 15; // 30 days in blocks (15s blocks) as default
        $results['staking'] = $this->deployContract(
            $stakingCode,
            [1000, 10, $stakingDuration], // minimum stake, reward per block, duration
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
}
