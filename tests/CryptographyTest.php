<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Blockchain\Core\Cryptography\KeyPair;
use Blockchain\Core\Cryptography\Signature;
use Blockchain\Core\Cryptography\EllipticCurve;

/**
 * Professional Cryptography Tests
 */
class CryptographyTest extends TestCase
{
    public function testKeyPairGeneration(): void
    {
        $keyPair = KeyPair::generate();
        
        $this->assertNotEmpty($keyPair->getPrivateKey());
        $this->assertNotEmpty($keyPair->getPublicKey());
        $this->assertNotEmpty($keyPair->getAddress());
        
        // Private key should be 64 hex characters (32 bytes)
        $this->assertEquals(64, strlen($keyPair->getPrivateKey()));
        
        // Address should start with 0x
        $this->assertStringStartsWith('0x', $keyPair->getAddress());
        $this->assertEquals(42, strlen($keyPair->getAddress()));
    }
    
    public function testKeyPairFromPrivateKey(): void
    {
        $privateKey = bin2hex(random_bytes(32));
        $keyPair = KeyPair::fromPrivateKey($privateKey);
        
        $this->assertEquals($privateKey, $keyPair->getPrivateKey());
        $this->assertNotEmpty($keyPair->getPublicKey());
        $this->assertNotEmpty($keyPair->getAddress());
    }
    
    public function testSignatureCreation(): void
    {
        $keyPair = KeyPair::generate();
        $message = "Test message for signing";
        
        $signature = $keyPair->sign($message);
        
        $this->assertNotEmpty($signature);
        $this->assertEquals(128, strlen($signature)); // 64 bytes for r + s
    }
    
    public function testSignatureVerification(): void
    {
        $keyPair = KeyPair::generate();
        $message = "Test message for verification";
        
        $signature = $keyPair->sign($message);
        $isValid = $keyPair->verify($message, $signature);
        
        $this->assertTrue($isValid);
        
        // Test with wrong message
        $wrongMessage = "Wrong message";
        $isInvalid = $keyPair->verify($wrongMessage, $signature);
        
        $this->assertFalse($isInvalid);
    }
    
    public function testEllipticCurveOperations(): void
    {
        $privateKey = bin2hex(random_bytes(32));
        $publicKey = EllipticCurve::generatePublicKey($privateKey);
        
        $this->assertIsArray($publicKey);
        $this->assertArrayHasKey('x', $publicKey);
        $this->assertArrayHasKey('y', $publicKey);
        
        // Test point compression
        $compressed = EllipticCurve::compressPublicKey($publicKey);
        $this->assertEquals(66, strlen($compressed)); // 33 bytes = 66 hex chars
        
        // Test point decompression
        $decompressed = EllipticCurve::decompressPublicKey($compressed);
        $this->assertEquals($publicKey['x'], $decompressed['x']);
        $this->assertEquals($publicKey['y'], $decompressed['y']);
    }
    
    public function testECDSASignAndVerify(): void
    {
        $privateKey = bin2hex(random_bytes(32));
        $message = "Test ECDSA message";
        $messageHash = hash('sha256', $message);
        
        $publicKey = EllipticCurve::generatePublicKey($privateKey);
        $signature = EllipticCurve::sign($messageHash, $privateKey);
        
        $this->assertIsArray($signature);
        $this->assertArrayHasKey('r', $signature);
        $this->assertArrayHasKey('s', $signature);
        
        $isValid = EllipticCurve::verify($messageHash, $signature, $publicKey);
        $this->assertTrue($isValid);
        
        // Test with wrong message
        $wrongHash = hash('sha256', "Wrong message");
        $isInvalid = EllipticCurve::verify($wrongHash, $signature, $publicKey);
        $this->assertFalse($isInvalid);
    }
    
    public function testDeterministicKeyGeneration(): void
    {
        $seed = "test seed phrase";
        $privateKey1 = hash('sha256', $seed);
        $privateKey2 = hash('sha256', $seed);
        
        $keyPair1 = KeyPair::fromPrivateKey($privateKey1);
        $keyPair2 = KeyPair::fromPrivateKey($privateKey2);
        
        // Same seed should produce same keys
        $this->assertEquals($keyPair1->getPrivateKey(), $keyPair2->getPrivateKey());
        $this->assertEquals($keyPair1->getPublicKey(), $keyPair2->getPublicKey());
        $this->assertEquals($keyPair1->getAddress(), $keyPair2->getAddress());
    }
}
