<?php
/**
 * Address Details Page  
 * URL: /explorer/address/{address}
 */

// Load environment and configuration
require_once __DIR__ . '/../vendor/autoload.php';

$baseDir = dirname(__DIR__);
require_once $baseDir . '/core/Environment/EnvironmentLoader.php';
\Blockchain\Core\Environment\EnvironmentLoader::load($baseDir);

$configFile = $baseDir . '/config/config.php';
$config = file_exists($configFile) ? require $configFile : [];

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
        'charset' => 'utf8mb4',
    ];
}

try {
    $host = $dbConfig['host'] ?? 'localhost';
    $dbname = $dbConfig['database'] ?? $dbConfig['name'] ?? 'blockchain';
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $dbname);
    $pdo = new PDO($dsn, $dbConfig['username'] ?? 'root', $dbConfig['password'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    $address = $address ?? ''; // Should be set by router
    
    // Get address balance and transaction count
    $stmt = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN to_address = ? AND status = 'confirmed' THEN amount ELSE 0 END) as received,
            SUM(CASE WHEN from_address = ? AND status = 'confirmed' THEN amount + fee ELSE 0 END) as sent,
            COUNT(*) as tx_count
        FROM transactions 
        WHERE (from_address = ? OR to_address = ?) AND status = 'confirmed'
    ");
    $stmt->execute([$address, $address, $address, $address]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $balance = ($stats['received'] ?? 0) - ($stats['sent'] ?? 0);
    $txCount = $stats['tx_count'] ?? 0;

    // Get transactions for this address (paginated)
    $page = (int)($_GET['page'] ?? 1);
    $limit = 20;
    $offset = ($page - 1) * $limit;
    
    $stmt = $pdo->prepare("
        SELECT * FROM transactions 
        WHERE (from_address = ? OR to_address = ?) AND status = 'confirmed'
        ORDER BY timestamp DESC 
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$address, $address, $limit, $offset]);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get total transaction count for pagination
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM transactions 
        WHERE (from_address = ? OR to_address = ?) AND status = 'confirmed'
    ");
    $stmt->execute([$address, $address]);
    $totalTxs = $stmt->fetchColumn();
    $totalPages = ceil($totalTxs / $limit);

} catch (Exception $e) {
    http_response_code(500);
    echo "<h1>Database Error</h1>";
    echo "<p>Unable to fetch address details.</p>";
    echo "<a href='/explorer/'>← Back to Explorer</a>";
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Address <?= htmlspecialchars($address) ?> - <?= htmlspecialchars($config['blockchain']['name'] ?? 'Blockchain') ?> Explorer</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="/explorer/explorer.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center" href="/explorer/">
                <img src="/assets/network-icon.svg" alt="Logo" style="height:24px" class="me-2"> <?= htmlspecialchars($config['blockchain']['name'] ?? 'Blockchain') ?> Explorer
            </a>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="row">
            <div class="col-12">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="/explorer/">Explorer</a></li>
                        <li class="breadcrumb-item active">Address</li>
                    </ol>
                </nav>

                <div class="card">
                    <div class="card-header">
                        <h4 class="mb-0">
                            <i class="fas fa-wallet"></i> Address Details
                        </h4>
                    </div>
                    <div class="card-body">
                        <!-- Address -->
                        <div class="row mb-3">
                            <div class="col-md-2"><strong>Address:</strong></div>
                            <div class="col-md-10">
                                <div class="tx-hash"><?= htmlspecialchars($address) ?></div>
                            </div>
                        </div>

                        <!-- Balance -->
                        <div class="row mb-3">
                            <div class="col-md-2"><strong>Balance:</strong></div>
                            <div class="col-md-10">
                                <span class="badge bg-success fs-6"><?= number_format($balance, 8) ?> <?= htmlspecialchars($config['blockchain']['symbol'] ?? 'ETH') ?></span>
                            </div>
                        </div>

                        <!-- Transaction Count -->
                        <div class="row mb-3">
                            <div class="col-md-2"><strong>Transactions:</strong></div>
                            <div class="col-md-10">
                                <span class="badge bg-info"><?= number_format($txCount) ?> transactions</span>
                            </div>
                        </div>

                        <!-- Received/Sent Stats -->
                        <div class="row mb-3">
                            <div class="col-md-2"><strong>Total Received:</strong></div>
                            <div class="col-md-4"><?= number_format($stats['received'] ?? 0, 8) ?> <?= htmlspecialchars($config['blockchain']['symbol'] ?? 'ETH') ?></div>
                            <div class="col-md-2"><strong>Total Sent:</strong></div>
                            <div class="col-md-4"><?= number_format($stats['sent'] ?? 0, 8) ?> <?= htmlspecialchars($config['blockchain']['symbol'] ?? 'ETH') ?></div>
                        </div>
                    </div>
                </div>

                <!-- Transactions List -->
                <?php if (!empty($transactions)): ?>
                    <div class="card mt-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="fas fa-list"></i> Transactions
                            </h5>
                            <span class="text-muted">
                                Showing <?= count($transactions) ?> of <?= number_format($totalTxs) ?>
                            </span>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-striped mb-0">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Hash</th>
                                            <th>Block</th>
                                            <th>From</th>
                                            <th>To</th>
                                            <th>Amount</th>
                                            <th>Type</th>
                                            <th>Time</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($transactions as $tx): ?>
                                            <?php
                                            $isIncoming = strtolower($tx['to_address']) === strtolower($address);
                                            $isOutgoing = strtolower($tx['from_address']) === strtolower($address);
                                            ?>
                                            <tr>
                                                <td>
                                                    <a href="/explorer/tx/<?= htmlspecialchars($tx['hash']) ?>" 
                                                       class="text-truncate d-inline-block" style="max-width: 120px;">
                                                        <?= htmlspecialchars($tx['hash']) ?>
                                                    </a>
                                                </td>
                                                <td>
                                                    <a href="/explorer/block/<?= $tx['block_height'] ?>" 
                                                       class="btn btn-sm btn-outline-primary">
                                                        #<?= $tx['block_height'] ?>
                                                    </a>
                                                </td>
                                                <td>
                                                    <span class="text-truncate d-inline-block" style="max-width: 100px;">
                                                        <?= htmlspecialchars($tx['from_address']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="text-truncate d-inline-block" style="max-width: 100px;">
                                                        <?= htmlspecialchars($tx['to_address']) ?>
                                                    </span>
                                                </td>
                                                <td><?= number_format($tx['amount'], 8) ?> <?= htmlspecialchars($config['blockchain']['symbol'] ?? 'ETH') ?></td>
                                                <td>
                                                    <?php if ($isIncoming && !$isOutgoing): ?>
                                                        <span class="badge bg-success">IN</span>
                                                    <?php elseif ($isOutgoing && !$isIncoming): ?>
                                                        <span class="badge bg-danger">OUT</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning">SELF</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <small class="text-muted">
                                                        <?= date('M j, Y H:i', $tx['timestamp']) ?>
                                                    </small>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                        <nav aria-label="Transaction pagination" class="mt-3">
                            <ul class="pagination justify-content-center">
                                <?php if ($page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?= $page - 1 ?>">Previous</a>
                                    </li>
                                <?php endif; ?>

                                <?php for ($i = max(1, $page - 3); $i <= min($totalPages, $page + 3); $i++): ?>
                                    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                        <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
                                    </li>
                                <?php endfor; ?>

                                <?php if ($page < $totalPages): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?= $page + 1 ?>">Next</a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="card mt-4">
                        <div class="card-body text-center">
                            <p class="text-muted">No transactions found for this address.</p>
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
<?php
/**
 * Address Details Page  
 * URL: /explorer/address/{address}
 */

// Load environment and configuration
require_once __DIR__ . '/../vendor/autoload.php';

$baseDir = dirname(__DIR__);
require_once $baseDir . '/core/Environment/EnvironmentLoader.php';
\Blockchain\Core\Environment\EnvironmentLoader::load($baseDir);

$configFile = $baseDir . '/config/config.php';
$config = file_exists($configFile) ? require $configFile : [];

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
        'charset' => 'utf8mb4',
    ];
}

try {
    $host = $dbConfig['host'] ?? 'localhost';
    $dbname = $dbConfig['database'] ?? $dbConfig['name'] ?? 'blockchain';
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $dbname);
    $pdo = new PDO($dsn, $dbConfig['username'] ?? 'root', $dbConfig['password'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    $address = $address ?? ''; // Should be set by router
    
    // Get address balance and transaction count
    $stmt = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN to_address = ? AND status = 'confirmed' THEN amount ELSE 0 END) as received,
            SUM(CASE WHEN from_address = ? AND status = 'confirmed' THEN amount + fee ELSE 0 END) as sent,
            COUNT(*) as tx_count
        FROM transactions 
        WHERE (from_address = ? OR to_address = ?) AND status = 'confirmed'
    ");
    $stmt->execute([$address, $address, $address, $address]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $balance = ($stats['received'] ?? 0) - ($stats['sent'] ?? 0);
    $txCount = $stats['tx_count'] ?? 0;

    // Get transactions for this address (paginated)
    $page = (int)($_GET['page'] ?? 1);
    $limit = 20;
    $offset = ($page - 1) * $limit;
    
    $stmt = $pdo->prepare("
        SELECT * FROM transactions 
        WHERE (from_address = ? OR to_address = ?) AND status = 'confirmed'
        ORDER BY timestamp DESC 
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$address, $address, $limit, $offset]);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get total transaction count for pagination
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM transactions 
        WHERE (from_address = ? OR to_address = ?) AND status = 'confirmed'
    ");
    $stmt->execute([$address, $address]);
    $totalTxs = $stmt->fetchColumn();
    $totalPages = ceil($totalTxs / $limit);

} catch (Exception $e) {
    http_response_code(500);
    echo "<h1>Database Error</h1>";
    echo "<p>Unable to fetch address details.</p>";
    echo "<a href='/explorer/'>← Back to Explorer</a>";
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Address <?= htmlspecialchars($address) ?> - <?= htmlspecialchars($config['blockchain']['name'] ?? 'Blockchain') ?> Explorer</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="explorer.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="/explorer/">
                <i class="fas fa-cube"></i> <?= htmlspecialchars($config['blockchain']['name'] ?? 'Blockchain') ?> Explorer
            </a>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="row">
            <div class="col-12">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="/explorer/">Explorer</a></li>
                        <li class="breadcrumb-item active">Address</li>
                    </ol>
                </nav>

                <div class="card">
                    <div class="card-header">
                        <h4 class="mb-0">
                            <i class="fas fa-wallet"></i> Address Details
                        </h4>
                    </div>
                    <div class="card-body">
                        <!-- Address -->
                        <div class="row mb-3">
                            <div class="col-md-2"><strong>Address:</strong></div>
                            <div class="col-md-10">
                                <div class="tx-hash"><?= htmlspecialchars($address) ?></div>
                            </div>
                        </div>

                        <!-- Balance -->
                        <div class="row mb-3">
                            <div class="col-md-2"><strong>Balance:</strong></div>
                            <div class="col-md-10">
                                <span class="badge bg-success fs-6"><?= number_format($balance, 8) ?> ETH</span>
                            </div>
                        </div>

                        <!-- Transaction Count -->
                        <div class="row mb-3">
                            <div class="col-md-2"><strong>Transactions:</strong></div>
                            <div class="col-md-10">
                                <span class="badge bg-info"><?= number_format($txCount) ?> transactions</span>
                            </div>
                        </div>

                        <!-- Received/Sent Stats -->
                        <div class="row mb-3">
                            <div class="col-md-2"><strong>Total Received:</strong></div>
                            <div class="col-md-4"><?= number_format($stats['received'] ?? 0, 8) ?> ETH</div>
                            <div class="col-md-2"><strong>Total Sent:</strong></div>
                            <div class="col-md-4"><?= number_format($stats['sent'] ?? 0, 8) ?> ETH</div>
                        </div>
                    </div>
                </div>

                <!-- Transactions List -->
                <?php if (!empty($transactions)): ?>
                    <div class="card mt-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="fas fa-list"></i> Transactions
                            </h5>
                            <span class="text-muted">
                                Showing <?= count($transactions) ?> of <?= number_format($totalTxs) ?>
                            </span>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-striped mb-0">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Hash</th>
                                            <th>Block</th>
                                            <th>From</th>
                                            <th>To</th>
                                            <th>Amount</th>
                                            <th>Type</th>
                                            <th>Time</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($transactions as $tx): ?>
                                            <?php
                                            $isIncoming = strtolower($tx['to_address']) === strtolower($address);
                                            $isOutgoing = strtolower($tx['from_address']) === strtolower($address);
                                            ?>
                                            <tr>
                                                <td>
                                                    <a href="/explorer/tx/<?= htmlspecialchars($tx['hash']) ?>" 
                                                       class="text-truncate d-inline-block" style="max-width: 120px;">
                                                        <?= htmlspecialchars($tx['hash']) ?>
                                                    </a>
                                                </td>
                                                <td>
                                                    <a href="/explorer/block/<?= $tx['block_height'] ?>" 
                                                       class="btn btn-sm btn-outline-primary">
                                                        #<?= $tx['block_height'] ?>
                                                    </a>
                                                </td>
                                                <td>
                                                    <span class="text-truncate d-inline-block" style="max-width: 100px;">
                                                        <?= htmlspecialchars($tx['from_address']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="text-truncate d-inline-block" style="max-width: 100px;">
                                                        <?= htmlspecialchars($tx['to_address']) ?>
                                                    </span>
                                                </td>
                                                <td><?= number_format($tx['amount'], 8) ?></td>
                                                <td>
                                                    <?php if ($isIncoming && !$isOutgoing): ?>
                                                        <span class="badge bg-success">IN</span>
                                                    <?php elseif ($isOutgoing && !$isIncoming): ?>
                                                        <span class="badge bg-danger">OUT</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning">SELF</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <small class="text-muted">
                                                        <?= date('M j, Y H:i', $tx['timestamp']) ?>
                                                    </small>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                        <nav aria-label="Transaction pagination" class="mt-3">
                            <ul class="pagination justify-content-center">
                                <?php if ($page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?= $page - 1 ?>">Previous</a>
                                    </li>
                                <?php endif; ?>

                                <?php for ($i = max(1, $page - 3); $i <= min($totalPages, $page + 3); $i++): ?>
                                    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                        <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
                                    </li>
                                <?php endfor; ?>

                                <?php if ($page < $totalPages): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?= $page + 1 ?>">Next</a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="card mt-4">
                        <div class="card-body text-center">
                            <p class="text-muted">No transactions found for this address.</p>
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
