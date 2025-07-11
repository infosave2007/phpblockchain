<?php
/**
 * Blockchain Explorer Interface
 * Multi-language support with beautiful UI
 */

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
function loadLanguage($lang) {
    $translations = [
        'en' => [
            'title' => 'Blockchain Explorer',
            'subtitle' => 'Explore blocks, transactions and addresses',
            'search_placeholder' => 'Enter block hash, transaction or address...',
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
            'day_ago' => 'd ago'
        ],
        'ru' => [
            'title' => 'Блокчейн Эксплорер',
            'subtitle' => 'Исследуйте блоки, транзакции и адреса в блокчейне',
            'search_placeholder' => 'Введите хеш блока, транзакции или адрес...',
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
            'day_ago' => 'д назад'
        ]
    ];
    
    return $translations[$lang] ?? $translations['en'];
}

$t = loadLanguage($language);

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

// Currency configuration
try {
    // Default values
    $cryptoSymbol = 'COIN';
    $cryptoName = 'Blockchain';
    
    // Try to load from main config first
    $configFile = dirname(__DIR__) . '/config/config.php';
    if (file_exists($configFile)) {
        $config = include $configFile;
        if (isset($config['network']['token_symbol'])) {
            $cryptoSymbol = $config['network']['token_symbol'];
        }
        if (isset($config['network']['token_name'])) {
            $cryptoName = $config['network']['token_name'];
        }
    }
    
} catch (Exception $e) {
    // Fallback values if configuration couldn't be loaded
    $cryptoSymbol = 'COIN';
    $cryptoName = 'Blockchain';
}
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
            <a class="navbar-brand" href="#">
                <i class="fas fa-cubes me-2"></i><?php echo htmlspecialchars($cryptoName); ?> <?php echo htmlspecialchars($t['title']); ?>
            </a>
            <div class="navbar-nav ms-auto d-flex align-items-center">
                <!-- Language Selector -->
                <div class="language-selector me-3">
                    <select class="form-select form-select-sm" onchange="changeLanguage(this.value)">
                        <?php echo getLanguageOptions($language); ?>
                    </select>
                </div>
                <!-- Network Selector -->
                <select class="form-select form-select-sm" id="networkSelect">
                    <option value="mainnet">Mainnet</option>
                    <option value="testnet">Testnet</option>
                    <option value="devnet">Devnet</option>
                </select>
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
                        <?php echo htmlspecialchars($cryptoName); ?> <?php echo htmlspecialchars($t['title']); ?>
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
                                <span class="pagination-info mx-3" id="blocksPageInfo"><?php echo htmlspecialchars($t['page']); ?> 1</span>
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
                                <span class="pagination-info mx-3" id="transactionsPageInfo"><?php echo htmlspecialchars($t['page']); ?> 1</span>
                                <button class="pagination-btn" id="nextTxBtn" onclick="loadTransactions('next')">
                                    <?php echo htmlspecialchars($t['next']); ?> <i class="fas fa-chevron-right ms-1"></i>
                                </button>
                            </div>
                        </div>
                    </div>
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
