<?php
// Transaction Details Page (generic)
require_once __DIR__ . '/config_helper.php';
use PDO; 

$net = getNetworkConfig();
$cryptoName = htmlspecialchars($net['name'] ?? 'Blockchain');
$cryptoSymbol = htmlspecialchars($net['token_symbol'] ?? 'COIN');

// Database connection
// Reuse shared DB helper
$pdo = getDbConnection();

// Get hash from URL
$urlPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$pathParts = explode('/', trim($urlPath, '/'));

// Extract hash from URL path (e.g., /explorer/tx/0x...)
$hash = null;
if (count($pathParts) >= 3 && $pathParts[1] === 'tx') {
    $hash = $pathParts[2];
} elseif (isset($_GET['hash'])) {
    $hash = $_GET['hash'];
}

if (!$hash) {
    http_response_code(400);
    die('Transaction hash is required');
}

// Normalize hash
$hash = strtolower(trim($hash));
if (!str_starts_with($hash, '0x')) {
    $hash = '0x' . $hash;
}

// Search for transaction in both confirmed and mempool
$transaction = null;
$status = 'unknown';

// First check confirmed transactions
$stmt = $pdo->prepare("
    SELECT 
        t.*,
        b.height as block_height,
        b.hash as block_hash,
        b.timestamp as block_timestamp
    FROM transactions t 
    LEFT JOIN blocks b ON t.block_hash = b.hash 
    WHERE t.hash = ?
");
$stmt->execute([$hash]);
$transaction = $stmt->fetch();

if ($transaction) {
    $status = $transaction['status'] ?? 'confirmed';
} else {
    // Check mempool
    $stmt = $pdo->prepare("
        SELECT 
            tx_hash as hash,
            from_address,
            to_address,
            amount,
            fee,
            gas_limit,
            gas_price,
            nonce,
            data,
            signature,
            created_at as timestamp,
            status,
            priority_score,
            'pending' as block_status
        FROM mempool 
        WHERE tx_hash = ?
    ");
    $stmt->execute([$hash]);
    $transaction = $stmt->fetch();
    
    if ($transaction) {
        $status = 'pending';
        $transaction['block_height'] = null;
        $transaction['block_hash'] = null;
        $transaction['block_timestamp'] = null;
    }
}

if (!$transaction) {
    http_response_code(404);
    $error = 'Transaction not found';
} else {
    // Format data for display
    $transaction['amount_formatted'] = number_format((float)$transaction['amount'], 8, '.', '');
    $transaction['fee_formatted'] = number_format((float)($transaction['fee'] ?? 0), 8, '.', '');
    $transaction['gas_price_formatted'] = number_format((float)($transaction['gas_price'] ?? 0), 8, '.', '');
    $transaction['timestamp_formatted'] = $transaction['timestamp'] ? date('Y-m-d H:i:s', (int)$transaction['timestamp']) : 'N/A';
    $transaction['block_timestamp_formatted'] = $transaction['block_timestamp'] ? date('Y-m-d H:i:s', (int)$transaction['block_timestamp']) : 'N/A';
    
    // Parse transaction data if JSON
    $transactionData = null;
    if (!empty($transaction['data'])) {
        $decoded = json_decode($transaction['data'], true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $transactionData = $decoded;
        }
    }
}

// Check if this is an API request
if (isset($_GET['format']) && $_GET['format'] === 'json') {
    header('Content-Type: application/json');
    if (isset($error)) {
        echo json_encode(['error' => $error]);
    } else {
        echo json_encode([
            'transaction' => $transaction,
            'status' => $status,
            'data' => $transactionData
        ]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($cryptoName) ?> - Transaction <?= htmlspecialchars(substr($hash, 0, 10)) ?>...</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="/explorer/explorer.css" rel="stylesheet">
    <style>
        .tx-hash {
            font-family: 'Courier New', monospace;
            word-break: break-all;
            background: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
            border: 1px solid #dee2e6;
        }
        .status-badge {
            font-size: 0.9em;
            padding: 0.5rem 1rem;
        }
        .status-confirmed { background-color: #28a745; }
        .status-pending { background-color: #ffc107; }
        .status-failed { background-color: #dc3545; }
        .data-section {
            max-height: 300px;
            overflow-y: auto;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 15px;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
                    <div class="container">
                                                <a class="navbar-brand d-flex align-items-center" href="/explorer/">
                            <img src="/assets/network-icon.svg" alt="Logo" style="height:28px" class="me-2"> <?= htmlspecialchars($cryptoName) ?> Explorer
                        </a>
                        <div class="navbar-nav ms-auto">
                                                        <a class="nav-link" href="/explorer/">
                                🏠 Home
                            </a>
                        </div>
                    </div>
                </nav>
            </div>
        </div>

        <div class="container my-4">
            <?php if (isset($error)): ?>
                <div class="alert alert-danger" role="alert">
                    <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
                </div>
                <div class="text-center">
                    <a href="/explorer/" class="btn btn-primary">
                        <i class="fas fa-arrow-left"></i> Back to Explorer
                    </a>
                </div>
            <?php else: ?>
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h4 class="card-title mb-0">
                                    <i class="fas fa-exchange-alt"></i> Transaction Details
                                    <span class="badge status-badge status-<?= $status ?> ms-2">
                                        <?= ucfirst($status) ?>
                                    </span>
                                </h4>
                            </div>
                            <div class="card-body">
                                <!-- Transaction Hash -->
                                <div class="row mb-3">
                                    <div class="col-md-3">
                                        <strong>Transaction Hash:</strong>
                                    </div>
                                    <div class="col-md-9">
                                        <div class="tx-hash">
                                            <?= htmlspecialchars($transaction['hash']) ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- Block Information -->
                                <?php if ($transaction['block_height'] !== null): ?>
                                <div class="row mb-3">
                                    <div class="col-md-3">
                                        <strong>Block Height:</strong>
                                    </div>
                                    <div class="col-md-9">
                                        <a href="/explorer/block/<?= $transaction['block_height'] ?>" class="btn btn-sm btn-outline-primary">
                                            #<?= number_format($transaction['block_height']) ?>
                                        </a>
                                    </div>
                                </div>

                                <div class="row mb-3">
                                    <div class="col-md-3">
                                        <strong>Block Hash:</strong>
                                    </div>
                                    <div class="col-md-9">
                                        <code><?= htmlspecialchars($transaction['block_hash']) ?></code>
                                    </div>
                                </div>

                                <div class="row mb-3">
                                    <div class="col-md-3">
                                        <strong>Block Timestamp:</strong>
                                    </div>
                                    <div class="col-md-9">
                                        <?= htmlspecialchars($transaction['block_timestamp_formatted']) ?>
                                        <?php if ($transaction['block_timestamp']): ?>
                                            <small class="text-muted">(<?= time() - (int)$transaction['block_timestamp'] ?> seconds ago)</small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <!-- Transaction Details -->
                                <div class="row mb-3">
                                    <div class="col-md-3">
                                        <strong>From Address:</strong>
                                    </div>
                                    <div class="col-md-9">
                                        <a href="/explorer/address/<?= $transaction['from_address'] ?>" class="text-decoration-none">
                                            <code><?= htmlspecialchars($transaction['from_address']) ?></code>
                                        </a>
                                    </div>
                                </div>

                                <div class="row mb-3">
                                    <div class="col-md-3">
                                        <strong>To Address:</strong>
                                    </div>
                                    <div class="col-md-9">
                                        <a href="/explorer/address/<?= $transaction['to_address'] ?>" class="text-decoration-none">
                                            <code><?= htmlspecialchars($transaction['to_address']) ?></code>
                                        </a>
                                    </div>
                                </div>

                                <div class="row mb-3">
                                    <div class="col-md-3">
                                        <strong>Amount:</strong>
                                    </div>
                                    <div class="col-md-9">
                                        <span class="h5 text-success">
                                            <?= htmlspecialchars($transaction['amount_formatted']) ?> <?= $cryptoSymbol ?>
                                        </span>
                                    </div>
                                </div>

                                <div class="row mb-3">
                                    <div class="col-md-3">
                                        <strong>Transaction Fee:</strong>
                                    </div>
                                    <div class="col-md-9">
                                        <?= htmlspecialchars($transaction['fee_formatted']) ?> <?= $cryptoSymbol ?>
                                    </div>
                                </div>

                                <div class="row mb-3">
                                    <div class="col-md-3">
                                        <strong>Nonce:</strong>
                                    </div>
                                    <div class="col-md-9">
                                        <?= number_format($transaction['nonce'] ?? 0) ?>
                                    </div>
                                </div>

                                <!-- Gas Information -->
                                <div class="row mb-3">
                                    <div class="col-md-3">
                                        <strong>Gas Limit:</strong>
                                    </div>
                                    <div class="col-md-9">
                                        <?= number_format($transaction['gas_limit'] ?? 0) ?>
                                    </div>
                                </div>

                                <?php if (isset($transaction['gas_used'])): ?>
                                <div class="row mb-3">
                                    <div class="col-md-3">
                                        <strong>Gas Used:</strong>
                                    </div>
                                    <div class="col-md-9">
                                        <?= number_format($transaction['gas_used']) ?>
                                        <small class="text-muted">
                                            (<?= round(($transaction['gas_used'] / max($transaction['gas_limit'], 1)) * 100, 2) ?>%)
                                        </small>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <div class="row mb-3">
                                    <div class="col-md-3">
                                        <strong>Gas Price:</strong>
                                    </div>
                                    <div class="col-md-9">
                                        <?= htmlspecialchars($transaction['gas_price_formatted']) ?> <?= $cryptoSymbol ?>
                                    </div>
                                </div>

                                <!-- Transaction Data -->
                                <?php if (!empty($transaction['data']) && $transaction['data'] !== 'null'): ?>
                                <div class="row mb-3">
                                    <div class="col-md-3">
                                        <strong>Transaction Data:</strong>
                                    </div>
                                    <div class="col-md-9">
                                        <div class="data-section">
                                            <?php if ($transactionData): ?>
                                                <pre><?= htmlspecialchars(json_encode($transactionData, JSON_PRETTY_PRINT)) ?></pre>
                                            <?php else: ?>
                                                <code><?= htmlspecialchars($transaction['data']) ?></code>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <!-- Signature -->
                                <?php if (!empty($transaction['signature'])): ?>
                                <div class="row mb-3">
                                    <div class="col-md-3">
                                        <strong>Signature:</strong>
                                    </div>
                                    <div class="col-md-9">
                                        <code><?= htmlspecialchars($transaction['signature']) ?></code>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <!-- Mempool specific info -->
                                <?php if ($status === 'pending' && isset($transaction['priority_score'])): ?>
                                <div class="row mb-3">
                                    <div class="col-md-3">
                                        <strong>Priority Score:</strong>
                                    </div>
                                    <div class="col-md-9">
                                        <?= number_format($transaction['priority_score']) ?>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <!-- Timestamp -->
                                <div class="row mb-3">
                                    <div class="col-md-3">
                                        <strong><?= $status === 'pending' ? 'Created At:' : 'Timestamp:' ?></strong>
                                    </div>
                                    <div class="col-md-9">
                                        <?= htmlspecialchars($transaction['timestamp_formatted']) ?>
                                        <?php if ($transaction['timestamp']): ?>
                                            <small class="text-muted">(<?= time() - (int)$transaction['timestamp'] ?> seconds ago)</small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="row mt-4">
                    <div class="col-12 text-center">
                        <a href="/explorer/" class="btn btn-secondary me-2">
                            <i class="fas fa-arrow-left"></i> Back to Explorer
                        </a>
                        <a href="?hash=<?= urlencode($hash) ?>&format=json" class="btn btn-outline-primary" target="_blank">
                            <i class="fas fa-code"></i> View JSON
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
