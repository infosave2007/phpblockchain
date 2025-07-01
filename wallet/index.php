<?php
/**
 * Blockchain Wallet Interface
 * Multi-language support with beautiful UI
 */

// Language detection and setting
$supportedLanguages = ['en', 'ru'];
$defaultLanguage = 'en';

// Get language from URL parameter, session, or browser
$language = $_GET['lang'] ?? $_SESSION['language'] ?? $defaultLanguage;

// Validate language
if (!in_array($language, $supportedLanguages)) {
    $language = $defaultLanguage;
}

// Store in session
session_start();
$_SESSION['language'] = $language;

// Load language strings
function loadLanguage($lang) {
    $translations = [
        'en' => [
            'title' => 'Blockchain Wallet',
            'subtitle' => 'Manage your digital assets',
            'create_wallet' => 'Create Wallet',
            'create_new_wallet' => 'Create New Wallet',
            'restore_wallet' => 'Restore Wallet',
            'restore_wallet_desc' => 'Restore from seed phrase',
            'all_wallets' => 'All Wallets',
            'show_all_wallets' => 'Show All Wallets',
            'my_wallets' => 'My Wallets',
            'my_saved_wallets' => 'My Saved Wallets',
            'language' => 'Language',
            'creating_wallet' => 'Creating wallet...',
            'loading_wallets' => 'Loading wallets...',
            'checking_balance' => 'Checking balance...',
            'wallet_created' => 'Wallet Created!',
            'wallet_restored' => 'Wallet Restored!',
            'address' => 'Address',
            'private_key' => 'Private Key',
            'public_key' => 'Public Key',
            'balance' => 'Balance',
            'copy' => 'Copy',
            'close' => 'Close',
            'cancel' => 'Cancel',
            'next' => 'Next',
            'back' => 'Back',
            'generate' => 'Generate',
            'create' => 'Create',
            'restore' => 'Restore',
            'save_wallet' => 'Save to My Wallets',
            'warning' => 'Warning',
            'important' => 'Important',
            'seed_phrase' => 'Seed Phrase',
            'mnemonic_warning' => 'Write down these 12 words in the correct order and store them in a safe place:',
            'mnemonic_danger' => 'If you lose this phrase, access to your wallet will be lost forever!',
            'step_1' => 'Step 1',
            'step_2' => 'Step 2',
            'step_3' => 'Step 3',
            'generate_seed' => 'We will generate a unique seed phrase (12 words) for you. This phrase is the only way to restore access to your wallet!',
            'generate_seed_btn' => 'Generate Seed Phrase',
            'confirm_saved' => 'Confirm that you saved the seed phrase',
            'confirm_saved_check' => 'I wrote down the seed phrase and saved it in a safe place',
            'confirm_understand_check' => 'I understand that losing the seed phrase means losing access to the wallet',
            'create_wallet_btn' => 'Create Wallet',
            'i_saved_phrase' => 'I saved the phrase in a safe place',
            'restore_wallet_title' => 'Restore Wallet',
            'restore_info' => 'Enter your seed phrase (12 words) to restore access to your wallet',
            'seed_phrase_label' => 'Seed phrase (enter 12 words separated by spaces):',
            'seed_phrase_placeholder' => 'Enter 12 words of your seed phrase separated by spaces...',
            'seed_phrase_example' => 'Example: abandon ability able about above absent absorb abstract absurd abuse access accident',
            'validate_phrase' => 'Validate Phrase',
            'restore_wallet_btn' => 'Restore Wallet',
            'staking' => 'Staking',
            'staking_desc' => 'Staking allows you to earn additional tokens by locking them for a certain period.',
            'stake_amount' => 'Amount to stake:',
            'stake_period' => 'Staking period:',
            'start_staking' => 'Start Staking',
            'days_apy' => 'days (APY: %)',
            'min_amount' => 'Minimum amount:',
            'copied' => 'Copied to clipboard!',
            'copy_mnemonic' => 'Copy Phrase',
            'no_wallets_found' => 'No wallets found',
            'error_creating_wallet' => 'Error creating wallet:',
            'error_loading_wallets' => 'Error loading wallets:',
            'error' => 'Error:',
            'success' => 'Success',
            'wallet_address' => 'Wallet Address:',
            'wallet_info' => 'Wallet Information',
            'check_balance' => 'Check Balance'
        ],
        'ru' => [
            'title' => 'Blockchain Кошелёк',
            'subtitle' => 'Управляйте своими цифровыми активами',
            'create_wallet' => 'Создать кошелёк',
            'create_new_wallet' => 'Создать новый кошелёк',
            'restore_wallet' => 'Восстановить кошелёк',
            'restore_wallet_desc' => 'Восстановить из сид-фразы',
            'all_wallets' => 'Все кошельки',
            'show_all_wallets' => 'Показать все кошельки',
            'my_wallets' => 'Мои кошельки',
            'my_saved_wallets' => 'Мои сохранённые кошельки',
            'language' => 'Язык',
            'creating_wallet' => 'Создание кошелька...',
            'loading_wallets' => 'Загрузка кошельков...',
            'checking_balance' => 'Проверка баланса...',
            'wallet_created' => 'Кошелёк создан!',
            'wallet_restored' => 'Кошелёк восстановлен!',
            'address' => 'Адрес',
            'private_key' => 'Приватный ключ',
            'public_key' => 'Публичный ключ',
            'balance' => 'Баланс',
            'copy' => 'Копировать',
            'close' => 'Закрыть',
            'cancel' => 'Отмена',
            'next' => 'Далее',
            'back' => 'Назад',
            'generate' => 'Сгенерировать',
            'create' => 'Создать',
            'restore' => 'Восстановить',
            'save_wallet' => 'Сохранить в мои кошельки',
            'warning' => 'Предупреждение',
            'important' => 'Важно',
            'seed_phrase' => 'Сид-фраза',
            'mnemonic_warning' => 'Запишите эти 12 слов в правильном порядке и храните в безопасном месте:',
            'mnemonic_danger' => 'Если вы потеряете эту фразу, доступ к кошельку будет утрачен навсегда!',
            'step_1' => 'Шаг 1',
            'step_2' => 'Шаг 2',
            'step_3' => 'Шаг 3',
            'generate_seed' => 'Мы сгенерируем для вас уникальную сид-фразу (12 слов). Эта фраза - единственный способ восстановить доступ к вашему кошельку!',
            'generate_seed_btn' => 'Сгенерировать сид-фразу',
            'confirm_saved' => 'Подтвердите, что вы сохранили сид-фразу',
            'confirm_saved_check' => 'Я записал сид-фразу и сохранил её в безопасном месте',
            'confirm_understand_check' => 'Я понимаю, что потеря сид-фразы означает потерю доступа к кошельку',
            'create_wallet_btn' => 'Создать кошелёк',
            'i_saved_phrase' => 'Я записал фразу в безопасном месте',
            'restore_wallet_title' => 'Восстановление кошелька',
            'restore_info' => 'Введите вашу сид-фразу (12 слов) для восстановления доступа к кошельку',
            'seed_phrase_label' => 'Сид-фраза (введите 12 слов через пробел):',
            'seed_phrase_placeholder' => 'Введите 12 слов вашей сид-фразы через пробел...',
            'seed_phrase_example' => 'Например: abandon ability able about above absent absorb abstract absurd abuse access accident',
            'validate_phrase' => 'Проверить фразу',
            'restore_wallet_btn' => 'Восстановить кошелёк',
            'staking' => 'Стейкинг',
            'staking_desc' => 'Стейкинг позволяет заработать дополнительные токены, заблокировав их на определённый период.',
            'stake_amount' => 'Количество для стейкинга:',
            'stake_period' => 'Период стейкинга:',
            'start_staking' => 'Начать стейкинг',
            'days_apy' => 'дней (APY: %)',
            'min_amount' => 'Минимальная сумма:',
            'copied' => 'Скопировано в буфер обмена!',
            'copy_mnemonic' => 'Скопировать фразу',
            'no_wallets_found' => 'Кошельки не найдены',
            'error_creating_wallet' => 'Ошибка создания кошелька:',
            'error_loading_wallets' => 'Ошибка загрузки кошельков:',
            'error' => 'Ошибка:',
            'success' => 'Успех',
            'wallet_address' => 'Адрес кошелька:',
            'wallet_info' => 'Информация о кошельке',
            'check_balance' => 'Проверить баланс'
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
?>
<!DOCTYPE html>
<html lang="<?php echo $language; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $t['title']; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --success-gradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            --warning-gradient: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
            --info-gradient: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%);
            --dark-gradient: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
            --card-shadow: 0 10px 30px rgba(0,0,0,0.1);
            --card-hover-shadow: 0 15px 40px rgba(0,0,0,0.15);
            --border-radius: 20px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            line-height: 1.6;
        }

        /* Header Styles */
        .wallet-header {
            background: var(--primary-gradient);
            color: white;
            border-radius: var(--border-radius);
            padding: 2.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--card-shadow);
            position: relative;
            overflow: hidden;
        }

        .wallet-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="50" cy="50" r="1" fill="rgba(255,255,255,0.1)"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
            opacity: 0.3;
        }

        .wallet-header .container {
            position: relative;
            z-index: 1;
        }

        .language-selector {
            position: absolute;
            top: 1rem;
            right: 1rem;
            z-index: 2;
        }

        .language-selector select {
            background: rgba(255,255,255,0.2);
            border: 1px solid rgba(255,255,255,0.3);
            color: white;
            border-radius: 10px;
            padding: 0.5rem 1rem;
            font-size: 0.9rem;
            backdrop-filter: blur(10px);
        }

        .language-selector select option {
            background: #333;
            color: white;
        }

        /* Card Styles */
        .action-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 2rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--card-shadow);
            transition: var(--transition);
            border: none;
            height: 100%;
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }

        .action-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: var(--primary-gradient);
            transform: scaleX(0);
            transition: var(--transition);
        }

        .action-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--card-hover-shadow);
        }

        .action-card:hover::before {
            transform: scaleX(1);
        }

        .action-icon {
            width: 60px;
            height: 60px;
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 1rem;
            transition: var(--transition);
        }

        .action-card:hover .action-icon {
            transform: scale(1.1) rotate(5deg);
        }

        .icon-success { background: var(--success-gradient); color: white; }
        .icon-warning { background: var(--warning-gradient); color: white; }
        .icon-info { background: var(--info-gradient); color: #333; }
        .icon-primary { background: var(--primary-gradient); color: white; }

        /* Button Styles */
        .btn-modern {
            border-radius: 15px;
            padding: 0.8rem 2rem;
            font-weight: 600;
            font-size: 0.95rem;
            border: none;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .btn-modern::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: var(--transition);
        }

        .btn-modern:hover::before {
            left: 100%;
        }

        .btn-primary.btn-modern {
            background: var(--primary-gradient);
            border: none;
        }

        .btn-success.btn-modern {
            background: var(--success-gradient);
            border: none;
        }

        .btn-warning.btn-modern {
            background: var(--warning-gradient);
            border: none;
        }

        .btn-info.btn-modern {
            background: var(--info-gradient);
            border: none;
            color: #333;
        }

        /* Modal Styles */
        .modal-content {
            border: none;
            border-radius: var(--border-radius);
            box-shadow: 0 20px 60px rgba(0,0,0,0.2);
        }

        .modal-header {
            background: var(--primary-gradient);
            color: white;
            border-radius: var(--border-radius) var(--border-radius) 0 0;
            border: none;
            padding: 1.5rem 2rem;
        }

        .modal-header .btn-close {
            filter: invert(1);
        }

        /* Address Display */
        .address-display {
            font-family: 'Courier New', monospace;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 1rem;
            border-radius: 10px;
            word-break: break-all;
            margin: 1rem 0;
            border: 2px solid #e9ecef;
            transition: var(--transition);
        }

        .address-display:hover {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        /* Mnemonic Grid */
        .mnemonic-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 10px;
            margin: 20px 0;
        }

        .mnemonic-word {
            background: linear-gradient(135deg, #fff 0%, #f8f9fa 100%);
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 15px;
            text-align: center;
            font-family: 'Courier New', monospace;
            font-weight: bold;
            transition: var(--transition);
            cursor: pointer;
        }

        .mnemonic-word:hover {
            border-color: #667eea;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .word-number {
            display: block;
            font-size: 0.8em;
            color: #6c757d;
            margin-bottom: 4px;
            font-weight: normal;
        }

        /* Alert Styles */
        .alert-modern {
            border: none;
            border-radius: 15px;
            padding: 1.5rem;
            font-weight: 500;
        }

        .alert-info.alert-modern {
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
            color: #1565c0;
        }

        .alert-warning.alert-modern {
            background: linear-gradient(135deg, #fff3e0 0%, #ffcc02 100%);
            color: #e65100;
        }

        .alert-danger.alert-modern {
            background: linear-gradient(135deg, #ffebee 0%, #ffcdd2 100%);
            color: #c62828;
        }

        .alert-success.alert-modern {
            background: linear-gradient(135deg, #e8f5e8 0%, #c8e6c9 100%);
            color: #2e7d32;
        }

        /* Loading Animation */
        .loading-pulse {
            animation: pulse 1.5s ease-in-out infinite alternate;
        }

        @keyframes pulse {
            0% { opacity: 0.6; }
            100% { opacity: 1; }
        }

        /* Form Styles */
        .form-control {
            border-radius: 10px;
            border: 2px solid #e9ecef;
            padding: 0.8rem 1rem;
            transition: var(--transition);
        }

        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .wallet-header {
                padding: 2rem 1rem;
                margin-bottom: 1rem;
            }
            
            .action-card {
                padding: 1.5rem;
            }
            
            .language-selector {
                position: static;
                margin-bottom: 1rem;
                text-align: center;
            }
        }

        /* Notification */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            min-width: 300px;
            border-radius: 15px;
            border: none;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            animation: slideIn 0.3s ease-out;
        }

        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="wallet-header">
        <div class="language-selector">
            <select class="form-select" onchange="changeLanguage(this.value)">
                <?php echo getLanguageOptions($language); ?>
            </select>
        </div>
        <div class="container text-center">
            <h1><i class="fas fa-wallet me-3"></i><?php echo $t['title']; ?></h1>
            <p class="mb-0 opacity-75"><?php echo $t['subtitle']; ?></p>
        </div>
    </div>

    <div class="container">
        <!-- Action Cards -->
        <div class="row mb-4" id="walletControls">
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="action-card" onclick="startWalletCreation()">
                    <div class="action-icon icon-success">
                        <i class="fas fa-plus-circle"></i>
                    </div>
                    <h5 class="fw-bold"><?php echo $t['create_wallet']; ?></h5>
                    <p class="text-muted mb-0"><?php echo $t['create_new_wallet']; ?></p>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="action-card" onclick="showRestoreModal()">
                    <div class="action-icon icon-warning">
                        <i class="fas fa-download"></i>
                    </div>
                    <h5 class="fw-bold"><?php echo $t['restore_wallet']; ?></h5>
                    <p class="text-muted mb-0"><?php echo $t['restore_wallet_desc']; ?></p>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="action-card" onclick="listWallets()">
                    <div class="action-icon icon-info">
                        <i class="fas fa-list"></i>
                    </div>
                    <h5 class="fw-bold"><?php echo $t['all_wallets']; ?></h5>
                    <p class="text-muted mb-0"><?php echo $t['show_all_wallets']; ?></p>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="action-card" onclick="showMyWallets()">
                    <div class="action-icon icon-primary">
                        <i class="fas fa-wallet"></i>
                    </div>
                    <h5 class="fw-bold"><?php echo $t['my_wallets']; ?></h5>
                    <p class="text-muted mb-0"><?php echo $t['my_saved_wallets']; ?></p>
                </div>
            </div>
        </div>

        <!-- Results -->
        <div id="results"></div>
    </div>

    <!-- Create Wallet Modal -->
    <div class="modal fade" id="createWalletModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-plus-circle me-2"></i><?php echo $t['create_wallet']; ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- Step 1 -->
                    <div id="step1" class="creation-step">
                        <div class="alert alert-info alert-modern">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong><?php echo $t['step_1']; ?>:</strong> <?php echo $t['generate_seed']; ?>
                        </div>
                        <div class="text-center">
                            <button class="btn btn-primary btn-modern" onclick="generateNewMnemonic()">
                                <i class="fas fa-dice me-2"></i><?php echo $t['generate_seed_btn']; ?>
                            </button>
                        </div>
                        <div id="mnemonicDisplay" style="display: none;" class="mt-3">
                            <div class="alert alert-warning alert-modern">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <strong><?php echo $t['important']; ?>!</strong> <?php echo $t['mnemonic_warning']; ?>
                            </div>
                            <div class="mnemonic-grid" id="mnemonicWords"></div>
                            <div class="alert alert-danger alert-modern">
                                <i class="fas fa-lock me-2"></i>
                                <strong><?php echo $t['warning']; ?>:</strong> <?php echo $t['mnemonic_danger']; ?>
                            </div>
                            <div class="text-center">
                                <button class="btn btn-warning btn-modern me-2" onclick="copyMnemonic()">
                                    <i class="fas fa-copy me-2"></i><?php echo $t['copy_mnemonic']; ?>
                                </button>
                                <button class="btn btn-success btn-modern" onclick="showStep2()">
                                    <i class="fas fa-check me-2"></i><?php echo $t['i_saved_phrase']; ?>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Step 2 -->
                    <div id="step2" class="creation-step" style="display: none;">
                        <div class="alert alert-warning alert-modern">
                            <i class="fas fa-shield-alt me-2"></i>
                            <strong><?php echo $t['step_2']; ?>:</strong> <?php echo $t['confirm_saved']; ?>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="confirmSaved">
                            <label class="form-check-label" for="confirmSaved">
                                <?php echo $t['confirm_saved_check']; ?>
                            </label>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="confirmUnderstand">
                            <label class="form-check-label" for="confirmUnderstand">
                                <?php echo $t['confirm_understand_check']; ?>
                            </label>
                        </div>
                        <div class="text-center">
                            <button class="btn btn-secondary btn-modern me-2" onclick="showStep1()">
                                <i class="fas fa-arrow-left me-2"></i><?php echo $t['back']; ?>
                            </button>
                            <button class="btn btn-success btn-modern" onclick="createWalletFromMnemonic()" id="createWalletBtn" disabled>
                                <i class="fas fa-wallet me-2"></i><?php echo $t['create_wallet_btn']; ?>
                            </button>
                        </div>
                    </div>

                    <!-- Step 3 -->
                    <div id="step3" class="creation-step" style="display: none;">
                        <div id="creationResult"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Restore Wallet Modal -->
    <div class="modal fade" id="restoreWalletModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-download me-2"></i><?php echo $t['restore_wallet_title']; ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info alert-modern">
                        <i class="fas fa-info-circle me-2"></i>
                        <?php echo $t['restore_info']; ?>
                    </div>
                    <div class="mb-3">
                        <label for="restoreMnemonic" class="form-label fw-bold"><?php echo $t['seed_phrase_label']; ?></label>
                        <textarea class="form-control" id="restoreMnemonic" rows="3" 
                                placeholder="<?php echo $t['seed_phrase_placeholder']; ?>"></textarea>
                        <div class="form-text"><?php echo $t['seed_phrase_example']; ?></div>
                    </div>
                    <div id="restoreValidation" class="mb-3"></div>
                    <div id="restoreResult"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-modern" data-bs-dismiss="modal"><?php echo $t['cancel']; ?></button>
                    <button type="button" class="btn btn-warning btn-modern" onclick="validateRestoreMnemonic()">
                        <i class="fas fa-check me-2"></i><?php echo $t['validate_phrase']; ?>
                    </button>
                    <button type="button" class="btn btn-success btn-modern" onclick="restoreWalletFromMnemonic()" id="restoreBtn" disabled>
                        <i class="fas fa-download me-2"></i><?php echo $t['restore_wallet_btn']; ?>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Global variables
        let currentMnemonic = [];
        let currentWalletData = null;
        let cryptoSymbol = 'COIN';
        
        // Language and translation
        const translations = <?php echo json_encode(['current_lang' => $language, 'translations' => $t]); ?>;
        const t = translations.translations;
        
        // Language change function
        function changeLanguage(lang) {
            const url = new URL(window.location);
            url.searchParams.set('lang', lang);
            window.location.href = url.toString();
        }
        
        // Load configuration
        async function loadConfig() {
            try {
                const response = await fetch('wallet_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'get_config' })
                });
                
                const data = await response.json();
                if (data.success && data.config) {
                    cryptoSymbol = data.config.crypto_symbol || 'COIN';
                }
            } catch (error) {
                console.log('Config load failed, using defaults');
            }
        }
        
        // Initialize
        document.addEventListener('DOMContentLoaded', loadConfig);
        
        // Notification system
        function showNotification(message, type = 'info') {
            const alertClass = type === 'danger' ? 'alert-danger' : 
                              type === 'success' ? 'alert-success' : 
                              type === 'warning' ? 'alert-warning' : 'alert-info';
            
            const notification = document.createElement('div');
            notification.className = `alert ${alertClass} alert-modern notification`;
            notification.innerHTML = `
                <i class="fas fa-${type === 'danger' ? 'exclamation-triangle' : type === 'success' ? 'check' : 'info'}-circle me-2"></i>
                ${message}
                <button type="button" class="btn-close ms-auto" onclick="this.parentElement.remove()"></button>
            `;
            document.body.appendChild(notification);
            
            setTimeout(() => notification.remove(), 5000);
        }
        
        // Copy to clipboard
        async function copyToClipboard(text) {
            try {
                await navigator.clipboard.writeText(text);
                showNotification(t.copied, 'success');
            } catch (err) {
                const textArea = document.createElement('textarea');
                textArea.value = text;
                document.body.appendChild(textArea);
                textArea.select();
                document.execCommand('copy');
                document.body.removeChild(textArea);
                showNotification(t.copied, 'success');
            }
        }
        
        // Start wallet creation
        function startWalletCreation() {
            const modal = new bootstrap.Modal(document.getElementById('createWalletModal'));
            modal.show();
            
            // Reset steps
            document.querySelectorAll('.creation-step').forEach(step => step.style.display = 'none');
            document.getElementById('step1').style.display = 'block';
            document.getElementById('mnemonicDisplay').style.display = 'none';
        }
        
        // Generate new mnemonic
        async function generateNewMnemonic() {
            const button = event.target;
            const originalText = button.innerHTML;
            button.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>' + t.generate;
            button.disabled = true;
            
            try {
                const response = await fetch('wallet_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'generate_mnemonic' })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    currentMnemonic = data.mnemonic;
                    displayMnemonic(currentMnemonic);
                } else {
                    showNotification(t.error + ' ' + data.error, 'danger');
                }
            } catch (error) {
                showNotification(t.error + ' ' + error.message, 'danger');
            } finally {
                button.innerHTML = originalText;
                button.disabled = false;
            }
        }
        
        // Display mnemonic words
        function displayMnemonic(words) {
            const container = document.getElementById('mnemonicWords');
            container.innerHTML = '';
            
            words.forEach((word, index) => {
                const wordDiv = document.createElement('div');
                wordDiv.className = 'col-md-3 col-6';
                wordDiv.innerHTML = `
                    <div class="mnemonic-word" onclick="copyToClipboard('${word}')">
                        <span class="word-number">${index + 1}</span>
                        <span class="word-text">${word}</span>
                    </div>
                `;
                container.appendChild(wordDiv);
            });
            
            document.getElementById('mnemonicDisplay').style.display = 'block';
        }
        
        // Copy mnemonic phrase
        function copyMnemonic() {
            const phrase = currentMnemonic.join(' ');
            copyToClipboard(phrase);
        }
        
        // Show step 2
        function showStep2() {
            document.getElementById('step1').style.display = 'none';
            document.getElementById('step2').style.display = 'block';
            
            // Enable/disable create button based on checkboxes
            const checkboxes = document.querySelectorAll('#step2 input[type="checkbox"]');
            const createBtn = document.getElementById('createWalletBtn');
            
            checkboxes.forEach(checkbox => {
                checkbox.addEventListener('change', () => {
                    const allChecked = Array.from(checkboxes).every(cb => cb.checked);
                    createBtn.disabled = !allChecked;
                });
            });
        }
        
        // Show step 1
        function showStep1() {
            document.getElementById('step2').style.display = 'none';
            document.getElementById('step1').style.display = 'block';
        }
        
        // Create wallet from mnemonic
        async function createWalletFromMnemonic() {
            const button = document.getElementById('createWalletBtn');
            const originalText = button.innerHTML;
            button.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>' + t.creating_wallet;
            button.disabled = true;
            
            try {
                const response = await fetch('wallet_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'create_wallet_from_mnemonic',
                        mnemonic: currentMnemonic
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    currentWalletData = data.wallet;
                    showWalletResult(data.wallet, 'created');
                } else {
                    showNotification(t.error_creating_wallet + ' ' + data.error, 'danger');
                }
            } catch (error) {
                showNotification(t.error + ' ' + error.message, 'danger');
            } finally {
                button.innerHTML = originalText;
                button.disabled = false;
            }
        }
        
        // Show wallet result
        function showWalletResult(wallet, type) {
            document.getElementById('step1').style.display = 'none';
            document.getElementById('step2').style.display = 'none';
            document.getElementById('step3').style.display = 'block';
            
            const title = type === 'created' ? t.wallet_created : t.wallet_restored;
            const icon = type === 'created' ? 'plus-circle' : 'download';
            
            document.getElementById('creationResult').innerHTML = `
                <div class="alert alert-success alert-modern text-center">
                    <i class="fas fa-${icon} fa-3x mb-3"></i>
                    <h4>${title}</h4>
                </div>
                
                <div class="wallet-info">
                    <div class="mb-3">
                        <label class="form-label fw-bold">${t.address}:</label>
                        <div class="address-display d-flex justify-content-between align-items-center">
                            <span class="flex-grow-1">${wallet.address}</span>
                            <button class="btn btn-sm btn-outline-primary" onclick="copyToClipboard('${wallet.address}')">
                                <i class="fas fa-copy"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">${t.balance}:</label>
                        <div class="balance-display">
                            <span class="h5 text-success">${wallet.balance || 0} ${cryptoSymbol}</span>
                        </div>
                    </div>
                    
                    <div class="text-center">
                        <button class="btn btn-primary btn-modern" onclick="addToWalletList('${wallet.address}', '${wallet.private_key}')">
                            <i class="fas fa-save me-2"></i>${t.save_wallet}
                        </button>
                    </div>
                </div>
            `;
        }
        
        // Show restore modal
        function showRestoreModal() {
            const modal = new bootstrap.Modal(document.getElementById('restoreWalletModal'));
            modal.show();
            
            // Clear previous data
            document.getElementById('restoreMnemonic').value = '';
            document.getElementById('restoreValidation').innerHTML = '';
            document.getElementById('restoreResult').innerHTML = '';
            document.getElementById('restoreBtn').disabled = true;
        }
        
        // Validate restore mnemonic
        function validateRestoreMnemonic() {
            const mnemonicText = document.getElementById('restoreMnemonic').value.trim();
            const words = mnemonicText.split(/\s+/).filter(word => word.length > 0);
            
            const validationDiv = document.getElementById('restoreValidation');
            
            if (words.length !== 12) {
                validationDiv.innerHTML = `
                    <div class="alert alert-danger alert-modern">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        ${t.error} Must be exactly 12 words (found ${words.length})
                    </div>
                `;
                document.getElementById('restoreBtn').disabled = true;
                return;
            }
            
            validationDiv.innerHTML = `
                <div class="alert alert-success alert-modern">
                    <i class="fas fa-check me-2"></i>
                    Valid seed phrase format (12 words)
                </div>
            `;
            document.getElementById('restoreBtn').disabled = false;
        }
        
        // Restore wallet from mnemonic
        async function restoreWalletFromMnemonic() {
            const mnemonicText = document.getElementById('restoreMnemonic').value.trim();
            const words = mnemonicText.split(/\s+/).filter(word => word.length > 0);
            
            const button = document.getElementById('restoreBtn');
            const originalText = button.innerHTML;
            button.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>' + t.restore;
            button.disabled = true;
            
            try {
                const response = await fetch('wallet_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'restore_wallet_from_mnemonic',
                        mnemonic: words
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    currentWalletData = data.wallet;
                    showRestoreResult(data.wallet);
                } else {
                    showNotification(t.error + ' ' + data.error, 'danger');
                }
            } catch (error) {
                showNotification(t.error + ' ' + error.message, 'danger');
            } finally {
                button.innerHTML = originalText;
                button.disabled = false;
            }
        }
        
        // Show restore result
        function showRestoreResult(wallet) {
            document.getElementById('restoreResult').innerHTML = `
                <div class="alert alert-success alert-modern text-center">
                    <i class="fas fa-download fa-3x mb-3"></i>
                    <h4>${t.wallet_restored}</h4>
                </div>
                
                <div class="wallet-info">
                    <div class="mb-3">
                        <label class="form-label fw-bold">${t.address}:</label>
                        <div class="address-display d-flex justify-content-between align-items-center">
                            <span class="flex-grow-1">${wallet.address}</span>
                            <button class="btn btn-sm btn-outline-primary" onclick="copyToClipboard('${wallet.address}')">
                                <i class="fas fa-copy"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">${t.balance}:</label>
                        <div class="balance-display">
                            <span class="h5 text-success">${wallet.balance || 0} ${cryptoSymbol}</span>
                        </div>
                    </div>
                    
                    <div class="text-center">
                        <button class="btn btn-primary btn-modern" onclick="addToWalletList('${wallet.address}', '${wallet.private_key}')">
                            <i class="fas fa-save me-2"></i>${t.save_wallet}
                        </button>
                    </div>
                </div>
            `;
        }
        
        // List all wallets
        async function listWallets() {
            const resultsDiv = document.getElementById('results');
            resultsDiv.innerHTML = `
                <div class="action-card text-center loading-pulse">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p class="mt-2">${t.loading_wallets}</p>
                </div>
            `;
            
            try {
                const response = await fetch('wallet_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'list_wallets' })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    displayWalletList(data.wallets, t.all_wallets);
                } else {
                    resultsDiv.innerHTML = `
                        <div class="alert alert-danger alert-modern">
                            ${t.error_loading_wallets} ${data.error}
                        </div>
                    `;
                }
            } catch (error) {
                resultsDiv.innerHTML = `
                    <div class="alert alert-danger alert-modern">
                        ${t.error} ${error.message}
                    </div>
                `;
            }
        }
        
        // Display wallet list
        function displayWalletList(wallets, title) {
            const resultsDiv = document.getElementById('results');
            
            if (wallets.length === 0) {
                resultsDiv.innerHTML = `
                    <div class="action-card text-center">
                        <div class="action-icon icon-info mx-auto">
                            <i class="fas fa-wallet"></i>
                        </div>
                        <h5>${title}</h5>
                        <p class="text-muted">${t.no_wallets_found}</p>
                    </div>
                `;
                return;
            }
            
            let html = `
                <div class="action-card">
                    <h5><i class="fas fa-list me-2"></i>${title}</h5>
                    <div class="row">
            `;
            
            wallets.forEach(wallet => {
                html += `
                    <div class="col-md-6 mb-3">
                        <div class="card border-0 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <small class="text-muted">${t.address}:</small>
                                    <button class="btn btn-sm btn-outline-primary" onclick="copyToClipboard('${wallet.address}')">
                                        <i class="fas fa-copy"></i>
                                    </button>
                                </div>
                                <div class="address-display small mb-2">${wallet.address}</div>
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="fw-bold text-success">${wallet.balance || 0} ${cryptoSymbol}</span>
                                    <button class="btn btn-sm btn-primary" onclick="checkBalance('${wallet.address}')">
                                        <i class="fas fa-refresh me-1"></i>${t.check_balance}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            });
            
            html += '</div></div>';
            resultsDiv.innerHTML = html;
        }
        
        // Show my wallets from localStorage
        function showMyWallets() {
            const wallets = JSON.parse(localStorage.getItem('myWallets') || '[]');
            displayWalletList(wallets, t.my_wallets);
        }
        
        // Add wallet to localStorage
        function addToWalletList(address, privateKey) {
            let wallets = JSON.parse(localStorage.getItem('myWallets') || '[]');
            
            if (wallets.some(w => w.address === address)) {
                showNotification('Wallet already in your list!', 'info');
                return;
            }
            
            wallets.push({
                address: address,
                privateKey: privateKey,
                type: 'saved',
                created: new Date().toISOString(),
                balance: 0
            });
            
            localStorage.setItem('myWallets', JSON.stringify(wallets));
            showNotification('Wallet added to your list!', 'success');
        }
        
        // Check balance
        async function checkBalance(address) {
            const button = event.target;
            const originalText = button.innerHTML;
            button.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>' + t.checking_balance;
            button.disabled = true;
            
            try {
                const response = await fetch('wallet_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'get_balance',
                        address: address
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    showNotification(`${t.balance}: ${data.balance} ${cryptoSymbol}`, 'success');
                    
                    // Update balance in the display
                    const walletCard = button.closest('.card-body');
                    if (walletCard) {
                        const balanceSpan = walletCard.querySelector('.text-success');
                        if (balanceSpan) {
                            balanceSpan.textContent = `${data.balance} ${cryptoSymbol}`;
                        }
                    }
                } else {
                    showNotification(t.error + ' ' + data.error, 'danger');
                }
            } catch (error) {
                showNotification(t.error + ' ' + error.message, 'danger');
            } finally {
                button.innerHTML = originalText;
                button.disabled = false;
            }
        }
    </script>
</body>
</html>
