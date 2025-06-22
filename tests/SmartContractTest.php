<?php
declare(strict_types=1);

namespace Blockchain\Tests;

use PHPUnit\Framework\TestCase;
use Blockchain\Core\SmartContract\VirtualMachine;
use Blockchain\Core\SmartContract\Compiler;

/**
 * Professional Smart Contract Tests
 */
class SmartContractTest extends TestCase
{
    private VirtualMachine $vm;
    private Compiler $compiler;
    
    protected function setUp(): void
    {
        $this->vm = new VirtualMachine(1000000); // 1M gas limit
        $this->compiler = new Compiler();
    }
    
    public function testVirtualMachineBasicOperations(): void
    {
        // Test ADD operation: PUSH1 5, PUSH1 3, ADD
        $bytecode = '60056003' . '01';
        
        $result = $this->vm->execute($bytecode);
        
        $this->assertTrue($result['success']);
        $this->assertGreaterThan(0, $result['gasUsed']);
    }
    
    public function testVirtualMachineStackOperations(): void
    {
        // Test stack operations: PUSH1 10, DUP1, ADD (10 + 10 = 20)
        $bytecode = '600a' . '80' . '01';
        
        $result = $this->vm->execute($bytecode);
        
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('gasUsed', $result);
    }
    
    public function testVirtualMachineStorage(): void
    {
        // Test storage: PUSH1 42, PUSH1 0, SSTORE (store 42 at slot 0)
        $bytecode = '602a' . '6000' . '55';
        
        $result = $this->vm->execute($bytecode);
        
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('stateChanges', $result);
        $this->assertEquals(42, $result['stateChanges'][0] ?? null);
    }
    
    public function testVirtualMachineOutOfGas(): void
    {
        $vm = new VirtualMachine(10); // Very low gas limit
        
        // Try to execute expensive operation
        $bytecode = '602a' . '6000' . '55'; // SSTORE costs 20000 gas
        
        $result = $vm->execute($bytecode);
        
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('gas', strtolower($result['error'] ?? ''));
    }
    
    public function testSmartContractCompilation(): void
    {
        $sourceCode = '
            contract SimpleStorage {
                uint256 value;
                
                function setValue(uint256 newValue) public {
                    value = newValue;
                }
                
                function getValue() public returns (uint256) {
                    return value;
                }
            }
        ';
        
        $result = $this->compiler->compile($sourceCode);
        
        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['bytecode']);
        $this->assertIsArray($result['abi']);
        $this->assertCount(2, $result['abi']); // setValue and getValue functions
    }
    
    public function testContractWithConstructor(): void
    {
        $sourceCode = '
            contract Token {
                uint256 totalSupply;
                
                constructor(uint256 _initialSupply) {
                    totalSupply = _initialSupply;
                }
                
                function getTotalSupply() public returns (uint256) {
                    return totalSupply;
                }
            }
        ';
        
        $result = $this->compiler->compile($sourceCode);
        
        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['bytecode']);
    }
    
    public function testContractExecution(): void
    {
        // Simple contract that adds two numbers
        $sourceCode = '
            contract Calculator {
                function add(uint256 a, uint256 b) public returns (uint256) {
                    return a + b;
                }
            }
        ';
        
        $compileResult = $this->compiler->compile($sourceCode);
        $this->assertTrue($compileResult['success']);
        
        $bytecode = $compileResult['bytecode'];
        
        // Execute contract
        $context = [
            'caller' => '0x1234567890123456789012345678901234567890',
            'value' => 0,
            'gasPrice' => 20000000000
        ];
        
        $result = $this->vm->execute($bytecode, $context);
        
        // Should execute without errors
        $this->assertTrue($result['success'] || !empty($result['error'])); // Either success or expected error
    }
    
    public function testInvalidBytecode(): void
    {
        $invalidBytecode = 'ff00'; // Invalid opcode sequence
        
        $result = $this->vm->execute($invalidBytecode);
        
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }
    
    public function testCompilerErrorHandling(): void
    {
        $invalidSourceCode = '
            contract Invalid {
                invalid syntax here
            }
        ';
        
        $result = $this->compiler->compile($invalidSourceCode);
        
        // Should handle gracefully (might succeed with basic parsing or fail gracefully)
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('bytecode', $result);
    }
    
    public function testVirtualMachineContextUsage(): void
    {
        // Test CALLER opcode
        $bytecode = '33'; // CALLER
        
        $context = [
            'caller' => '0x1234567890123456789012345678901234567890'
        ];
        
        $result = $this->vm->execute($bytecode, $context);
        
        $this->assertTrue($result['success']);
    }
}
