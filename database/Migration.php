<?php
declare(strict_types=1);

namespace Blockchain\Database;

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
    
    public function __construct(PDO $database, ?string $migrationsPath = null)
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
            
            return true;
            
        } catch (Exception $e) {
            throw new Exception('Schema creation failed: ' . $e->getMessage());
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
                hash VARCHAR(66) NOT NULL UNIQUE,
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
                contract_address VARCHAR(42) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_validator (validator),
                INDEX idx_staker (staker),
                INDEX idx_status (status),
                INDEX idx_start_block (start_block),
                INDEX idx_contract_address (contract_address)
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
                INDEX idx_status_reputation (status, reputation_score),
                INDEX idx_ping_time (ping_time),
                UNIQUE KEY unique_node (ip_address, port)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            
            -- Mempool table (consensus-critical)
            CREATE TABLE IF NOT EXISTS mempool (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                tx_hash VARCHAR(66) NOT NULL UNIQUE,
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
                expires_at TIMESTAMP NULL,
                status ENUM('pending', 'processing', 'failed') DEFAULT 'pending',
                retry_count INT DEFAULT 0,
                last_retry_at TIMESTAMP NULL,
                node_id VARCHAR(64) NULL,
                broadcast_count INT DEFAULT 0,
                
                INDEX idx_tx_hash (tx_hash),
                INDEX idx_from_address (from_address),
                INDEX idx_to_address (to_address),
                INDEX idx_priority_score (priority_score DESC),
                INDEX idx_created_at (created_at),
                INDEX idx_status (status),
                INDEX idx_expires_at (expires_at),
                INDEX idx_nonce_from (from_address, nonce),
                INDEX idx_status_created (status, created_at),
                INDEX idx_priority_score_asc (priority_score),
                INDEX idx_broadcast_count (broadcast_count)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            
            -- Configuration table
            CREATE TABLE IF NOT EXISTS config (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                key_name VARCHAR(255) NOT NULL UNIQUE,
                value TEXT NOT NULL,
                description TEXT,
                is_system TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_key_name (key_name),
                INDEX idx_key_pattern (key_name(20))
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
            
            -- Users table (for admin and user management)
            CREATE TABLE IF NOT EXISTS users (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(255) UNIQUE NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                api_key VARCHAR(64) UNIQUE,
                role ENUM('admin', 'user') DEFAULT 'user',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_email (email),
                INDEX idx_role (role)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            
            -- Insert default configuration
            INSERT IGNORE INTO config (key_name, value, description, is_system) VALUES
            ('blockchain.genesis_block', '', 'Genesis block hash', 1),
            ('blockchain.block_time', '10', 'Target block time in seconds', 1),
            ('blockchain.max_block_size', '1000000', 'Maximum block size in bytes', 1),
            ('consensus.min_stake', '1000', 'Minimum stake required for validation', 1),
            ('consensus.reward_rate', '0.05', 'Annual staking reward rate', 1),
            ('network.max_peers', '50', 'Maximum number of peer connections', 1),
            ('network.sync_batch_size', '100', 'Number of blocks to sync at once', 1),
            ('network.name', 'Blockchain Network', 'Network name', 0),
            ('network.token_symbol', 'COIN', 'Token symbol', 0),
            ('network.token_name', 'Blockchain Coin', 'Token full name', 0),
            ('network.initial_supply', '1000000', 'Initial token supply', 0),
            ('network.decimals', '18', 'Token decimal places', 0),
            ('network.chain_id', '20250808', 'Network chain ID', 1),
            ('network.protocol_version', '1.0.0', 'Protocol version', 1),
            
            -- Parameters for transaction broadcasting
            ('broadcast.enabled', '1', 'Enable transaction broadcasting to network nodes', 0),
            ('broadcast.timeout', '10', 'Timeout for broadcast requests in seconds', 0),
            ('broadcast.max_retries', '3', 'Maximum retry attempts for failed broadcasts', 0),
            ('broadcast.min_success_rate', '50', 'Minimum success rate percentage for broadcast', 0),
            -- Shared HMAC secret for inter-node broadcast authentication (set per environment)
            ('network.broadcast_secret', '', 'Shared HMAC secret for inter-node broadcast (X-Broadcast-Signature)', 1),
            
            -- Parameters for automatic block mining
            ('auto_mine.enabled', '1', 'Enable automatic block mining', 0),
            ('auto_mine.min_transactions', '10', 'Minimum transactions required to start mining', 0),
            ('auto_mine.max_transactions_per_block', '100', 'Maximum transactions per block', 0),
            ('auto_mine.max_blocks_per_minute', '2', 'Maximum blocks to mine per minute', 0),
            
            -- Parameters for automatic blockchain synchronization
            ('auto_sync.enabled', '1', 'Enable automatic blockchain synchronization', 0),
            ('auto_sync.trigger_on_transaction', '1', 'Trigger sync on new transactions', 0),
            ('auto_sync.trigger_on_block', '1', 'Trigger sync on new blocks', 0),
            ('auto_sync.max_height_difference', '5', 'Maximum height difference before forced sync', 0),
            ('auto_sync.check_interval', '30', 'Sync check interval in seconds', 0),
            ('auto_sync.max_sync_attempts', '3', 'Maximum sync attempts per trigger', 0),
            ('auto_sync.min_nodes_online', '2', 'Minimum nodes online to trigger sync', 0),
            
            -- Parameters for multi_curl optimization
            ('network.multi_curl.max_concurrent', '50', 'Maximum concurrent connections for multi_curl', 0),
            ('network.multi_curl.timeout', '30', 'Timeout for multi_curl requests', 0),
            ('network.multi_curl.connect_timeout', '5', 'Connection timeout for multi_curl', 0),
            
            -- Current node ID (will be auto-generated)
            ('node.id', '', 'Current node ID (will be auto-generated)', 1),
            
            -- Network topology parameters
            ('network.topology_ttl', '300', 'Network topology TTL in seconds', 0),
            ('network.broadcast_batch_size', '10', 'Number of nodes per broadcast batch', 0),
            ('network.max_connections_per_node', '20', 'Maximum connections per node', 0),
            ('network.topology_update_interval', '60', 'Topology update interval in seconds', 0),

            -- Staking contract configuration
            ('staking.contract_address', '', 'Staking contract address (auto-deploy if empty)', 0),
            ('contracts.auto_deploy.enabled', '1', 'Enable auto-deployment of smart contracts', 0);
            
            -- Create network topology tables
            CREATE TABLE IF NOT EXISTS network_topology (
                id INT AUTO_INCREMENT PRIMARY KEY,
                source_node_id VARCHAR(64) NOT NULL,
                target_node_id VARCHAR(64) NOT NULL,
                connection_strength INT DEFAULT 1,
                connection_type VARCHAR(50) NOT NULL DEFAULT 'pos_peer',
                last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                ttl_expires_at TIMESTAMP NULL,
                INDEX idx_source (source_node_id),
                INDEX idx_target (target_node_id),
                INDEX idx_ttl (ttl_expires_at),
                UNIQUE KEY unique_connection (source_node_id, target_node_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            
            CREATE TABLE IF NOT EXISTS network_topology_cache (
                id INT AUTO_INCREMENT PRIMARY KEY,
                cache_key VARCHAR(255) NOT NULL UNIQUE,
                cache_data LONGTEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                expires_at TIMESTAMP NOT NULL,
                INDEX idx_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            
            -- Broadcast tracking table (anti-loop system)
            CREATE TABLE IF NOT EXISTS broadcast_tracking (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                transaction_hash VARCHAR(66) NOT NULL,
                source_node_id VARCHAR(64) NOT NULL,
                current_node_id VARCHAR(64) NOT NULL,
                hop_count INT NOT NULL DEFAULT 0,
                broadcast_path TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                expires_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_broadcast_tracking_tx_hash (transaction_hash),
                INDEX idx_source_node (source_node_id),
                INDEX idx_current_node (current_node_id),
                INDEX idx_hop_count (hop_count),
                INDEX idx_broadcast_tracking_expires (expires_at),
                UNIQUE KEY unique_tx_source_current (transaction_hash, source_node_id, current_node_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            
                        -- Broadcast statistics table
            CREATE TABLE IF NOT EXISTS broadcast_stats (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                node_id VARCHAR(64) NOT NULL,
                metric_type VARCHAR(50) NOT NULL,
                metric_value DECIMAL(10,4) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_node_id (node_id),
                INDEX idx_metric_type (metric_type),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            
            -- Sync monitoring table
            CREATE TABLE IF NOT EXISTS sync_monitoring (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                event_type ENUM('height_check', 'sync_triggered', 'sync_completed', 'sync_failed', 'alert_raised') NOT NULL,
                local_height BIGINT NOT NULL,
                network_max_height BIGINT NULL,
                height_difference BIGINT NULL,
                nodes_checked INT NULL,
                nodes_responding INT NULL,
                sync_duration DECIMAL(8,3) NULL,
                error_message TEXT NULL,
                metadata JSON NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_event_type (event_type),
                INDEX idx_local_height (local_height),
                INDEX idx_height_difference (height_difference),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            -- Node status table
            CREATE TABLE IF NOT EXISTS node_status (
                node_id VARCHAR(64) PRIMARY KEY,
                node_url VARCHAR(255) NOT NULL,
                status ENUM('healthy', 'degraded', 'recovering', 'offline', 'error') NOT NULL,
                details JSON,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_status (status),
                INDEX idx_updated (updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            -- Sync health monitoring table
            CREATE TABLE IF NOT EXISTS sync_health_monitor (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                node_id VARCHAR(64) NOT NULL,
                metric_type VARCHAR(50) NOT NULL,
                metric_value DECIMAL(15,4) NOT NULL,
                threshold_warning DECIMAL(15,4) NOT NULL,
                threshold_critical DECIMAL(15,4) NOT NULL,
                status ENUM('healthy', 'warning', 'critical') NOT NULL,
                last_check TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                recovery_triggered BOOLEAN DEFAULT FALSE,
                recovery_count INT DEFAULT 0,
                INDEX idx_sync_health_node (node_id),
                INDEX idx_sync_health_metric (metric_type),
                INDEX idx_sync_health_status (status),
                INDEX idx_sync_health_check (last_check),
                UNIQUE KEY unique_node_metric (node_id, metric_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            -- Sync recovery log table
            CREATE TABLE IF NOT EXISTS sync_recovery_log (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                node_id VARCHAR(64) NOT NULL,
                recovery_type VARCHAR(50) NOT NULL,
                trigger_reason TEXT NOT NULL,
                recovery_actions JSON NOT NULL,
                started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                completed_at TIMESTAMP NULL,
                success BOOLEAN DEFAULT FALSE,
                error_message TEXT NULL,
                metrics_before JSON NULL,
                metrics_after JSON NULL,
                INDEX idx_sync_recovery_node (node_id),
                INDEX idx_sync_recovery_type (recovery_type),
                INDEX idx_sync_recovery_started (started_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            -- Governance proposals table
            CREATE TABLE IF NOT EXISTS governance_proposals (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                description TEXT NOT NULL,
                type ENUM('parameter', 'consensus', 'economic', 'upgrade', 'emergency') NOT NULL,
                proposer_address VARCHAR(42) NOT NULL,
                proposer_stake DECIMAL(20,8) NOT NULL,
                changes JSON NOT NULL,
                status ENUM('draft', 'active', 'approved', 'rejected', 'implemented', 'cancelled') DEFAULT 'draft',
                voting_start TIMESTAMP NULL,
                voting_end TIMESTAMP NULL,
                votes_for DECIMAL(20,8) DEFAULT 0,
                votes_against DECIMAL(20,8) DEFAULT 0,
                votes_abstain DECIMAL(20,8) DEFAULT 0,
                implementation_block INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_status (status),
                INDEX idx_type (type),
                INDEX idx_proposer (proposer_address)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            -- Governance votes table
            CREATE TABLE IF NOT EXISTS governance_votes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                proposal_id INT NOT NULL,
                voter_address VARCHAR(42) NOT NULL,
                vote ENUM('for', 'against', 'abstain') NOT NULL,
                weight DECIMAL(20,8) NOT NULL,
                reason TEXT,
                transaction_hash VARCHAR(66),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (proposal_id) REFERENCES governance_proposals(id),
                UNIQUE KEY unique_vote (proposal_id, voter_address),
                INDEX idx_proposal (proposal_id),
                INDEX idx_voter (voter_address)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            -- Governance delegations table
            CREATE TABLE IF NOT EXISTS governance_delegations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                delegator_address VARCHAR(42) NOT NULL,
                delegate_address VARCHAR(42) NOT NULL,
                weight DECIMAL(20,8) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                expires_at TIMESTAMP NULL,
                UNIQUE KEY unique_delegation (delegator_address, delegate_address),
                INDEX idx_delegator (delegator_address),
                INDEX idx_delegate (delegate_address)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            -- Governance implementations table
            CREATE TABLE IF NOT EXISTS governance_implementations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                proposal_id INT NOT NULL,
                implementation_hash VARCHAR(66) NOT NULL,
                block_height INT NOT NULL,
                success BOOLEAN NOT NULL,
                error_message TEXT,
                rollback_data JSON,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (proposal_id) REFERENCES governance_proposals(id),
                INDEX idx_proposal (proposal_id),
                INDEX idx_block (block_height)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            -- Event queue table
            CREATE TABLE IF NOT EXISTS event_queue (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                event_type VARCHAR(50) NOT NULL,
                event_data JSON NOT NULL,
                event_id VARCHAR(64) NOT NULL,
                source_node VARCHAR(64) NOT NULL,
                priority TINYINT NOT NULL DEFAULT 5,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                processed_at TIMESTAMP NULL,
                retry_count TINYINT DEFAULT 0,
                status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
                INDEX idx_event_queue_status (status),
                INDEX idx_event_queue_priority (priority),
                INDEX idx_event_queue_created (created_at),
                INDEX idx_event_queue_type (event_type),
                UNIQUE KEY unique_event_id (event_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            -- Sync rate limits table
            CREATE TABLE IF NOT EXISTS sync_rate_limits (
                id VARCHAR(100) PRIMARY KEY,
                request_count INT NOT NULL DEFAULT 0,
                window_start TIMESTAMP NOT NULL,
                last_request TIMESTAMP NOT NULL,
                blocked_until TIMESTAMP NULL,
                INDEX idx_sync_rate_limits_window (window_start),
                INDEX idx_sync_rate_limits_blocked (blocked_until)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            -- Sync queue priority table
            CREATE TABLE IF NOT EXISTS sync_queue_priority (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                sync_type VARCHAR(50) NOT NULL,
                node_id VARCHAR(64) NOT NULL,
                priority INT NOT NULL DEFAULT 5,
                data JSON NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                scheduled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                processed_at TIMESTAMP NULL,
                status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
                retry_count INT DEFAULT 0,
                INDEX idx_sync_queue_status (status),
                INDEX idx_sync_queue_scheduled (scheduled_at),
                INDEX idx_sync_queue_priority (priority),
                INDEX idx_sync_queue_type (sync_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            -- Node health metrics table
            CREATE TABLE IF NOT EXISTS node_health_metrics (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                node_id VARCHAR(64) NOT NULL,
                node_url VARCHAR(255) NOT NULL,
                response_time DECIMAL(8,4) NOT NULL DEFAULT 0,
                success_rate DECIMAL(5,2) NOT NULL DEFAULT 100.00,
                cpu_usage DECIMAL(5,2) DEFAULT NULL,
                memory_usage DECIMAL(5,2) DEFAULT NULL,
                disk_usage DECIMAL(5,2) DEFAULT NULL,
                active_connections INT DEFAULT NULL,
                queue_size INT DEFAULT NULL,
                last_error TEXT NULL,
                last_check TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                health_score DECIMAL(5,2) NOT NULL DEFAULT 100.00,
                status ENUM('healthy', 'degraded', 'unhealthy', 'offline') DEFAULT 'healthy',
                INDEX idx_node_health_id (node_id),
                INDEX idx_node_health_score (health_score),
                INDEX idx_node_health_status (status),
                INDEX idx_node_health_check (last_check),
                UNIQUE KEY unique_node_url (node_url)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            -- Node request history table
            CREATE TABLE IF NOT EXISTS node_request_history (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                node_id VARCHAR(64) NOT NULL,
                request_type VARCHAR(50) NOT NULL,
                response_time DECIMAL(8,4) NOT NULL,
                success BOOLEAN NOT NULL,
                error_message TEXT NULL,
                request_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_node_request_id (node_id),
                INDEX idx_node_request_type (request_type),
                INDEX idx_node_request_timestamp (request_timestamp),
                INDEX idx_node_request_success (success)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            -- Circuit breaker state table
            CREATE TABLE IF NOT EXISTS circuit_breaker_state (
                id VARCHAR(100) PRIMARY KEY,
                node_id VARCHAR(64) NOT NULL,
                operation_type VARCHAR(50) NOT NULL,
                state ENUM('closed', 'open', 'half_open') DEFAULT 'closed',
                failure_count INT DEFAULT 0,
                success_count INT DEFAULT 0,
                last_failure_time TIMESTAMP NULL,
                last_success_time TIMESTAMP NULL,
                state_changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                next_attempt_time TIMESTAMP NULL,
                total_requests INT DEFAULT 0,
                failed_requests INT DEFAULT 0,
                INDEX idx_circuit_node (node_id),
                INDEX idx_circuit_operation (operation_type),
                INDEX idx_circuit_state (state),
                INDEX idx_circuit_next_attempt (next_attempt_time)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            -- Circuit breaker events table
            CREATE TABLE IF NOT EXISTS circuit_breaker_events (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                circuit_id VARCHAR(100) NOT NULL,
                event_type ENUM('opened', 'closed', 'half_opened', 'request_rejected', 'request_allowed') NOT NULL,
                node_id VARCHAR(64) NOT NULL,
                operation_type VARCHAR(50) NOT NULL,
                failure_count INT DEFAULT 0,
                success_count INT DEFAULT 0,
                error_message TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_circuit_events_circuit (circuit_id),
                INDEX idx_circuit_events_node (node_id),
                INDEX idx_circuit_events_type (event_type),
                INDEX idx_circuit_events_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
    }
}
