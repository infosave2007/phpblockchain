<?php
declare(strict_types=1);

namespace Blockchain\Core\Cryptography;

/**
 * Professional Merkle Tree Implementation
 * 
 * Creates and verifies Merkle trees for transaction verification
 */
class MerkleTree
{
    private array $leaves;
    private array $tree;
    private string $root;
    
    public function __construct(array $data = [])
    {
        $this->leaves = [];
        $this->tree = [];
        
        if (!empty($data)) {
            $this->buildTree($data);
        }
    }
    
    /**
     * Build Merkle tree from data array
     */
    public function buildTree(array $data): string
    {
        if (empty($data)) {
            $this->root = str_repeat('0', 64);
            return $this->root;
        }
        
        // Create leaves (hash each data item)
        $this->leaves = [];
        foreach ($data as $item) {
            if (is_array($item) || is_object($item)) {
                $item = json_encode($item);
            }
            $this->leaves[] = hash('sha256', (string)$item);
        }
        
        // Build tree levels
        $currentLevel = $this->leaves;
        $this->tree = [$currentLevel];
        
        while (count($currentLevel) > 1) {
            $nextLevel = [];
            
            // Process pairs
            for ($i = 0; $i < count($currentLevel); $i += 2) {
                $left = $currentLevel[$i];
                $right = $currentLevel[$i + 1] ?? $left; // Duplicate if odd number
                
                $nextLevel[] = hash('sha256', $left . $right);
            }
            
            $currentLevel = $nextLevel;
            $this->tree[] = $currentLevel;
        }
        
        $this->root = $currentLevel[0];
        return $this->root;
    }
    
    /**
     * Get Merkle root
     */
    public function getRoot(): string
    {
        return $this->root ?? '';
    }
    
    /**
     * Get Merkle proof for specific data item
     */
    public function getProof(int $index): array
    {
        if ($index >= count($this->leaves)) {
            throw new \Exception("Index out of bounds");
        }
        
        $proof = [];
        $currentIndex = $index;
        
        // Traverse tree from bottom to top
        for ($level = 0; $level < count($this->tree) - 1; $level++) {
            $currentLevelNodes = $this->tree[$level];
            $isRightNode = ($currentIndex % 2) === 1;
            $siblingIndex = $isRightNode ? $currentIndex - 1 : $currentIndex + 1;
            
            if ($siblingIndex < count($currentLevelNodes)) {
                $proof[] = [
                    'hash' => $currentLevelNodes[$siblingIndex],
                    'direction' => $isRightNode ? 'left' : 'right'
                ];
            }
            
            $currentIndex = intval($currentIndex / 2);
        }
        
        return $proof;
    }
    
    /**
     * Verify Merkle proof
     */
    public function verifyProof(string $leaf, array $proof, string $root): bool
    {
        $currentHash = $leaf;
        
        foreach ($proof as $proofElement) {
            $siblingHash = $proofElement['hash'];
            $direction = $proofElement['direction'];
            
            if ($direction === 'left') {
                $currentHash = hash('sha256', $siblingHash . $currentHash);
            } else {
                $currentHash = hash('sha256', $currentHash . $siblingHash);
            }
        }
        
        return $currentHash === $root;
    }
    
    /**
     * Verify leaf is in tree
     */
    public function verifyLeaf(string $data, int $index): bool
    {
        if ($index >= count($this->leaves)) {
            return false;
        }
        
        $expectedHash = hash('sha256', $data);
        return $this->leaves[$index] === $expectedHash;
    }
    
    /**
     * Get all leaves
     */
    public function getLeaves(): array
    {
        return $this->leaves;
    }
    
    /**
     * Get tree structure
     */
    public function getTree(): array
    {
        return $this->tree;
    }
    
    /**
     * Get tree depth
     */
    public function getDepth(): int
    {
        return count($this->tree);
    }
    
    /**
     * Static method to create Merkle root from transaction hashes
     */
    public static function createRoot(array $transactionHashes): string
    {
        if (empty($transactionHashes)) {
            return str_repeat('0', 64);
        }
        
        $tree = new self();
        return $tree->buildTree($transactionHashes);
    }
    
    /**
     * Static method to verify transaction in block
     */
    public static function verifyTransaction(
        string $transactionHash, 
        array $proof, 
        string $merkleRoot
    ): bool {
        $tree = new self();
        return $tree->verifyProof($transactionHash, $proof, $merkleRoot);
    }
    
    /**
     * Create proof for transaction in block
     */
    public static function createTransactionProof(
        array $transactionHashes, 
        int $transactionIndex
    ): array {
        $tree = new self($transactionHashes);
        return $tree->getProof($transactionIndex);
    }
    
    /**
     * Combine two Merkle roots
     */
    public static function combineRoots(string $leftRoot, string $rightRoot): string
    {
        return hash('sha256', $leftRoot . $rightRoot);
    }
    
    /**
     * Create sparse Merkle tree for efficient updates
     */
    public function createSparseTree(array $keyValuePairs, int $depth = 256): string
    {
        $defaultHashes = $this->generateDefaultHashes($depth);
        $root = $defaultHashes[$depth];
        
        foreach ($keyValuePairs as $key => $value) {
            $root = $this->updateSparseTree($root, $key, $value, $depth, $defaultHashes);
        }
        
        return $root;
    }
    
    /**
     * Generate default hashes for sparse tree
     */
    private function generateDefaultHashes(int $depth): array
    {
        $defaultHashes = [str_repeat('0', 64)];
        
        for ($i = 1; $i <= $depth; $i++) {
            $prev = $defaultHashes[$i - 1];
            $defaultHashes[$i] = hash('sha256', $prev . $prev);
        }
        
        return $defaultHashes;
    }
    
    /**
     * Update sparse Merkle tree
     */
    private function updateSparseTree(
        string $root, 
        string $key, 
        string $value, 
        int $depth, 
        array $defaultHashes
    ): string {
        // This is a simplified implementation
        // In production, you'd need a more sophisticated sparse tree
        $valueHash = hash('sha256', $value);
        $keyBits = str_pad(decbin(hexdec(substr($key, 0, 8))), 32, '0', STR_PAD_LEFT);
        
        return hash('sha256', $root . $valueHash . $keyBits);
    }
}
