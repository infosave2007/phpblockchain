<?php
/**
 * Blockchain Explorer Interface
 * Multi-language support with beautiful UI
 */

require_once 'config_helper.php';
$__net = getNetworkConfig();
$cryptoName = $__net['name'];
$cryptoSymbol = $__net['token_symbol'];

// Simple routing for explorer URLs
$request_uri = $_SERVER['REQUEST_URI'];
$path = parse_url($request_uri, PHP_URL_PATH);

// Remove base path and clean up
$path = str_replace('/explorer', '', $path);
$path = trim($path, '/');

// Route handling
if (preg_match('/^tx\/([a-fA-F0-9x]+)$/', $path, $matches)) {
    $hash = $matches[1];
    include 'tx.php';
    exit;
} elseif (preg_match('/^block\/(\d+|[a-fA-F0-9x]+)$/', $path, $matches)) {
            $blockIdentifier = $matches[1];
    include 'block.php';
    exit;
} elseif (preg_match('/^address\/([a-fA-F0-9x]+)$/', $path, $matches)) {
    $address = $matches[1];
    include 'address.php';
    exit;
}

// Default route: show explorer homepage

// Language detection and setting
$supportedLanguages = ['en', 'ru'];
$defaultLanguage = 'en';
// Get language from URL parameter, session, or browser
session_start();
$language = $_GET['lang'] ?? $_SESSION['language'] ?? $defaultLanguage;

// Validate language
if (!in_array($language, $supportedLanguages)) {
    $language = $defaultLanguage;
}

// Store in session
$_SESSION['language'] = $language;

// Load language strings
function loadLanguage($lang, $cryptoName = 'Blockchain') {
    $translations = [
        'en' => [
            'title' => $cryptoName . ' Explorer',
            'subtitle' => 'Explore blocks, transactions and addresses',
            'search_placeholder' => 'Enter block hash, transaction or address...',
            'smart_contracts' => 'Smart Contracts',
            'latest_contracts' => 'Latest Smart Contracts',
            'contract_details' => 'Contract Details',
            'creator' => 'Creator',
            'deployed_at_block' => 'Deployed at Block',
            'status' => 'Status',
            'view' => 'View',
            'address' => 'Address',
            'abi' => 'ABI',
            'bytecode' => 'Bytecode',
            'block_height' => 'Block Height',
            'transactions' => 'Transactions',
            'hash_rate' => 'Hash Rate',
            'active_nodes' => 'Active Nodes',
            'latest_blocks' => 'Latest Blocks',
            'latest_transactions' => 'Latest Transactions',
            'search_results' => 'Search Results',
            'loading_data' => 'Loading data...',
            'page' => 'Page',
            'back' => 'Back',
            'next' => 'Next',
            'language' => 'Language',
            'block' => 'Block',
            'transaction' => 'Transaction',
            'hash' => 'Hash',
            'validator' => 'Validator',
            'size' => 'Size',
            'details' => 'Details',
            'confirmed' => 'Confirmed',
            'pending' => 'Pending',
            'failed' => 'Failed',
            'unknown' => 'Unknown',
            'from' => 'From',
            'to' => 'To',
            'fee' => 'Fee',
            'transaction_hash' => 'Transaction Hash',
            'sec_ago' => 'sec ago',
            'min_ago' => 'min ago',
            'hour_ago' => 'h ago',
            'day_ago' => 'd ago',
            'close' => 'Close',
            'transaction_details' => 'Transaction Details',
            'block_details' => 'Block Details',
            'status' => 'Status',
            'confirmations' => 'Confirmations',
            'timestamp' => 'Date & Time',
            'from_address' => 'From Address',
            'to_address' => 'To Address',
            'amount' => 'Amount',
            'copy_to_clipboard' => 'Copy to Clipboard',
            'previous_hash' => 'Previous Hash',
            'merkle_root' => 'Merkle Root',
            'difficulty' => 'Difficulty',
            'nonce' => 'Nonce',
            'tx_count' => 'Transaction Count',
            'copied' => 'Copied to clipboard!',
            'type' => 'Type',
            'block_hash' => 'Block Hash',
            'metadata' => 'Metadata',
            'height' => 'Height'
        ],
        'ru' => [
            'title' => $cryptoName . ' Эксплорер',
            'subtitle' => 'Исследуйте блоки, транзакции и адреса в блокчейне',
            'search_placeholder' => 'Введите хеш блока, транзакции или адрес...',
            'smart_contracts' => 'Смарт‑контракты',
            'latest_contracts' => 'Последние смарт‑контракты',
            'contract_details' => 'Детали контракта',
            'creator' => 'Создатель',
            'deployed_at_block' => 'Деплой в блоке',
            'status' => 'Статус',
            'view' => 'Открыть',
            'address' => 'Адрес',
            'abi' => 'ABI',
            'bytecode' => 'Байт‑код',
            'block_height' => 'Высота блока',
            'transactions' => 'Транзакций',
            'hash_rate' => 'Хеш-рейт',
            'active_nodes' => 'Активные ноды',
            'latest_blocks' => 'Последние блоки',
            'latest_transactions' => 'Последние транзакции',
            'search_results' => 'Результаты поиска',
            'loading_data' => 'Загрузка данных...',
            'page' => 'Страница',
            'back' => 'Назад',
            'next' => 'Далее',
            'language' => 'Язык',
            'block' => 'Блок',
            'transaction' => 'Транзакция',
            'hash' => 'Хеш',
            'validator' => 'Валидатор',
            'size' => 'Размер',
            'details' => 'Подробнее',
            'confirmed' => 'Подтвержден',
            'pending' => 'В ожидании',
            'failed' => 'Отклонен',
            'unknown' => 'Неизвестно',
            'from' => 'От',
            'to' => 'Кому',
            'fee' => 'Комиссия',
            'transaction_hash' => 'Хеш транзакции',
            'sec_ago' => 'сек назад',
            'min_ago' => 'мин назад',
            'hour_ago' => 'ч назад',
            'day_ago' => 'д назад',
            'close' => 'Закрыть',
            'transaction_details' => 'Детали транзакции',
            'block_details' => 'Детали блока',
            'status' => 'Статус',
            'confirmations' => 'Подтверждения',
            'timestamp' => 'Дата и время',
            'from_address' => 'Адрес отправителя',
            'to_address' => 'Адрес получателя',
            'amount' => 'Сумма',
            'copy_to_clipboard' => 'Копировать в буфер',
            'previous_hash' => 'Хеш предыдущего блока',
            'merkle_root' => 'Корень Меркла',
            'difficulty' => 'Сложность',
            'nonce' => 'Nonce',
            'tx_count' => 'Количество транзакций',
            'copied' => 'Скопировано в буфер!',
            'type' => 'Тип',
            'block_hash' => 'Хеш блока',
            'metadata' => 'Метаданные',
            'height' => 'Высота'
        ]
    ];
    
    return $translations[$lang] ?? $translations['en'];
}

