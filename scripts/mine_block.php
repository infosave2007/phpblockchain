<?php
/**
 * Block Miner Script
 * 
 * Packs mempool transactions into a new block and saves via BlockStorage.
 * Supports both local and distributed blockchain networks.
 * 
 * Usage: php scripts/mine_block.php [--max=100] [--dry] [--verbose]
 * 
 * Options:
 *   --max=N     Maximum number of transactions per block (default: 100)
 *   --dry       Dry run mode - prepare block but don't save
 *   --verbose   Enable verbose output
 * 
 * Environment:
 *   This script reads database configuration from config/config.php
 *   and supports .env file overrides via bootstrap_env.php
 */

use Blockchain\Core\Blockchain\Block;
use Blockchain\Core\Transaction\MempoolManager;
use Blockchain\Core\Storage\BlockStorage;
use Blockchain\Core\Consensus\ValidatorManager;
use Blockchain\Core\Consensus\ProofOfStake;
use Blockchain\Core\Transaction\Transaction;

require_once __DIR__ . '/../vendor/autoload.php';

// Determine project base directory
$baseDir = dirname(__DIR__);

// Load environment variables
require_once $baseDir . '/core/Environment/EnvironmentLoader.php';
\Blockchain\Core\Environment\EnvironmentLoader::load($baseDir);

// Load config
$configFile = $baseDir . '/config/config.php';
$config = [];
if (file_exists($configFile)) {
    $config = require $configFile;
}

$opts = [
    'max' => 100,
    'dry' => false,
    'verbose' => false,
];
foreach ($argv as $arg) {
    if (preg_match('/^--max=(\d+)$/', $arg, $m)) $opts['max'] = (int)$m[1];
    elseif ($arg === '--dry') $opts['dry'] = true;
    elseif ($arg === '--verbose') $opts['verbose'] = true;
}

function out($msg, $force = false) {
    global $opts; if ($opts['verbose'] || $force) echo $msg . "\n"; }

try {
    // Build database config with priority: config.php -> .env -> defaults
    $dbConfig = $config['database'] ?? [];
    
    // If empty, fallback to environment variables
    if (empty($dbConfig) || !isset($dbConfig['host'])) {
        $dbConfig = [
            'host' => \Blockchain\Core\Environment\EnvironmentLoader::get('DB_HOST', 'localhost'),
            'port' => (int)\Blockchain\Core\Environment\EnvironmentLoader::get('DB_PORT', 3306),
            'database' => \Blockchain\Core\Environment\EnvironmentLoader::get('DB_DATABASE', 'blockchain'),
            'username' => \Blockchain\Core\Environment\EnvironmentLoader::get('DB_USERNAME', 'root'),
            'password' => \Blockchain\Core\Environment\EnvironmentLoader::get('DB_PASSWORD', ''),
            'charset' => 'utf8mb4'
        ];
    }
    
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', 
        $dbConfig['host'], 
        $dbConfig['database']
    );
    $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    $mempool = new MempoolManager($pdo, ['min_fee' => 0.001]);

    // Determine previous block index/hash from DB blocks table
    $prevHash = 'GENESIS';
    $height = 0;
    $stmt = $pdo->query("SELECT hash,height FROM blocks ORDER BY height DESC LIMIT 1");
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $prevHash = $row['hash'];
        $height = (int)$row['height'] + 1;
    }

    $txs = $mempool->getTransactionsForBlock($opts['max']);
    if (empty($txs)) {
        out('No transactions in mempool, nothing to mine', true);
        exit(0);
    }

    // Get original hashes from mempool for cleanup
    $stmt = $pdo->prepare("
        SELECT tx_hash FROM mempool 
        ORDER BY priority_score DESC, created_at ASC 
        LIMIT :max_count
    ");
    $stmt->bindParam(':max_count', $opts['max'], PDO::PARAM_INT);
    $stmt->execute();
    $originalHashes = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $originalHashes[] = $row['tx_hash'];
    }

    out('Preparing block height ' . $height . ' with ' . count($txs) . ' tx(s)', true);

    // Instantiate PoS + validator manager for signature (best-effort)
    $validatorManager = new ValidatorManager($pdo, $config);
    
    // Use NullLogger for PSR-3 compatibility
    require_once __DIR__ . '/../core/Logging/NullLogger.php';
    $logger = new \Blockchain\Core\Logging\NullLogger();
    
    $pos = new ProofOfStake($logger);
    $pos->setValidatorManager($validatorManager);

    $block = new Block($height, $txs, $prevHash, [] , []);

    // Sign block via ValidatorManager
    try { $pos->signBlock($block); } catch (Throwable $e) { out('Block signing failed: '.$e->getMessage(), true); }

    if ($opts['dry']) {
        out('[DRY] Block assembled hash='.$block->getHash().' merkle='.$block->getMerkleRoot());
        exit(0);
    }

    $storage = new BlockStorage(__DIR__ . '/../storage/blockchain_runtime.json', $pdo, $validatorManager);
    $ok = $storage->saveBlock($block);
    if (!$ok) {
        out('Failed to persist block', true);
        exit(1);
    }

    // Remove mined tx from mempool using original hashes
    $removed = 0;
    $del = $pdo->prepare('DELETE FROM mempool WHERE tx_hash = ? OR tx_hash = ?');
    foreach ($originalHashes as $originalHash) {
        $dh = strtolower(trim((string)$originalHash));
        $dh0 = str_starts_with($dh,'0x') ? $dh : ('0x'.$dh);
        $dh1 = str_starts_with($dh,'0x') ? substr($dh,2) : $dh;
        $del->execute([$dh0, $dh1]);
        $removed += $del->rowCount();
    }

    out('Block mined: height='.$height.' hash='.$block->getHash().' txs='.count($txs).' removed='.$removed, true);
    exit(0);

} catch (Throwable $e) {
    out('Error: '.$e->getMessage(), true);
    exit(1);
}
