<?php
/**
 * Universal Staking Rewards Cron Script
 * Автоматически начисляет награды всем активным стейкингам
 * 
 * Usage: php update_staking_rewards.php [--force] [--quiet]
 * Cron example: 0 0,12 * * * /usr/bin/php /path/to/update_staking_rewards.php --quiet
 */

// Parse command line arguments
$options = getopt('', ['force', 'quiet', 'help']);
$isQuiet = isset($options['quiet']);
$isForced = isset($options['force']);

if (isset($options['help'])) {
    echo "Universal Staking Rewards Cron Script\n";
    echo "Usage: php update_staking_rewards.php [OPTIONS]\n";
    echo "  --force   Force execution even if lock exists\n";
    echo "  --quiet   Minimal output for cron execution\n";
    echo "  --help    Show this help message\n";
    exit(0);
}

// Lock file to prevent multiple instances
$lockFile = __DIR__ . '/logs/staking_rewards.lock';
$logFile = __DIR__ . '/logs/staking_rewards.log';

// Ensure logs directory exists
if (!is_dir(__DIR__ . '/logs')) {
    mkdir(__DIR__ . '/logs', 0755, true);
}

// Check for existing lock
if (file_exists($lockFile) && !$isForced) {
    $lockTime = filemtime($lockFile);
    $lockAge = time() - $lockTime;
    
    // If lock is older than 2 hours, consider it stale
    if ($lockAge > 7200) {
        unlink($lockFile);
        logMessage("Removed stale lock file (age: {$lockAge}s)", $isQuiet);
    } else {
        logMessage("Script already running (lock age: {$lockAge}s)", $isQuiet);
        exit(1);
    }
}

// Create lock file
file_put_contents($lockFile, getmypid() . "\n" . date('Y-m-d H:i:s'));

// Register cleanup function
register_shutdown_function(function() use ($lockFile) {
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
});

/**
 * Log message to both console and log file
 */
