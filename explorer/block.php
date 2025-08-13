<?php
// Generic Block details page
require_once __DIR__ . '/config_helper.php';
use PDO; 

$net = getNetworkConfig();
$name = htmlspecialchars($net['name'] ?? 'Blockchain');

$pdo = getDbConnection();
$blockIdentifier = $blockIdentifier ?? ($_GET['block'] ?? '');

// Determine if numeric height or hash
$isHeight = ctype_digit((string)$blockIdentifier);
$block = null;
if ($isHeight) {
    $stmt = $pdo->prepare('SELECT * FROM blocks WHERE height = ? LIMIT 1');
    $stmt->execute([$blockIdentifier]);
    $block = $stmt->fetch();
} else {
    $stmt = $pdo->prepare('SELECT * FROM blocks WHERE hash = ? LIMIT 1');
    $stmt->execute([$blockIdentifier]);
    $block = $stmt->fetch();
}
if (!$block) {
    http_response_code(404);
    echo '<h1>Block not found</h1><a href="/explorer/">Back</a>';
    exit;
}
$stmt = $pdo->prepare('SELECT * FROM transactions WHERE block_height = ? ORDER BY timestamp ASC');
$stmt->execute([$block['height']]);
$transactions = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Block #<?= htmlspecialchars($block['height']) ?> - <?= $name ?> Explorer</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="/explorer/explorer.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center" href="/explorer/">
                <img src="/assets/network-icon.svg" alt="Logo" style="height:24px" class="me-2"> <?= $name ?> Explorer
            </a>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="row">
            <div class="col-12">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="/explorer/">Explorer</a></li>
                        <li class="breadcrumb-item active">Block #<?= htmlspecialchars($block['height']) ?></li>
                    </ol>
                </nav>

                <div class="card">
                    <div class="card-header">
                        <h4 class="mb-0">
                            <i class="fas fa-cube"></i> Block #<?= htmlspecialchars($block['height']) ?>
                            <span class="badge bg-success ms-2">Confirmed</span>
                        </h4>
                    </div>
                    <div class="card-body">
                        <!-- Block Hash -->
                        <div class="row mb-3">
                            <div class="col-md-3"><strong>Block Hash:</strong></div>
                            <div class="col-md-9">
                                <div class="tx-hash"><?= htmlspecialchars($block['hash']) ?></div>
                            </div>
                        </div>

                        <!-- Previous Hash -->
                        <div class="row mb-3">
                            <div class="col-md-3"><strong>Previous Hash:</strong></div>
                            <div class="col-md-9">
                                <div class="tx-hash">
                                    <?php if ($block['previous_hash'] !== 'GENESIS'): ?>
                                        <a href="/explorer/block/<?= htmlspecialchars($block['previous_hash']) ?>">
                                            <?= htmlspecialchars($block['previous_hash']) ?>
                                        </a>
                                    <?php else: ?>
                                        <?= htmlspecialchars($block['previous_hash']) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Timestamp -->
                        <div class="row mb-3">
                            <div class="col-md-3"><strong>Timestamp:</strong></div>
                            <div class="col-md-9">
                                <?= date('Y-m-d H:i:s', $block['timestamp']) ?> UTC
                                <small class="text-muted">(<?= time() - $block['timestamp'] ?> seconds ago)</small>
                            </div>
                        </div>

                        <!-- Merkle Root -->
                        <div class="row mb-3">
                            <div class="col-md-3"><strong>Merkle Root:</strong></div>
                            <div class="col-md-9">
                                <div class="tx-hash"><?= htmlspecialchars($block['merkle_root']) ?></div>
                            </div>
                        </div>

                        <!-- Transaction Count -->
                        <div class="row mb-3">
                            <div class="col-md-3"><strong>Transactions:</strong></div>
                            <div class="col-md-9">
                                <span class="badge bg-info"><?= count($transactions) ?> transactions</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Transactions List -->
                <?php if (!empty($transactions)): ?>
                    <div class="card mt-4">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-list"></i> Transactions in this Block
                            </h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-striped mb-0">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Hash</th>
                                            <th>From</th>
                                            <th>To</th>
                                            <th>Amount</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($transactions as $tx): ?>
                                            <tr>
                                                <td>
                                                    <a href="/explorer/tx/<?= htmlspecialchars($tx['hash']) ?>" 
                                                       class="text-truncate d-inline-block" style="max-width: 150px;">
                                                        <?= htmlspecialchars($tx['hash']) ?>
                                                    </a>
                                                </td>
                                                <td>
                                                    <a href="/explorer/address/<?= htmlspecialchars($tx['from_address']) ?>" 
                                                       class="text-truncate d-inline-block" style="max-width: 100px;">
                                                        <?= htmlspecialchars($tx['from_address']) ?>
                                                    </a>
                                                </td>
                                                <td>
                                                    <a href="/explorer/address/<?= htmlspecialchars($tx['to_address']) ?>" 
                                                       class="text-truncate d-inline-block" style="max-width: 100px;">
                                                        <?= htmlspecialchars($tx['to_address']) ?>
                                                    </a>
                                                </td>
                                                <td><?= number_format($tx['amount'], 8) ?></td>
                                                <td>
                                                    <span class="badge bg-success">
                                                        <?= htmlspecialchars($tx['status']) ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<style>
.tx-hash {
    font-family: 'Courier New', monospace;
    word-break: break-all;
    background: #f8f9fa;
    padding: 10px;
    border-radius: 5px;
    border: 1px solid #dee2e6;
}
</style>
