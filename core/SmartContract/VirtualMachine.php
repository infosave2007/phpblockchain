<?php
declare(strict_types=1);

namespace Blockchain\Core\SmartContract;

use Exception;

/**
 * Professional Smart Contract Virtual Machine
 * 
 * Executes smart contract bytecode with gas metering and state management
 */
class VirtualMachine
{
    private array $state = [];
    private int $gasLimit = 3000000;
    private int $gasUsed = 0;
    private array $stack = [];
    private array $memory = [];
    private array $logs = [];
    private int $pc = 0; // Program counter
    
    // Opcodes
    private const OPCODES = [
        0x00 => 'STOP',
        0x01 => 'ADD',
        0x02 => 'MUL',
        0x03 => 'SUB',
        0x04 => 'DIV',
        0x05 => 'SDIV',
        0x06 => 'MOD',
        0x07 => 'SMOD',
        0x08 => 'ADDMOD',
        0x09 => 'MULMOD',
        0x0a => 'EXP',
        0x10 => 'LT',
        0x11 => 'GT',
        0x12 => 'SLT',
        0x13 => 'SGT',
        0x14 => 'EQ',
        0x15 => 'ISZERO',
        0x16 => 'AND',
        0x17 => 'OR',
        0x18 => 'XOR',
        0x19 => 'NOT',
        0x1a => 'BYTE',
        0x20 => 'SHA3',
        0x30 => 'ADDRESS',
        0x31 => 'BALANCE',
        0x32 => 'ORIGIN',
        0x33 => 'CALLER',
        0x34 => 'CALLVALUE',
        0x35 => 'CALLDATALOAD',
        0x36 => 'CALLDATASIZE',
        0x37 => 'CALLDATACOPY',
        0x38 => 'CODESIZE',
        0x39 => 'CODECOPY',
        0x3a => 'GASPRICE',
        0x50 => 'POP',
        0x51 => 'MLOAD',
        0x52 => 'MSTORE',
        0x53 => 'MSTORE8',
        0x54 => 'SLOAD',
        0x55 => 'SSTORE',
        0x56 => 'JUMP',
        0x57 => 'JUMPI',
        0x58 => 'PC',
        0x59 => 'MSIZE',
        0x5a => 'GAS',
        0x5b => 'JUMPDEST',
        0x60 => 'PUSH1',
        0x80 => 'DUP1',
        0x90 => 'SWAP1',
        0xa0 => 'LOG0',
        0xf0 => 'CREATE',
        0xf1 => 'CALL',
        0xf2 => 'CALLCODE',
        0xf3 => 'RETURN',
        0xf4 => 'DELEGATECALL',
        0xff => 'SELFDESTRUCT'
    ];
    
    // Gas costs
    private const GAS_COSTS = [
        'STOP' => 0,
        'ADD' => 3,
        'MUL' => 5,
        'SUB' => 3,
        'DIV' => 5,
        'MOD' => 5,
        'EXP' => 10,
        'LT' => 3,
        'GT' => 3,
        'EQ' => 3,
        'ISZERO' => 3,
        'AND' => 3,
        'OR' => 3,
        'XOR' => 3,
        'NOT' => 3,
        'BYTE' => 3,
        'SHA3' => 30,
        'ADDRESS' => 2,
        'BALANCE' => 700,
        'CALLER' => 2,
        'CALLVALUE' => 2,
        'SLOAD' => 800,
        'SSTORE' => 20000,
        'JUMP' => 8,
        'JUMPI' => 10,
        'POP' => 2,
        'MLOAD' => 3,
        'MSTORE' => 3,
        'CALL' => 700,
        'RETURN' => 0,
        'SELFDESTRUCT' => 5000
    ];

    public function __construct(int $gasLimit = 3000000)
    {
        $this->gasLimit = $gasLimit;
    }

    /**
     * Execute smart contract bytecode
     */
    public function execute(string $bytecode, array $context = []): array
    {
        $this->reset();
        
        try {
            $code = hex2bin($bytecode);
            $codeLength = strlen($code);
            
            while ($this->pc < $codeLength) {
                $opcode = ord($code[$this->pc]);
                
                if (!isset(self::OPCODES[$opcode])) {
                    throw new Exception("Invalid opcode: 0x" . dechex($opcode));
                }
                
                $operation = self::OPCODES[$opcode];
                
                // Check gas
                $gasCost = self::GAS_COSTS[$operation] ?? 1;
                if ($this->gasUsed + $gasCost > $this->gasLimit) {
                    throw new Exception("Out of gas");
                }
                
                $this->gasUsed += $gasCost;
                
                // Execute operation
                $this->executeOperation($operation, $code, $context);
                
                if ($operation === 'STOP' || $operation === 'RETURN') {
                    break;
                }
                
                $this->pc++;
            }
            
            return [
                'success' => true,
                'gasUsed' => $this->gasUsed,
                'result' => $this->getReturnData(),
                'logs' => $this->logs,
                'stateChanges' => $this->state
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'gasUsed' => $this->gasUsed,
                'error' => $e->getMessage(),
                'logs' => $this->logs,
                'stateChanges' => []
            ];
        }
    }