function logMessage($message, $isQuiet = false, $logFileOverride = null) {
    static $globalLogFile = null;
    if ($globalLogFile === null) {
        $globalLogFile = __DIR__ . '/logs/staking_rewards.log';
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] $message\n";
    
    // Write to log file
    $actualLogFile = $logFileOverride ?: $globalLogFile;
    if ($actualLogFile) {
        file_put_contents($actualLogFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
    
    // Write to console unless quiet mode
    if (!$isQuiet) {
        echo $logEntry;
    }
}

// Load environment and configuration
require_once __DIR__ . '/vendor/autoload.php';

// Determine project base directory  
$baseDir = __DIR__;

// Load environment variables
require_once $baseDir . '/core/Environment/EnvironmentLoader.php';
\Blockchain\Core\Environment\EnvironmentLoader::load($baseDir);

// Load config
$configFile = $baseDir . '/config/config.php';
$config = [];
if (file_exists($configFile)) {
    $config = require $configFile;
}

// Use DatabaseManager for standardized connection
require_once $baseDir . '/core/Database/DatabaseManager.php';

try {
    logMessage("🏆 Starting staking rewards update process", $isQuiet);
    
    // Get database connection using project's standard DatabaseManager
    $pdo = \Blockchain\Core\Database\DatabaseManager::getConnection();
    
    if (!$pdo) {
        throw new Exception("Failed to establish database connection");
    }
    
    // Get current block height
    $stmt = $pdo->query("SELECT MAX(height) as current_block FROM blocks");
    $currentBlock = $stmt->fetchColumn() ?: 1743; // Fallback to initial block
    
    logMessage("📦 Current block: $currentBlock", $isQuiet);
    
    // Get all active stakings
    $stmt = $pdo->prepare("
        SELECT 
            id, validator, staker, amount, reward_rate, 
            start_block, last_reward_block, rewards_earned,
            TIMESTAMPDIFF(SECOND, created_at, NOW()) as seconds_elapsed,
            created_at
        FROM staking 
        WHERE status = 'active'
        ORDER BY created_at ASC
    ");
    $stmt->execute();
    $stakings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stakingCount = count($stakings);
    logMessage("💰 Found active stakings: $stakingCount", $isQuiet);
    
    if ($stakingCount === 0) {
        logMessage("✅ No active stakings to process", $isQuiet);
        exit(0);
    }
    
    $totalRewardsAdded = 0;
    
    foreach ($stakings as $staking) {
        $stakingId = $staking['id'];
        $address = $staking['staker'];
        $amount = (float)$staking['amount'];
        $rewardRate = (float)$staking['reward_rate'];
        $startBlock = (int)$staking['start_block'];
        $lastRewardBlock = (int)$staking['last_reward_block'] ?: $startBlock;
        $currentRewards = (float)$staking['rewards_earned'];
        $secondsElapsed = (int)$staking['seconds_elapsed'];
        
        // Рассчитываем блоки, прошедшие с последнего начисления
        $blocksElapsed = max(0, $currentBlock - $lastRewardBlock);
        
        if ($blocksElapsed > 0) {
            // Формула начисления: (amount * reward_rate * blocks_elapsed) / blocks_in_year
            $blocksInYear = 315360; // 10-секундные блоки: (365 * 24 * 60 * 60) / 10
            $newRewards = ($amount * $rewardRate * $blocksElapsed) / $blocksInYear;
            $totalNewRewards = $currentRewards + $newRewards;
            
            // Обновляем запись стейкинга
            $updateStmt = $pdo->prepare("
                UPDATE staking 
                SET 
                    rewards_earned = ?,
                    last_reward_block = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $updateStmt->execute([$totalNewRewards, $currentBlock, $stakingId]);
            
            $totalRewardsAdded += $newRewards;
            
            $message = sprintf(
                "✅ ID %d (%s): +%.8f tokens (blocks: %d, total rewards: %.8f)",
                $stakingId,
                substr($address, 0, 10) . '...',
                $newRewards,
                $blocksElapsed,
                $totalNewRewards
            );
            logMessage($message, $isQuiet);
            
        } else {
            if (!$isQuiet) {
                $message = sprintf(
                    "⏳ ID %d (%s): rewards up to date (block %d)",
                    $stakingId,
                    substr($address, 0, 10) . '...',
                    $lastRewardBlock
                );
                logMessage($message, $isQuiet);
            }
        }
    }
    
    logMessage("🏆 Rewards update completed!", $isQuiet);
    logMessage("💎 Total rewards distributed: " . number_format($totalRewardsAdded, 8) . " tokens", $isQuiet);
    
    // Performance metrics for monitoring
    $executionTime = microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
    logMessage("⚡ Execution time: " . number_format($executionTime, 3) . "s", $isQuiet);
    logMessage("📊 Processed: $stakingCount stakings", $isQuiet);
    
    // Summary statistics for monitoring
    if (!$isQuiet && $stakingCount > 0) {
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_active,
                SUM(amount) as total_staked,
                SUM(rewards_earned) as total_rewards,
                AVG(reward_rate) as avg_rate
            FROM staking 
            WHERE status = 'active'
        ");
        $stmt->execute();
        $summary = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($summary && $summary['total_active'] > 0) {
            logMessage("", $isQuiet);
            logMessage("📈 STAKING NETWORK SUMMARY:", $isQuiet);
            logMessage("� Total staked: " . number_format($summary['total_staked'], 2) . " tokens", $isQuiet);
            logMessage("🏆 Total rewards earned: " . number_format($summary['total_rewards'], 8) . " tokens", $isQuiet);
            logMessage("� Average APY: " . number_format($summary['avg_rate'] * 100, 2) . "%", $isQuiet);
            logMessage("👥 Active stakers: " . $summary['total_active'], $isQuiet);
        }
    }
    
} catch (Exception $e) {
    $errorMessage = "❌ Error: " . $e->getMessage();
    logMessage($errorMessage, false); // Always log errors even in quiet mode
    logMessage("Stack trace: " . $e->getTraceAsString(), false);
    exit(1);
} catch (Error $e) {
    $errorMessage = "💥 Fatal error: " . $e->getMessage();
    logMessage($errorMessage, false); // Always log fatal errors
    logMessage("Stack trace: " . $e->getTraceAsString(), false);
    exit(2);
}

// Success exit
logMessage("✅ Staking rewards update completed successfully", $isQuiet);
exit(0);
?>