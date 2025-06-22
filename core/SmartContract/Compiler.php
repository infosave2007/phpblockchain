<?php
declare(strict_types=1);

namespace Blockchain\Core\SmartContract;

use Exception;

/**
 * Professional Smart Contract Compiler
 * 
 * Compiles high-level contract code to bytecode
 */
class Compiler
{
    private array $functions = [];
    private array $variables = [];
    private int $nextAddress = 0;
    
    /**
     * Compile Solidity-like contract to bytecode
     */
    public function compile(string $sourceCode): array
    {
        try {
            // Remove comments and clean up
            $cleanCode = $this->preprocessCode($sourceCode);
            
            // Parse contract structure
            $contract = $this->parseContract($cleanCode);
            
            // Generate bytecode
            $bytecode = $this->generateBytecode($contract);
            
            // Generate ABI
            $abi = $this->generateABI($contract);
            
            return [
                'success' => true,
                'bytecode' => $bytecode,
                'abi' => $abi,
                'functions' => $this->functions,
                'variables' => $this->variables
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'bytecode' => '',
                'abi' => []
            ];
        }
    }

    /**
     * Preprocess source code
     */
    private function preprocessCode(string $code): string
    {
        // Remove single-line comments
        $code = preg_replace('/\/\/.*$/m', '', $code);
        
        // Remove multi-line comments
        $code = preg_replace('/\/\*.*?\*\//s', '', $code);
        
        // Normalize whitespace
        $code = preg_replace('/\s+/', ' ', $code);
        
        return trim($code);
    }

    /**
     * Parse contract structure
     */
    private function parseContract(string $code): array
    {
        $contract = [
            'name' => 'Contract',
            'constructor' => null,
            'functions' => [],
            'variables' => [],
            'events' => []
        ];

        // Extract contract name
        if (preg_match('/contract\s+(\w+)/', $code, $matches)) {
            $contract['name'] = $matches[1];
        }

        // Extract state variables
        if (preg_match_all('/(\w+)\s+(?:public\s+)?(\w+)\s*(?:=\s*([^;]+))?;/', $code, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $contract['variables'][] = [
                    'type' => $match[1],
                    'name' => $match[2],
                    'defaultValue' => $match[3] ?? null,
                    'address' => $this->nextAddress++
                ];
            }
        }

        // Extract functions
        if (preg_match_all('/function\s+(\w+)\s*\([^)]*\)\s*(?:public|private)?\s*(?:returns\s*\([^)]*\))?\s*\{([^}]+)\}/', $code, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $contract['functions'][] = [
                    'name' => $match[1],
                    'body' => trim($match[2]),
                    'parameters' => $this->parseParameters($match[0]),
                    'returns' => $this->parseReturns($match[0])
                ];
            }
        }

        return $contract;
    }

    /**
     * Generate bytecode from contract
     */
    private function generateBytecode(array $contract): string
    {
        $bytecode = '';
        
        // Constructor bytecode
        if ($contract['constructor']) {
            $bytecode .= $this->compileFunctionToBytecode($contract['constructor']);
        }
        
        // Function selector dispatcher
        $bytecode .= $this->generateFunctionDispatcher($contract['functions']);
        
        // Individual functions
        foreach ($contract['functions'] as $function) {
            $bytecode .= $this->compileFunctionToBytecode($function);
        }
        
        return $bytecode;
    }

    /**
     * Generate function dispatcher
     */
    private function generateFunctionDispatcher(array $functions): string
    {
        $bytecode = '';
        
        // CALLDATASIZE check
        $bytecode .= '36'; // CALLDATASIZE
        $bytecode .= '6004'; // PUSH1 4
        $bytecode .= '10'; // LT
        $bytecode .= '6080'; // PUSH1 fallback_offset
        $bytecode .= '57'; // JUMPI
        
        // Load function selector
        $bytecode .= '6000'; // PUSH1 0
        $bytecode .= '35'; // CALLDATALOAD
        $bytecode .= '7c0100000000000000000000000000000000000000000000000000000000'; // PUSH29
        $bytecode .= '04'; // SHR (shift right 4 bytes)
        
        // Function selector comparisons
        foreach ($functions as $index => $function) {
            $selector = $this->getFunctionSelector($function['name']);
            $bytecode .= '80'; // DUP1
            $bytecode .= '63' . $selector; // PUSH4 selector
            $bytecode .= '14'; // EQ
            $bytecode .= '61' . str_pad(dechex(100 + $index * 50), 4, '0', STR_PAD_LEFT); // PUSH2 function_offset
            $bytecode .= '57'; // JUMPI
        }
        
        return $bytecode;
    }

    /**
     * Compile function to bytecode
     */
    private function compileFunctionToBytecode(array $function): string
    {
        $bytecode = '';
        $statements = $this->parseStatements($function['body']);
        
        foreach ($statements as $statement) {
            $bytecode .= $this->compileStatement($statement);
        }
        
        return $bytecode;
    }

    /**
     * Compile individual statement
     */
    private function compileStatement(array $statement): string
    {
        switch ($statement['type']) {
            case 'assignment':
                return $this->compileAssignment($statement);
                
            case 'return':
                return $this->compileReturn($statement);
                
            case 'if':
                return $this->compileIf($statement);
                
            case 'function_call':
                return $this->compileFunctionCall($statement);
                
            default:
                return '';
        }
    }