    /**
     * Execute individual operation
     */
    private function executeOperation(string $operation, string $code, array $context): void
    {
        switch ($operation) {
            case 'STOP':
                return;
                
            case 'ADD':
                $a = $this->pop();
                $b = $this->pop();
                $this->push(($a + $b) & 0xFFFFFFFFFFFFFFFF);
                break;
                
            case 'MUL':
                $a = $this->pop();
                $b = $this->pop();
                $this->push(($a * $b) & 0xFFFFFFFFFFFFFFFF);
                break;
                
            case 'SUB':
                $a = $this->pop();
                $b = $this->pop();
                $this->push(($a - $b) & 0xFFFFFFFFFFFFFFFF);
                break;
                
            case 'DIV':
                $a = $this->pop();
                $b = $this->pop();
                if ($b === 0) {
                    $this->push(0);
                } else {
                    $this->push(intval($a / $b));
                }
                break;
                
            case 'MOD':
                $a = $this->pop();
                $b = $this->pop();
                if ($b === 0) {
                    $this->push(0);
                } else {
                    $this->push($a % $b);
                }
                break;
                
            case 'LT':
                $a = $this->pop();
                $b = $this->pop();
                $this->push($a < $b ? 1 : 0);
                break;
                
            case 'GT':
                $a = $this->pop();
                $b = $this->pop();
                $this->push($a > $b ? 1 : 0);
                break;
                
            case 'EQ':
                $a = $this->pop();
                $b = $this->pop();
                $this->push($a === $b ? 1 : 0);
                break;
                
            case 'ISZERO':
                $a = $this->pop();
                $this->push($a === 0 ? 1 : 0);
                break;
                
            case 'AND':
                $a = $this->pop();
                $b = $this->pop();
                $this->push($a & $b);
                break;
                
            case 'OR':
                $a = $this->pop();
                $b = $this->pop();
                $this->push($a | $b);
                break;
                
            case 'XOR':
                $a = $this->pop();
                $b = $this->pop();
                $this->push($a ^ $b);
                break;
                
            case 'NOT':
                $a = $this->pop();
                $this->push(~$a & 0xFFFFFFFFFFFFFFFF);
                break;
                
            case 'PUSH1':
                $this->pc++;
                $value = ord($code[$this->pc]);
                $this->push($value);
                break;
                
            case 'POP':
                $this->pop();
                break;
                
            case 'DUP1':
                $this->push($this->peek());
                break;
                
            case 'SWAP1':
                $a = $this->pop();
                $b = $this->pop();
                $this->push($a);
                $this->push($b);
                break;
                
            case 'SLOAD':
                $key = $this->pop();
                $value = $this->state[$key] ?? 0;
                $this->push($value);
                break;
                
            case 'SSTORE':
                $key = $this->pop();
                $value = $this->pop();
                $this->state[$key] = $value;
                break;
                
            case 'CALLER':
                $caller = $context['caller'] ?? '0x0000000000000000000000000000000000000000';
                $this->push((int)hexdec(substr($caller, 2, 8))); // Convert first 4 bytes to int
                break;
                
            case 'CALLVALUE':
                $value = $context['value'] ?? 0;
                $this->push($value);
                break;
                
            case 'BALANCE':
                $address = $this->pop();
                $balance = $context['getBalance']($address) ?? 0;
                $this->push($balance);
                break;
                
            case 'LOG0':
                $offset = $this->pop();
                $length = $this->pop();
                $data = substr($this->memory, $offset, $length);
                $this->logs[] = [
                    'data' => bin2hex($data),
                    'topics' => []
                ];
                break;
                
            case 'RETURN':
                $offset = $this->pop();
                $length = $this->pop();
                $this->returnData = substr($this->memory, $offset, $length);
                return;
                
            case 'MLOAD':
                $offset = $this->pop();
                $value = $this->loadFromMemory($offset, 32);
                $this->push($value);
                break;
                
            case 'MSTORE':
                $offset = $this->pop();
                $value = $this->pop();
                $this->storeToMemory($offset, $value, 32);
                break;
                
            default:
                throw new Exception("Unimplemented operation: $operation");
        }
    }

    /**
     * Stack operations
     */
    private function push(int $value): void
    {
        array_push($this->stack, $value);
    }

    private function pop(): int
    {
        if (empty($this->stack)) {
            throw new Exception("Stack underflow");
        }
        return array_pop($this->stack);
    }

    private function peek(): int
    {
        if (empty($this->stack)) {
            throw new Exception("Stack empty");
        }
        return end($this->stack);
    }

    /**
     * Memory operations
     */
    private function loadFromMemory(int $offset, int $length): int
    {
        $data = '';
        for ($i = 0; $i < $length; $i++) {
            $data .= $this->memory[$offset + $i] ?? chr(0);
        }
        return hexdec(bin2hex($data));
    }

    private function storeToMemory(int $offset, int $value, int $length): void
    {
        $hex = str_pad(dechex($value), $length * 2, '0', STR_PAD_LEFT);
        $binary = hex2bin($hex);
        
        for ($i = 0; $i < $length; $i++) {
            $this->memory[$offset + $i] = $binary[$i] ?? chr(0);
        }
    }

    /**
     * Reset VM state
     */
    private function reset(): void
    {
        $this->gasUsed = 0;
        $this->stack = [];
        $this->memory = [];
        $this->logs = [];
        $this->state = [];
        $this->pc = 0;
        $this->returnData = '';
    }

    /**
     * Get return data
     */
    private function getReturnData(): string
    {
        return $this->returnData ?? '';
    }

    /**
     * Get current gas usage
     */
    public function getGasUsed(): int
    {
        return $this->gasUsed;
    }

    /**
     * Get logs
     */
    public function getLogs(): array
    {
        return $this->logs;
    }

    /**
     * Get state changes
     */
    public function getStateChanges(): array
    {
        return $this->state;
    }
}
