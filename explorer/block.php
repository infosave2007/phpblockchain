<?php
// Generic Block details page
require_once __DIR__ . '/config_helper.php';

$__net = getNetworkConfig();
$cryptoName = $__net['name'];
$cryptoSymbol = $__net['token_symbol'];

// Start session early
session_start();

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
        'block_details' => 'Block Details',
        'block' => 'Block',
        'block_hash' => 'Block Hash',
        'previous_hash' => 'Previous Hash',
        'timestamp' => 'Timestamp',
        'seconds_ago' => 'seconds ago',
        'transactions' => 'Transactions',
        'hash' => 'Hash',
        'from' => 'From',
        'to' => 'To',
        'amount' => 'Amount',
        'fee' => 'Fee',
        'time' => 'Time',
        'confirmed' => 'Confirmed',
        'pending' => 'Pending',
        'failed' => 'Failed',
        'genesis' => 'GENESIS',
        'back_to_explorer' => '← Back to Explorer',
        'language' => 'Language',
        'validator' => 'Validator',
        'difficulty' => 'Difficulty',
        'nonce' => 'Nonce',
        'size' => 'Size',
        'merkle_root' => 'Merkle Root'
    ],
    'ru' => [
        'title' => 'Исследователь ' . $cryptoName,
        'subtitle' => 'Исследуйте блоки, транзакции и адреса',
        'block_details' => 'Детали блока',
        'block' => 'Блок',
        'block_hash' => 'Хеш блока',
        'previous_hash' => 'Предыдущий хеш',
        'timestamp' => 'Временная метка',
        'seconds_ago' => 'секунд назад',
        'transactions' => 'Транзакции',
        'hash' => 'Хеш',
        'from' => 'От',
        'to' => 'К',
        'amount' => 'Сумма',
        'fee' => 'Комиссия',
        'time' => 'Время',
        'confirmed' => 'Подтверждено',
        'pending' => 'В ожидании',
        'failed' => 'Ошибка',
        'genesis' => 'ГЕНЕЗИС',
        'back_to_explorer' => '← Назад к исследователю',
        'language' => 'Язык',
        'validator' => 'Валидатор',
        'difficulty' => 'Сложность',
        'nonce' => 'Nonce',
        'size' => 'Размер',
        'merkle_root' => 'Корень Меркла'
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
    <title><?php echo htmlspecialchars($cryptoName); ?> - <?php echo htmlspecialchars($t['block_details']); ?> #<?php echo htmlspecialchars($block['height']); ?></title>
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
                        <li class="breadcrumb-item active"><?php echo htmlspecialchars($t['block']); ?> #<?php echo htmlspecialchars($block['height']); ?></li>
                    </ol>
                </nav>

                <!-- Block Details Card -->
                <div class="content-section mb-4">
                                            <div class="section-header">
                            <h3 class="section-title">
                                <div class="section-icon">
                                    <i class="fas fa-cube"></i>
                                </div>
                                <?php echo htmlspecialchars($t['block_details']); ?>
                                <span class="badge bg-success ms-2"><?php echo htmlspecialchars($t['confirmed']); ?></span>
                            </h3>
                        </div>
                    <div class="card">
                        <div class="card-body">
                            <!-- Block Number -->
                            <div class="row mb-3">
                                <div class="col-md-3"><strong><?php echo htmlspecialchars($t['block']); ?>:</strong></div>
                                <div class="col-md-9">
                                    <span class="badge bg-primary fs-6">#<?php echo htmlspecialchars($block['height']); ?></span>
                                </div>
                            </div>

                            <!-- Block Hash -->
                            <div class="row mb-3">
                                <div class="col-md-3"><strong><?php echo htmlspecialchars($t['block_hash']); ?>:</strong></div>
                                <div class="col-md-9">
                                    <div class="tx-hash"><?php echo htmlspecialchars($block['hash']); ?></div>
                                </div>
                            </div>

                            <!-- Previous Hash -->
                            <div class="row mb-3">
                                <div class="col-md-3"><strong><?php echo htmlspecialchars($t['previous_hash']); ?>:</strong></div>
                                <div class="col-md-9">
                                    <div class="tx-hash">
                                        <?php if (isset($block['parent_hash']) && $block['parent_hash'] && $block['parent_hash'] !== '0'): ?>
                                            <a href="/explorer/block/<?php echo htmlspecialchars($block['parent_hash']); ?>">
                                                <?php echo htmlspecialchars($block['parent_hash']); ?>
                                            </a>
                                        <?php else: ?>
                                            <?php echo htmlspecialchars($t['genesis']); ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Timestamp -->
                            <div class="row mb-3">
                                <div class="col-md-3"><strong><?php echo htmlspecialchars($t['timestamp']); ?>:</strong></div>
                                <div class="col-md-9">
                                    <?php echo date('d.m.Y, H:i:s', $block['timestamp']); ?>
                                    <small class="text-muted">(<?php echo time() - $block['timestamp']; ?> <?php echo htmlspecialchars($t['seconds_ago']); ?>)</small>
                                </div>
                            </div>



                            <!-- Transaction Count -->
                            <div class="row mb-3">
                                <div class="col-md-3"><strong><?php echo htmlspecialchars($t['transactions']); ?>:</strong></div>
                                <div class="col-md-9">
                                    <span class="badge bg-info"><?php echo count($transactions); ?></span>
                                </div>
                            </div>

                            <!-- Validator -->
                            <div class="row mb-3">
                                <div class="col-md-3"><strong><?php echo htmlspecialchars($t['validator']); ?>:</strong></div>
                                <div class="col-md-9">
                                    <a href="/explorer/address/<?php echo htmlspecialchars($block['validator'] ?? '0x0000000000000000000000000000000000000000'); ?>">
                                        <?php echo htmlspecialchars($block['validator'] ?? '0x0000000000000000000000000000000000000000'); ?>
                                    </a>
                                </div>
                            </div>

                            <!-- Difficulty -->
                            <div class="row mb-3">
                                <div class="col-md-3"><strong><?php echo htmlspecialchars($t['difficulty']); ?>:</strong></div>
                                <div class="col-md-9">
                                    <span class="text-muted">
                                        <?php 
                                        if (isset($block['metadata']) && is_string($block['metadata'])) {
                                            $metadata = json_decode($block['metadata'], true);
                                            echo htmlspecialchars($metadata['difficulty'] ?? 'N/A');
                                        } else {
                                            echo 'N/A';
                                        }
                                        ?>
                                    </span>
                                </div>
                            </div>

                            <!-- Nonce -->
                            <div class="row mb-3">
                                <div class="col-md-3"><strong><?php echo htmlspecialchars($t['nonce']); ?>:</strong></div>
                                <div class="col-md-9">
                                    <span class="text-muted">
                                        <?php 
                                        if (isset($block['metadata']) && is_string($block['metadata'])) {
                                            $metadata = json_decode($block['metadata'], true);
                                            echo htmlspecialchars($metadata['nonce'] ?? 'N/A');
                                        } else {
                                            echo 'N/A';
                                        }
                                        ?>
                                    </span>
                                </div>
                            </div>

                            <!-- Size -->
                            <div class="row mb-3">
                                <div class="col-md-3"><strong><?php echo htmlspecialchars($t['size']); ?>:</strong></div>
                                <div class="col-md-9">
                                    <span class="text-muted"><?php echo number_format(strlen(json_encode($block)) / 1024, 2); ?> KB</span>
                                </div>
                            </div>

                            <!-- Merkle Root -->
                            <div class="row mb-3">
                                <div class="col-md-3"><strong><?php echo htmlspecialchars($t['merkle_root']); ?>:</strong></div>
                                <div class="col-md-9">
                                    <div class="tx-hash"><?php echo htmlspecialchars($block['merkle_root']); ?></div>
                                </div>
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
                                    <?php echo count($transactions); ?>
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
                                                <th><?php echo htmlspecialchars($t['from']); ?></th>
                                                <th><?php echo htmlspecialchars($t['to']); ?></th>
                                                <th><?php echo htmlspecialchars($t['amount']); ?></th>
                                                <th><?php echo htmlspecialchars($t['fee']); ?></th>
                                                <th><?php echo htmlspecialchars($t['time']); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($transactions as $tx): ?>
                                                <tr>
                                                    <td>
                                                        <a href="/explorer/tx/<?php echo htmlspecialchars($tx['hash']); ?>" 
                                                           class="text-truncate d-inline-block" style="max-width: 120px;">
                                                            <?php echo htmlspecialchars($tx['hash']); ?>
                                                        </a>
                                                    </td>
                                                    <td>
                                                        <a href="/explorer/address/<?php echo htmlspecialchars($tx['from_address']); ?>" 
                                                           class="text-truncate d-inline-block" style="max-width: 100px;">
                                                            <?php echo htmlspecialchars($tx['from_address']); ?>
                                                        </a>
                                                    </td>
                                                    <td>
                                                        <a href="/explorer/address/<?php echo htmlspecialchars($tx['to_address']); ?>" 
                                                           class="text-truncate d-inline-block" style="max-width: 100px;">
                                                            <?php echo htmlspecialchars($tx['to_address']); ?>
                                                        </a>
                                                    </td>
                                                    <td><?php echo number_format($tx['amount'], 8); ?> <?php echo htmlspecialchars($cryptoSymbol); ?></td>
                                                    <td><?php echo number_format($tx['fee'] ?? 0, 8); ?> <?php echo htmlspecialchars($cryptoSymbol); ?></td>
                                                    <td>
                                                        <small class="text-muted">
                                                            <?php echo date('d.m.Y, H:i', $tx['timestamp']); ?>
                                                        </small>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="content-section">
                        <div class="card">
                            <div class="card-body text-center">
                                <p class="text-muted"><?php echo htmlspecialchars($t['transactions']); ?> <?php echo htmlspecialchars($t['no_transactions'] ?? 'not found'); ?></p>
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