$t = loadLanguage($language, $cryptoName);

// Get available languages for selector
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

// Currency configuration already sourced from DB (generic defaults in helper)
?>
<!DOCTYPE html>
<html lang="<?php echo $language; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($cryptoName); ?> - <?php echo htmlspecialchars($t['title']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="explorer.css?v=<?php echo time(); ?>" rel="stylesheet">
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
                <!-- Header Section -->
                <div class="explorer-header text-center">
                    <h1 class="mb-4">
                        <i class="fas fa-search me-3"></i>
                        <?php echo htmlspecialchars($t['title']); ?>
                    </h1>
                    <p class="lead mb-4"><?php echo htmlspecialchars($t['subtitle']); ?></p>
                    
                    <div class="search-container">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" class="search-input" id="searchInput" 
                               placeholder="<?php echo htmlspecialchars($t['search_placeholder']); ?>">
                        <button class="search-btn" onclick="performSearch()">
                            <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                </div>

                <!-- Network Statistics -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-layer-group"></i>
                        </div>
                        <div class="stat-value" id="blockHeight">-</div>
                        <div class="stat-label"><?php echo htmlspecialchars($t['block_height']); ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-exchange-alt"></i>
                        </div>
                        <div class="stat-value" id="totalTx">-</div>
                        <div class="stat-label"><?php echo htmlspecialchars($t['transactions']); ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-tachometer-alt"></i>
                        </div>
                        <div class="stat-value" id="hashRate">-</div>
                        <div class="stat-label"><?php echo htmlspecialchars($t['hash_rate']); ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-network-wired"></i>
                        </div>
                        <div class="stat-value" id="activeNodes">-</div>
                        <div class="stat-label"><?php echo htmlspecialchars($t['active_nodes']); ?></div>
                    </div>
                </div>

                <!-- Loading Spinner -->
                <div class="loading-spinner" id="loadingSpinner">
                    <div class="spinner"></div>
                    <p><?php echo htmlspecialchars($t['loading_data']); ?></p>
                </div>

                <!-- Search Results -->
                <div id="searchResults" class="d-none">
                    <div class="content-section">
                        <div class="section-header">
                            <h3 class="section-title">
                                <div class="section-icon">
                                    <i class="fas fa-search"></i>
                                </div>
                                <?php echo htmlspecialchars($t['search_results']); ?>
                            </h3>
                        </div>
                        <div id="resultContent"></div>
                    </div>
                </div>

                <!-- Main Content -->
                <div class="row">
                    <!-- Latest Blocks -->
                    <div class="col-lg-6">
                        <div class="content-section">
                            <div class="section-header">
                                <h3 class="section-title">
                                    <div class="section-icon">
                                        <i class="fas fa-cube"></i>
                                    </div>
                                    <?php echo htmlspecialchars($t['latest_blocks']); ?>
                                </h3>
                                <div class="d-flex gap-2">
                                    <button class="btn btn-outline-primary btn-sm" onclick="refreshBlocks()">
                                        <i class="fas fa-sync-alt"></i>
                                    </button>
                                </div>
                            </div>
                            <div id="latestBlocks">
                                <!-- Blocks will be loaded here -->
                            </div>
                            
                            <!-- Pagination for Blocks -->
                            <div class="pagination-container" id="blocksPagination">
                                <button class="pagination-btn" id="prevBlocksBtn" onclick="loadBlocks('prev')" disabled>
                                    <i class="fas fa-chevron-left me-1"></i> <?php echo htmlspecialchars($t['back']); ?>
                                </button>
                                <span class="pagination-info mx-3" id="blocksPageInfo"><?php echo htmlspecialchars($t['page']); ?> 1 из 20</span>
                                <button class="pagination-btn" id="nextBlocksBtn" onclick="loadBlocks('next')">
                                    <?php echo htmlspecialchars($t['next']); ?> <i class="fas fa-chevron-right ms-1"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Latest Transactions -->
                    <div class="col-lg-6">
                        <div class="content-section">
                            <div class="section-header">
                                <h3 class="section-title">
                                    <div class="section-icon">
                                        <i class="fas fa-exchange-alt"></i>
                                    </div>
                                    <?php echo htmlspecialchars($t['latest_transactions']); ?>
                                </h3>
                                <div class="d-flex gap-2">
                                    <button class="btn btn-outline-primary btn-sm" onclick="refreshTransactions()">
                                        <i class="fas fa-sync-alt"></i>
                                    </button>
                                </div>
                            </div>
                            <div id="latestTransactions">
                                <!-- Transactions will be loaded here -->
                            </div>
                            
                            <!-- Pagination for Transactions -->
                            <div class="pagination-container" id="transactionsPagination">
                                <button class="pagination-btn" id="prevTxBtn" onclick="loadTransactions('prev')" disabled>
                                    <i class="fas fa-chevron-left me-1"></i> <?php echo htmlspecialchars($t['back']); ?>
                                </button>
                                <span class="pagination-info mx-3" id="transactionsPageInfo"><?php echo htmlspecialchars($t['page']); ?> 1 из 45</span>
                                <button class="pagination-btn" id="nextTxBtn" onclick="loadTransactions('next')">
                                    <?php echo htmlspecialchars($t['next']); ?> <i class="fas fa-chevron-right ms-1"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Smart Contracts -->
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="content-section">
                            <div class="section-header">
                                <h3 class="section-title">
                                    <div class="section-icon">
                                        <i class="fas fa-file-code"></i>
                                    </div>
                                    <?php echo htmlspecialchars($t['latest_contracts'] ?? 'Latest Smart Contracts'); ?>
                                </h3>
                                <div class="d-flex gap-2">
                                    <button class="btn btn-outline-primary btn-sm" onclick="refreshContracts()">
                                        <i class="fas fa-sync-alt"></i>
                                    </button>
                                </div>
                            </div>
                            <div id="latestContracts">
                                <!-- Contracts will be loaded here -->
                            </div>
                            
                            <!-- Pagination for Contracts -->
                            <div class="pagination-container" id="contractsPagination">
                                <button class="pagination-btn" id="prevContractsBtn" onclick="loadContracts('prev')" disabled>
                                    <i class="fas fa-chevron-left me-1"></i> <?php echo htmlspecialchars($t['back']); ?>
                                </button>
                                <span class="pagination-info mx-3" id="contractsPageInfo"><?php echo htmlspecialchars($t['page']); ?> 1</span>
                                <button class="pagination-btn" id="nextContractsBtn" onclick="loadContracts('next')">
                                    <?php echo htmlspecialchars($t['next']); ?> <i class="fas fa-chevron-right ms-1"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Transaction Details Modal -->
    <div class="modal fade" id="transactionDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-info-circle me-2"></i>
                        <span id="modalTitle"><?php echo htmlspecialchars($t['transaction_details'] ?? 'Transaction Details'); ?></span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="modalBody">
                    <!-- Content will be loaded here -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <?php echo htmlspecialchars($t['close'] ?? 'Close'); ?>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Block Details Modal -->
    <div class="modal fade" id="blockDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-cube me-2"></i>
                        <span id="blockModalTitle"><?php echo htmlspecialchars($t['block_details'] ?? 'Block Details'); ?></span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="blockModalBody">
                    <!-- Content will be loaded here -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <?php echo htmlspecialchars($t['close'] ?? 'Close'); ?>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Contract Details Modal -->
    <div class="modal fade" id="contractDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-file-code me-2"></i>
                        <span id="contractModalTitle"><?php echo htmlspecialchars($t['contract_details'] ?? 'Contract Details'); ?></span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="contractModalBody">
                    <!-- Content will be loaded here -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <?php echo htmlspecialchars($t['close'] ?? 'Close'); ?>
                    </button>
                </div>
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
    <script src="explorer.js?v=<?php echo time(); ?>"></script>
</body>
</html>
