<?php
declare(strict_types=1);

namespace Blockchain\Core\Database;

use PDO;
use Exception;

/**
 * Database Migration System
 * 
 * Handles database schema creation and updates
 */
class Migration
{
    private PDO $database;
    private string $migrationsPath;
    
    public function __construct(PDO $database, string $migrationsPath = null)
    {
        $this->database = $database;
        $this->migrationsPath = $migrationsPath ?? __DIR__ . '/../../database/migrations';
    }
    
    /**
     * Run all pending migrations
     */
    public function migrate(): bool
    {
        try {
            // Create migrations table if it doesn't exist
            $this->createMigrationsTable();
            
            // Get executed migrations
            $executedMigrations = $this->getExecutedMigrations();
            
            // Get all migration files
            $migrationFiles = $this->getMigrationFiles();
            
            // Run pending migrations
            foreach ($migrationFiles as $file) {
                $migrationName = basename($file, '.sql');
                
                if (!in_array($migrationName, $executedMigrations)) {
                    $this->runMigration($file, $migrationName);
                    echo "Executed migration: {$migrationName}\n";
                }
            }
            
            return true;
            
        } catch (Exception $e) {
            echo "Migration failed: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    /**
     * Create initial database schema
     */
    public function createSchema(): bool
    {
        try {
            $schema = $this->getInitialSchema();
            $this->database->exec($schema);
            
            // Mark initial migrations as executed
            $this->markAsExecuted('001_initial_schema');
            
            return true;
            
        } catch (Exception $e) {
            echo "Schema creation failed: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    /**
     * Create migrations table
     */
    private function createMigrationsTable(): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS migrations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(255) NOT NULL UNIQUE,
                executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";
        
        $this->database->exec($sql);
    }
    
    /**
     * Get executed migrations
     */
    private function getExecutedMigrations(): array
    {
        $stmt = $this->database->query("SELECT migration FROM migrations ORDER BY id");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    /**
     * Get migration files
     */
    private function getMigrationFiles(): array
    {
        if (!is_dir($this->migrationsPath)) {
            return [];
        }
        
        $files = glob($this->migrationsPath . '/*.sql');
        sort($files);
        
        return $files;
    }
    
    /**
     * Run a migration file
     */
    private function runMigration(string $file, string $migrationName): void
    {
        $sql = file_get_contents($file);
        
        if ($sql === false) {
            throw new Exception("Could not read migration file: {$file}");
        }
        
        // Begin transaction
        $this->database->beginTransaction();
        
        try {
            // Execute migration
            $this->database->exec($sql);
            
            // Mark as executed
            $this->markAsExecuted($migrationName);
            
            // Commit
            $this->database->commit();
            
        } catch (Exception $e) {
            $this->database->rollback();
            throw $e;
        }
    }
    
    /**
     * Mark migration as executed
     */
    private function markAsExecuted(string $migrationName): void
    {
        $stmt = $this->database->prepare("INSERT INTO migrations (migration) VALUES (?)");
        $stmt->execute([$migrationName]);
    }
    
    /**
     * Get initial database schema
     */
    private function getInitialSchema(): string
    {
        return "
            -- Blocks table
            CREATE TABLE IF NOT EXISTS blocks (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                hash VARCHAR(64) NOT NULL UNIQUE,
                parent_hash VARCHAR(64) NOT NULL,
                height BIGINT NOT NULL,
                timestamp BIGINT NOT NULL,
                validator VARCHAR(42) NOT NULL,
                signature TEXT NOT NULL,
                merkle_root VARCHAR(64) NOT NULL,
                transactions_count INT NOT NULL DEFAULT 0,
                metadata JSON,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_hash (hash),
                INDEX idx_height (height),
                INDEX idx_timestamp (timestamp),
                INDEX idx_validator (validator)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            
            -- Transactions table
            CREATE TABLE IF NOT EXISTS transactions (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                hash VARCHAR(64) NOT NULL UNIQUE,
                block_hash VARCHAR(64) NOT NULL,
                block_height BIGINT NOT NULL,
                from_address VARCHAR(42) NOT NULL,
                to_address VARCHAR(42) NOT NULL,
                amount DECIMAL(20,8) NOT NULL DEFAULT 0,
                fee DECIMAL(20,8) NOT NULL DEFAULT 0,
                gas_limit BIGINT NOT NULL DEFAULT 0,
                gas_used BIGINT NOT NULL DEFAULT 0,
                gas_price DECIMAL(20,8) NOT NULL DEFAULT 0,
                nonce BIGINT NOT NULL DEFAULT 0,
                data TEXT,
                signature TEXT NOT NULL,
                status ENUM('pending', 'confirmed', 'failed') NOT NULL DEFAULT 'pending',
                timestamp BIGINT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_hash (hash),
                INDEX idx_block_hash (block_hash),
                INDEX idx_from_address (from_address),
                INDEX idx_to_address (to_address),
                INDEX idx_status (status),
                INDEX idx_timestamp (timestamp),
                FOREIGN KEY (block_hash) REFERENCES blocks(hash) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            
            -- Wallets table
            CREATE TABLE IF NOT EXISTS wallets (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                address VARCHAR(42) NOT NULL UNIQUE,
                public_key TEXT NOT NULL,
                balance DECIMAL(20,8) NOT NULL DEFAULT 0,
                staked_balance DECIMAL(20,8) NOT NULL DEFAULT 0,
                nonce BIGINT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_address (address),
                INDEX idx_balance (balance)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            
            -- Smart Contracts table
            CREATE TABLE IF NOT EXISTS smart_contracts (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                address VARCHAR(42) NOT NULL UNIQUE,
                creator VARCHAR(42) NOT NULL,
                name VARCHAR(255) NOT NULL,
                version VARCHAR(50) NOT NULL DEFAULT '1.0.0',
                bytecode LONGTEXT NOT NULL,
                abi JSON NOT NULL,
                source_code LONGTEXT,
                deployment_tx VARCHAR(64) NOT NULL,
                deployment_block BIGINT NOT NULL,
                gas_used BIGINT NOT NULL DEFAULT 0,
                status ENUM('active', 'paused', 'destroyed') NOT NULL DEFAULT 'active',
                storage JSON,
                metadata JSON,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_address (address),
                INDEX idx_creator (creator),
                INDEX idx_status (status),
                INDEX idx_deployment_block (deployment_block)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            
            -- Staking table
            CREATE TABLE IF NOT EXISTS staking (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                validator VARCHAR(42) NOT NULL,
                staker VARCHAR(42) NOT NULL,
                amount DECIMAL(20,8) NOT NULL,
                reward_rate DECIMAL(5,4) NOT NULL DEFAULT 0.05,
                start_block BIGINT NOT NULL,
                end_block BIGINT NULL,
                status ENUM('active', 'pending_withdrawal', 'withdrawn') NOT NULL DEFAULT 'active',
                rewards_earned DECIMAL(20,8) NOT NULL DEFAULT 0,
                last_reward_block BIGINT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_validator (validator),
                INDEX idx_staker (staker),
                INDEX idx_status (status),
                INDEX idx_start_block (start_block)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            
            -- Validators table
            CREATE TABLE IF NOT EXISTS validators (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                address VARCHAR(42) NOT NULL UNIQUE,
                public_key TEXT NOT NULL,
                stake DECIMAL(20,8) NOT NULL DEFAULT 0,
                delegated_stake DECIMAL(20,8) NOT NULL DEFAULT 0,
                commission_rate DECIMAL(5,4) NOT NULL DEFAULT 0.1,
                status ENUM('active', 'inactive', 'jailed', 'unbonding') NOT NULL DEFAULT 'inactive',
                blocks_produced BIGINT NOT NULL DEFAULT 0,
                blocks_missed BIGINT NOT NULL DEFAULT 0,
                last_active_block BIGINT NOT NULL DEFAULT 0,
                jail_until_block BIGINT NULL,
                metadata JSON,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_address (address),
                INDEX idx_status (status),
                INDEX idx_stake (stake)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            
            -- Nodes table
            CREATE TABLE IF NOT EXISTS nodes (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                node_id VARCHAR(64) NOT NULL UNIQUE,
                ip_address VARCHAR(45) NOT NULL,
                port INT NOT NULL,
                public_key TEXT NOT NULL,
                version VARCHAR(50) NOT NULL,
                status ENUM('active', 'inactive', 'banned') NOT NULL DEFAULT 'inactive',
                last_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                blocks_synced BIGINT NOT NULL DEFAULT 0,
                ping_time INT NOT NULL DEFAULT 0,
                reputation_score INT NOT NULL DEFAULT 100,
                metadata JSON,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_node_id (node_id),
                INDEX idx_status (status),
                INDEX idx_last_seen (last_seen),
                UNIQUE KEY unique_node (ip_address, port)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            
            -- Mempool table
            CREATE TABLE IF NOT EXISTS mempool (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                tx_hash VARCHAR(64) NOT NULL UNIQUE,
                from_address VARCHAR(42) NOT NULL,
                to_address VARCHAR(42) NOT NULL,
                amount DECIMAL(20,8) NOT NULL DEFAULT 0,
                fee DECIMAL(20,8) NOT NULL DEFAULT 0,
                gas_price DECIMAL(20,8) NOT NULL DEFAULT 0,
                gas_limit BIGINT NOT NULL DEFAULT 0,
                nonce BIGINT NOT NULL DEFAULT 0,
                data TEXT,
                signature TEXT NOT NULL,
                priority_score INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_tx_hash (tx_hash),
                INDEX idx_from_address (from_address),
                INDEX idx_priority_score (priority_score),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            
            -- Configuration table
            CREATE TABLE IF NOT EXISTS config (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                key_name VARCHAR(255) NOT NULL UNIQUE,
                value TEXT NOT NULL,
                description TEXT,
                is_system BOOLEAN NOT NULL DEFAULT FALSE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_key_name (key_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            
            -- Logs table
            CREATE TABLE IF NOT EXISTS logs (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                level ENUM('debug', 'info', 'warning', 'error', 'critical') NOT NULL,
                message TEXT NOT NULL,
                context JSON,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_level (level),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            
            -- Insert default configuration
            INSERT IGNORE INTO config (key_name, value, description, is_system) VALUES
            ('blockchain.genesis_block', '', 'Genesis block hash', TRUE),
            ('blockchain.block_time', '10', 'Target block time in seconds', TRUE),
            ('blockchain.max_block_size', '1000000', 'Maximum block size in bytes', TRUE),
            ('consensus.min_stake', '1000', 'Minimum stake required for validation', TRUE),
            ('consensus.reward_rate', '0.05', 'Annual staking reward rate', TRUE),
            ('network.max_peers', '50', 'Maximum number of peer connections', TRUE),
            ('network.sync_batch_size', '100', 'Number of blocks to sync at once', TRUE);
        ";
    }
}
