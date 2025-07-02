<?php
declare(strict_types=1);

namespace Blockchain\Core\Config;

use PDO;
use Exception;

/**
 * Network Configuration Manager
 * Manages network and blockchain configuration in database
 */
class NetworkConfig
{
    private ?PDO $database;
    private array $cache = [];
    private bool $cacheLoaded = false;
    
    public function __construct(?PDO $database = null)
    {
        $this->database = $database;
    }
    
    /**
     * Get configuration value
     */
    public function get(string $key, $default = null)
    {
        if (!$this->cacheLoaded) {
            $this->loadCache();
        }
        
        return $this->cache[$key] ?? $default;
    }
    
    /**
     * Set configuration value
     */
    public function set(string $key, string $value, string $description = '', bool $isSystem = false): bool
    {
        if (!$this->database) {
            return false;
        }
        
        try {
            $stmt = $this->database->prepare("
                INSERT INTO config (key_name, value, description, is_system) 
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                value = VALUES(value), 
                description = VALUES(description),
                updated_at = CURRENT_TIMESTAMP
            ");
            
            $result = $stmt->execute([$key, $value, $description, $isSystem]);
            
            if ($result) {
                $this->cache[$key] = $value;
            }
            
            return $result;
            
        } catch (Exception $e) {
            error_log("Failed to set config {$key}: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get all network configuration
     */
    public function getNetworkConfig(): array
    {
        if (!$this->cacheLoaded) {
            $this->loadCache();
        }
        
        $networkConfig = [];
        foreach ($this->cache as $key => $value) {
            if (str_starts_with($key, 'network.') || str_starts_with($key, 'consensus.')) {
                $networkConfig[$key] = $value;
            }
        }
        
        return $networkConfig;
    }
    
    /**
     * Get token information
     */
    public function getTokenInfo(): array
    {
        return [
            'symbol' => $this->get('network.token_symbol', 'COIN'),
            'name' => $this->get('network.token_name', 'Blockchain Token'),
            'decimals' => (int)$this->get('network.decimals', 8),
            'initial_supply' => (int)$this->get('network.initial_supply', 1000000)
        ];
    }
    
    /**
     * Get network information
     */
    public function getNetworkInfo(): array
    {
        return [
            'name' => $this->get('network.name', 'Blockchain Network'),
            'chain_id' => (int)$this->get('network.chain_id', 1),
            'protocol_version' => $this->get('network.protocol_version', '1.0.0'),
            'consensus_algorithm' => $this->get('consensus.algorithm', 'pos'),
            'min_stake' => (int)$this->get('consensus.min_stake', 1000),
            'block_time' => (int)$this->get('blockchain.block_time', 10)
        ];
    }
    
    /**
     * Load configuration from database into cache
     */
    private function loadCache(): void
    {
        if (!$this->database) {
            $this->cacheLoaded = true;
            return;
        }
        
        try {
            $stmt = $this->database->query("SELECT key_name, value FROM config");
            $configs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($configs as $config) {
                $this->cache[$config['key_name']] = $config['value'];
            }
            
            $this->cacheLoaded = true;
            
        } catch (Exception $e) {
            error_log("Failed to load config cache: " . $e->getMessage());
            $this->cacheLoaded = true; // Prevent infinite retry
        }
    }
    
    /**
     * Clear cache and reload from database
     */
    public function refresh(): void
    {
        $this->cache = [];
        $this->cacheLoaded = false;
        $this->loadCache();
    }
    
    /**
     * Check if configuration key exists
     */
    public function has(string $key): bool
    {
        if (!$this->cacheLoaded) {
            $this->loadCache();
        }
        
        return isset($this->cache[$key]);
    }
    
    /**
     * Get all configuration as array
     */
    public function all(): array
    {
        if (!$this->cacheLoaded) {
            $this->loadCache();
        }
        
        return $this->cache;
    }
    
    /**
     * Set multiple configuration values at once
     */
    public function setMultiple(array $configs): bool
    {
        if (!$this->database) {
            return false;
        }
        
        try {
            $this->database->beginTransaction();
            
            $stmt = $this->database->prepare("
                INSERT INTO config (key_name, value, description, is_system) 
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                value = VALUES(value), 
                description = VALUES(description),
                updated_at = CURRENT_TIMESTAMP
            ");
            
            foreach ($configs as $key => $data) {
                if (is_array($data)) {
                    $value = $data['value'] ?? '';
                    $description = $data['description'] ?? '';
                    $isSystem = $data['is_system'] ?? false;
                } else {
                    $value = (string)$data;
                    $description = '';
                    $isSystem = false;
                }
                
                $stmt->execute([$key, $value, $description, $isSystem]);
                $this->cache[$key] = $value;
            }
            
            $this->database->commit();
            return true;
            
        } catch (Exception $e) {
            $this->database->rollback();
            error_log("Failed to set multiple configs: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get configuration for wallet API
     */
    public function getWalletApiConfig(): array
    {
        $tokenInfo = $this->getTokenInfo();
        $networkInfo = $this->getNetworkInfo();
        
        return [
            'crypto_symbol' => $tokenInfo['symbol'],
            'crypto_name' => $tokenInfo['name'],
            'crypto_decimals' => $tokenInfo['decimals'],
            'network_name' => $networkInfo['name'],
            'chain_id' => $networkInfo['chain_id'],
            'min_stake_amount' => $networkInfo['min_stake'],
            'consensus_algorithm' => $networkInfo['consensus_algorithm']
        ];
    }
}
