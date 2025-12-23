<?php
// Transaction Details Page (generic)
require_once __DIR__ . '/config_helper.php';

$__net = getNetworkConfig();
$cryptoName = $__net['name'];
$cryptoSymbol = $__net['token_symbol'];

// Start session early
session_start();

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
} elseif (isset($_GET['tx'])) {
    // Compatibility with explorer/.htaccess rewrite rule (tx.php?tx=...)
    $hash = $_GET['tx'];
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
    if (!empty($transaction['data'])) {
        try {
            $decodedData = json_decode($transaction['data'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $transaction['data_parsed'] = $decodedData;
            }
        } catch (Exception $e) {
            // Data is not JSON, treat as raw
        }
    }
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
        'transaction_details' => 'Transaction Details',
        'transaction' => 'Transaction',
        'hash' => 'Hash',
        'status' => 'Status',
        'block' => 'Block',
        'timestamp' => 'Timestamp',
        'from' => 'From',
        'to' => 'To',
        'amount' => 'Amount',
        'fee' => 'Fee',
        'gas_limit' => 'Gas Limit',
        'gas_price' => 'Gas Price',
        'nonce' => 'Nonce',
        'data' => 'Data',
        'signature' => 'Signature',
        'confirmed' => 'Confirmed',
        'pending' => 'Pending',
        'failed' => 'Failed',
        'unknown' => 'Unknown',
        'back_to_explorer' => '← Back to Explorer',
        'language' => 'Language',
        'no_data' => 'No data',
        'raw_data' => 'Raw Data',
        'parsed_data' => 'Parsed Data'
    ],
    'ru' => [
        'title' => 'Исследователь ' . $cryptoName,
        'subtitle' => 'Исследуйте блоки, транзакции и адреса',
        'transaction_details' => 'Детали транзакции',
        'transaction' => 'Транзакция',
        'hash' => 'Хеш',
        'status' => 'Статус',
        'block' => 'Блок',
        'timestamp' => 'Временная метка',
        'from' => 'От',
        'to' => 'К',
        'amount' => 'Сумма',
        'fee' => 'Комиссия',
        'gas_limit' => 'Лимит газа',
        'gas_price' => 'Цена газа',
        'nonce' => 'Нонс',
        'data' => 'Данные',
        'signature' => 'Подпись',
        'confirmed' => 'Подтверждено',
        'pending' => 'В ожидании',
        'failed' => 'Ошибка',
        'unknown' => 'Неизвестно',
        'back_to_explorer' => '← Назад к исследователю',
        'language' => 'Язык',
        'no_data' => 'Нет данных',
        'raw_data' => 'Сырые данные',
        'parsed_data' => 'Разобранные данные'
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
    <title><?php echo htmlspecialchars($cryptoName); ?> - <?php echo htmlspecialchars($t['transaction_details']); ?></title>
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
                        <li class="breadcrumb-item active"><?php echo htmlspecialchars($t['transaction_details']); ?></li>
                    </ol>
                </nav>

                <?php if (isset($error)): ?>
                    <div class="alert alert-danger">
                        <h4><?php echo htmlspecialchars($error); ?></h4>
                        <a href="/explorer/" class="btn btn-primary"><?php echo htmlspecialchars($t['back_to_explorer']); ?></a>
                    </div>
                <?php else: ?>
                    <!-- Transaction Details Card -->
                    <div class="content-section mb-4">
                        <div class="section-header">
                            <h3 class="section-title">
                                <div class="section-icon">
                                    <i class="fas fa-exchange-alt"></i>
                                </div>
                                <?php echo htmlspecialchars($t['transaction_details']); ?>
                                <span class="badge bg-<?php echo $status === 'confirmed' ? 'success' : ($status === 'pending' ? 'warning' : 'danger'); ?> ms-2">
                                    <?php echo htmlspecialchars($t[$status] ?? $t['unknown']); ?>
                                </span>
                            </h3>
                        </div>
                        <div class="card">
                            <div class="card-body">
                                <!-- Transaction Hash -->
                                <div class="row mb-3">
                                    <div class="col-md-3"><strong><?php echo htmlspecialchars($t['hash']); ?>:</strong></div>
                                    <div class="col-md-9">
                                        <div class="tx-hash"><?php echo htmlspecialchars($transaction['hash']); ?></div>
                                    </div>
                                </div>

                                <!-- Status -->
                                <div class="row mb-3">
                                    <div class="col-md-3"><strong><?php echo htmlspecialchars($t['status']); ?>:</strong></div>
                                    <div class="col-md-9">
                                        <span class="badge bg-<?php echo $status === 'confirmed' ? 'success' : ($status === 'pending' ? 'warning' : 'danger'); ?>">
                                            <?php echo htmlspecialchars($t[$status] ?? $t['unknown']); ?>
                                        </span>
                                    </div>
                                </div>

                                <!-- Block Information -->
                                <?php if ($transaction['block_height']): ?>
                                    <div class="row mb-3">
                                        <div class="col-md-3"><strong><?php echo htmlspecialchars($t['block']); ?>:</strong></div>
                                        <div class="col-md-9">
                                            <a href="/explorer/block/<?php echo htmlspecialchars($transaction['block_height']); ?>" 
                                               class="btn btn-sm btn-outline-primary">
                                                #<?php echo htmlspecialchars($transaction['block_height']); ?>
                                            </a>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <!-- Timestamp -->
                                <div class="row mb-3">
                                    <div class="col-md-3"><strong><?php echo htmlspecialchars($t['timestamp']); ?>:</strong></div>
                                    <div class="col-md-9">
                                        <?php echo htmlspecialchars($transaction['timestamp_formatted']); ?>
                                    </div>
                                </div>

                                <!-- From Address -->
                                <div class="row mb-3">
                                    <div class="col-md-3"><strong><?php echo htmlspecialchars($t['from']); ?>:</strong></div>
                                    <div class="col-md-9">
                                        <a href="/explorer/address/<?php echo htmlspecialchars($transaction['from_address']); ?>" 
                                           class="text-truncate d-inline-block" style="max-width: 300px;">
                                            <?php echo htmlspecialchars($transaction['from_address']); ?>
                                        </a>
                                    </div>
                                </div>

                                <!-- To Address -->
                                <div class="row mb-3">
                                    <div class="col-md-3"><strong><?php echo htmlspecialchars($t['to']); ?>:</strong></div>
                                    <div class="col-md-9">
                                        <a href="/explorer/address/<?php echo htmlspecialchars($transaction['to_address']); ?>" 
                                           class="text-truncate d-inline-block" style="max-width: 300px;">
                                            <?php echo htmlspecialchars($transaction['to_address']); ?>
                                        </a>
                                    </div>
                                </div>

                                <!-- Amount -->
                                <div class="row mb-3">
                                    <div class="col-md-3"><strong><?php echo htmlspecialchars($t['amount']); ?>:</strong></div>
                                    <div class="col-md-9">
                                        <span class="badge bg-success fs-6">
                                            <?php echo htmlspecialchars($transaction['amount_formatted']); ?> <?php echo htmlspecialchars($cryptoSymbol); ?>
                                        </span>
                                    </div>
                                </div>

                                <!-- Fee -->
                                <?php if (isset($transaction['fee']) && $transaction['fee'] > 0): ?>
                                    <div class="row mb-3">
                                        <div class="col-md-3"><strong><?php echo htmlspecialchars($t['fee']); ?>:</strong></div>
                                        <div class="col-md-9">
                                            <?php echo htmlspecialchars($transaction['fee_formatted']); ?> <?php echo htmlspecialchars($cryptoSymbol); ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <!-- Gas Limit -->
                                <?php if (isset($transaction['gas_limit']) && $transaction['gas_limit'] > 0): ?>
                                    <div class="row mb-3">
                                        <div class="col-md-3"><strong><?php echo htmlspecialchars($t['gas_limit']); ?>:</strong></div>
                                        <div class="col-md-9">
                                            <?php echo htmlspecialchars($transaction['gas_limit']); ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <!-- Gas Price -->
                                <?php if (isset($transaction['gas_price']) && $transaction['gas_price'] > 0): ?>
                                    <div class="row mb-3">
                                        <div class="col-md-3"><strong><?php echo htmlspecialchars($t['gas_price']); ?>:</strong></div>
                                        <div class="col-md-9">
                                            <?php echo htmlspecialchars($transaction['gas_price_formatted']); ?> <?php echo htmlspecialchars($cryptoSymbol); ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <!-- Nonce -->
                                <?php if (isset($transaction['nonce'])): ?>
                                    <div class="row mb-3">
                                        <div class="col-md-3"><strong><?php echo htmlspecialchars($t['nonce']); ?>:</strong></div>
                                        <div class="col-md-9">
                                            <?php echo htmlspecialchars($transaction['nonce']); ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <!-- Data -->
                                <?php if (!empty($transaction['data'])): ?>
                                    <div class="row mb-3">
                                        <div class="col-md-3"><strong><?php echo htmlspecialchars($t['data']); ?>:</strong></div>
                                        <div class="col-md-9">
                                            <?php if (isset($transaction['data_parsed'])): ?>
                                                <div class="mb-2">
                                                    <strong><?php echo htmlspecialchars($t['parsed_data']); ?>:</strong>
                                                    <pre class="bg-light p-2 rounded mt-1"><?php echo htmlspecialchars(json_encode($transaction['data_parsed'], JSON_PRETTY_PRINT)); ?></pre>
                                                </div>
                                            <?php endif; ?>
                                            <div>
                                                <strong><?php echo htmlspecialchars($t['raw_data']); ?>:</strong>
                                                <div class="tx-hash"><?php echo htmlspecialchars($transaction['data']); ?></div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <!-- Signature -->
                                <?php if (!empty($transaction['signature'])): ?>
                                    <div class="row mb-3">
                                        <div class="col-md-3"><strong><?php echo htmlspecialchars($t['signature']); ?>:</strong></div>
                                        <div class="col-md-9">
                                            <div class="tx-hash"><?php echo htmlspecialchars($transaction['signature']); ?></div>
                                        </div>
                                    </div>
                                <?php endif; ?>
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
