<?php
declare(strict_types=1);

namespace Blockchain\Core\Storage;

use Blockchain\Core\Contracts\BlockInterface;

/**
 * Professional Block Storage
 */
class BlockStorage
{
    private array $blocks = [];
    private string $storageFile;
    
    public function __construct(string $storageFile = 'blockchain.json')
    {
        $this->storageFile = $storageFile;
        $this->loadBlocks();
    }
    
    public function saveBlock(BlockInterface $block): bool
    {
        $this->blocks[$block->getIndex()] = $block;
        return $this->saveToFile();
    }
    
    public function getBlock(int $index): ?BlockInterface
    {
        return $this->blocks[$index] ?? null;
    }
    
    public function getLatestBlock(): ?BlockInterface
    {
        if (empty($this->blocks)) {
            return null;
        }
        
        $maxIndex = max(array_keys($this->blocks));
        return $this->blocks[$maxIndex];
    }
    
    public function getBlockCount(): int
    {
        return count($this->blocks);
    }
    
    private function loadBlocks(): void
    {
        if (file_exists($this->storageFile)) {
            $data = json_decode(file_get_contents($this->storageFile), true);
            // In a real implementation, we'd deserialize blocks
            $this->blocks = $data ?? [];
        }
    }
    
    private function saveToFile(): bool
    {
        // In a real implementation, we'd serialize blocks properly
        return file_put_contents($this->storageFile, json_encode([])) !== false;
    }
}
