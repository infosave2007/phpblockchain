<?php
/**
 * Staking invariants regression test (E1).
 *
 * Verifies the core money-safety properties that previous "phantom staking" /
 * "double withdrawal" bugs violated:
 *   1. Staking moves funds available -> staked, conserving the total.
 *   2. You cannot stake more than your available balance.
 *   3. You cannot unstake more than you have staked / before the lock expires.
 *
 * Run inside the app container:
 *   docker compose exec blockchain php tests/StakingInvariantsTest.php
 * Optional: API_BASE env (default http://nginx).
 */

declare(strict_types=1);

use Blockchain\Core\Database\DatabaseManager;
use Blockchain\Wallet\WalletManager;

require __DIR__ . '/../vendor/autoload.php';

$API = rtrim(getenv('API_BASE') ?: 'http://nginx', '/') . '/wallet/wallet_api.php';
$pass = 0; $fail = 0;

function api(string $url, array $payload): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    return json_decode((string)$body, true) ?: ['_raw' => $body];
}
function check(string $name, bool $ok): void {
    global $pass, $fail;
    echo ($ok ? "  PASS  " : "  FAIL  ") . $name . "\n";
    $ok ? $pass++ : $fail++;
}

$pdo = DatabaseManager::getConnection();
$wm  = new WalletManager($pdo);

// Fresh funded wallet
$w = $wm->createWallet(null, true);
$addr = strtolower($w['address']);
$pk   = $w['private_key'];
$pdo->prepare("UPDATE wallets SET balance = 10000 WHERE address = ?")->execute([$addr]);

echo "Test wallet: $addr (funded 10000)\n";
echo "API: $API\n\n";

$bal = fn() => api($API, ['action' => 'get_balance', 'address' => $addr])['balance'] ?? [];

$b0 = $bal();
echo "Initial: available={$b0['available']} staked={$b0['staked']} total={$b0['total']}\n";

// 1) Stake 3000
api($API, ['action' => 'stake_tokens', 'address' => $addr, 'amount' => 3000, 'period' => 30, 'private_key' => $pk]);
usleep(300000);
$b1 = $bal();
echo "After stake 3000: available={$b1['available']} staked={$b1['staked']} total={$b1['total']}\n";
check('stake moves 3000 available->staked', abs(($b0['available'] - $b1['available']) - 3000) < 1e-6 && abs($b1['staked'] - $b0['staked'] - 3000) < 1e-6);
check('total conserved across stake', abs($b1['total'] - $b0['total']) < 1e-6);

// 2) Over-stake: try to stake more than available
$avail = $b1['available'];
$r = api($API, ['action' => 'stake_tokens', 'address' => $addr, 'amount' => $avail + 100000, 'period' => 30, 'private_key' => $pk]);
usleep(300000);
$b2 = $bal();
check('over-stake rejected (staked unchanged)', abs($b2['staked'] - $b1['staked']) < 1e-6);

// 3) Over-unstake: try to unstake more than staked
$r = api($API, ['action' => 'unstake_tokens', 'address' => $addr, 'amount' => $b2['staked'] + 100000, 'private_key' => $pk]);
usleep(300000);
$b3 = $bal();
check('over-unstake does not increase available beyond staked', $b3['available'] <= $b1['available'] + 1e-6);
check('staked never goes negative', $b3['staked'] >= -1e-6);

// 4) Fixed-term rules: only allowed periods, flat 12% APY
$rBad = api($API, ['action' => 'stake_tokens', 'address' => $addr, 'amount' => 100, 'period' => 45, 'private_key' => $pk]);
check('disallowed period (45d) rejected', ($rBad['success'] ?? false) === false && stripos(json_encode($rBad), 'Invalid staking period') !== false);

$bBefore = $bal();
$rYear = api($API, ['action' => 'stake_tokens', 'address' => $addr, 'amount' => 1000, 'period' => 365, 'private_key' => $pk]);
$apy = $rYear['staked']['apy'] ?? $rYear['apy'] ?? null;
check('1-year stake accepted at 12% APY', ($rYear['success'] ?? false) === true && abs(((float)$apy) - 12.0) < 1e-6);

// 5) Fixed-term lock: a freshly staked term cannot be unstaked immediately
$rEarly = api($API, ['action' => 'unstake_tokens', 'address' => $addr, 'amount' => 500, 'private_key' => $pk]);
usleep(300000);
$bAfter = $bal();
check('fixed-term lock blocks early unstake', ($rEarly['success'] ?? false) === false && abs($bAfter['available'] - $bBefore['available'] + 1000) < 1e-6);

echo "\nResult: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
