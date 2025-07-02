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
            'check_balance' => 'Check Balance',
            'transfer_tokens' => 'Transfer Tokens',
            'transfer_tokens_desc' => 'Send tokens to another wallet',
            'stake_tokens' => 'Stake Tokens',
            'stake_tokens_desc' => 'Earn rewards by staking',
            'recipient_address' => 'Recipient Address',
            'transfer_amount' => 'Amount to Transfer',
            'memo_optional' => 'Memo (optional)',
            'memo_placeholder' => 'Enter memo for transfer...',
            'send_transfer' => 'Send Transfer',
            'transfer_successful' => 'Transfer Successful!',
            'transfer_failed' => 'Transfer Failed',
            'staking_period' => 'Staking Period',
            'start_staking' => 'Start Staking',
            'staking_successful' => 'Staking Successful!',
            'staking_failed' => 'Staking Failed',
            'unstake_tokens' => 'Unstake Tokens',
            'unstake_amount' => 'Amount to Unstake',
            'unstake_successful' => 'Unstaking Successful!',
            'view_staking' => 'View Staking',
            'private_key_required' => 'Private key required for transactions',
            'enter_private_key' => 'Enter your private key',
            'insufficient_balance' => 'Insufficient balance',
            'invalid_address' => 'Invalid wallet address',
            'transaction_confirmed' => 'Transaction confirmed in blockchain',
            'rewards_earned' => 'Rewards Earned',
            'total_received' => 'Total Received',
            'days_until_unlock' => 'Days Until Unlock'
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
            'check_balance' => 'Проверить баланс',
            'transfer_tokens' => 'Перевести токены',
            'transfer_tokens_desc' => 'Отправить токены в другой кошелёк',
            'stake_tokens' => 'Заблокировать токены',
            'stake_tokens_desc' => 'Получайте награды через стейкинг',
            'recipient_address' => 'Адрес получателя',
            'transfer_amount' => 'Сумма перевода',
            'memo_optional' => 'Заметка (необязательно)',
            'memo_placeholder' => 'Введите заметку к переводу...',
            'send_transfer' => 'Отправить перевод',
            'transfer_successful' => 'Перевод выполнен!',
            'transfer_failed' => 'Ошибка перевода',
            'staking_period' => 'Период стейкинга',
            'start_staking' => 'Начать стейкинг',
            'staking_successful' => 'Стейкинг успешен!',
            'staking_failed' => 'Ошибка стейкинга',
            'unstake_tokens' => 'Разблокировать токены',
            'unstake_amount' => 'Сумма для разблокировки',
            'unstake_successful' => 'Разблокировка успешна!',
            'view_staking' => 'Просмотр стейкинга',
            'private_key_required' => 'Требуется приватный ключ для транзакций',
            'enter_private_key' => 'Введите ваш приватный ключ',
            'insufficient_balance' => 'Недостаточно средств',
            'invalid_address' => 'Неверный адрес кошелька',
            'transaction_confirmed' => 'Транзакция подтверждена в блокчейне',
            'rewards_earned' => 'Заработанные награды',
            'total_received' => 'Получено всего',
            'days_until_unlock' => 'Дней до разблокировки'
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

    <!-- Transfer Tokens Modal -->
    <div class="modal fade" id="transferModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-paper-plane me-2"></i><?php echo $t['transfer_tokens']; ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info alert-modern">
                        <i class="fas fa-info-circle me-2"></i>
                        <?php echo $t['transfer_tokens_desc']; ?>
                    </div>
                    
                    <form id="transferForm">
                        <div class="mb-3">
                            <label for="fromAddress" class="form-label fw-bold"><?php echo $t['wallet_address']; ?></label>
                            <select class="form-select" id="fromAddress" required>
                                <option value="">Select wallet...</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="toAddress" class="form-label fw-bold"><?php echo $t['recipient_address']; ?></label>
                            <input type="text" class="form-control" id="toAddress" required 
                                   placeholder="Enter recipient wallet address...">
                        </div>
                        
                        <div class="mb-3">
                            <label for="transferAmount" class="form-label fw-bold"><?php echo $t['transfer_amount']; ?></label>
                            <div class="input-group">
                                <input type="number" class="form-control" id="transferAmount" 
                                       step="0.01" min="0.01" required>
                                <span class="input-group-text" id="transferSymbol">COIN</span>
                            </div>
                            <div class="form-text">Available: <span id="availableBalance">0</span> <span id="availableSymbol">COIN</span></div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="transferMemo" class="form-label fw-bold"><?php echo $t['memo_optional']; ?></label>
                            <textarea class="form-control" id="transferMemo" rows="2" 
                                      placeholder="<?php echo $t['memo_placeholder']; ?>"></textarea>
                            <div class="form-text">
                                <i class="fas fa-lock text-success me-1"></i>
                                Messages are automatically encrypted for security
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="transferPrivateKey" class="form-label fw-bold"><?php echo $t['private_key']; ?></label>
                            <input type="password" class="form-control" id="transferPrivateKey" required 
                                   placeholder="<?php echo $t['enter_private_key']; ?>">
                        </div>
                    </form>
                    
                    <div id="transferResult"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-modern" data-bs-dismiss="modal"><?php echo $t['cancel']; ?></button>
                    <button type="button" class="btn btn-success btn-modern" onclick="executeTransfer()" id="transferBtn">
                        <i class="fas fa-paper-plane me-2"></i><?php echo $t['send_transfer']; ?>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Staking Modal -->
    <div class="modal fade" id="stakingModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-coins me-2"></i><?php echo $t['stake_tokens']; ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info alert-modern">
                        <i class="fas fa-info-circle me-2"></i>
                        <?php echo $t['stake_tokens_desc']; ?>
                    </div>
                    
                    <form id="stakingForm">
                        <div class="mb-3">
                            <label for="stakingAddress" class="form-label fw-bold"><?php echo $t['wallet_address']; ?></label>
                            <select class="form-select" id="stakingAddress" required>
                                <option value="">Select wallet...</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="stakingAmount" class="form-label fw-bold"><?php echo $t['stake_amount']; ?></label>
                            <div class="input-group">
                                <input type="number" class="form-control" id="stakingAmount" 
                                       step="0.01" min="100" required>
                                <span class="input-group-text" id="stakingSymbol">COIN</span>
                            </div>
                            <div class="form-text"><?php echo $t['min_amount']; ?> 100 COIN</div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="stakingPeriod" class="form-label fw-bold"><?php echo $t['staking_period']; ?></label>
                            <select class="form-select" id="stakingPeriod" required>
                                <option value="7">7 <?php echo $t['days_apy']; ?>4%)</option>
                                <option value="30">30 <?php echo $t['days_apy']; ?>6%)</option>
                                <option value="90">90 <?php echo $t['days_apy']; ?>8%)</option>
                                <option value="180">180 <?php echo $t['days_apy']; ?>10%)</option>
                                <option value="365">365 <?php echo $t['days_apy']; ?>12%)</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="stakingPrivateKey" class="form-label fw-bold"><?php echo $t['private_key']; ?></label>
                            <input type="password" class="form-control" id="stakingPrivateKey" required 
                                   placeholder="<?php echo $t['enter_private_key']; ?>">
                        </div>
                        
                        <div id="stakingPreview" class="alert alert-success alert-modern" style="display: none;">
                            <h6>Staking Preview:</h6>
                            <div class="row">
                                <div class="col-6">Amount: <span id="previewAmount">0</span> COIN</div>
                                <div class="col-6">Period: <span id="previewPeriod">0</span> days</div>
                                <div class="col-6">APY: <span id="previewAPY">0</span>%</div>
                                <div class="col-6">Expected Rewards: <span id="previewRewards">0</span> COIN</div>
                            </div>
                        </div>
                    </form>
                    
                    <div id="stakingResult"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-modern" data-bs-dismiss="modal"><?php echo $t['cancel']; ?></button>
                    <button type="button" class="btn btn-warning btn-modern" onclick="executeStaking()" id="stakingBtn">
                        <i class="fas fa-coins me-2"></i><?php echo $t['start_staking']; ?>
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
                    
                    // Update all symbol displays
                    updateSymbolDisplays(cryptoSymbol);
                    
                    console.log('Config loaded:', data.config);
                } else {
                    console.log('Config load failed, using defaults');
                }
            } catch (error) {
                console.log('Config load failed, using defaults:', error);
            }
        }
        
        // Update all symbol displays in the interface
        function updateSymbolDisplays(symbol) {
            // Update transfer modal
            const transferSymbol = document.getElementById('transferSymbol');
            if (transferSymbol) transferSymbol.textContent = symbol;
            
            const availableSymbol = document.getElementById('availableSymbol');
            if (availableSymbol) availableSymbol.textContent = symbol;
            
            // Update staking modal
            const stakingSymbol = document.getElementById('stakingSymbol');
            if (stakingSymbol) stakingSymbol.textContent = symbol;
            
            // Update all static symbol references
            document.querySelectorAll('.crypto-symbol').forEach(el => {
                el.textContent = symbol;
            });
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
                    showRestoreResult(data.wallet, data.verification);
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
        function showRestoreResult(wallet, verification = {}) {
            const isActive = verification?.exists_in_blockchain || false;
            const txCount = verification?.transaction_count || 0;
            const statusIcon = isActive ? 'check-circle text-success' : 'info-circle text-warning';
            const statusText = isActive ? 'Active in blockchain' : 'Ready for activation';
            
            let activationButton = '';
            if (!isActive) {
                activationButton = `
                    <button class="btn btn-warning btn-modern me-2" onclick="activateWallet('${wallet.address}', '${wallet.public_key}')">
                        <i class="fas fa-play me-2"></i>Activate in Blockchain
                    </button>
                `;
            }
            
            document.getElementById('restoreResult').innerHTML = `
                <div class="alert alert-success alert-modern text-center">
                    <i class="fas fa-download fa-3x mb-3"></i>
                    <h4>${t.wallet_restored}</h4>
                    <div class="mt-2">
                        <i class="fas fa-${statusIcon} me-2"></i>
                        <span class="fw-bold">${statusText}</span>
                    </div>
                    ${txCount > 0 ? `<small class="text-muted">Found ${txCount} transaction(s)</small>` : ''}
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
                            ${wallet.staked_balance > 0 ? `<br><small class="text-info">Staked: ${wallet.staked_balance} ${cryptoSymbol}</small>` : ''}
                        </div>
                    </div>
                    
                    ${wallet.note ? `
                    <div class="alert alert-info alert-modern mb-3">
                        <i class="fas fa-info-circle me-2"></i>
                        ${wallet.note}
                    </div>
                    ` : ''}
                    
                    <div class="text-center">
                        ${activationButton}
                        <button class="btn btn-primary btn-modern" onclick="addToWalletList('${wallet.address}', '${wallet.private_key}')">
                            <i class="fas fa-save me-2"></i>${t.save_wallet}
                        </button>
                    </div>
                </div>
            `;
        }
        
        // Activate wallet in blockchain
        async function activateWallet(address, publicKey) {
            const button = event.target;
            const originalText = button.innerHTML;
            button.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Activating...';
            button.disabled = true;
            
            try {
                const response = await fetch('wallet_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'activate_restored_wallet',
                        address: address,
                        public_key: publicKey
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    if (data.data.already_active) {
                        showNotification('Wallet is already active in blockchain', 'info');
                    } else if (data.data.activated) {
                        showNotification('Wallet successfully activated in blockchain!', 'success');
                        // Refresh the display
                        button.style.display = 'none';
                        // Update status indicator
                        const statusElements = document.querySelectorAll('.fa-info-circle.text-warning');
                        statusElements.forEach(el => {
                            el.className = 'fas fa-check-circle text-success me-2';
                            el.nextSibling.textContent = 'Active in blockchain';
                        });
                    }
                } else {
                    showNotification('Error: ' + data.error, 'danger');
                }
            } catch (error) {
                showNotification('Error: ' + error.message, 'danger');
            } finally {
                button.innerHTML = originalText;
                button.disabled = false;
            }
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
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="fw-bold text-success">${wallet.balance || 0} ${cryptoSymbol}</span>
                                    <button class="btn btn-sm btn-primary" onclick="checkBalance('${wallet.address}')">
                                        <i class="fas fa-refresh me-1"></i>${t.check_balance}
                                    </button>
                                </div>
                                ${wallet.staked_balance > 0 ? `
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <small class="text-info">Staked: ${wallet.staked_balance} ${cryptoSymbol}</small>
                                    </div>
                                ` : ''}
                                <div class="btn-group w-100 mt-2" role="group">
                                    <button class="btn btn-sm btn-success" onclick="showTransferModal('${wallet.address}')" title="${t.transfer_tokens}">
                                        <i class="fas fa-paper-plane"></i>
                                    </button>
                                    <button class="btn btn-sm btn-warning" onclick="showStakingModal('${wallet.address}')" title="${t.stake_tokens}">
                                        <i class="fas fa-coins"></i>
                                    </button>
                                    <button class="btn btn-sm btn-info" onclick="showStakingInfo('${wallet.address}')" title="${t.view_staking}">
                                        <i class="fas fa-chart-line"></i>
                                    </button>
                                    <button class="btn btn-sm btn-secondary" onclick="showTransactionHistory('${wallet.address}')" title="Transaction History">
                                        <i class="fas fa-history"></i>
                                    </button>
                                    ${wallet.type === 'saved' ? `
                                        <button class="btn btn-sm btn-danger" onclick="deleteWalletFromList('${wallet.address}')" title="Delete Wallet">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    ` : ''}
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
            // Mark all wallets as 'saved' type for display
            const walletsWithType = wallets.map(wallet => ({
                ...wallet,
                type: 'saved'
            }));
            displayWalletList(walletsWithType, t.my_wallets);
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
                    // Правильно извлекаем данные баланса
                    const balanceData = data.balance;
                    const availableBalance = balanceData.available || 0;
                    const stakedBalance = balanceData.staked || 0;
                    const totalBalance = balanceData.total || 0;
                    
                    showNotification(`${t.balance}: ${totalBalance} ${cryptoSymbol} (Available: ${availableBalance}, Staked: ${stakedBalance})`, 'success');
                    
                    // Update balance in the display
                    const walletCard = button.closest('.card-body');
                    if (walletCard) {
                        const balanceSpan = walletCard.querySelector('.text-success');
                        if (balanceSpan) {
                            balanceSpan.textContent = `${totalBalance} ${cryptoSymbol}`;
                        }
                        
                        // Update or add staked balance display
                        let stakedDiv = walletCard.querySelector('.staked-balance');
                        if (stakedBalance > 0) {
                            if (!stakedDiv) {
                                stakedDiv = document.createElement('div');
                                stakedDiv.className = 'staked-balance d-flex justify-content-between align-items-center mb-2';
                                balanceSpan.parentElement.insertAdjacentElement('afterend', stakedDiv);
                            }
                            stakedDiv.innerHTML = `<small class="text-info">Staked: ${stakedBalance} ${cryptoSymbol}</small>`;
                        } else if (stakedDiv) {
                            stakedDiv.remove();
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
        
        // Show transfer modal
        function showTransferModal(fromAddress = '') {
            const modal = new bootstrap.Modal(document.getElementById('transferModal'));
            modal.show();
            
            // Clear previous data
            document.getElementById('transferForm').reset();
            document.getElementById('transferResult').innerHTML = '';
            
            // Populate wallet selector
            populateWalletSelector('fromAddress', fromAddress);
            
            // Update available balance when from address changes
            document.getElementById('fromAddress').addEventListener('change', updateAvailableBalance);
        }
        
        // Show staking modal
        function showStakingModal(address = '') {
            const modal = new bootstrap.Modal(document.getElementById('stakingModal'));
            modal.show();
            
            // Clear previous data
            document.getElementById('stakingForm').reset();
            document.getElementById('stakingResult').innerHTML = '';
            document.getElementById('stakingPreview').style.display = 'none';
            
            // Populate wallet selector
            populateWalletSelector('stakingAddress', address);
            
            // Add event listeners for preview
            document.getElementById('stakingAmount').addEventListener('input', updateStakingPreview);
            document.getElementById('stakingPeriod').addEventListener('change', updateStakingPreview);
        }
        
        // Populate wallet selector
        function populateWalletSelector(selectId, selectedAddress = '') {
            const select = document.getElementById(selectId);
            const myWallets = JSON.parse(localStorage.getItem('myWallets') || '[]');
            
            select.innerHTML = '<option value="">Select wallet...</option>';
            
            myWallets.forEach(wallet => {
                const option = document.createElement('option');
                option.value = wallet.address;
                option.textContent = `${wallet.address.substring(0, 20)}... (${wallet.balance || 0} ${cryptoSymbol})`;
                option.setAttribute('data-private-key', wallet.privateKey);
                if (wallet.address === selectedAddress) {
                    option.selected = true;
                }
                select.appendChild(option);
            });
            
            // Update balance if address is pre-selected
            if (selectedAddress) {
                updateAvailableBalance.call(select);
            }
        }
        
        // Update available balance
        async function updateAvailableBalance() {
            const address = this.value;
            if (!address) return;
            
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
                    // Правильно извлекаем available balance из nested объекта
                    const balanceData = data.balance;
                    const availableBalance = balanceData.available || 0;
                    const stakedBalance = balanceData.staked || 0;
                    
                    document.getElementById('availableBalance').textContent = availableBalance;
                    document.getElementById('availableSymbol').textContent = cryptoSymbol;
                    
                    // Также обновляем информацию о стейкинге если есть
                    const stakedElement = document.getElementById('stakedBalance');
                    if (stakedElement) {
                        stakedElement.textContent = stakedBalance;
                    }
                }
            } catch (error) {
                console.error('Failed to get balance:', error);
            }
        }
        
        // Update staking preview
        function updateStakingPreview() {
            const amount = parseFloat(document.getElementById('stakingAmount').value) || 0;
            const period = parseInt(document.getElementById('stakingPeriod').value) || 0;
            
            if (amount > 0 && period > 0) {
                // Calculate APY based on period
                let apy;
                if (period >= 365) apy = 12.0;
                else if (period >= 180) apy = 10.0;
                else if (period >= 90) apy = 8.0;
                else if (period >= 30) apy = 6.0;
                else apy = 4.0;
                
                const expectedRewards = amount * (apy / 100) * (period / 365);
                
                // Update preview
                document.getElementById('previewAmount').textContent = amount;
                document.getElementById('previewPeriod').textContent = period;
                document.getElementById('previewAPY').textContent = apy;
                document.getElementById('previewRewards').textContent = expectedRewards.toFixed(4);
                document.getElementById('stakingPreview').style.display = 'block';
            } else {
                document.getElementById('stakingPreview').style.display = 'none';
            }
        }
        
        // Execute transfer
        async function executeTransfer() {
            const fromAddress = document.getElementById('fromAddress').value;
            const toAddress = document.getElementById('toAddress').value;
            const amount = parseFloat(document.getElementById('transferAmount').value);
            const memo = document.getElementById('transferMemo').value;
            const encryptMemo = true; // Always encrypt messages
            const privateKey = document.getElementById('transferPrivateKey').value;
            
            if (!fromAddress || !toAddress || !amount || !privateKey) {
                showNotification('Please fill all required fields', 'danger');
                return;
            }
            
            const button = document.getElementById('transferBtn');
            const originalText = button.innerHTML;
            button.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Processing...';
            button.disabled = true;
            
            try {
                const response = await fetch('wallet_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'transfer_tokens',
                        from_address: fromAddress,
                        to_address: toAddress,
                        amount: amount,
                        private_key: privateKey,
                        memo: memo,
                        encrypt_memo: encryptMemo
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    document.getElementById('transferResult').innerHTML = `
                        <div class="alert alert-success alert-modern">
                            <i class="fas fa-check fa-2x mb-2"></i>
                            <h5>${t.transfer_successful}</h5>
                            <p>Transaction Hash: <code>${data.transaction.hash}</code></p>
                            <p>Amount: ${amount} ${cryptoSymbol}</p>
                            <p>Fee: ${data.transaction.fee} ${cryptoSymbol}</p>
                            ${data.blockchain.recorded ? '<p class="text-success">✅ Recorded in blockchain</p>' : ''}
                        </div>
                    `;
                    showNotification(t.transfer_successful, 'success');
                    
                    // Clear form
                    document.getElementById('transferForm').reset();
                } else {
                    showNotification(t.transfer_failed + ': ' + data.error, 'danger');
                }
            } catch (error) {
                showNotification(t.error + ' ' + error.message, 'danger');
            } finally {
                button.innerHTML = originalText;
                button.disabled = false;
            }
        }
        
        // Execute staking
        async function executeStaking() {
            const address = document.getElementById('stakingAddress').value;
            const amount = parseFloat(document.getElementById('stakingAmount').value);
            const period = parseInt(document.getElementById('stakingPeriod').value);
            const privateKey = document.getElementById('stakingPrivateKey').value;
            
            if (!address || !amount || !period || !privateKey) {
                showNotification('Please fill all required fields', 'danger');
                return;
            }
            
            const button = document.getElementById('stakingBtn');
            const originalText = button.innerHTML;
            button.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Processing...';
            button.disabled = true;
            
            try {
                const response = await fetch('wallet_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'stake_tokens_new',
                        address: address,
                        amount: amount,
                        period: period,
                        private_key: privateKey
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    document.getElementById('stakingResult').innerHTML = `
                        <div class="alert alert-success alert-modern">
                            <i class="fas fa-coins fa-2x mb-2"></i>
                            <h5>${t.staking_successful}</h5>
                            <p>Amount Staked: ${data.staking_info.amount} ${cryptoSymbol}</p>
                            <p>Period: ${data.staking_info.period} days</p>
                            <p>APY: ${data.staking_info.apy}%</p>
                            <p>Expected Rewards: ${data.staking_info.expected_rewards.toFixed(4)} ${cryptoSymbol}</p>
                            <p>Unlock Date: ${data.staking_info.unlock_date}</p>
                            ${data.blockchain.recorded ? '<p class="text-success">✅ Recorded in blockchain</p>' : ''}
                        </div>
                    `;
                    showNotification(t.staking_successful, 'success');
                    
                    // Clear form
                    document.getElementById('stakingForm').reset();
                    document.getElementById('stakingPreview').style.display = 'none';
                } else {
                    showNotification(t.staking_failed + ': ' + data.error, 'danger');
                }
            } catch (error) {
                showNotification(t.error + ' ' + error.message, 'danger');
            } finally {
                button.innerHTML = originalText;
                button.disabled = false;
            }
        }
        
        // Show staking information
        async function showStakingInfo(address) {
            try {
                const response = await fetch('wallet_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'get_staking_info',
                        address: address
                    })
                });
                
                const data = await response.json();
                console.log('Staking info response:', data); // Debug log
                
                if (data.success) {
                    const stakingInfo = data.staking_info || {};
                    
                    // Безопасно извлекаем значения с fallback
                    const totalStaked = stakingInfo.total_staked || 0;
                    const totalRewards = stakingInfo.total_rewards_earning || 0;
                    const unlockedAmount = stakingInfo.unlocked_amount || 0;
                    const stakingAvailable = stakingInfo.staking_available || 0;
                    const activeStakes = stakingInfo.active_stakes || [];
                    
                    let stakingHtml = `
                        <div class="action-card">
                            <h5><i class="fas fa-chart-line me-2"></i>Staking Information - ${address.substring(0, 20)}...</h5>
                            
                            <div class="row mb-4">
                                <div class="col-md-3">
                                    <div class="text-center">
                                        <h6 class="text-primary">Total Staked</h6>
                                        <h4 class="text-success">${totalStaked} ${cryptoSymbol}</h4>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="text-center">
                                        <h6 class="text-primary">Expected Rewards</h6>
                                        <h4 class="text-warning">${totalRewards.toFixed(4)} ${cryptoSymbol}</h4>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="text-center">
                                        <h6 class="text-primary">Unlocked Amount</h6>
                                        <h4 class="text-info">${unlockedAmount} ${cryptoSymbol}</h4>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="text-center">
                                        <h6 class="text-primary">Available to Stake</h6>
                                        <h4 class="text-secondary">${stakingAvailable} ${cryptoSymbol}</h4>
                                    </div>
                                </div>
                            </div>
                            
                            <h6>Active Stakes:</h6>
                            <div class="row">
                    `;
                    
                    if (activeStakes.length > 0) {
                        activeStakes.forEach(stake => {
                            const isUnlocked = stake.lock_status === 'unlocked' || stake.lock_status === 'pending';
                            const statusClass = isUnlocked ? 'success' : 'warning';
                            const statusIcon = isUnlocked ? 'unlock' : 'lock';
                            
                            stakingHtml += `
                                <div class="col-md-6 mb-3">
                                    <div class="card border-${statusClass}">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-center mb-2">
                                                <h6 class="mb-0">${stake.amount || 0} ${cryptoSymbol}</h6>
                                                <span class="badge bg-${statusClass}">
                                                    <i class="fas fa-${statusIcon} me-1"></i>${stake.lock_status || 'active'}
                                                </span>
                                            </div>
                                            <p class="mb-1">Reward Rate: ${((stake.reward_rate || stake.apy || 0) * 100).toFixed(2)}%</p>
                                            <p class="mb-1">Expected Rewards: ${stake.rewards_earned || 0} ${cryptoSymbol}</p>
                                            <p class="mb-1">Created: ${stake.created_at || 'N/A'}</p>
                                            <p class="mb-0">
                                                ${isUnlocked ? 
                                                    'Ready to unstake!' : 
                                                    `Unlock date: ${stake.unlock_date || 'N/A'}`
                                                }
                                            </p>
                                            ${isUnlocked ? `
                                                <button class="btn btn-sm btn-success mt-2" onclick="showUnstakeModal('${address}', ${stake.amount || 0})">
                                                    <i class="fas fa-unlock me-1"></i>Unstake
                                                </button>
                                            ` : ''}
                                        </div>
                                    </div>
                                </div>
                            `;
                        });
                    } else {
                        stakingHtml += '<div class="col-12"><p class="text-muted">No active stakes found.</p></div>';
                    }
                    
                    stakingHtml += '</div></div>';
                    
                    document.getElementById('results').innerHTML = stakingHtml;
                } else {
                    showNotification('Failed to load staking info: ' + data.error, 'danger');
                }
            } catch (error) {
                showNotification('Error: ' + error.message, 'danger');
            }
        }
        
        // Show unstake modal (simplified - you can create a full modal like others)
        function showUnstakeModal(address, maxAmount) {
            const amount = prompt(`Enter amount to unstake (max: ${maxAmount} ${cryptoSymbol}):`);
            if (amount && parseFloat(amount) > 0) {
                const privateKey = prompt('Enter your private key:');
                if (privateKey) {
                    executeUnstake(address, parseFloat(amount), privateKey);
                }
            }
        }
        
        // Execute unstaking
        async function executeUnstake(address, amount, privateKey) {
            try {
                const response = await fetch('wallet_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'unstake_tokens',
                        address: address,
                        amount: amount,
                        private_key: privateKey
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    showNotification(`${t.unstake_successful} Received: ${data.total_received} ${cryptoSymbol}`, 'success');
                    // Refresh staking info
                    showStakingInfo(address);
                } else {
                    showNotification('Unstaking failed: ' + data.error, 'danger');
                }
            } catch (error) {
                showNotification('Error: ' + error.message, 'danger');
            }
        }
        
        // Show transaction history with encrypted message support
        async function showTransactionHistory(address) {
            try {
                const response = await fetch('wallet_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'get_transaction_history',
                        address: address
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    displayTransactionHistory(data.transactions, address);
                } else {
                    showNotification('Failed to load transaction history: ' + data.error, 'danger');
                }
            } catch (error) {
                showNotification('Error: ' + error.message, 'danger');
            }
        }
        
        // Display transaction history
        function displayTransactionHistory(transactions, address) {
            const resultsDiv = document.getElementById('results');
            
            let html = `
                <div class="action-card">
                    <h5><i class="fas fa-history me-2"></i>Transaction History - ${address.substring(0, 20)}...</h5>
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Type</th>
                                    <th>From/To</th>
                                    <th>Amount</th>
                                    <th>Message</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
            `;
            
            if (transactions.length === 0) {
                html += '<tr><td colspan="6" class="text-center text-muted">No transactions found</td></tr>';
            } else {
                transactions.forEach(tx => {
                    const isIncoming = tx.to_address === address;
                    const direction = isIncoming ? 'Received from' : 'Sent to';
                    const otherAddress = isIncoming ? tx.from_address : tx.to_address;
                    const amountClass = isIncoming ? 'text-success' : 'text-danger';
                    const amountSign = isIncoming ? '+' : '-';
                    
                    const hasEncryptedMemo = tx.memo && tx.memo.startsWith('ENCRYPTED:');
                    const memoDisplay = hasEncryptedMemo ? 
                        '<i class="fas fa-lock text-warning" title="Encrypted message"></i> Encrypted' : 
                        (tx.memo || 'No message');
                    
                    html += `
                        <tr>
                            <td>${new Date(tx.timestamp * 1000).toLocaleDateString()}</td>
                            <td>${direction}</td>
                            <td>${otherAddress.substring(0, 15)}...</td>
                            <td class="${amountClass}">${amountSign}${tx.amount} ${cryptoSymbol}</td>
                            <td>${memoDisplay}</td>
                            <td>
                                ${hasEncryptedMemo ? `
                                    <button class="btn btn-sm btn-warning" onclick="decryptTransactionMessage('${tx.hash}', '${address}')">
                                        <i class="fas fa-unlock"></i>
                                    </button>
                                ` : ''}
                                <button class="btn btn-sm btn-info" onclick="copyToClipboard('${tx.hash}')">
                                    <i class="fas fa-copy"></i>
                                </button>
                            </td>
                        </tr>
                    `;
                });
            }
            
            html += `
                            </tbody>
                        </table>
                    </div>
                </div>
            `;
            
            resultsDiv.innerHTML = html;
        }
        
        // Decrypt transaction message
        async function decryptTransactionMessage(txHash, walletAddress) {
            const privateKey = prompt('Enter your private key to decrypt the message:');
            if (!privateKey) return;
            
            try {
                const response = await fetch('wallet_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'decrypt_transaction_message',
                        tx_hash: txHash,
                        wallet_address: walletAddress,
                        private_key: privateKey
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    if (data.decrypted) {
                        alert(`Decrypted message: ${data.message}`);
                    } else {
                        alert(`Message: ${data.message}`);
                    }
                } else {
                    showNotification('Failed to decrypt message: ' + data.error, 'danger');
                }
            } catch (error) {
                showNotification('Error: ' + error.message, 'danger');
            }
        }
        
        // Delete wallet from local list
        function deleteWalletFromList(address) {
            if (!confirm('Are you sure you want to remove this wallet from your saved list?\n\nNote: This only removes it from your browser storage, the wallet will still exist in the blockchain.')) {
                return;
            }
            
            let wallets = JSON.parse(localStorage.getItem('myWallets') || '[]');
            wallets = wallets.filter(w => w.address !== address);
            localStorage.setItem('myWallets', JSON.stringify(wallets));
            
            showNotification('Wallet removed from your list', 'success');
            
            // Refresh the display if currently showing my wallets
            const resultsDiv = document.getElementById('results');
            if (resultsDiv.innerHTML.includes('My Wallets')) {
                showMyWallets();
            }
        }
        
        // Delete wallet completely (from blockchain) - requires confirmation
        async function deleteWalletCompletely(address, privateKey) {
            // First check if wallet has any balance or staked tokens
            if (!confirm('WARNING: This will permanently delete the wallet from the blockchain!\n\nThis action cannot be undone. Are you absolutely sure?')) {
                return;
            }
            
            const finalConfirm = prompt('Type "DELETE" to confirm permanent wallet deletion:');
            if (finalConfirm !== 'DELETE') {
                showNotification('Wallet deletion cancelled', 'info');
                return;
            }
            
            try {
                const response = await fetch('wallet_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'delete_wallet',
                        address: address,
                        private_key: privateKey
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    showNotification('Wallet permanently deleted from blockchain', 'success');
                    
                    // Also remove from local storage
                    deleteWalletFromList(address);
                    
                    // Refresh current view
                    const resultsDiv = document.getElementById('results');
                    if (resultsDiv.innerHTML.includes('All Wallets')) {
                        listWallets();
                    } else if (resultsDiv.innerHTML.includes('My Wallets')) {
                        showMyWallets();
                    }
                } else {
                    showNotification('Failed to delete wallet: ' + data.error, 'danger');
                }
            } catch (error) {
                showNotification('Error: ' + error.message, 'danger');
            }
        }
    </script>
</body>
</html>
