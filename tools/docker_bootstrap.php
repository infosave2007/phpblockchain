<?php
/**
 * Idempotent bootstrap for a fresh deployment (Docker).
 * - Creates DB schema + runs migrations
 * - Seeds network config
 * - Creates a funded admin wallet
 * - Creates the genesis block (if missing)
 *
 * Usage (inside app container):
 *   php tools/docker_bootstrap.php
 */

declare(strict_types=1);

use Blockchain\Core\Database\DatabaseManager;
use Blockchain\Wallet\WalletManager;
use Blockchain\Core\Blockchain\Blockchain;

require __DIR__ . '/../vendor/autoload.php';

function out(string $m): void { fwrite(STDOUT, $m . "\n"); }

$INITIAL_SUPPLY   = 1000000.0;   // total network supply
$ADMIN_FUNDING    = 500000.0;    // credited to admin wallet
$NETWORK_NAME     = getenv('NETWORK_NAME') ?: 'Local DeFi Chain';
$TOKEN_SYMBOL     = getenv('TOKEN_SYMBOL') ?: 'MBC';
$TOKEN_DECIMALS   = 18;
$CHAIN_ID         = (int)(getenv('CHAIN_ID') ?: 1337);
$MIN_STAKE        = 1000;

try {
    $pdo = DatabaseManager::getConnection();
    out('[1/5] DB connected');

    // --- Schema + migrations ---
    $migration = new \Blockchain\Database\Migration($pdo, __DIR__ . '/../database/migrations');
    $migration->createSchema();
    $migration->migrate();

    // Real ERC20-style token ledger (DEX/swap settlement). Native token stays in wallets.balance.
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS token_balances (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            address VARCHAR(42) NOT NULL,
            token   VARCHAR(64) NOT NULL,
            balance DECIMAL(36,18) NOT NULL DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_address_token (address, token),
            INDEX idx_token (token)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // ERC20 allowances (owner -> spender -> amount) for approve()/transferFrom().
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS token_allowances (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            token   VARCHAR(64) NOT NULL,
            owner   VARCHAR(42) NOT NULL,
            spender VARCHAR(42) NOT NULL,
            amount  DECIMAL(36,18) NOT NULL DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_token_owner_spender (token, owner, spender)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    out('[2/5] Schema created + migrations applied + token ledgers ready');

    // Self-heal: remove duplicate DEX artifacts created by repeated deploy_dex_contracts calls,
    // keeping the earliest (lowest id) of each named system contract.
    $dupRemoved = $pdo->exec(
        "DELETE sc FROM smart_contracts sc
         JOIN (
            SELECT name, MIN(id) AS keep_id FROM smart_contracts
            WHERE name IN ('DEX_Factory','DEX_Router','WETH') GROUP BY name
         ) k ON sc.name = k.name AND sc.id > k.keep_id"
    );
    if ($dupRemoved) { out('     Removed ' . $dupRemoved . ' duplicate DEX contract rows'); }

    // --- Config seeding (idempotent) ---
    $configRows = [
        'network.name'           => $NETWORK_NAME,
        'network.token_symbol'   => $TOKEN_SYMBOL,
        'network.token_name'     => $TOKEN_SYMBOL . ' Token',
        'network.decimals'       => (string)$TOKEN_DECIMALS,
        'network.chain_id'       => (string)$CHAIN_ID,
        'network.initial_supply' => (string)$INITIAL_SUPPLY,
        'network.total_supply'   => (string)$INITIAL_SUPPLY,
        'network.min_stake'      => (string)$MIN_STAKE,
        'consensus.algorithm'    => 'pos',
    ];
    $stmt = $pdo->prepare(
        "INSERT INTO config (key_name, value, is_system) VALUES (?, ?, 1)
         ON DUPLICATE KEY UPDATE value = VALUES(value)"
    );
    foreach ($configRows as $k => $v) {
        $stmt->execute([$k, $v]);
    }
    out('[3/5] Config seeded (' . count($configRows) . ' keys)');

    // --- Admin wallet ---
    $wm = new WalletManager($pdo);
    // reuse existing admin if present
    $existing = $pdo->query("SELECT address FROM wallets ORDER BY balance DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($existing && (float)($pdo->query("SELECT balance FROM wallets WHERE address='".$existing['address']."'")->fetchColumn()) > 0) {
        $adminAddress = $existing['address'];
        out('[4/5] Admin wallet already exists: ' . $adminAddress);
        $adminMnemonic = '(existing — not shown)';
    } else {
        $wallet = $wm->createWallet(null, true);
        $adminAddress = strtolower($wallet['address']);
        $adminMnemonic = is_array($wallet['mnemonic']) ? implode(' ', $wallet['mnemonic']) : (string)$wallet['mnemonic'];
        // fund cached balance
        $pdo->prepare("UPDATE wallets SET balance = ? WHERE address = ?")
            ->execute([$ADMIN_FUNDING, $adminAddress]);
        out('[4/5] Admin wallet created + funded: ' . $adminAddress);
    }

    // Seed admin with a test ERC20 token balance (WETH) so DEX token-side flows are usable.
    $pdo->prepare(
        "INSERT INTO token_balances (address, token, balance) VALUES (?, 'weth_contract', 100000)
         ON DUPLICATE KEY UPDATE balance = GREATEST(balance, 100000)"
    )->execute([$adminAddress]);
    out('     Admin seeded with 100000 WETH (token_balances)');

    // --- Genesis block (if missing) ---
    $hasGenesis = (int)$pdo->query("SELECT COUNT(*) FROM blocks WHERE height = 0")->fetchColumn() > 0;
    if ($hasGenesis) {
        out('[5/5] Genesis block already present — skipped');
    } else {
        $genesisConfig = [
            'initial_supply'       => $INITIAL_SUPPLY,
            'network_name'         => $NETWORK_NAME,
            'token_symbol'         => $TOKEN_SYMBOL,
            'consensus_algorithm'  => 'pos',
            'wallet_address'       => $adminAddress,
            'primary_wallet_amount'=> $ADMIN_FUNDING,
            'staking_amount'       => $MIN_STAKE,
            'min_stake_amount'     => $MIN_STAKE,
            'node_domain'          => 'localhost',
            'protocol'             => 'http',
        ];
        $block = Blockchain::createGenesisWithDatabase($pdo, $genesisConfig);
        out('[5/5] Genesis block created: ' . $block->getHash());
    }

    // --- Mirror the chain to the on-disk binary ledger (independent durable copy) ---
    try {
        $dataDir = dirname(__DIR__) . '/storage/blockchain';
        if (!is_dir($dataDir)) { @mkdir($dataDir, 0775, true); }
        $bin = new \Blockchain\Core\Storage\BlockchainBinaryStorage($dataDir, [], false);
        $res = $bin->importFromDatabase($pdo);
        unset($bin); // release file handles before chown
        // Bootstrap runs as root (docker exec), but php-fpm serves as www-data — make the
        // binary ledger files writable by the runtime so the live mirror can append to them.
        foreach (glob($dataDir . '/*') as $bf) {
            @chown($bf, 'www-data'); @chgrp($bf, 'www-data'); @chmod($bf, 0664);
        }
        @chown($dataDir, 'www-data'); @chmod($dataDir, 0775);
        out('     Binary ledger synced from DB: imported=' . $res['imported'] . ' skipped=' . $res['skipped'] . ' errors=' . $res['errors'] . ' (files chowned to www-data)');
    } catch (Throwable $e) {
        out('     Binary ledger sync skipped: ' . $e->getMessage());
    }

    out('');
    out('================ BOOTSTRAP COMPLETE ================');
    out('Admin address : ' . $adminAddress);
    out('Admin mnemonic: ' . $adminMnemonic);
    out('Admin balance : ' . number_format($ADMIN_FUNDING, 2) . ' ' . $TOKEN_SYMBOL);
    out('===================================================');
    exit(0);
} catch (Throwable $e) {
    out('BOOTSTRAP FAILED: ' . $e->getMessage());
    out($e->getFile() . ':' . $e->getLine());
    exit(1);
}
