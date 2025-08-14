<?php
/**
 * Address Details Page  
 * URL: /explorer/address/{address}
 */

require_once 'config_helper.php';

// Start session early
session_start();

$__net = getNetworkConfig();
$cryptoName = $__net['name'];
$cryptoSymbol = $__net['token_symbol'];

// Get address from URL path since nginx may not pass variables correctly
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
if (preg_match('/\/explorer\/address\/([a-fA-F0-9x]+)/', $requestUri, $matches)) {
    $address = $matches[1];
} else {
    $address = $address ?? ''; // Fallback to router variable
}

if (empty($address)) {
    http_response_code(400);
    echo "<h1>Invalid Address</h1>";
    echo "<p>Address parameter is missing or invalid.</p>";
    echo "<a href='/explorer/'>← Back to Explorer</a>";
    exit;
}

try {
    $pdo = getDbConnection();
    
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
    $stmt->bindValue(1, $address, PDO::PARAM_STR);
    $stmt->bindValue(2, $address, PDO::PARAM_STR);
    $stmt->bindValue(3, $limit, PDO::PARAM_INT);
    $stmt->bindValue(4, $offset, PDO::PARAM_INT);
    $stmt->execute();
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

// Language detection and setting
$supportedLanguages = ['en', 'ru'];
$defaultLanguage = 'en';
$language = $_GET['lang'] ?? $_SESSION['language'] ?? $defaultLanguage;

if (!in_array($language, $supportedLanguages)) {
    $language = $defaultLanguage;
}

$_SESSION['language'] = $language;

// Define translations directly
$translations = [
    'en' => [
        'title' => $cryptoName . ' Explorer',
        'subtitle' => 'Explore blocks, transactions and addresses',
        'address_details' => 'Address Details',
        'address' => 'Address',
        'balance' => 'Balance',
        'transactions' => 'Transactions',
        'total_received' => 'Total Received',
        'total_sent' => 'Total Sent',
        'hash' => 'Hash',
        'block' => 'Block',
        'from' => 'From',
        'to' => 'To',
        'amount' => 'Amount',
        'type' => 'Type',
        'time' => 'Time',
        'no_transactions' => 'No transactions found for this address',
        'showing' => 'Showing',
        'of' => 'of',
        'previous' => 'Previous',
        'next' => 'Next',
        'back_to_explorer' => '← Back to Explorer',
        'confirmed' => 'Confirmed',
        'pending' => 'Pending',
        'failed' => 'Failed',
        'incoming' => 'IN',
        'outgoing' => 'OUT',
        'self' => 'SELF',
        'page' => 'Page',
        'language' => 'Language'
    ],
    'ru' => [
        'title' => 'Исследователь ' . $cryptoName,
        'subtitle' => 'Исследуйте блоки, транзакции и адреса',
        'address_details' => 'Детали адреса',
        'address' => 'Адрес',
        'balance' => 'Баланс',
        'transactions' => 'Транзакции',
        'total_received' => 'Всего получено',
        'total_sent' => 'Всего отправлено',
        'hash' => 'Хеш',
        'block' => 'Блок',
        'from' => 'От',
        'to' => 'К',
        'amount' => 'Сумма',
        'type' => 'Тип',
        'time' => 'Время',
        'no_transactions' => 'Транзакции для этого адреса не найдены',
        'showing' => 'Показано',
        'of' => 'из',
        'previous' => 'Предыдущая',
        'next' => 'Следующая',
        'back_to_explorer' => '← Назад к исследователю',
        'confirmed' => 'Подтверждено',
        'pending' => 'В ожидании',
        'failed' => 'Ошибка',
        'incoming' => 'ВХОД',
        'outgoing' => 'ВЫХОД',
        'self' => 'СЕБЕ',
        'page' => 'Страница',
        'language' => 'Язык'
    ]
];

$t = $translations[$language] ?? $translations['en'];

// Language selector helper
if (!function_exists('getLanguageOptions')) {
    function getLanguageOptions($currentLang) {
        $languages = [
            'en' => ['name' => 'English', 'flag' => '🇺🇸'],
            'ru' => ['name' => 'Русский', 'flag' => '🇷🇺']
        ];
        
        $options = '';
        foreach ($languages as $code => $info) {
            $selected = $code === $currentLang ? 'selected' : '';
            $options .= "<option value=\"{$code}\" {$selected}>{$info['flag']} {$info['name']}</option>";
        }
        
        return $options;
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo $language; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($cryptoName); ?> - <?php echo htmlspecialchars($t['address_details']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="/explorer/explorer.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark navbar-custom fixed-top">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center" href="/explorer/">
                <img src="/assets/network-icon.svg" alt="Logo" style="height:28px" class="me-2"> <?php echo htmlspecialchars($t['title']); ?>
            </a>
            <div class="navbar-nav ms-auto d-flex align-items-center">
                <div class="language-selector me-3">
                    <select class="form-select form-select-sm" onchange="changeLanguage(this.value)">
                        <?php echo getLanguageOptions($language); ?>
                    </select>
                </div>
            </div>
        </div>
    </nav>

    <div class="container-fluid" style="padding-top: 76px;">
        <div class="main-container">
            <div class="container py-4">
                <!-- Breadcrumb -->
                <nav aria-label="breadcrumb" class="mb-4">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="/explorer/"><?php echo htmlspecialchars($t['title']); ?></a></li>
                        <li class="breadcrumb-item active"><?php echo htmlspecialchars($t['address_details']); ?></li>
                    </ol>
                </nav>

                <!-- Address Details Card -->
                <div class="content-section mb-4">
                    <div class="section-header">
                        <h3 class="section-title">
                            <div class="section-icon">
                                <i class="fas fa-wallet"></i>
                            </div>
                            <?php echo htmlspecialchars($t['address_details']); ?>
                        </h3>
                    </div>
                    <div class="card">
                    <div class="card-body">
                        <!-- Address -->
                        <div class="row mb-3">
                                <div class="col-md-2"><strong><?php echo htmlspecialchars($t['address']); ?>:</strong></div>
                            <div class="col-md-10">
                                    <div class="tx-hash"><?php echo htmlspecialchars($address); ?></div>
                                </div>
                        </div>

                        <!-- Balance -->
                        <div class="row mb-3">
                                <div class="col-md-2"><strong><?php echo htmlspecialchars($t['balance']); ?>:</strong></div>
                            <div class="col-md-10">
                                    <span class="badge bg-success fs-6"><?php echo number_format($balance, 8); ?> <?php echo htmlspecialchars($cryptoSymbol); ?></span>
                                </div>
                        </div>

                        <!-- Transaction Count -->
                        <div class="row mb-3">
                                <div class="col-md-2"><strong><?php echo htmlspecialchars($t['transactions']); ?>:</strong></div>
                            <div class="col-md-10">
                                    <span class="badge bg-info"><?php echo number_format($txCount); ?> <?php echo htmlspecialchars($t['transactions']); ?></span>
                                </div>
                        </div>

                        <!-- Received/Sent Stats -->
                        <div class="row mb-3">
                                <div class="col-md-2"><strong><?php echo htmlspecialchars($t['total_received']); ?>:</strong></div>
                                <div class="col-md-4"><?php echo number_format($stats['received'] ?? 0, 8); ?> <?php echo htmlspecialchars($cryptoSymbol); ?></div>
                                <div class="col-md-2"><strong><?php echo htmlspecialchars($t['total_sent']); ?>:</strong></div>
                                <div class="col-md-4"><?php echo number_format($stats['sent'] ?? 0, 8); ?> <?php echo htmlspecialchars($cryptoSymbol); ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Transactions List -->
                <?php if (!empty($transactions)): ?>
                    <div class="content-section">
                        <div class="section-header">
                            <h3 class="section-title">
                                <div class="section-icon">
                                    <i class="fas fa-list"></i>
                                </div>
                                <?php echo htmlspecialchars($t['transactions']); ?>
                            </h3>
                            <div class="d-flex gap-2">
                            <span class="text-muted">
                                    <?php echo htmlspecialchars($t['showing']); ?> <?php echo count($transactions); ?> <?php echo htmlspecialchars($t['of']); ?> <?php echo number_format($totalTxs); ?>
                            </span>
                            </div>
                        </div>
                        <div class="card">
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-striped mb-0">
                                    <thead class="table-dark">
                                        <tr>
                                                <th><?php echo htmlspecialchars($t['hash']); ?></th>
                                                <th><?php echo htmlspecialchars($t['block']); ?></th>
                                                <th><?php echo htmlspecialchars($t['from']); ?></th>
                                                <th><?php echo htmlspecialchars($t['to']); ?></th>
                                                <th><?php echo htmlspecialchars($t['amount']); ?></th>
                                                <th><?php echo htmlspecialchars($t['type']); ?></th>
                                                <th><?php echo htmlspecialchars($t['time']); ?></th>
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
                                                        <a href="/explorer/tx/<?php echo htmlspecialchars($tx['hash']); ?>" 
                                                       class="text-truncate d-inline-block" style="max-width: 120px;">
                                                            <?php echo htmlspecialchars($tx['hash']); ?>
                                                    </a>
                                                </td>
                                                <td>
                                                        <a href="/explorer/block/<?php echo $tx['block_height']; ?>" 
                                                       class="btn btn-sm btn-outline-primary">
                                                            #<?php echo $tx['block_height']; ?>
                                                    </a>
                                                </td>
                                                <td>
                                                    <span class="text-truncate d-inline-block" style="max-width: 100px;">
                                                            <?php echo htmlspecialchars($tx['from_address']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="text-truncate d-inline-block" style="max-width: 100px;">
                                                            <?php echo htmlspecialchars($tx['to_address']); ?>
                                                    </span>
                                                </td>
                                                    <td><?php echo number_format($tx['amount'], 8); ?> <?php echo htmlspecialchars($cryptoSymbol); ?></td>
                                                <td>
                                                    <?php if ($isIncoming && !$isOutgoing): ?>
                                                            <span class="badge bg-success"><?php echo htmlspecialchars($t['incoming']); ?></span>
                                                    <?php elseif ($isOutgoing && !$isIncoming): ?>
                                                            <span class="badge bg-danger"><?php echo htmlspecialchars($t['outgoing']); ?></span>
                                                    <?php else: ?>
                                                            <span class="badge bg-warning"><?php echo htmlspecialchars($t['self']); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <small class="text-muted">
                                                            <?php echo date('M j, Y H:i', $tx['timestamp']); ?>
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
                            <div class="pagination-container mt-3" id="addressPagination">
                                <?php if ($page > 1): ?>
                                    <button class="pagination-btn" onclick="window.location.href='?page=<?php echo $page - 1; ?>'">
                                        <i class="fas fa-chevron-left me-1"></i> <?php echo htmlspecialchars($t['previous']); ?>
                                    </button>
                                <?php endif; ?>

                                <span class="pagination-info mx-3"><?php echo htmlspecialchars($t['page']); ?> <?php echo $page; ?> <?php echo htmlspecialchars($t['of']); ?> <?php echo $totalPages; ?></span>

                                <?php if ($page < $totalPages): ?>
                                    <button class="pagination-btn" onclick="window.location.href='?page=<?php echo $page + 1; ?>'">
                                        <?php echo htmlspecialchars($t['next']); ?> <i class="fas fa-chevron-right ms-1"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                    <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="content-section">
                        <div class="card">
                        <div class="card-body text-center">
                                <p class="text-muted"><?php echo htmlspecialchars($t['no_transactions']); ?></p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Configuration from PHP
        const CRYPTO_SYMBOL = '<?php echo addslashes($cryptoSymbol); ?>';
        const CRYPTO_NAME = '<?php echo addslashes($cryptoName); ?>';
        
        // Language and translation
        const translations = <?php echo json_encode(['current_lang' => $language, 'translations' => $t]); ?>;
        const t = translations.translations;
        
        // Language change function
        function changeLanguage(lang) {
            const url = new URL(window.location);
            url.searchParams.set('lang', lang);
            window.location.href = url.toString();
        }
    </script>
</body>
</html>

<style>
.tx-hash {
    font-family: 'Courier New', monospace;
    word-break: break-all;
    background: #f8f9fa;
    padding: 10px;
    border-radius: 4px;
    border: 1px solid #dee2e6;
}
</style>
