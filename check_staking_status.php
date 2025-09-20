<?php
require_once '/var/www/html/config/config.php';

// Подключение к базе данных
$pdo = new PDO('mysql:host=database;dbname=blockchain', 'blockchain', 'blockchain123');

echo "🔍 Checking staking transaction status...\n\n";

// Проверяем mempool
$stmt = $pdo->query('SELECT COUNT(*) as count FROM transactions WHERE status = "pending"');
$mempool = $stmt->fetch();
echo "📊 Mempool transactions: " . $mempool['count'] . "\n";

// Проверяем последний блок
$stmt = $pdo->query('SELECT height, hash FROM blocks ORDER BY height DESC LIMIT 1');
$lastBlock = $stmt->fetch();
echo "📦 Latest block: #" . $lastBlock['height'] . "\n";

// Проверяем стейкинг транзакцию
$txHash = '0x51667dd6418d96ddec5010fe1ab413a0c82a0e941c21df5ed793c1ccd5de6fbd';
$stmt = $pdo->prepare('SELECT * FROM transactions WHERE hash = ?');
$stmt->execute([$txHash]);
$tx = $stmt->fetch();

if ($tx) {
    echo "💰 Staking transaction found:\n";
    echo "   Status: " . $tx['status'] . "\n";
    echo "   Block hash: " . $tx['block_hash'] . "\n";
    echo "   Amount: " . $tx['amount'] . "\n";
    echo "   From: " . $tx['from_address'] . "\n";
    echo "   To: " . $tx['to_address'] . "\n";
} else {
    echo "❌ Staking transaction not found!\n";
}

// Проверяем активные стейки пользователя
$userAddress = '0x74250ff08e6a4bcc09611f9576013a740f7beb0d';
$stmt = $pdo->prepare('SELECT * FROM staking WHERE staker = ? ORDER BY created_at DESC LIMIT 3');
$stmt->execute([$userAddress]);
$stakes = $stmt->fetchAll();

echo "\n🏦 User's active stakes:\n";
foreach ($stakes as $stake) {
    echo "   Stake ID: " . $stake['id'] . "\n";
    echo "   Amount: " . $stake['amount'] . "\n";
    echo "   Reward Rate: " . ($stake['reward_rate'] * 100) . "%\n";
    echo "   Start Block: " . $stake['start_block'] . "\n";
    echo "   End Block: " . ($stake['end_block'] ?? 'N/A') . "\n";
    echo "   Status: " . $stake['status'] . "\n";
    echo "   Rewards Earned: " . $stake['rewards_earned'] . "\n";
    echo "   Created: " . $stake['created_at'] . "\n";
    echo "   ---\n";
}
?>