    /**
     * Compile assignment statement
     */
    private function compileAssignment(array $statement): string
    {
        $bytecode = '';
        
        // Compile right-hand side expression
        $bytecode .= $this->compileExpression($statement['value']);
        
        // Store to variable
        if (isset($statement['variable'])) {
            $address = $this->getVariableAddress($statement['variable']);
            $bytecode .= '61' . str_pad(dechex($address), 4, '0', STR_PAD_LEFT); // PUSH2 address
            $bytecode .= '55'; // SSTORE
        }
        
        return $bytecode;
    }

    /**
     * Compile return statement
     */
    private function compileReturn(array $statement): string
    {
        $bytecode = '';
        
        if (isset($statement['value'])) {
            // Compile return value
            $bytecode .= $this->compileExpression($statement['value']);
            
            // Store in memory and return
            $bytecode .= '6000'; // PUSH1 0 (memory offset)
            $bytecode .= '52'; // MSTORE
            $bytecode .= '6020'; // PUSH1 32 (return length)
            $bytecode .= '6000'; // PUSH1 0 (memory offset)
        } else {
            $bytecode .= '6000'; // PUSH1 0
            $bytecode .= '6000'; // PUSH1 0
        }
        
        $bytecode .= 'f3'; // RETURN
        
        return $bytecode;
    }

    /**
     * Compile expression to bytecode
     */
    private function compileExpression(string $expression): string
    {
        $expression = trim($expression);
        
        // Number literal
        if (is_numeric($expression)) {
            $value = intval($expression);
            if ($value < 256) {
                return '60' . str_pad(dechex($value), 2, '0', STR_PAD_LEFT); // PUSH1
            } else {
                return '61' . str_pad(dechex($value), 4, '0', STR_PAD_LEFT); // PUSH2
            }
        }
        
        // Variable reference
        if (preg_match('/^\w+$/', $expression)) {
            $address = $this->getVariableAddress($expression);
            return '61' . str_pad(dechex($address), 4, '0', STR_PAD_LEFT) . '54'; // PUSH2 address, SLOAD
        }
        
        // Binary operation
        if (preg_match('/(.+)\s*([+\-*/])\s*(.+)/', $expression, $matches)) {
            $left = $this->compileExpression($matches[1]);
            $right = $this->compileExpression($matches[3]);
            $operator = $matches[2];
            
            $opcode = match($operator) {
                '+' => '01', // ADD
                '-' => '03', // SUB
                '*' => '02', // MUL
                '/' => '04', // DIV
                default => '01'
            };
            
            return $left . $right . $opcode;
        }
        
        return '6000'; // Default: PUSH1 0
    }

    /**
     * Parse function parameters
     */
    private function parseParameters(string $functionSignature): array
    {
        if (preg_match('/\(([^)]*)\)/', $functionSignature, $matches)) {
            $params = trim($matches[1]);
            if (empty($params)) {
                return [];
            }
            
            $parameters = [];
            foreach (explode(',', $params) as $param) {
                $param = trim($param);
                if (preg_match('/(\w+)\s+(\w+)/', $param, $paramMatches)) {
                    $parameters[] = [
                        'type' => $paramMatches[1],
                        'name' => $paramMatches[2]
                    ];
                }
            }
            return $parameters;
        }
        
        return [];
    }

    /**
     * Parse return types
     */
    private function parseReturns(string $functionSignature): array
    {
        if (preg_match('/returns\s*\(([^)]*)\)/', $functionSignature, $matches)) {
            $returns = trim($matches[1]);
            if (empty($returns)) {
                return [];
            }
            
            return explode(',', $returns);
        }
        
        return [];
    }

    /**
     * Parse statements from function body
     */
    private function parseStatements(string $body): array
    {
        $statements = [];
        $lines = explode(';', $body);
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            // Assignment
            if (preg_match('/(\w+)\s*=\s*(.+)/', $line, $matches)) {
                $statements[] = [
                    'type' => 'assignment',
                    'variable' => $matches[1],
                    'value' => $matches[2]
                ];
            }
            // Return
            elseif (preg_match('/return\s*(.*)/', $line, $matches)) {
                $statements[] = [
                    'type' => 'return',
                    'value' => $matches[1] ?: null
                ];
            }
        }
        
        return $statements;
    }

    /**
     * Get function selector (first 4 bytes of keccak256 hash)
     */
    private function getFunctionSelector(string $functionName): string
    {
        $hash = hash('sha3-256', $functionName . '()');
        return substr($hash, 0, 8);
    }

    /**
     * Get variable storage address
     */
    private function getVariableAddress(string $variableName): int
    {
        foreach ($this->variables as $var) {
            if ($var['name'] === $variableName) {
                return $var['address'];
            }
        }
        
        // Create new variable
        $address = $this->nextAddress++;
        $this->variables[] = [
            'name' => $variableName,
            'address' => $address
        ];
        
        return $address;
    }

    /**
     * Generate contract ABI
     */
    private function generateABI(array $contract): array
    {
        $abi = [];
        
        foreach ($contract['functions'] as $function) {
            $abi[] = [
                'type' => 'function',
                'name' => $function['name'],
                'inputs' => $function['parameters'],
                'outputs' => array_map(fn($type) => ['type' => trim($type)], $function['returns']),
                'stateMutability' => 'nonpayable'
            ];
        }
        
        return $abi;
    }
}
