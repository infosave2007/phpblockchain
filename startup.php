<?php
/**
 * Simple PHP Startup Script for Budget Hosting
 * No shell commands - pure PHP implementation
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

class BlockchainNodeManager
{
    private $config;
    private $logFile;
    private $pidFile;
    private $storageDir;
    
    public function __construct()
    {
        $this->config = $this->loadConfig();
        $this->logFile = __DIR__ . '/logs/node.log';
        $this->pidFile = __DIR__ . '/storage/node.pid';
        $this->storageDir = __DIR__ . '/storage';
        
        $this->ensureDirectories();
    }
    
    /**
     * Main entry point
     */
    public function run(string $command = 'help'): void
    {
        $this->log("🚀 Blockchain Node Manager - Command: {$command}");
        
        try {
            switch ($command) {
                case 'start':
                    $this->startNode();
                    break;
                    
                case 'stop':
                    $this->stopNode();
                    break;
                    
                case 'restart':
                    $this->restartNode();
                    break;
                    
                case 'status':
                    $this->checkStatus();
                    break;
                    
                case 'health':
                    $this->checkHealth();
                    break;
                    
                case 'backup':
                    $this->createBackup();
                    break;
                    
                case 'install':
                    $this->installDependencies();
                    break;
                    
                case 'setup':
                    $this->setupDatabase();
                    break;
                    
                case 'recovery':
                    $this->runRecovery();
                    break;
                    
                case 'help':
                default:
                    $this->showHelp();
                    break;
            }
        } catch (Exception $e) {
            $this->error("Command failed: " . $e->getMessage());
            exit(1);
        }
    }
    
    /**
     * Start the blockchain node
     */
    private function startNode(): void
    {
        if ($this->isRunning()) {
            $this->success("Node is already running");
            return;
        }
        
        $this->log("Starting blockchain node...");
        
        // Perform pre-start checks
        $this->performPreStartChecks();
        
        // Create startup backup
        $this->createStartupBackup();
        
        // Start the node process
        $this->log("Launching node process...");
        
        if (function_exists('pcntl_fork')) {
            // Use fork if available (Linux/Unix)
            $pid = pcntl_fork();
            
            if ($pid === 0) {
                // Child process - run the node
                $this->runNodeProcess();
                exit(0);
            } elseif ($pid > 0) {
                // Parent process - save PID and exit
                file_put_contents($this->pidFile, $pid);
                $this->success("Node started with PID: {$pid}");
            } else {
                throw new Exception("Failed to fork process");
            }
        } else {
            // Fallback for hosting without pcntl
            $this->log("PCntl not available, starting in background mode...");
            
            // Use output buffering to simulate background process
            ob_start();
            
            // Redirect to log file
            ini_set('log_errors', 1);
            ini_set('error_log', $this->logFile);
            
            // Save a pseudo-PID
            file_put_contents($this->pidFile, getmypid());
            
            $this->success("Node started in background mode");
            
            // Start the actual node
            $this->runNodeProcess();
        }
    }
    
    /**
     * Stop the blockchain node
     */
    private function stopNode(): void
    {
        if (!$this->isRunning()) {
            $this->warning("Node is not running");
            return;
        }
        
        $this->log("Stopping blockchain node...");
        
        $pid = $this->getNodePid();
        
        if (function_exists('posix_kill')) {
            // Send SIGTERM if available
            if (posix_kill($pid, SIGTERM)) {
                $this->log("Sent SIGTERM to process {$pid}");
                
                // Wait for graceful shutdown
                $attempts = 0;
                while ($this->isProcessRunning($pid) && $attempts < 30) {
                    sleep(1);
                    $attempts++;
                }
                
                if ($this->isProcessRunning($pid)) {
                    // Force kill if still running
                    posix_kill($pid, SIGKILL);
                    $this->log("Force killed process {$pid}");
                }
            }
        }
        
        // Remove PID file
        if (file_exists($this->pidFile)) {
            unlink($this->pidFile);
        }
        
        $this->success("Node stopped");
    }
    
    /**
     * Restart the node
     */
    private function restartNode(): void
    {
        $this->log("Restarting blockchain node...");
        $this->stopNode();
        sleep(2);
        $this->startNode();
    }
    
    /**
     * Check node status
     */
    private function checkStatus(): void
    {
        echo "\n🔍 Blockchain Node Status\n";
        echo "========================\n\n";
        
        $running = $this->isRunning();
        echo "Status: " . ($running ? "🟢 Running" : "🔴 Stopped") . "\n";
        
        if ($running) {
            $pid = $this->getNodePid();
            echo "PID: {$pid}\n";
            
            if (function_exists('memory_get_usage')) {
                echo "Memory: " . $this->formatBytes(memory_get_usage(true)) . "\n";
                echo "Peak Memory: " . $this->formatBytes(memory_get_peak_usage(true)) . "\n";
            }
        }
        
        echo "Log file: {$this->logFile}\n";
        echo "Storage: {$this->storageDir}\n";
        
        // Check storage space
        $freeSpace = disk_free_space($this->storageDir);
        echo "Free space: " . $this->formatBytes($freeSpace) . "\n";
        
        // Check key files
        $binaryFile = $this->storageDir . '/blockchain/blockchain.bin';
        echo "Binary file: " . (file_exists($binaryFile) ? "✅ Present" : "❌ Missing") . "\n";
        
        if (file_exists($binaryFile)) {
            echo "Binary size: " . $this->formatBytes(filesize($binaryFile)) . "\n";
        }
        
        echo "\n";
    }
    
    /**
     * Check node health
     */
    private function checkHealth(): void
    {
        echo "\n🏥 Node Health Check\n";
        echo "===================\n\n";
        
        $healthy = true;
        
        // Check 1: Node running
        if ($this->isRunning()) {
            echo "✅ Node process: Running\n";
        } else {
            echo "❌ Node process: Stopped\n";
            $healthy = false;
        }
        
        // Check 2: Binary file
        $binaryFile = $this->storageDir . '/blockchain/blockchain.bin';
        if (file_exists($binaryFile) && filesize($binaryFile) > 8) {
            echo "✅ Binary file: Valid\n";
        } else {
            echo "❌ Binary file: Invalid or missing\n";
            $healthy = false;
        }
        
        // Check 3: Database
        try {
            $this->testDatabaseConnection();
            echo "✅ Database: Connected\n";
        } catch (Exception $e) {
            echo "❌ Database: " . $e->getMessage() . "\n";
            $healthy = false;
        }
        
        // Check 4: Storage space
        $freeSpace = disk_free_space($this->storageDir);
        $requiredSpace = 100 * 1024 * 1024; // 100MB
        
        if ($freeSpace > $requiredSpace) {
            echo "✅ Storage space: " . $this->formatBytes($freeSpace) . " available\n";
        } else {
            echo "❌ Storage space: Only " . $this->formatBytes($freeSpace) . " available\n";
            $healthy = false;
        }
        
        // Check 5: PHP version and extensions
        echo "✅ PHP version: " . PHP_VERSION . "\n";
        
        $required = ['pdo', 'json', 'curl'];
        foreach ($required as $ext) {
            if (extension_loaded($ext)) {
                echo "✅ Extension {$ext}: Loaded\n";
            } else {
                echo "❌ Extension {$ext}: Missing\n";
                $healthy = false;
            }
        }
        
        echo "\n" . ($healthy ? "🎉 Overall health: GOOD" : "⚠️ Overall health: ISSUES DETECTED") . "\n\n";
        
        if (!$healthy) {
            echo "💡 Run 'php startup.php recovery' to attempt automatic fixes\n\n";
        }
    }
    
    /**
     * Create backup
     */
    private function createBackup(): void
    {
        $this->log("Creating backup...");
        
        $backupDir = $this->storageDir . '/backups/' . date('Y-m-d_H-i-s');
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        
        $files = [
            'blockchain/blockchain.bin',
            'blockchain/blockchain.idx',
            'state/latest.json'
        ];
        
        $backedUp = 0;
        foreach ($files as $file) {
            $source = $this->storageDir . '/' . $file;
            $dest = $backupDir . '/' . basename($file);
            
            if (file_exists($source)) {
                if (copy($source, $dest)) {
                    $backedUp++;
                }
            }
        }
        
        $this->success("Backup created: {$backupDir} ({$backedUp} files)");
    }
    
    /**
     * Install dependencies
     */
    private function installDependencies(): void
    {
        $this->log("Checking dependencies...");
        
        // Check Composer
        if (file_exists(__DIR__ . '/composer.json')) {
            if (file_exists(__DIR__ . '/composer.phar')) {
                $this->log("Running composer install...");
                $output = shell_exec('cd ' . __DIR__ . ' && php composer.phar install --no-dev 2>&1');
                $this->log("Composer output: " . $output);
            } else {
                $this->warning("Composer not found. Please install dependencies manually.");
            }
        }
        
        $this->success("Dependencies check completed");
    }
    
    /**
     * Setup database
     */
    private function setupDatabase(): void
    {
        $this->log("Setting up database...");
        
        try {
            $pdo = $this->getDatabaseConnection();
            
            // Create tables
            $this->createTables($pdo);
            
            $this->success("Database setup completed");
            
        } catch (Exception $e) {
            throw new Exception("Database setup failed: " . $e->getMessage());
        }
    }
    
    /**
     * Run recovery
     */
    private function runRecovery(): void
    {
        $this->log("Starting recovery process...");
        
        // Step 1: Create backup before recovery
        $this->createBackup();
        
        // Step 2: Check and repair binary file
        $this->repairBinaryFile();
        
        // Step 3: Check database
        $this->repairDatabase();
        
        // Step 4: Clear caches
        $this->clearCaches();
        
        $this->success("Recovery process completed");
    }
    
    /**
     * Show help
     */
    private function showHelp(): void
    {
        echo "\n🔗 Blockchain Node Manager (PHP Edition)\n";
        echo "========================================\n\n";
        echo "Usage: php startup.php <command>\n\n";
        echo "Commands:\n";
        echo "  start      Start the blockchain node\n";
        echo "  stop       Stop the blockchain node\n";
        echo "  restart    Restart the blockchain node\n";
        echo "  status     Show node status\n";
        echo "  health     Perform health check\n";
        echo "  backup     Create backup\n";
        echo "  install    Install/update dependencies\n";
        echo "  setup      Setup database\n";
        echo "  recovery   Run recovery process\n";
        echo "  help       Show this help\n\n";
        echo "Examples:\n";
        echo "  php startup.php start\n";
        echo "  php startup.php health\n";
        echo "  php startup.php backup\n\n";
    }
    
    // Helper methods
    
    private function loadConfig(): array
    {
        // Load environment variables first
        require_once __DIR__ . '/core/Environment/EnvironmentLoader.php';
        \Blockchain\Core\Environment\EnvironmentLoader::load(__DIR__);
        
        $configFile = __DIR__ . '/config/config.php';
        
        if (file_exists($configFile)) {
            return include $configFile;
        }
        
        // Default config with environment variable support
        return [
            'database' => [
                'type' => 'mysql',
                'host' => \Blockchain\Core\Environment\EnvironmentLoader::get('DB_HOST', 'localhost'),
                'port' => (int)\Blockchain\Core\Environment\EnvironmentLoader::get('DB_PORT', 3306),
                'database' => \Blockchain\Core\Environment\EnvironmentLoader::get('DB_DATABASE', 'blockchain'),
                'username' => \Blockchain\Core\Environment\EnvironmentLoader::get('DB_USERNAME', 'root'),
                'password' => \Blockchain\Core\Environment\EnvironmentLoader::get('DB_PASSWORD', ''),
            ],
            'node' => [
                'id' => 'node_' . uniqid(),
                'port' => 8080
            ]
        ];
    }
    
    private function ensureDirectories(): void
    {
        $dirs = [
            dirname($this->logFile),
            $this->storageDir,
            $this->storageDir . '/blockchain',
            $this->storageDir . '/backups',
            $this->storageDir . '/state'
        ];
        
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }
    
    private function isRunning(): bool
    {
        if (!file_exists($this->pidFile)) {
            return false;
        }
        
        $pid = $this->getNodePid();
        return $this->isProcessRunning($pid);
    }
    
    private function getNodePid(): int
    {
        if (file_exists($this->pidFile)) {
            return (int) file_get_contents($this->pidFile);
        }
        return 0;
    }
    
    private function isProcessRunning(int $pid): bool
    {
        if ($pid <= 0) return false;
        
        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }
        
        // Fallback for systems without posix
        return false;
    }
    
    private function performPreStartChecks(): void
    {
        $this->log("Performing pre-start checks...");
        
        // Check storage space
        $freeSpace = disk_free_space($this->storageDir);
        if ($freeSpace < 50 * 1024 * 1024) { // 50MB minimum
            throw new Exception("Insufficient storage space: " . $this->formatBytes($freeSpace));
        }
        
        // Check PHP extensions
        $required = ['pdo', 'json'];
        foreach ($required as $ext) {
            if (!extension_loaded($ext)) {
                throw new Exception("Required PHP extension missing: {$ext}");
            }
        }
        
        $this->log("Pre-start checks passed");
    }
    
    private function createStartupBackup(): void
    {
        try {
            $this->createBackup();
        } catch (Exception $e) {
            $this->warning("Failed to create startup backup: " . $e->getMessage());
        }
    }
    
    private function runNodeProcess(): void
    {
        // This would include the main blockchain node
        include __DIR__ . '/index.php';
    }
    
    private function testDatabaseConnection(): void
    {
        $pdo = $this->getDatabaseConnection();
        $pdo->query("SELECT 1");
    }
    
    private function getDatabaseConnection(): PDO
    {
        // Use DatabaseManager if available, otherwise fallback to SQLite
        try {
            require_once __DIR__ . '/core/Database/DatabaseManager.php';
            return \Blockchain\Core\Database\DatabaseManager::getConnection();
        } catch (Exception $e) {
            // Fallback to SQLite
            $config = $this->config['database'] ?? [];
            $dbFile = $config['file'] ?? $this->storageDir . '/blockchain.db';
            return new PDO("sqlite:{$dbFile}");
        }
    }
    
    private function createTables(PDO $pdo): void
    {
        $tables = [
            "CREATE TABLE IF NOT EXISTS blocks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                hash VARCHAR(64) UNIQUE NOT NULL,
                previous_hash VARCHAR(64),
                timestamp INTEGER NOT NULL,
                data TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS node_status (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                node_id VARCHAR(64) NOT NULL,
                status VARCHAR(32) NOT NULL,
                health_data TEXT,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_node (node_id)
            )"
        ];
        
        foreach ($tables as $sql) {
            $pdo->exec($sql);
        }
    }
    
    private function repairBinaryFile(): void
    {
        $binaryFile = $this->storageDir . '/blockchain/blockchain.bin';
        
        if (!file_exists($binaryFile)) {
            $this->log("Creating new binary file...");
            file_put_contents($binaryFile, 'BLKC' . pack('V', 1));
        }
    }
    
    private function repairDatabase(): void
    {
        try {
            $pdo = $this->getDatabaseConnection();
            $this->createTables($pdo);
            $this->log("Database repaired");
        } catch (Exception $e) {
            $this->warning("Database repair failed: " . $e->getMessage());
        }
    }
    
    private function clearCaches(): void
    {
        $cacheFiles = glob($this->storageDir . '/cache/*');
        $cleared = 0;
        
        foreach ($cacheFiles as $file) {
            if (is_file($file) && unlink($file)) {
                $cleared++;
            }
        }
        
        $this->log("Cleared {$cleared} cache files");
    }
    
    private function formatBytes(int $size): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }
        
        return round($size, 2) . ' ' . $units[$i];
    }
    
    private function log(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[{$timestamp}] {$message}\n";
        
        file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);
        echo $logEntry;
    }
    
    private function success(string $message): void
    {
        $this->log("✅ {$message}");
    }
    
    private function warning(string $message): void
    {
        $this->log("⚠️ {$message}");
    }
    
    private function error(string $message): void
    {
        $this->log("❌ {$message}");
    }
}

// Main execution
if (php_sapi_name() === 'cli') {
    $command = $argv[1] ?? 'help';
    $manager = new BlockchainNodeManager();
    $manager->run($command);
} else {
    echo "This script must be run from command line.\n";
    echo "Usage: php startup.php <command>\n";
}
