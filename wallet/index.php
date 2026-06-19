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
            'transaction_history' => 'Transaction History',
            'show_transaction_history' => 'View transaction history',
            'decrypt_message' => 'Decrypt Message',
            'encrypted_message' => 'Encrypted Message',
            'no_transactions_found' => 'No transactions found',
            'transaction_details' => 'Transaction Details',
            'select_wallet_for_history' => 'Select wallet to view history',
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
            'click_word_to_copy' => 'Tip: Click on any word to copy it individually',
            'all_types' => 'All Types',
            'sent' => 'Sent',
            'received' => 'Received',
            'type_filter' => 'Type Filter',
            'clear' => 'Clear',
            'details' => 'Details',
            'transaction_details' => 'Transaction Details',
            'loading_transactions' => 'Loading transactions...',
            'error_loading_transactions' => 'Error loading transactions',
            'no_transaction_history' => 'No transaction history found for this wallet',
            'transactions' => 'transactions',
            'transaction_hash' => 'Transaction Hash',
            'from_address' => 'From Address',
            'to_address' => 'To Address',
            'amount' => 'Amount',
            'fee' => 'Transaction Fee',
            'timestamp' => 'Date & Time',
            'block_height' => 'Block Height',
            'confirmations' => 'Confirmations',
            'status' => 'Status',
            'memo' => 'Memo',
            'encrypted_message' => 'Encrypted Message',
            'decrypt_message' => 'Decrypt Message',
            'enter_private_key_decrypt' => 'Enter your private key to decrypt the message',
            'decrypted_message' => 'Decrypted Message',
            'decrypt' => 'Decrypt',
            'decryption_failed' => 'Decryption failed',
            'page' => 'Page',
            'of' => 'of',
            'showing' => 'Showing',
            'to' => 'to',
            'entries' => 'entries',
            'previous' => 'Previous',
            'next' => 'Next',
            'per_page' => 'Per page',
            'view_details' => 'View Details',
            'decrypting' => 'Decrypting...',
            'message_decrypted' => 'Message decrypted successfully',
            'transaction_not_found' => 'Transaction not found',
            'pending' => 'Pending',
            'confirmed' => 'Confirmed',
            'unconfirmed' => 'Unconfirmed',
            'from' => 'From',
            'to' => 'To',
            'has_encrypted_message' => 'Has encrypted message',
            'click_to_decrypt' => 'Click envelope to decrypt',
            'blockchain_info' => 'Blockchain Information',
            'blockchain_info_description' => 'View blockchain network status and statistics',
            'smart_contracts' => 'Smart Contracts',
            'smart_contracts_description' => 'Deploy and interact with smart contracts',
            'settings' => 'Settings',
            'settings_description' => 'Configure wallet and network settings',
            'feature_coming_soon' => 'This feature is coming soon!',
            'message_encrypted' => 'This message is encrypted. Enter your private key to decrypt it.',
            'block' => 'Block',
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
            'memo_too_long' => 'Message is too long. Maximum 1000 characters allowed.',
            'send_transfer' => 'Send Transfer',
            'transfer_successful' => 'Transfer Successful!',
            'transfer_failed' => 'Transfer Failed',
            'staking_period' => 'Staking Period',
            'staking_preview' => 'Staking Preview',
            'days' => 'days',
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
            'staking_information' => 'Staking Information',
            'total_staked' => 'Total Staked',
            'unlocked_amount' => 'Unlocked Amount',
            'available_to_stake' => 'Available to Stake',
            'active_stakes' => 'Active Stakes',
            'reward_rate' => 'Reward Rate',
            'unlimited_staking' => 'Unlimited staking - can unstake anytime',
            'created' => 'Created',
            'ready_to_unstake' => 'Ready to unstake!',
            'unlock_date' => 'Unlock date',
            'days_until_unlock' => 'Days Until Unlock',
            'about' => 'About',
            'about_description' => 'About this blockchain wallet',
            'about_info' => 'Secure blockchain wallet for managing your digital assets',
            'settings' => 'Settings',
            'settings_description' => 'Configure wallet and network settings',
            'feature_coming_soon' => 'This feature is coming soon!',
            'confirm_delete_wallet' => 'Are you sure you want to delete this wallet from your saved list?',
            'wallet_deleted' => 'Wallet deleted from saved list',
            'wallet_saved' => 'Wallet saved successfully',
            'wallet_loaded' => 'Wallet loaded successfully',
            'wallet_not_found' => 'Wallet not found',
            'delete_wallet' => 'Delete Wallet',
            'load_wallet' => 'Load Wallet',
            
            // Additional staking translations
            'active_stakes' => 'Active Stakes',
            'expected_rewards' => 'Expected Rewards',
            'reward_rate' => 'Reward Rate',
            'created' => 'Created',
            'unlock_date' => 'Unlock Date',
            'ready_to_unstake' => 'Ready to unstake!',
            'unlimited_staking' => 'Unlimited staking - can unstake anytime',
            'no_active_stakes' => 'No active stakes found.',
            'unstake_now' => 'Unstake Now',
            'unstake' => 'Unstake',
            'add_liquidity' => 'Add Liquidity',
            'add_liquidity_desc' => 'Provide liquidity and earn fees',
            'defi_liquidity' => 'DeFi Liquidity',
            'token_a' => 'Token A',
            'token_b' => 'Token B',
            'amount_a' => 'Amount A',
            'amount_b' => 'Amount B',
            'select_token' => 'Select Token',
            'main_token' => 'Main Token',
            'wrapped_token' => 'Wrapped Token',
            'slippage_tolerance' => 'Slippage Tolerance',
            'add_to_pool' => 'Add to Pool',
            'liquidity_pools' => 'Liquidity Pools',
            'your_positions' => 'Your Positions',
            'pool_share' => 'Pool Share',
            'estimated_apy' => 'Estimated APY',
            'connect_metamask' => 'Connect MetaMask',
            'metamask_required' => 'MetaMask Required',
            'loading_pools' => 'Loading pools...',
            'loading_positions' => 'Loading your positions...',
            'no_positions_found' => 'No positions found',
            'remove_liquidity' => 'Remove Liquidity',
            'total_liquidity' => 'Total Liquidity',
            'pool_tokens' => 'Pool Tokens',
            'pool_reserves' => 'Reserves',
            'add_to_existing' => 'Add to Pool'
        ],
        'ru' => [
            'title' => 'Blockchain Кошелёк',
            'subtitle' => 'Управляйте своими цифровыми активами',
            'create_wallet' => 'Создать кошелёк',
            'create_new_wallet' => 'Создать новый кошелёк',
            'restore_wallet' => 'Восстановить кошелёк',
            'restore_wallet_desc' => 'Восстановить из сид-фразы',
            'transaction_history' => 'История транзакций',
            'show_transaction_history' => 'Просмотр истории транзакций',
            'decrypt_message' => 'Расшифровать сообщение',
            'encrypted_message' => 'Зашифрованное сообщение',
            'no_transactions_found' => 'Транзакции не найдены',
            'transaction_details' => 'Детали транзакции',
            'select_wallet_for_history' => 'Выберите кошелек для просмотра истории',
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
            'click_word_to_copy' => 'Подсказка: Нажмите на любое слово, чтобы скопировать его',
            'all_types' => 'Все типы',
            'sent' => 'Отправлено',
            'received' => 'Получено',
            'type_filter' => 'Фильтр по типу',
            'clear' => 'Очистить',
            'details' => 'Подробности',
            'transaction_details' => 'Детали транзакции',
            'loading_transactions' => 'Загрузка транзакций...',
            'error_loading_transactions' => 'Ошибка загрузки транзакций',
            'no_transaction_history' => 'История транзакций для этого кошелька не найдена',
            'transactions' => 'транзакций',
            'transaction_hash' => 'Хеш транзакции',
            'from_address' => 'Адрес отправителя',
            'to_address' => 'Адрес получателя',
            'amount' => 'Сумма',
            'fee' => 'Комиссия',
            'timestamp' => 'Дата и время',
            'block_height' => 'Высота блока',
            'confirmations' => 'Подтверждения',
            'status' => 'Статус',
            'memo' => 'Заметка',
            'encrypted_message' => 'Зашифрованное сообщение',
            'decrypt_message' => 'Расшифровать сообщение',
            'enter_private_key_decrypt' => 'Введите ваш приватный ключ для расшифровки сообщения',
            'decrypted_message' => 'Расшифрованное сообщение',
            'decrypt' => 'Расшифровать',
            'decryption_failed' => 'Расшифровка не удалась',
            'page' => 'Страница',
            'of' => 'из',
            'showing' => 'Показано',
            'to' => 'до',
            'entries' => 'записей',
            'previous' => 'Предыдущая',
            'next' => 'Следующая',
            'per_page' => 'На странице',
            'view_details' => 'Подробнее',
            'decrypting' => 'Расшифровка...',
            'message_decrypted' => 'Сообщение успешно расшифровано',
            'transaction_not_found' => 'Транзакция не найдена',
            'pending' => 'Ожидает',
            'confirmed' => 'Подтверждено',
            'unconfirmed' => 'Не подтверждено',
            'from' => 'От',
            'to' => 'К',
            'has_encrypted_message' => 'Содержит зашифрованное сообщение',
            'click_to_decrypt' => 'Нажмите конверт для расшифровки',
            'blockchain_info' => 'Информация о блокчейне',
            'blockchain_info_description' => 'Просмотр статуса и статистики сети блокчейн',
            'smart_contracts' => 'Смарт-контракты',
            'smart_contracts_description' => 'Развертывание и взаимодействие со смарт-контрактами',
            'settings' => 'Настройки',
            'settings_description' => 'Настройка кошелька и параметров сети',
            'feature_coming_soon' => 'Эта функция скоро появится!',
            'message_encrypted' => 'Это сообщение зашифровано. Введите ваш приватный ключ для расшифровки.',
            'block' => 'Блок',
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
            'memo_too_long' => 'Сообщение слишком длинное. Максимум 1000 символов.',
            'send_transfer' => 'Отправить перевод',
            'transfer_successful' => 'Перевод выполнен!',
            'transfer_failed' => 'Ошибка перевода',
            'staking_period' => 'Период стейкинга',
            'staking_preview' => 'Предпросмотр стейкинга',
            'days' => 'дней',
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
            'staking_information' => 'Информация о стейкинге',
            'total_staked' => 'Всего в стейкинге',
            'unlocked_amount' => 'Разблокированная сумма',
            'available_to_stake' => 'Доступно для стейкинга',
            'active_stakes' => 'Активные стейки',
            'reward_rate' => 'Ставка награды',
            'unlimited_staking' => 'Неограниченный стейкинг - можно разблокировать в любое время',
            'created' => 'Создано',
            'ready_to_unstake' => 'Готово к разблокировке!',
            'unlock_date' => 'Дата разблокировки',
            'no_active_stakes' => 'Активных стейков не найдено.',
            'days_until_unlock' => 'Дней до разблокировки',
            'about' => 'О приложении',
            'about_description' => 'О данном блокчейн кошельке',
            'about_info' => 'Безопасный блокчейн кошелёк для управления цифровыми активами',
            'settings' => 'Настройки',
            'settings_description' => 'Настройка кошелька и сети',
            'feature_coming_soon' => 'Эта функция скоро будет доступна!',
            'confirm_delete_wallet' => 'Вы уверены, что хотите удалить этот кошелёк из сохранённого списка?',
            'wallet_deleted' => 'Кошелёк удалён из сохранённого списка',
            'wallet_saved' => 'Кошелёк успешно сохранён',
            'wallet_loaded' => 'Кошелёк успешно загружен',
            'wallet_not_found' => 'Кошелёк не найден',
            'delete_wallet' => 'Удалить кошелёк',
            'load_wallet' => 'Загрузить кошелёк',
            
            // Дополнительные переводы для стейкинга
            'active_stakes' => 'Активные стейки',
            'expected_rewards' => 'Ожидаемые награды',
            'reward_rate' => 'Ставка награды',
            'created' => 'Создано',
            'unlock_date' => 'Дата разблокировки',
            'ready_to_unstake' => 'Готов к разблокировке!',
            'unlimited_staking' => 'Неограниченный стейкинг - можно разблокировать в любое время',
            'no_active_stakes' => 'Активные стейки не найдены.',
            'unstake_now' => 'Разблокировать сейчас',
            'unstake' => 'Разблокировать',
            'add_liquidity' => 'Добавить ликвидность',
            'add_liquidity_desc' => 'Предоставьте ликвидность и получайте комиссии',
            'defi_liquidity' => 'DeFi ликвидность',
            'token_a' => 'Токен A',
            'token_b' => 'Токен B',
            'amount_a' => 'Количество A',
            'amount_b' => 'Количество B',
            'select_token' => 'Выберите токен',
            'main_token' => 'Основной токен',
            'wrapped_token' => 'Обернутый токен',
            'slippage_tolerance' => 'Допустимое проскальзывание',
            'add_to_pool' => 'Добавить в пул',
            'liquidity_pools' => 'Пулы ликвидности',
            'your_positions' => 'Ваши позиции',
            'pool_share' => 'Доля в пуле',
            'estimated_apy' => 'Ожидаемый APY',
            'connect_metamask' => 'Подключить MetaMask',
            'metamask_required' => 'Требуется MetaMask',
            'loading_pools' => 'Загрузка пулов...',
            'loading_positions' => 'Загрузка ваших позиций...',
            'no_positions_found' => 'Позиции не найдены',
            'remove_liquidity' => 'Удалить ликвидность',
            'total_liquidity' => 'Общая ликвидность',
            'pool_tokens' => 'Токены пула',
            'pool_reserves' => 'Резервы',
            'add_to_existing' => 'Добавить в пул'
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
        .icon-settings { background: var(--dark-gradient); color: white; }

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
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 12px;
            margin: 20px 0;
            max-width: 100%;
        }

        .mnemonic-word {
            background: linear-gradient(135deg, #fff 0%, #f8f9fa 100%);
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 12px 8px;
            text-align: center;
            font-family: 'Courier New', monospace;
            font-weight: bold;
            font-size: 1.15em;
            line-height: 1.2;
            min-height: 60px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            transition: var(--transition);
            cursor: pointer;
            word-break: break-word;
            hyphens: auto;
            overflow-wrap: break-word;
        }

        .mnemonic-word:hover {
            border-color: #667eea;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .mnemonic-word:active {
            transform: translateY(0);
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
            border-color: #5a67d8;
        }

        .word-number {
            display: block;
            font-size: 1em;
            color: #6c757d;
            margin-bottom: 4px;
            font-weight: normal;
            flex-shrink: 0;
        }

        .word-text {
            font-size: 1.05em;
            color: #495057;
            font-weight: bold;
            word-break: break-word;
            hyphens: auto;
            max-width: 100%;
        }

        /* Адаптивные стили для мнемонических слов */
        @media (max-width: 768px) {
            .mnemonic-grid {
                grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
                gap: 8px;
            }
            
            .mnemonic-word {
                padding: 10px 6px;
                min-height: 55px;
                font-size: 0.85em;
            }
            
            .word-number {
                font-size: 0.7em;
            }
            
            .word-text {
                font-size: 0.8em;
            }
        }

        @media (max-width: 480px) {
            .mnemonic-grid {
                grid-template-columns: repeat(auto-fit, minmax(80px, 1fr));
                gap: 6px;
            }
            
            .mnemonic-word {
                padding: 8px 4px;
                min-height: 50px;
                font-size: 1em;
            }
            
            .word-number {
                font-size: 0.8em;
                margin-bottom: 2px;
            }
            
            .word-text {
                font-size: 0.95em;
            }
        }

        .mnemonic-word-container {
            display: contents; /* Позволяет элементу не влиять на grid-layout */
        }

        /* Transaction and Pagination Styles */
        .transaction-row {
            transition: var(--transition);
        }

        .transaction-row:hover {
            background-color: rgba(102, 126, 234, 0.05);
            border-color: rgba(102, 126, 234, 0.2);
        }

        .pagination .page-link {
            border-radius: 10px;
            margin: 0 2px;
            border: 2px solid #e9ecef;
            color: #667eea;
            font-weight: 500;
            transition: var(--transition);
        }

        .pagination .page-link:hover {
            background-color: #667eea;
            border-color: #667eea;
            color: white;
            transform: translateY(-1px);
        }

        .pagination .page-item.active .page-link {
            background: var(--primary-gradient);
            border-color: #667eea;
            color: white;
        }

        .pagination .page-item.disabled .page-link {
            background-color: #f8f9fa;
            border-color: #e9ecef;
            color: #6c757d;
            cursor: not-allowed;
        }

        /* Enhanced transaction details modal */
        .font-monospace {
            font-family: 'Courier New', Monaco, monospace !important;
            font-size: 0.9em;
        }

        .transaction-overview .card {
            transition: var(--transition);
        }

        .transaction-overview .card:hover {
            transform: translateY(-2px);
            box-shadow: var(--card-hover-shadow);
        }

        /* Copy button enhancements */
        .btn-outline-secondary {
            border-color: #e9ecef;
            transition: var(--transition);
        }

        .btn-outline-secondary:hover {
            background-color: #667eea;
            border-color: #667eea;
            color: white;
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

        /* Mobile Menu Styles */
        .mobile-menu-toggle {
            display: none;
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1001;
            background: rgba(255,255,255,0.9);
            border: none;
            border-radius: 10px;
            padding: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            backdrop-filter: blur(10px);
            transition: var(--transition);
        }

        .mobile-menu-toggle:hover {
            background: rgba(255,255,255,1);
            transform: scale(1.1);
        }

        .mobile-menu-toggle .hamburger {
            width: 24px;
            height: 18px;
            position: relative;
            cursor: pointer;
        }

        .mobile-menu-toggle .hamburger span {
            display: block;
            position: absolute;
            height: 3px;
            width: 100%;
            background: #333;
            border-radius: 2px;
            transition: all 0.3s ease;
        }

        .mobile-menu-toggle .hamburger span:nth-child(1) { top: 0; }
        .mobile-menu-toggle .hamburger span:nth-child(2) { top: 7px; }
        .mobile-menu-toggle .hamburger span:nth-child(3) { top: 14px; }

        .mobile-menu-toggle.active .hamburger span:nth-child(1) {
            transform: rotate(45deg);
            top: 7px;
        }

        .mobile-menu-toggle.active .hamburger span:nth-child(2) {
            opacity: 0;
        }

        .mobile-menu-toggle.active .hamburger span:nth-child(3) {
            transform: rotate(-45deg);
            top: 7px;
        }

        .mobile-menu-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 999;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        .mobile-menu-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .mobile-menu {
            position: fixed;
            top: 0;
            right: -100%;
            width: 300px;
            max-width: 85vw;
            height: 100vh;
            background: white;
            z-index: 1000;
            padding: 80px 0 20px;
            transition: all 0.3s ease;
            box-shadow: -5px 0 20px rgba(0,0,0,0.2);
            overflow-y: auto;
            border-left: 1px solid #e9ecef;
        }

        .mobile-menu.active {
            right: 0;
        }

        .mobile-menu-item {
            display: block;
            padding: 18px 25px;
            margin: 0;
            background: transparent;
            border: none;
            border-bottom: 1px solid #f0f0f0;
            text-align: left;
            cursor: pointer;
            transition: all 0.2s ease;
            color: #333;
            font-size: 16px;
            font-weight: 500;
            text-decoration: none;
            position: relative;
            border: none;
            border-radius: 12px;
            text-decoration: none;
            color: #333;
            font-weight: 500;
            transition: var(--transition);
            cursor: pointer;
            width: 100%;
            text-align: left;
        }

        .mobile-menu-item:hover {
            background: var(--primary-gradient);
            color: white;
            transform: translateX(5px);
        }

        .mobile-menu-item i {
            width: 20px;
            margin-right: 10px;
        }

        /* Mobile responsive adjustments */
        @media (max-width: 768px) {
            .mobile-menu-toggle {
                display: block;
                z-index: 1001;
            }

            /* Hide action cards on mobile - use mobile menu instead */
            #walletControls {
                display: none !important;
            }
            
            .wallet-header {
                padding-right: 80px; /* Space for hamburger button */
                position: relative;
            }

            .container {
                padding-top: 20px;
            }

            /* Improve mobile layout */
            .wallet-info {
                margin-bottom: 1rem;
            }

            .balance-display {
                font-size: 1.2rem;
            }

            /* Mobile menu improvements */
            .mobile-menu-toggle {
                position: fixed;
                top: 20px;
                right: 20px;
                z-index: 1001;
                background: var(--primary-color);
                border: none;
                border-radius: 50%;
                width: 50px;
                height: 50px;
                display: flex;
                align-items: center;
                justify-content: center;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            }

            .mobile-menu {
                top: 80px; /* Below the hamburger button */
            }
        }

        /* Desktop - show action cards, hide mobile menu */
        @media (min-width: 769px) {
            .mobile-menu-toggle {
                display: none !important;
            }
            
            .mobile-menu-overlay,
            .mobile-menu {
                display: none !important;
            }

            #walletControls {
                display: flex !important;
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

    <!-- Mobile Menu Toggle -->
    <button class="mobile-menu-toggle" onclick="toggleMobileMenu()">
        <div class="hamburger">
            <span></span>
            <span></span>
            <span></span>
        </div>
    </button>

    <!-- Mobile Menu Overlay -->
    <div class="mobile-menu-overlay" onclick="closeMobileMenu()"></div>

    <!-- Mobile Menu -->
    <div class="mobile-menu">
        <div class="mobile-menu-item" onclick="startWalletCreation(); closeMobileMenu();">
            <i class="fas fa-plus-circle"></i>
            <?php echo $t['create_wallet']; ?>
        </div>
        <div class="mobile-menu-item" onclick="showRestoreModal(); closeMobileMenu();">
            <i class="fas fa-download"></i>
            <?php echo $t['restore_wallet']; ?>
        </div>
        <div class="mobile-menu-item" onclick="showTransactionHistory(); closeMobileMenu();">
            <i class="fas fa-history"></i>
            <?php echo $t['transaction_history']; ?>
        </div>
        <div class="mobile-menu-item" onclick="showMyWallets(); closeMobileMenu();">
            <i class="fas fa-wallet"></i>
            <?php echo $t['my_wallets']; ?>
        </div>
        <div class="mobile-menu-item" onclick="showTransferModal(); closeMobileMenu();">
            <i class="fas fa-paper-plane"></i>
            <?php echo $t['transfer'] ?? 'Send Tokens'; ?>
        </div>
        <div class="mobile-menu-item" onclick="showStakingModal(); closeMobileMenu();">
            <i class="fas fa-chart-line"></i>
            <?php echo $t['staking'] ?? 'Staking'; ?>
        </div>
        <div class="mobile-menu-item" onclick="showDashboard(); closeMobileMenu();">
            <i class="fas fa-tachometer-alt"></i>
            <?php echo $t['dashboard'] ?? 'Dashboard'; ?>
        </div>
        <div class="mobile-menu-item" onclick="showBlockchainInfo(); closeMobileMenu();">
            <i class="fas fa-info-circle"></i>
            <?php echo $t['blockchain_info'] ?? 'Blockchain Info'; ?>
        </div>
        <div class="mobile-menu-item" onclick="showSmartContracts(); closeMobileMenu();">
            <i class="fas fa-file-contract"></i>
            <?php echo $t['smart_contracts'] ?? 'Smart Contracts'; ?>
        </div>
        <div class="mobile-menu-item" onclick="showSettings(); closeMobileMenu();">
            <i class="fas fa-cogs"></i>
            <?php echo $t['settings'] ?? 'Settings'; ?>
        </div>
        <div class="mobile-menu-item" onclick="showAbout(); closeMobileMenu();">
            <i class="fas fa-info-circle"></i>
            <?php echo $t['about'] ?? 'About'; ?>
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
                <div class="action-card" onclick="showTransactionHistory()">
                    <div class="action-icon icon-info">
                        <i class="fas fa-history"></i>
                    </div>
                    <h5 class="fw-bold"><?php echo $t['transaction_history']; ?></h5>
                    <p class="text-muted mb-0"><?php echo $t['show_transaction_history']; ?></p>
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
        
        <!-- DeFi Section -->
        <div class="row mb-4" id="defiControls">
            <div class="col-12 mb-3">
                <h4 class="text-center mb-4">
                    <i class="fas fa-exchange-alt me-2 text-primary"></i>
                    <?php echo $t['defi_liquidity']; ?>
                </h4>
            </div>
            <div class="col-lg-4 col-md-6 mb-3">
                <div class="action-card" onclick="showSwapModal()">
                    <div class="action-icon" style="background: linear-gradient(45deg, #11998e 0%, #38ef7d 100%);">
                        <i class="fas fa-exchange-alt"></i>
                    </div>
                    <h5 class="fw-bold"><?php echo $t['swap_tokens_title'] ?? 'Swap Tokens'; ?></h5>
                    <p class="text-muted mb-0"><?php echo $t['swap_tokens_desc'] ?? 'Exchange tokens via the on-chain AMM'; ?></p>
                </div>
            </div>
            <div class="col-lg-4 col-md-6 mb-3">
                <div class="action-card" onclick="showLiquidityModal()">
                    <div class="action-icon" style="background: linear-gradient(45deg, #667eea 0%, #764ba2 100%);">
                        <i class="fas fa-plus-circle"></i>
                    </div>
                    <h5 class="fw-bold"><?php echo $t['add_liquidity']; ?></h5>
                    <p class="text-muted mb-0"><?php echo $t['add_liquidity_desc']; ?></p>
                </div>
            </div>
            <div class="col-lg-4 col-md-6 mb-3">
                <div class="action-card" onclick="showPoolsModal()">
                    <div class="action-icon" style="background: linear-gradient(45deg, #f093fb 0%, #f5576c 100%);">
                        <i class="fas fa-swimming-pool"></i>
                    </div>
                    <h5 class="fw-bold"><?php echo $t['liquidity_pools']; ?></h5>
                    <p class="text-muted mb-0">View existing liquidity pools</p>
                </div>
            </div>
            <div class="col-lg-4 col-md-6 mb-3">
                <div class="action-card" onclick="showMyPositions()">
                    <div class="action-icon" style="background: linear-gradient(45deg, #4facfe 0%, #00f2fe 100%);">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <h5 class="fw-bold"><?php echo $t['your_positions']; ?></h5>
                    <p class="text-muted mb-0">Manage your liquidity positions</p>
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
                            <div class="alert alert-info alert-modern">
                                <i class="fas fa-info-circle me-2"></i>
                                <?php echo $t['click_word_to_copy']; ?>
                            </div>
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
                            <textarea class="form-control" id="transferMemo" rows="2" maxlength="1000"
                                      placeholder="<?php echo $t['memo_placeholder']; ?>"></textarea>
                            <div class="form-text">
                                <i class="fas fa-lock text-success me-1"></i>
                                All messages are automatically encrypted for security
                                <span class="ms-2">
                                    <span id="memoCharCount">0</span>/1000 characters
                                </span>
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
                            <div class="form-text" id="minStakeText"><?php echo $t['min_amount']; ?> <span id="minStakeAmount">100</span> <span class="crypto-symbol">COIN</span></div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="stakingPeriod" class="form-label fw-bold"><?php echo $t['staking_period']; ?></label>
                            <select class="form-select" id="stakingPeriod" required>
                                <?php
                                // Fixed staking terms only — all at a flat 12% APY.
                                $stakingTerms = [
                                    30   => ['en' => '1 month',  'ru' => '1 месяц'],
                                    180  => ['en' => '6 months', 'ru' => '6 месяцев'],
                                    365  => ['en' => '1 year',   'ru' => '1 год'],
                                    730  => ['en' => '2 years',  'ru' => '2 года'],
                                    1095 => ['en' => '3 years',  'ru' => '3 года'],
                                ];
                                $stLang = ($language === 'ru') ? 'ru' : 'en';
                                foreach ($stakingTerms as $days => $labels) {
                                    echo '<option value="' . $days . '">' . $labels[$stLang] . ' (12% APY)</option>';
                                }
                                ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="stakingPrivateKey" class="form-label fw-bold"><?php echo $t['private_key']; ?></label>
                            <input type="password" class="form-control" id="stakingPrivateKey" required 
                                   placeholder="<?php echo $t['enter_private_key']; ?>">
                        </div>
                        
                        <div id="stakingPreview" class="alert alert-success alert-modern" style="display: none;">
                            <h6><?php echo $t['staking_preview'] ?? 'Staking Preview'; ?></h6>
                            <div class="row">
                                <div class="col-6"><?php echo $t['amount'] ?? 'Amount'; ?>: <span id="previewAmount">0</span> <span class="crypto-symbol">COIN</span></div>
                                <div class="col-6"><?php echo $t['staking_period'] ?? 'Period'; ?>: <span id="previewPeriod">0</span> <?php echo $t['days'] ?? 'days'; ?></div>
                                <div class="col-6"><?php echo $t['estimated_apy'] ?? 'APY'; ?>: <span id="previewAPY">0</span>%</div>
                                <div class="col-6"><?php echo $t['expected_rewards'] ?? 'Expected Rewards'; ?>: <span id="previewRewards">0</span> <span class="crypto-symbol">COIN</span></div>
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

    <!-- Decrypt Message Modal -->
    <div class="modal fade" id="decryptMessageModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-lock-open me-2"></i><?php echo $t['decrypt_message'] ?? 'Decrypt Message'; ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info alert-modern">
                        <i class="fas fa-info-circle me-2"></i>
                        <?php echo $t['decrypt_message_info'] ?? 'Enter your private key to decrypt the encrypted message.'; ?>
                    </div>
                    
                    <div class="mb-3">
                        <label for="decryptPrivateKey" class="form-label fw-bold"><?php echo $t['private_key']; ?></label>
                        <input type="password" class="form-control" id="decryptPrivateKey" required 
                               placeholder="<?php echo $t['enter_private_key']; ?>">
                    </div>
                    
                    <div id="decryptedMessage" style="display: none;">
                        <div class="alert alert-success alert-modern">
                            <h6><i class="fas fa-check-circle me-2"></i><?php echo $t['decrypted_message'] ?? 'Decrypted Message'; ?></h6>
                            <div id="messageContent" class="mt-2"></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-modern" data-bs-dismiss="modal"><?php echo $t['close']; ?></button>
                    <button type="button" class="btn btn-primary btn-modern" onclick="decryptMessageFromModal()" id="decryptBtn">
                        <i class="fas fa-lock-open me-2"></i><?php echo $t['decrypt'] ?? 'Decrypt'; ?>
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
        let minStakeAmount = 100;
        let currentTransactions = [];
        let currentPage = 1;
        let itemsPerPage = 10;
        let totalTransactions = 0;
        
        // Extract last JSON object from response text (handles progress + final response)
        function extractLastJsonObject(text) {
            let depth = 0, start = -1, last = null, inStr = false, esc = false;
            for (let i = 0; i < text.length; i++) {
                const ch = text[i];
                if (inStr) {
                    if (esc) { esc = false; continue; }
                    if (ch === '\\') { esc = true; continue; }
                    if (ch === '"') { inStr = false; }
                    continue;
                }
                if (ch === '"') { inStr = true; continue; }
                if (ch === '{') { if (depth === 0) start = i; depth++; }
                else if (ch === '}') {
                    if (depth > 0) depth--;
                    if (depth === 0 && start !== -1) {
                        last = text.slice(start, i + 1);
                        start = -1;
                    }
                }
            }
            if (last) {
                try { return JSON.parse(last); } catch (e) {}
            }
            return null;
        }
        
        // Language and translation
        const translations = <?php echo json_encode(['current_lang' => $language, 'translations' => $t]); ?>;
        const t = translations.translations;
        
        // Language change function
        function changeLanguage(lang) {
            const url = new URL(window.location);
            url.searchParams.set('lang', lang);
            window.location.href = url.toString();
        }

        // Mobile menu functions
        function toggleMobileMenu() {
            const menuToggle = document.querySelector('.mobile-menu-toggle');
            const menuOverlay = document.querySelector('.mobile-menu-overlay');
            const menu = document.querySelector('.mobile-menu');

            menuToggle.classList.toggle('active');
            menuOverlay.classList.toggle('active');
            menu.classList.toggle('active');

            // Prevent body scroll when menu is open
            if (menu.classList.contains('active')) {
                document.body.style.overflow = 'hidden';
            } else {
                document.body.style.overflow = '';
            }
        }

        function closeMobileMenu() {
            const menuToggle = document.querySelector('.mobile-menu-toggle');
            const menuOverlay = document.querySelector('.mobile-menu-overlay');
            const menu = document.querySelector('.mobile-menu');

            menuToggle.classList.remove('active');
            menuOverlay.classList.remove('active');
            menu.classList.remove('active');
            document.body.style.overflow = '';
        }

        // Close mobile menu on window resize if desktop
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768) {
                closeMobileMenu();
            }
        });
        
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
                    minStakeAmount = parseFloat(data.config.min_stake_amount) || 100;
                    
                    // Update all symbol displays
                    updateSymbolDisplays(cryptoSymbol);
                    updateMinStakeDisplay();
                    
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
        
        // Update minimum stake amount display
        function updateMinStakeDisplay() {
            const minStakeAmountElement = document.getElementById('minStakeAmount');
            if (minStakeAmountElement) {
                minStakeAmountElement.textContent = minStakeAmount;
            }
            
            const stakingAmountInput = document.getElementById('stakingAmount');
            if (stakingAmountInput) {
                stakingAmountInput.min = minStakeAmount;
            }
        }
        
        // Initialize
        document.addEventListener('DOMContentLoaded', loadConfig);
        
        // UTF-8 safe base64 encoding/decoding functions
        function utf8ToBase64(str) {
            try {
                return btoa(unescape(encodeURIComponent(str)));
            } catch (e) {
                console.error('UTF-8 to Base64 encoding failed:', e);
                return '';
            }
        }
        
        function base64ToUtf8(str) {
            try {
                return decodeURIComponent(escape(atob(str)));
            } catch (e) {
                console.error('Base64 to UTF-8 decoding failed:', e);
                return str;
            }
        }

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
        
        // Toggle private key visibility
        function togglePrivateKey(elementId) {
            const input = document.getElementById(elementId);
            const icon = event.currentTarget.querySelector('i');
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
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
                wordDiv.className = 'mnemonic-word-container';
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
                
                // Handle multiple JSON objects in response (progress + final result)
                const responseText = await response.text();
                const data = extractLastJsonObject(responseText) || JSON.parse(responseText);
                
                if (data.success) {
                    currentWalletData = data.wallet;
                    showWalletResult(data.wallet, 'created');
                } else {
                    // Check if error is about existing wallet
                    if (data.error && data.error.includes('already exists')) {
                        // Show special error message with restore option
                        const restoreConfirm = confirm(
                            'This mnemonic phrase corresponds to an existing wallet. ' +
                            'Would you like to restore the existing wallet instead of creating a new one?\n\n' +
                            'Click OK to restore the wallet, or Cancel to try a different mnemonic phrase.'
                        );
                        
                        if (restoreConfirm) {
                            // Close create modal and open restore modal with current mnemonic
                            const createModal = bootstrap.Modal.getInstance(document.getElementById('createWalletModal'));
                            createModal.hide();
                            
                            // Wait a bit for modal to close, then open restore modal
                            setTimeout(() => {
                                showRestoreModal();
                                // Pre-fill the mnemonic
                                document.getElementById('restoreMnemonic').value = currentMnemonic.join(' ');
                                validateRestoreMnemonic();
                            }, 500);
                            
                            return;
                        }
                    }
                    
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
                        <div class="input-group">
                            <input type="text" class="form-control" value="${wallet.address}" readonly>
                            <button class="btn btn-outline-secondary" onclick="copyToClipboard('${wallet.address}')"><i class="fas fa-copy"></i></button>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">${t.public_key}:</label>
                        <div class="input-group">
                            <input type="text" class="form-control" value="${wallet.public_key}" readonly>
                            <button class="btn btn-outline-secondary" onclick="copyToClipboard('${wallet.public_key}')"><i class="fas fa-copy"></i></button>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">${t.private_key}:</label>
                        <div class="input-group">
                            <input type="password" class="form-control" value="${wallet.private_key}" readonly id="createPrivateKey">
                            <button class="btn btn-outline-secondary" onclick="togglePrivateKey('createPrivateKey')"><i class="fas fa-eye"></i></button>
                            <button class="btn btn-outline-secondary" onclick="copyToClipboard('${wallet.private_key}')"><i class="fas fa-copy"></i></button>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">${t.balance}:</label>
                        <div class="balance-display">
                            <span class="h5 text-success">${wallet.balance || 0} ${cryptoSymbol}</span>
                            ${wallet.staked_balance > 0 ? `<br><small class="text-info">Staked: ${wallet.staked_balance} ${cryptoSymbol}</small>` : ''}
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
            // Кошелек считается активным если:
            // 1. Он существует в блокчейне (найдены транзакции) - даже с балансом 0
            // 2. У него есть записи в blockchain verification
            const existsInBlockchain = verification?.exists_in_blockchain || false;
            const hasTransactions = (verification?.transaction_count || 0) > 0;
            
            // Кошелек активен если он существует в блокчейне ИЛИ есть транзакции
            // Баланс не важен - если кошелек был когда-то использован, он уже активен
            const isActive = existsInBlockchain || hasTransactions;
            
            const statusIcon = isActive ? 'check-circle text-success' : 'info-circle text-warning';
            const statusText = isActive ? 'Active in blockchain' : 'Ready for activation';
            
            let activationButton = '';
            // Показываем кнопку активации только если кошелек НЕ найден в блокчейне
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
                    ${hasTransactions ? `<small class="text-muted">Found ${verification.transaction_count} transaction(s)</small>` : ''}
                </div>
                
                <div class="wallet-info">
                    <div class="mb-3">
                        <label class="form-label fw-bold">${t.address}:</label>
                        <div class="input-group">
                            <input type="text" class="form-control" value="${wallet.address}" readonly>
                            <button class="btn btn-outline-secondary" onclick="copyToClipboard('${wallet.address}')"><i class="fas fa-copy"></i></button>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">${t.public_key}:</label>
                        <div class="input-group">
                            <input type="text" class="form-control" value="${wallet.public_key}" readonly>
                            <button class="btn btn-outline-secondary" onclick="copyToClipboard('${wallet.public_key}')"><i class="fas fa-copy"></i></button>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">${t.private_key}:</label>
                        <div class="input-group">
                            <input type="password" class="form-control" value="${wallet.private_key}" readonly id="restorePrivateKey">
                            <button class="btn btn-outline-secondary" onclick="togglePrivateKey('restorePrivateKey')"><i class="fas fa-eye"></i></button>
                            <button class="btn btn-outline-secondary" onclick="copyToClipboard('${wallet.private_key}')"><i class="fas fa-copy"></i></button>
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
                // Сначала проверим, есть ли кошелек уже в блокчейне
                const historyResponse = await fetch('wallet_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'get_wallet_transaction_history',
                        address: address
                    })
                });
                
                const historyData = await historyResponse.json();
                
                // Если у кошелька уже есть история транзакций, он не нуждается в активации
                if (historyData.success && historyData.transactions && historyData.transactions.length > 0) {
                    showNotification('Wallet is already active in blockchain (has transaction history)', 'info');
                    button.style.display = 'none';
                    // Update status indicator
                    const statusElements = document.querySelectorAll('.fa-info-circle.text-warning');
                    statusElements.forEach(el => {
                        el.className = 'fas fa-check-circle text-success me-2';
                        el.nextSibling.textContent = 'Active in blockchain';
                    });
                    return;
                }
                
                // Если истории нет, выполняем активацию
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
                // Автоскролл к результатам, чтобы пользователь увидел контент
                scrollToResults();
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
                                    <span class="fw-bold text-success">${wallet.available_balance || wallet.balance || 0} ${cryptoSymbol}</span>
                                    <button class="btn btn-sm btn-primary" onclick="checkBalance('${wallet.address}')">
                                        <i class="fas fa-refresh me-1"></i>${t.check_balance}
                                    </button>
                                </div>
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
                                        <button class="btn btn-sm btn-danger" onclick="deleteWalletFromList('${wallet.address}')" title="${t.delete_wallet}">
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
            // Автоскролл к результатам после рендера списка кошельков
            scrollToResults();
        }
        
        // Плавный автоскролл к блоку результатов
        function scrollToResults() {
            const resultsDiv = document.getElementById('results');
            if (!resultsDiv) return;
            try {
                resultsDiv.scrollIntoView({ behavior: 'smooth', block: 'start' });
            } catch (e) {
                // Фолбэк на случай старых браузеров
                const top = resultsDiv.getBoundingClientRect().top + window.pageYOffset - 10;
                window.scrollTo({ top, behavior: 'smooth' });
            }
        }
        
        // Show dashboard
        function showDashboard() {
            const resultsDiv = document.getElementById('results');
            resultsDiv.innerHTML = `
                <div class="action-card text-center">
                    <div class="action-icon icon-dashboard mx-auto">
                        <i class="fas fa-tachometer-alt"></i>
                    </div>
                    <h5>${t.dashboard || 'Dashboard'}</h5>
                    <p class="text-muted">${t.dashboard_description || 'Welcome to your blockchain wallet dashboard'}</p>
                </div>
            `;
        }
        
        // Show my wallets from localStorage
        // Show my wallets from localStorage with updated balances
        async function showMyWallets() {
            const wallets = JSON.parse(localStorage.getItem('myWallets') || '[]');
            
            if (wallets.length === 0) {
                const resultsDiv = document.getElementById('results');
                resultsDiv.innerHTML = `
                    <div class="action-card text-center">
                        <div class="action-icon icon-wallet mx-auto">
                            <i class="fas fa-wallet"></i>
                        </div>
                        <h5>${t.my_wallets}</h5>
                        <p class="text-muted">No saved wallets found</p>
                        <button class="btn btn-primary" onclick="startWalletCreation()">
                            <i class="fas fa-plus me-2"></i>Create Wallet
                        </button>
                    </div>
                `;
                // Автоскролл к результатам, чтобы пользователь увидел сообщение
                scrollToResults();
                return;
            }
            
            // Update balances for all wallets
            const walletsWithBalances = [];
            
            for (const wallet of wallets) {
                try {
                    const response = await fetch('wallet_api.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action: 'get_balance',
                            address: wallet.address
                        })
                    });
                    
                    const data = await response.json();
                    
                    if (data.success) {
                        const balanceData = data.balance;
                        walletsWithBalances.push({
                            ...wallet,
                            balance: balanceData.total || 0,
                            available_balance: balanceData.available || 0,
                            staked_balance: balanceData.staked || 0,
                            type: 'saved'
                        });
                    } else {
                        // If balance fetch fails, use stored values
                        walletsWithBalances.push({
                            ...wallet,
                            type: 'saved'
                        });
                    }
                } catch (error) {
                    // If request fails, use stored values
                    console.error('Failed to update balance for', wallet.address, error);
                    walletsWithBalances.push({
                        ...wallet,
                        type: 'saved'
                    });
                }
            }
            
            // Update localStorage with fresh balances
            const updatedWallets = walletsWithBalances.map(w => ({
                address: w.address,
                private_key: w.private_key,
                public_key: w.public_key,
                balance: w.balance,
                available_balance: w.available_balance,
                staked_balance: w.staked_balance
            }));
            localStorage.setItem('myWallets', JSON.stringify(updatedWallets));
            
            displayWalletList(walletsWithBalances, t.my_wallets);
            // После отображения списка «Мои кошельки» прокручиваем к результатам
            scrollToResults();
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
        
        // Save wallet to local storage (alias for addToWalletList)
        function saveWalletToLocal(address, privateKey) {
            return addToWalletList(address, privateKey);
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
                            balanceSpan.textContent = `${availableBalance} ${cryptoSymbol}`;
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
            
            // Update minimum stake display
            updateMinStakeDisplay();
            
            // Populate wallet selector
            populateWalletSelector('stakingAddress', address);
            
            
            
            // Add event listeners for preview
            document.getElementById('stakingAmount').addEventListener('input', updateStakingPreview);
            document.getElementById('stakingPeriod').addEventListener('change', updateStakingPreview);
        }
        
        // Populate wallet selector
        async function populateWalletSelector(selectId, selectedAddress = '') {
            const select = document.getElementById(selectId);
            const myWallets = JSON.parse(localStorage.getItem('myWallets') || '[]');
            
            select.innerHTML = '<option value="">Select wallet...</option>';
            
            // Load balances for all wallets
            for (const wallet of myWallets) {
                try {
                    const response = await fetch('wallet_api.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action: 'get_balance',
                            address: wallet.address
                        })
                    });
                    
                    const data = await response.json();
                    if (data.success && data.balance) {
                        wallet.balance = data.balance.available || 0;
                    }
                } catch (error) {
                    console.error('Failed to load balance for', wallet.address, error);
                }
            }
            
            // Update localStorage with fresh balances
            localStorage.setItem('myWallets', JSON.stringify(myWallets));
            
            // Populate options with updated balances
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
                // Flat 12% APY for all fixed staking terms.
                const apy = 12.0;

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
            const privateKey = document.getElementById('transferPrivateKey').value;
            
            if (!fromAddress || !toAddress || !amount || !privateKey) {
                showNotification('Please fill all required fields', 'danger');
                return;
            }
            
            // Check memo length
            if (memo && memo.length > 1000) {
                showNotification(t.memo_too_long || 'Message is too long. Maximum 1000 characters allowed.', 'danger');
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
                        memo: memo
                    })
                });
                
                // Handle multiple JSON objects in response (progress + final result)
                const responseText = await response.text();
                const data = extractLastJsonObject(responseText) || JSON.parse(responseText);
                
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
            
            // Check minimum stake amount
            if (amount < minStakeAmount) {
                showNotification(`Minimum staking amount is ${minStakeAmount} ${cryptoSymbol}`, 'danger');
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
                            <p>${t.expected_rewards}: ${data.staking_info.expected_rewards.toFixed(4)} ${cryptoSymbol}</p>
                            <p>${t.unlock_date}: ${data.staking_info.unlock_date}</p>
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
                            <h5><i class="fas fa-chart-line me-2"></i>${t.staking_information} - ${address.substring(0, 20)}...</h5>
                            
                            <div class="row mb-4">
                                <div class="col-md-3">
                                    <div class="text-center">
                                        <h6 class="text-primary">${t.total_staked}</h6>
                                        <h4 class="text-success">${totalStaked} ${cryptoSymbol}</h4>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="text-center">
                                        <h6 class="text-primary">${t.expected_rewards}</h6>
                                        <h4 class="text-warning">${totalRewards.toFixed(4)} ${cryptoSymbol}</h4>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="text-center">
                                        <h6 class="text-primary">${t.unlocked_amount}</h6>
                                        <h4 class="text-info">${unlockedAmount} ${cryptoSymbol}</h4>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="text-center">
                                        <h6 class="text-primary">${t.available_to_stake}</h6>
                                        <h4 class="text-secondary">${stakingAvailable} ${cryptoSymbol}</h4>
                                    </div>
                                </div>
                            </div>
                            
                            <h6>${t.active_stakes}:</h6>
                            <div class="row">
                    `;
                    
                    if (activeStakes.length > 0) {
                        activeStakes.forEach(stake => {
                            const hasUnlockTime = stake.unlock_time !== null && stake.unlock_time !== undefined;
                            const isUnlocked = stake.is_unlocked === true || stake.lock_status === 'unlocked' || stake.lock_status === 'pending';
                            // Only legacy rows without a fixed term are "unlimited".
                            const isUnlimited = !hasUnlockTime && (stake.end_block === null || stake.end_block === undefined);
                            // Fixed-term stakes can only be unstaked once unlocked.
                            const canUnstake = isUnlocked || isUnlimited;
                            const statusClass = isUnlocked ? 'success' : (isUnlimited ? 'info' : 'warning');
                            const statusIcon = isUnlocked ? 'unlock' : (isUnlimited ? 'infinity' : 'lock');
                            
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
                                            <p class="mb-1">${t.reward_rate}: ${((stake.reward_rate || stake.apy || 0) * 100).toFixed(2)}%</p>
                                            <p class="mb-1">${t.expected_rewards}: ${stake.rewards_earned || 0} ${cryptoSymbol}</p>
                                            <p class="mb-1">${t.created}: ${stake.created_at || 'N/A'}</p>
                                            <p class="mb-0">
                                                ${isUnlocked ? 
                                                    t.ready_to_unstake : 
                                                    (isUnlimited ? t.unlimited_staking : `${t.unlock_date}: ${stake.unlock_date || 'N/A'}`)
                                                }
                                            </p>
                                            ${canUnstake ? `
                                                <button class="btn btn-sm ${isUnlocked ? 'btn-success' : 'btn-primary'} mt-2" onclick="showUnstakeModal('${address}', ${stake.amount || 0})">
                                                    <i class="fas fa-${isUnlocked ? 'unlock' : 'hand-paper'} me-1"></i>${isUnlimited && !isUnlocked ? t.unstake_now : t.unstake}
                                                </button>
                                            ` : ''}
                                        </div>
                                    </div>
                                </div>
                            `;
                        });
                    } else {
                        stakingHtml += `<div class="col-12"><p class="text-muted">${t.no_active_stakes}</p></div>`;
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
        
        // Confirm staking function
        function confirmStaking() {
            // Get form values
            const amount = document.getElementById('stakingAmount').value;
            const period = document.getElementById('stakingPeriod').value;
            
            if (!amount || !period) {
                showNotification('Please fill in all staking fields', 'warning');
                return false;
            }
            
            if (parseFloat(amount) <= 0) {
                showNotification('Staking amount must be greater than 0', 'warning');
                return false;
            }
            
            // Show confirmation dialog
            const confirmMessage = `Are you sure you want to stake ${amount} tokens for ${period} days?`;
            if (!confirm(confirmMessage)) {
                return false;
            }
            
            // Send staking request to backend with period parameter
            fetch('wallet_api.php?action=stake', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    amount: amount,
                    period: period,
                    private_key: document.getElementById('stakingPrivateKey').value
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(t.staking_successful || 'Staking successful!', 'success');
                    closeModal('stakingModal');
                    // Refresh wallet balance
                    if (typeof updateWalletBalance === 'function') {
                        updateWalletBalance();
                    }
                } else {
                    showNotification(data.error || t.staking_failed || 'Staking failed', 'danger');
                }
            })
            .catch(error => {
                console.error('Staking error:', error);
                showNotification(t.staking_failed || 'Staking failed', 'danger');
            });
            
            return false; // Prevent form submission
        }
        
        // Confirm restore (placeholder function)
        function confirmRestore() {
            return confirm(t.confirm_restore || 'Are you sure you want to restore this wallet?');
        }
        
        // Close modal (helper function)
        function closeModal(modalId) {
            const modal = bootstrap.Modal.getInstance(document.getElementById(modalId));
            if (modal) {
                modal.hide();
            }
        }
        
        // Close mnemonic modal (helper function)
        function closeMnemonicModal() {
            closeModal('createWalletModal');
        }
        
        // Show decrypt modal for encrypted messages
        // Attempt to decrypt message with current wallet's private key, fallback to modal
        async function attemptAutoDecrypt(encryptedMessageJson) {
            console.log('Auto-decrypt called - showing decryption modal for security');
            
            // SECURITY FIX: Always show decryption modal instead of auto-decrypting
            // This prevents accidental decryption of sensitive messages
            showDecryptModal(encryptedMessageJson);
        }
        
        // Try to resolve public key for given private key from current session or local storage
        function getPublicKeyForPrivateKey(privateKey) {
            try {
                if (currentWalletData && currentWalletData.private_key === privateKey && currentWalletData.public_key) {
                    return currentWalletData.public_key;
                }
                const myWallets = JSON.parse(localStorage.getItem('myWallets') || '[]');
                // Some records may store key as private_key, others as privateKey; also try to match by address if available
                for (const w of myWallets) {
                    if ((w.private_key && w.private_key === privateKey) || (w.privateKey && w.privateKey === privateKey)) {
                        if (w.public_key) return w.public_key;
                    }
                }
            } catch (e) {
                console.warn('getPublicKeyForPrivateKey error:', e);
            }
            return null;
        }

        // Ensure encrypted_message JSON contains recipient_public_key to improve legacy compatibility
        function prepareEncryptedMessageWithRecipientKey(encryptedMessageJson, privateKey) {
            try {
                const obj = typeof encryptedMessageJson === 'string' ? JSON.parse(encryptedMessageJson) : encryptedMessageJson;
                if (obj && obj.encrypted_data && typeof obj.encrypted_data === 'object') {
                    if (!obj.encrypted_data.recipient_public_key) {
                        const pub = getPublicKeyForPrivateKey(privateKey);
                        if (pub) {
                            obj.encrypted_data.recipient_public_key = pub;
                        }
                    }
                }
                return JSON.stringify(obj);
            } catch (e) {
                console.warn('prepareEncryptedMessageWithRecipientKey failed, sending original payload:', e);
                return encryptedMessageJson;
            }
        }

        // Try to decrypt message with given private key
        async function tryDecryptWithKey(encryptedMessageJson, privateKey) {
            try {
                const preparedEncrypted = prepareEncryptedMessageWithRecipientKey(encryptedMessageJson, privateKey);
                const response = await fetch('wallet_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'decrypt_message',
                        encrypted_message: preparedEncrypted,
                        private_key: privateKey
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    // Show decrypted message inline
                    showInlineDecryptedMessage(data.message);
                    showNotification(t.message_decrypted || 'Message decrypted successfully', 'success');
                    return true;
                } else {
                    return false;
                }
            } catch (error) {
                console.error('Decryption error:', error);
                return false;
            }
        }
        
        // Show decrypted message inline (temporary notification or toast)
        function showInlineDecryptedMessage(message) {
            // Create a temporary modal or notification with the decrypted message
            const tempModal = document.createElement('div');
            tempModal.className = 'modal fade';
            tempModal.innerHTML = `
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="fas fa-unlock text-success me-2"></i>
                                ${t.decrypted_message || 'Decrypted Message'}
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="alert alert-success">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div class="flex-grow-1">
                                        <p class="mb-0">${message}</p>
                                    </div>
                                    <button class="btn btn-sm btn-outline-secondary ms-2 copy-message-btn" data-message='${message}'>
                                        <i class="fas fa-copy"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">${t.close || 'Close'}</button>
                        </div>
                    </div>
                </div>
            `;
            
            document.body.appendChild(tempModal);
            const modal = new bootstrap.Modal(tempModal);
            modal.show();
            
            // Remove modal from DOM when hidden
            tempModal.addEventListener('hidden.bs.modal', function() {
                document.body.removeChild(tempModal);
            });
        }

        // Decrypt message in current modal (for transaction details modal)
        async function decryptInModal(encryptedMessageJson, buttonElement) {
            const privateKeyInput = buttonElement.closest('.card-body').querySelector('#decryptPrivateKey');
            const decryptedDiv = buttonElement.closest('.card-body').querySelector('#decryptedMessage');
            const messageContentDiv = buttonElement.closest('.card-body').querySelector('#messageContent');
            
            const privateKey = privateKeyInput.value.trim();
            if (!privateKey) {
                showNotification(t.enter_private_key || 'Please enter your private key', 'warning');
                return;
            }
            
            const originalText = buttonElement.innerHTML;
            buttonElement.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>' + (t.decrypting || 'Decrypting...');
            buttonElement.disabled = true;
            
            try {
        const preparedEncrypted = prepareEncryptedMessageWithRecipientKey(encryptedMessageJson, privateKey);
                const response = await fetch('wallet_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'decrypt_message',
            encrypted_message: preparedEncrypted,
                        private_key: privateKey
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    messageContentDiv.innerHTML = `
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="flex-grow-1">
                                <p class="mb-0">${data.message}</p>
                            </div>
                            <button class="btn btn-sm btn-outline-secondary ms-2 copy-message-btn" data-message='${data.message}'>
                                <i class="fas fa-copy"></i>
                            </button>
                        </div>
                    `;
                    
                    decryptedDiv.style.display = 'block';
                    privateKeyInput.value = ''; // Clear for security
                    
                    showNotification(t.message_decrypted || 'Message decrypted successfully', 'success');
                } else {
                    showNotification((t.decryption_failed || 'Decryption failed') + ': ' + (data.error || 'Unknown error'), 'danger');
                }
            } catch (error) {
                showNotification(t.error + ': ' + error.message, 'danger');
            } finally {
                buttonElement.innerHTML = originalText;
                buttonElement.disabled = false;
            }
        }

        function showDecryptModal(encryptedMessageJson) {
            try {
                // Store the encrypted message globally for later use
                window.currentEncryptedMessage = JSON.parse(encryptedMessageJson);
                
                // Clear previous data
                document.getElementById('decryptPrivateKey').value = '';
                document.getElementById('decryptedMessage').style.display = 'none';
                document.getElementById('messageContent').innerHTML = '';
                
                // Show modal
                const modal = new bootstrap.Modal(document.getElementById('decryptMessageModal'));
                modal.show();
            } catch (e) {
                console.error('Error parsing encrypted message:', e);
                showNotification(t.invalid_encrypted_message || 'Invalid encrypted message format', 'danger');
            }
        }
        
        // Decrypt message from modal
        // Decrypt message from modal
        async function decryptMessageFromModal() {
            if (!window.currentEncryptedMessage) {
                showNotification(t.no_encrypted_message || 'No encrypted message to decrypt', 'danger');
                return;
            }
            
            const privateKey = document.getElementById('decryptPrivateKey').value.trim();
            if (!privateKey) {
                showNotification(t.enter_private_key || 'Please enter your private key', 'warning');
                return;
            }
            
            const decryptButton = document.getElementById('decryptBtn');
            const originalText = decryptButton.innerHTML;
            decryptButton.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>' + (t.decrypting || 'Decrypting...');
            decryptButton.disabled = true;
            
            try {
        const preparedEncrypted = prepareEncryptedMessageWithRecipientKey(JSON.stringify(window.currentEncryptedMessage), privateKey);
                const response = await fetch('wallet_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'decrypt_message',
            encrypted_message: preparedEncrypted,
                        private_key: privateKey
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    const messageContent = document.getElementById('messageContent');
                    const decryptedDiv = document.getElementById('decryptedMessage');
                    
                    messageContent.innerHTML = `
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="flex-grow-1">
                                <p class="mb-0">${data.message}</p>
                            </div>
                            <button class="btn btn-sm btn-outline-secondary ms-2 copy-message-btn" data-message='${data.message}'>
                                <i class="fas fa-copy"></i>
                            </button>
                        </div>
                    `;
                    
                    decryptedDiv.style.display = 'block';
                    document.getElementById('decryptPrivateKey').value = ''; // Clear for security
                    
                    showNotification(t.message_decrypted || 'Message decrypted successfully', 'success');
                } else {
                    showNotification((t.decryption_failed || 'Decryption failed') + ': ' + (data.error || 'Unknown error'), 'danger');
                }
            } catch (error) {
                showNotification(t.error + ': ' + error.message, 'danger');
            } finally {
                decryptButton.innerHTML = originalText;
                decryptButton.disabled = false;
            }
        }
        
        // Alias for generateNewMnemonic to match window export
        function generateMnemonic() {
            return generateNewMnemonic();
        }
        
        // Show transaction history with encryption support and pagination
        async function showTransactionHistory(address = null) {
            // If specific address is provided, load its history directly
            if (address) {
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
                        // После загрузки истории транзакций прокручиваем к результатам
                        scrollToResults();
                    } else {
                        showNotification('Failed to load transaction history: ' + data.error, 'danger');
                    }
                } catch (error) {
                    showNotification('Error: ' + error.message, 'danger');
                }
                return;
            }
            
            // Show wallet selector interface
            const resultsDiv = document.getElementById('results');
            const myWallets = JSON.parse(localStorage.getItem('myWallets') || '[]');
            
            if (myWallets.length === 0) {
                resultsDiv.innerHTML = `
                    <div class="action-card text-center">
                        <div class="action-icon icon-warning mx-auto">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <h5>${t.no_wallets_found}</h5>
                        <p class="text-muted">${t.select_wallet_for_history}</p>
                    </div>
                `;
                // Автоскролл к результатам, чтобы пользователь увидел уведомление
                scrollToResults();
                return;
            }
            
            resultsDiv.innerHTML = `
                <div class="action-card">
                    <h5><i class="fas fa-history me-2"></i>${t.transaction_history}</h5>
                    
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label for="historyWalletSelect" class="form-label fw-bold">${t.select_wallet || 'Select Wallet'}:</label>
                            <select class="form-select" id="historyWalletSelect" onchange="loadWalletHistory()">
                                <option value="">${t.select_wallet_for_history || 'Select wallet to view history'}</option>
                                ${myWallets.map(wallet => 
                                    `<option value="${wallet.address}">${wallet.address.substring(0, 20)}... (${wallet.balance || 0} ${cryptoSymbol})</option>`
                                ).join('')}
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="transactionTypeFilter" class="form-label fw-bold">${t.type_filter || 'Type Filter'}:</label>
                            <select class="form-select" id="transactionTypeFilter" onchange="filterTransactions()">
                                <option value="">${t.all_types || 'All Types'}</option>
                                <option value="sent">${t.sent || 'Sent'}</option>
                                <option value="received">${t.received || 'Received'}</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="transactionAmountFilter" class="form-label fw-bold">${t.min_amount || 'Min Amount'}:</label>
                            <input type="number" class="form-control" id="transactionAmountFilter" 
                                   placeholder="0" step="0.01" onchange="filterTransactions()">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-bold">&nbsp;</label>
                            <button class="btn btn-outline-secondary d-block w-100" onclick="clearFilters()">
                                <i class="fas fa-times"></i> ${t.clear || 'Clear'}
                            </button>
                        </div>
                    </div>
                    
                    <div id="transactionResults">
                        <div class="text-center text-muted">
                            <i class="fas fa-arrow-up me-2"></i>
                            ${t.select_wallet_for_history || 'Select a wallet to view transaction history'}
                        </div>
                    </div>
                </div>
            `;
            // Автоскролл к результатам сразу после показа интерфейса выбора кошелька
            scrollToResults();
        }
        
        // Load wallet transaction history
        async function loadWalletHistory() {
            const select = document.getElementById('historyWalletSelect');
            const address = select.value;
            const resultsDiv = document.getElementById('transactionResults');
            
            if (!address) {
                resultsDiv.innerHTML = `
                    <div class="text-center text-muted">
                        <i class="fas fa-arrow-up me-2"></i>
                        ${t.select_wallet_for_history || 'Select a wallet to view transaction history'}
                    </div>
                `;
                return;
            }
            
            resultsDiv.innerHTML = `
                <div class="text-center">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p class="mt-2">${t.loading_transactions || 'Loading transactions...'}</p>
                </div>
            `;
            
            try {
                const response = await fetch('wallet_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'get_wallet_transaction_history',
                        address: address
                    })
                });
                
                const data = await response.json();
                
                if (data.success && data.transactions) {
                    displayTransactions(data.transactions, address);
                } else {
                    resultsDiv.innerHTML = `
                        <div class="alert alert-info alert-modern">
                            <i class="fas fa-info-circle me-2"></i>
                            ${t.no_transactions_found}
                        </div>
                    `;
                }
            } catch (error) {
                resultsDiv.innerHTML = `
                    <div class="alert alert-danger alert-modern">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        ${t.error_loading_transactions || 'Error loading transactions'}: ${error.message}
                    </div>
                `;
            }
        }
        
        // Display transaction history
        function displayTransactionHistory(transactions, address) {
            const resultsDiv = document.getElementById('results');
            
            if (!transactions || transactions.length === 0) {
                resultsDiv.innerHTML = `
                    <div class="action-card text-center">
                        <div class="action-icon icon-info mx-auto">
                            <i class="fas fa-info-circle"></i>
                        </div>
                        <h5>${t.no_transactions_found}</h5>
                        <p class="text-muted">${t.no_transaction_history}</p>
                    </div>
                `;
                // Автоскролл к результатам, чтобы пользователь увидел сообщение
                scrollToResults();
                return;
            }
            
            let html = `
                <div class="action-card">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5><i class="fas fa-history me-2"></i>${t.transaction_history} - ${address.substring(0, 20)}...</h5>
                        <span class="badge bg-secondary">${transactions.length} ${t.transactions || 'transactions'}</span>
                    </div>
                    <div class="list-group">
            `;
            
            transactions.forEach(tx => {
                const date = tx.timestamp ? new Date(tx.timestamp * 1000) : new Date();
                const isReceived = tx.to_address === address;
                const amount = parseFloat(tx.amount || 0);
                
                // Debug: Log transaction data to console
                console.log('Transaction data:', tx);
                console.log('Transaction.data:', tx.data);
                console.log('Transaction.memo:', tx.memo);
                
                // Check for encrypted messages in new format
                let hasEncryptedMessage = false;
                let encryptedMessage = null;
                
                // Check if transaction has encrypted memo (new format)
                let txDataObj = tx.data;
                
                // Parse tx.data if it's a JSON string
                if (typeof tx.data === 'string') {
                    try {
                        txDataObj = JSON.parse(tx.data);
                        console.log('Parsed tx.data from string:', txDataObj);
                    } catch (e) {
                        console.log('Failed to parse tx.data as JSON:', e);
                        txDataObj = tx.data;
                    }
                }
                
                if (txDataObj && typeof txDataObj === 'object') {
                    // Case 1: txDataObj.memo contains full encrypted message structure with encrypted_data
                    if (txDataObj.memo && typeof txDataObj.memo === 'object' && txDataObj.memo.encrypted_data) {
                        hasEncryptedMessage = true;
                        encryptedMessage = txDataObj.memo;
                        console.log('Found encrypted message in tx.data.memo (full structure)');
                    }
                    // Case 2: txDataObj.encrypted === true (old format - remove this after migration)
                    else if (txDataObj.encrypted === true) {
                        hasEncryptedMessage = true;
                        // For old format, the memo might be a string, but we still consider it encrypted
                        encryptedMessage = { legacy: true, memo: txDataObj.memo };
                        console.log('Found encrypted message in tx.data (legacy format)');
                    }
                }
                
                // Also check direct memo field (legacy support)
                if (!hasEncryptedMessage && tx.memo && typeof tx.memo === 'object') {
                    if (tx.memo.encrypted_data && typeof tx.memo.encrypted_data === 'object') {
                        hasEncryptedMessage = true;
                        encryptedMessage = tx.memo;
                        console.log('Found encrypted message in tx.memo');
                    }
                }
                if (!hasEncryptedMessage && tx.memo && typeof tx.memo === 'object') {
                    if (tx.memo.encrypted_data && typeof tx.memo.encrypted_data === 'object') {
                        hasEncryptedMessage = true;
                        encryptedMessage = tx.memo;
                        console.log('Found encrypted message in tx.memo');
                    }
                }
                
                console.log('Has encrypted message:', hasEncryptedMessage);
                console.log('Encrypted message object:', encryptedMessage);
                
                // Convert encrypted message to JSON string for passing to functions
                const encryptedMessageJson = encryptedMessage ? JSON.stringify(encryptedMessage) : '';
                
                // Extract plain text memo if available and not encrypted
                let displayMemo = '';
                if (!hasEncryptedMessage && tx.memo && typeof tx.memo === 'string') {
                    displayMemo = tx.memo;
                } else if (!hasEncryptedMessage && tx.data && tx.data.memo && typeof tx.data.memo === 'string') {
                    displayMemo = tx.data.memo;
                }
                
                html += `
                    <div class="list-group-item">
                        <div class="d-flex w-100 justify-content-between align-items-center">
                            <div class="flex-grow-1">
                                <div class="d-flex align-items-center mb-1">
                                    <span class="badge ${isReceived ? 'bg-success' : 'bg-danger'} me-2">
                                        ${isReceived ? '↓ ' + (t.received || 'Received') : '↑ ' + (t.sent || 'Sent')}
                                    </span>
                                    <strong class="text-${isReceived ? 'success' : 'danger'}">
                                        ${isReceived ? '+' : '-'}${amount.toFixed(8)} ${cryptoSymbol}
                                    </strong>
                                    ${hasEncryptedMessage ? '<i class="fas fa-envelope text-warning ms-2 encrypted-message-icon" title="' + (t.has_encrypted_message || 'Has encrypted message') + '" data-encrypted-message=\'' + encryptedMessageJson + '\' style="cursor: pointer;"></i>' : ''}
                                </div>
                                <p class="mb-1 text-muted small">
                                    ${isReceived ? (t.from || 'From') : (t.to || 'To')}: 
                                    <code class="bg-light px-1 rounded">${isReceived ? tx.from_address : tx.to_address}</code>
                                </p>
                                <small class="text-muted">
                                    <i class="far fa-clock me-1"></i>
                                    ${date.toLocaleString()}
                                </small>
                                ${displayMemo ? `<div class="small text-info mt-1"><i class="fas fa-sticky-note me-1"></i>${displayMemo}</div>` : ''}
                                ${hasEncryptedMessage ? `<div class="small text-warning mt-1"><i class="fas fa-lock me-1"></i>${t.encrypted_message || 'Encrypted message'} - ${t.click_to_decrypt || 'Click envelope to decrypt'}</div>` : ''}
                            </div>
                            <div class="text-end">
                                <small class="text-muted d-block">${t.block || 'Block'}: ${tx.block_height || t.pending || 'Pending'}</small>
                                ${hasEncryptedMessage ? 
                                    `<button class="btn btn-sm btn-outline-info mt-1 view-details-btn" 
                                             data-tx-hash="${tx.hash}" data-encrypted-message='${encryptedMessageJson}'>
                                        <i class="fas fa-eye"></i> ${t.view_details || 'View Details'}
                                    </button>` : 
                                    `<button class="btn btn-sm btn-outline-secondary mt-1 view-details-btn" 
                                             data-tx-hash="${tx.hash}">
                                        <i class="fas fa-info"></i> ${t.details || 'Details'}
                                    </button>`
                                }
                            </div>
                        </div>
                    </div>
                `;
            });
            
            html += '</div></div>';
            resultsDiv.innerHTML = html;
            // Автоскролл к результатам после рендера истории транзакций
            scrollToResults();
        }
        
        // Display transactions with enhanced features and pagination
        function displayTransactions(transactions, walletAddress) {
            const resultsDiv = document.getElementById('transactionResults');
            
            // Store current transactions for pagination
            currentTransactions = transactions;
            totalTransactions = transactions.length;
            
            if (transactions.length === 0) {
                resultsDiv.innerHTML = `
                    <div class="alert alert-info alert-modern">
                        <i class="fas fa-info-circle me-2"></i>
                        ${t.no_transactions_found}
                    </div>
                `;
                return;
            }
            
            renderTransactionPage();
        }
        
        // Render current page of transactions
        function renderTransactionPage() {
            const resultsDiv = document.getElementById('transactionResults');
            const startIndex = (currentPage - 1) * itemsPerPage;
            const endIndex = Math.min(startIndex + itemsPerPage, totalTransactions);
            const pageTransactions = currentTransactions.slice(startIndex, endIndex);
            const totalPages = Math.ceil(totalTransactions / itemsPerPage);
            
            let html = `
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <span class="transaction-count badge bg-secondary">${totalTransactions} ${t.transactions || 'transactions'}</span>
                        <span class="text-muted ms-2">
                            ${t.showing || 'Showing'} ${startIndex + 1} ${t.to || 'to'} ${endIndex} ${t.of || 'of'} ${totalTransactions} ${t.entries || 'entries'}
                        </span>
                    </div>
                    <div class="d-flex align-items-center">
                        <label class="form-label me-2 mb-0">${t.per_page || 'Per page'}:</label>
                        <select class="form-select form-select-sm" style="width: auto;" onchange="changeItemsPerPage(this.value)">
                            <option value="5" ${itemsPerPage === 5 ? 'selected' : ''}>5</option>
                            <option value="10" ${itemsPerPage === 10 ? 'selected' : ''}>10</option>
                            <option value="25" ${itemsPerPage === 25 ? 'selected' : ''}>25</option>
                            <option value="50" ${itemsPerPage === 50 ? 'selected' : ''}>50</option>
                        </select>
                    </div>
                </div>
                <div class="list-group">
            `;
            
            pageTransactions.forEach(tx => {
                const date = tx.timestamp ? new Date(tx.timestamp * 1000) : new Date();
                const isReceived = tx.to_address === getCurrentWalletAddress();
                const amount = parseFloat(tx.amount || 0);
                
                // Debug: Log transaction data to console
                console.log('Page Transaction data:', tx);
                console.log('Page Transaction.data:', tx.data);
                
                // Check for encrypted messages in new format
                let hasEncryptedMessage = false;
                let encryptedMessage = null;
                
                // Check if transaction has encrypted memo (new format)
                let txDataObj = tx.data;
                
                // Parse tx.data if it's a JSON string
                if (typeof tx.data === 'string') {
                    try {
                        txDataObj = JSON.parse(tx.data);
                        console.log('Page Parsed tx.data from string:', txDataObj);
                    } catch (e) {
                        console.log('Page Failed to parse tx.data as JSON:', e);
                        txDataObj = tx.data;
                    }
                }
                
                if (txDataObj && typeof txDataObj === 'object') {
                    // Case 1: txDataObj.memo contains full encrypted message structure with encrypted_data
                    if (txDataObj.memo && typeof txDataObj.memo === 'object' && txDataObj.memo.encrypted_data) {
                        hasEncryptedMessage = true;
                        encryptedMessage = txDataObj.memo;
                        console.log('Page Found encrypted message in tx.data.memo (full structure)');
                    }
                    // Case 2: txDataObj.encrypted === true (old format - remove this after migration)
                    else if (txDataObj.encrypted === true) {
                        hasEncryptedMessage = true;
                        // For old format, the memo might be a string, but we still consider it encrypted
                        encryptedMessage = { legacy: true, memo: txDataObj.memo };
                        console.log('Page Found encrypted message in tx.data (legacy format)');
                    }
                }
                
                // Also check direct memo field (legacy support)
                if (!hasEncryptedMessage && tx.memo && typeof tx.memo === 'object') {
                    if (tx.memo.encrypted_data && typeof tx.memo.encrypted_data === 'object') {
                        hasEncryptedMessage = true;
                        encryptedMessage = tx.memo;
                        console.log('Page Found encrypted message in tx.memo');
                    }
                }
                
                console.log('Page Has encrypted message:', hasEncryptedMessage);
                console.log('Page Encrypted message object:', encryptedMessage);
                
                const type = isReceived ? 'received' : 'sent';
                
                // Convert encrypted message to JSON string for passing to functions
                const encryptedMessageJson = encryptedMessage ? JSON.stringify(encryptedMessage) : '';
                
                html += `
                    <div class="list-group-item transaction-row" 
                         data-type="${type}" 
                         data-amount="${amount}">
                        <div class="d-flex w-100 justify-content-between align-items-center">
                            <div class="flex-grow-1">
                                <div class="d-flex align-items-center mb-1">
                                    <span class="badge ${isReceived ? 'bg-success' : 'bg-danger'} me-2">
                                        ${isReceived ? '↓ ' + (t.received || 'Received') : '↑ ' + (t.sent || 'Sent')}
                                    </span>
                                    <strong class="text-${isReceived ? 'success' : 'danger'}">
                                        ${isReceived ? '+' : '-'}${amount.toFixed(8)} ${cryptoSymbol}
                                    </strong>
                                    ${hasEncryptedMessage ? '<i class="fas fa-envelope text-warning ms-2 encrypted-message-icon" title="' + (t.has_encrypted_message || 'Has encrypted message') + '" data-encrypted-message=\'' + encryptedMessageJson + '\' style="cursor: pointer;"></i>' : ''}
                                </div>
                                <p class="mb-1 text-muted small">
                                    ${isReceived ? (t.from_address || 'From') : (t.to_address || 'To')}: 
                                    <code class="bg-light px-1 rounded">${(isReceived ? tx.from_address : tx.to_address).substring(0, 20)}...</code>
                                </p>
                                <small class="text-muted">
                                    <i class="far fa-clock me-1"></i>
                                    ${date.toLocaleString()}
                                </small>
                                ${!hasEncryptedMessage && tx.memo && typeof tx.memo === 'string' ? `<div class="small text-info mt-1"><i class="fas fa-sticky-note me-1"></i>${tx.memo}</div>` : ''}
                                ${hasEncryptedMessage ? `<div class="small text-warning mt-1"><i class="fas fa-lock me-1"></i>${t.encrypted_message || 'Encrypted message'} - ${t.click_to_decrypt || 'Click envelope to decrypt'}</div>` : ''}
                            </div>
                            <div class="text-end">
                                <small class="text-muted d-block">${t.block_height || 'Block'}: ${tx.block_height || t.pending || 'Pending'}</small>
                                ${hasEncryptedMessage ? 
                                    `<button class="btn btn-sm btn-outline-info mt-1 view-details-btn" 
                                             data-tx-hash="${tx.hash}" data-encrypted-message='${encryptedMessageJson}'>
                                        <i class="fas fa-eye"></i> ${t.view_details || 'View Details'}
                                    </button>` : 
                                    `<button class="btn btn-sm btn-outline-secondary mt-1 view-details-btn" 
                                             data-tx-hash="${tx.hash}">
                                        <i class="fas fa-info"></i> ${t.details || 'Details'}
                                    </button>`
                                }
                            </div>
                        </div>
                    </div>
                `;
            });
            
            html += '</div>';
            
            // Add pagination
            if (totalPages > 1) {
                html += `
                    <nav class="mt-3">
                        <ul class="pagination justify-content-center">
                            <li class="page-item ${currentPage === 1 ? 'disabled' : ''}">
                                <a class="page-link" href="#" onclick="changePage(${currentPage - 1})">
                                    <i class="fas fa-chevron-left"></i> ${t.previous || 'Previous'}
                                </a>
                            </li>
                `;
                
                // Show page numbers
                const startPage = Math.max(1, currentPage - 2);
                const endPage = Math.min(totalPages, currentPage + 2);
                
                if (startPage > 1) {
                    html += `<li class="page-item"><a class="page-link" href="#" onclick="changePage(1)">1</a></li>`;
                    if (startPage > 2) {
                        html += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
                    }
                }
                
                for (let i = startPage; i <= endPage; i++) {
                    html += `
                        <li class="page-item ${i === currentPage ? 'active' : ''}">
                            <a class="page-link" href="#" onclick="changePage(${i})">${i}</a>
                        </li>
                    `;
                }
                
                if (endPage < totalPages) {
                    if (endPage < totalPages - 1) {
                        html += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
                    }
                    html += `<li class="page-item"><a class="page-link" href="#" onclick="changePage(${totalPages})">${totalPages}</a></li>`;
                }
                
                html += `
                            <li class="page-item ${currentPage === totalPages ? 'disabled' : ''}">
                                <a class="page-link" href="#" onclick="changePage(${currentPage + 1})">
                                    ${t.next || 'Next'} <i class="fas fa-chevron-right"></i>
                                </a>
                            </li>
                        </ul>
                        <div class="text-center text-muted small mt-2">
                            ${t.page || 'Page'} ${currentPage} ${t.of || 'of'} ${totalPages}
                        </div>
                    </nav>
                `;
            }
            
            resultsDiv.innerHTML = html;
        }
        
        // Change page
        function changePage(page) {
            const totalPages = Math.ceil(totalTransactions / itemsPerPage);
            if (page >= 1 && page <= totalPages) {
                currentPage = page;
                renderTransactionPage();
            }
            return false; // Prevent default link behavior
        }
        
        // Change items per page
        function changeItemsPerPage(newItemsPerPage) {
            itemsPerPage = parseInt(newItemsPerPage);
            currentPage = 1; // Reset to first page
            renderTransactionPage();
        }
        
        // Get current wallet address (helper function)
        function getCurrentWalletAddress() {
            const select = document.getElementById('historyWalletSelect');
            return select ? select.value : '';
        }
        
        // Filter transactions and update pagination
        function filterTransactions() {
            const typeFilter = document.getElementById('transactionTypeFilter').value;
            const amountFilter = parseFloat(document.getElementById('transactionAmountFilter').value) || 0;
            
            // Filter the original transactions array
            const filteredTransactions = currentTransactions.filter(tx => {
                const walletAddress = getCurrentWalletAddress();
                const isReceived = tx.to_address === walletAddress;
                const type = isReceived ? 'received' : 'sent';
                const amount = parseFloat(tx.amount || 0);
                
                const typeMatch = !typeFilter || type === typeFilter;
                const amountMatch = amount >= amountFilter;
                
                return typeMatch && amountMatch;
            });
            
            // Update current transactions and reset pagination
            const originalTransactions = currentTransactions;
            currentTransactions = filteredTransactions;
            totalTransactions = filteredTransactions.length;
            currentPage = 1;
            
            // Re-render with filtered data
            renderTransactionPage();
            
            // Restore original transactions for future filtering
            setTimeout(() => {
                if (currentTransactions === filteredTransactions) {
                    currentTransactions = originalTransactions;
                }
            }, 100);
        }
        
        // Clear filters and reset pagination
        function clearFilters() {
            document.getElementById('transactionTypeFilter').value = '';
            document.getElementById('transactionAmountFilter').value = '';
            
            // Reset pagination
            currentPage = 1;
            totalTransactions = currentTransactions.length;
            
            // Re-render with all data
            renderTransactionPage();
        }
        
        // View transaction details with comprehensive information and decryption
        async function viewTransactionDetails(txHash, encryptedMessage = null) {
            const walletAddress = getCurrentWalletAddress();
            
            // Try to find transaction in current data first, then fetch from API
            let transaction = currentTransactions.find(tx => tx.hash === txHash);
            
            if (!transaction) {
                // Fetch transaction from API
                try {
                    const response = await fetch(`wallet_api.php?action=get_transaction&hash=${txHash}`);
                    const result = await response.json();
                    
                    if (result.success && result.transaction) {
                        transaction = result.transaction;
                    } else {
                        showNotification(t.transaction_not_found || 'Transaction not found', 'danger');
                        return;
                    }
                } catch (error) {
                    console.error('Error fetching transaction:', error);
                    showNotification(t.transaction_not_found || 'Transaction not found', 'danger');
                    return;
                }
            }
            
            const isReceived = transaction.to_address === walletAddress;
            const date = transaction.timestamp ? new Date(transaction.timestamp * 1000) : new Date();
            const amount = parseFloat(transaction.amount || 0);
            
            // Use encrypted message directly
            let decodedEncryptedMessage = encryptedMessage;
            
            let detailsHTML = `
                <div class="modal fade" id="transactionDetailsModal" tabindex="-1">
                    <div class="modal-dialog modal-xl">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">
                                    <i class="fas fa-info-circle me-2"></i>
                                    ${t.transaction_details || 'Transaction Details'}
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <!-- Transaction Overview -->
                                <div class="row mb-4">
                                    <div class="col-md-6">
                                        <div class="card bg-${isReceived ? 'success' : 'danger'} bg-opacity-10 border-${isReceived ? 'success' : 'danger'}">
                                            <div class="card-body text-center">
                                                <i class="fas fa-${isReceived ? 'arrow-down' : 'arrow-up'} fa-3x text-${isReceived ? 'success' : 'danger'} mb-2"></i>
                                                <h4 class="text-${isReceived ? 'success' : 'danger'}">
                                                    ${isReceived ? '+' : '-'}${amount.toFixed(8)} ${cryptoSymbol}
                                                </h4>
                                                <p class="mb-0">
                                                    <span class="badge bg-${isReceived ? 'success' : 'danger'}">
                                                        ${isReceived ? (t.received || 'Received') : (t.sent || 'Sent')}
                                                    </span>
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="card bg-light">
                                            <div class="card-body">
                                                <h6 class="card-title">${t.status || 'Status'}</h6>
                                                <p class="mb-2">
                                                    <span class="badge ${transaction.confirmations >= 6 ? 'bg-success' : transaction.confirmations > 0 ? 'bg-warning' : 'bg-secondary'}">
                                                        ${transaction.confirmations >= 6 ? 'Confirmed' : transaction.confirmations > 0 ? 'Pending' : 'Unconfirmed'}
                                                    </span>
                                                </p>
                                                <small class="text-muted">
                                                    ${transaction.confirmations || 0} ${t.confirmations || 'confirmations'}
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Transaction Details -->
                                <div class="row">
                                    <div class="col-12">
                                        <h6 class="border-bottom pb-2 mb-3">${t.transaction_details || 'Transaction Details'}</h6>
                                    </div>
                                </div>
                                
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">${t.transaction_hash || 'Transaction Hash'}:</label>
                                        <div class="input-group">
                                            <input type="text" class="form-control font-monospace" value="${txHash}" readonly>
                                            <button class="btn btn-outline-secondary" onclick="copyToClipboard('${txHash}')">
                                                <i class="fas fa-copy"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">${t.timestamp || 'Date & Time'}:</label>
                                        <input type="text" class="form-control" value="${date.toLocaleString()}" readonly>
                                    </div>
                                </div>
                                
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">${t.from_address || 'From Address'}:</label>
                                        <div class="input-group">
                                            <input type="text" class="form-control font-monospace" value="${transaction.from_address}" readonly>
                                            <button class="btn btn-outline-secondary" onclick="copyToClipboard('${transaction.from_address}')">
                                                <i class="fas fa-copy"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">${t.to_address || 'To Address'}:</label>
                                        <div class="input-group">
                                            <input type="text" class="form-control font-monospace" value="${transaction.to_address}" readonly>
                                            <button class="btn btn-outline-secondary" onclick="copyToClipboard('${transaction.to_address}')">
                                                <i class="fas fa-copy"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row mb-3">
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold">${t.amount || 'Amount'}:</label>
                                        <input type="text" class="form-control" value="${amount.toFixed(8)} ${cryptoSymbol}" readonly>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold">${t.fee || 'Transaction Fee'}:</label>
                                        <input type="text" class="form-control" value="${parseFloat(transaction.fee || 0).toFixed(8)} ${cryptoSymbol}" readonly>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold">${t.block_height || 'Block Height'}:</label>
                                        <input type="text" class="form-control" value="${transaction.block_height || t.pending || 'Pending'}" readonly>
                                    </div>
                                </div>
                                
                                ${transaction.memo ? `
                                <div class="row mb-3">
                                    <div class="col-12">
                                        <label class="form-label fw-bold">${t.memo || 'Memo'}:</label>
                                        <div class="alert alert-info">
                                            <i class="fas fa-sticky-note me-2"></i>
                                            ${transaction.memo}
                                        </div>
                                    </div>
                                </div>
                                ` : ''}
            `;
            
            if (decodedEncryptedMessage) {
                detailsHTML += `
                                <!-- Encrypted Message Section -->
                                <div class="row">
                                    <div class="col-12">
                                        <h6 class="border-bottom pb-2 mb-3">${t.encrypted_message || 'Encrypted Message'}</h6>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <div class="card bg-light border-info">
                                        <div class="card-body">
                                            <div class="d-flex align-items-center mb-3">
                                                <i class="fas fa-lock text-info fa-3x me-3"></i>
                                                <div>
                                                    <h6 class="mb-1">${t.encrypted_message || 'Encrypted Message'}</h6>
                                                    <p class="text-muted mb-0">
                                                        ${t.enter_private_key_decrypt || 'Enter your private key to decrypt the message'}
                                                    </p>
                                                    <small class="text-info">
                                                        <i class="fas fa-shield-alt me-1"></i>
                                                        Protected with secp256k1 ECIES encryption
                                                    </small>
                                                </div>
                                            </div>
                                            
                                            <div class="row">
                                                <div class="col-md-8">
                                                    <input type="password" class="form-control" id="decryptPrivateKey" 
                                                           placeholder="${t.enter_private_key || 'Enter your private key'}">
                                                </div>
                                                <div class="col-md-4">
                                                    <button class="btn btn-info w-100 decrypt-in-modal-btn" 
                                                            data-encrypted-message='${decodedEncryptedMessage}' 
                                                            data-tx-hash="${txHash}">
                                                        <i class="fas fa-unlock me-2"></i>
                                                        ${t.decrypt || 'Decrypt'}
                                                    </button>
                                                </div>
                                            </div>
                                            
                                            <div id="decryptedMessage" class="mt-3" style="display: none;">
                                                <div class="alert alert-success border-success">
                                                    <div class="d-flex align-items-center mb-2">
                                                        <i class="fas fa-unlock text-success me-2"></i>
                                                        <strong>${t.decrypted_message || 'Decrypted Message'}:</strong>
                                                    </div>
                                                    <div id="messageContent" class="bg-white p-3 rounded border"></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                `;
            }
            
            detailsHTML += `
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                    <i class="fas fa-times me-2"></i>
                                    ${t.close || 'Close'}
                                </button>
                                <button type="button" class="btn btn-primary" onclick="copyToClipboard('${txHash}')">
                                    <i class="fas fa-copy me-2"></i>
                                    ${t.copy || 'Copy'} Hash
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            // Remove existing modal
            const existingModal = document.getElementById('transactionDetailsModal');
            if (existingModal) {
                existingModal.remove();
            }
            
            // Add new modal
            document.body.insertAdjacentHTML('beforeend', detailsHTML);
            
            // Show modal
            const modal = new bootstrap.Modal(document.getElementById('transactionDetailsModal'));
            modal.show();
        }
        
        // Show blockchain info — real network status & statistics.
        async function showBlockchainInfo() {
            const resultsDiv = document.getElementById('results');
            resultsDiv.innerHTML = '<div class="action-card text-center"><i class="fas fa-spinner fa-spin fa-2x"></i></div>';
            try {
                const [statsR, cfgR] = await Promise.all([
                    fetch('/api/explorer/index.php?action=get_network_stats').then(r => r.json()).catch(() => ({})),
                    fetch('wallet_api.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ action:'get_config' }) }).then(r => r.json()).catch(() => ({}))
                ]);
                const s = statsR || {};
                const row = (k, v) => `<tr><td class="text-muted">${k}</td><td class="text-end fw-bold">${v ?? '-'}</td></tr>`;
                resultsDiv.innerHTML = `
                    <div class="action-card">
                        <h5 class="mb-3"><i class="fas fa-info-circle me-2 text-info"></i>${t.blockchain_info || 'Blockchain Information'}</h5>
                        <table class="table table-sm">
                            ${row('Network', s.network)}
                            ${row('Status', s.status)}
                            ${row('Consensus', s.consensus)}
                            ${row('Block height', s.current_height)}
                            ${row('Total transactions', s.total_transactions)}
                            ${row('Total supply', s.total_supply)}
                            ${row('Circulating supply', s.circulating_supply)}
                            ${row('Active nodes', s.active_nodes)}
                            ${row('Hash rate', s.hash_rate)}
                            ${row('Version', s.version)}
                        </table>
                        <a class="btn btn-outline-primary btn-sm" href="/explorer/" target="_blank"><i class="fas fa-search me-1"></i>Open Explorer</a>
                    </div>`;
            } catch (e) {
                resultsDiv.innerHTML = `<div class="alert alert-danger">Error: ${e.message}</div>`;
            }
        }

        // Show smart contracts — deploy ERC20 tokens and interact with them (real).
        async function showSmartContracts() {
            const resultsDiv = document.getElementById('results');
            const addr = (document.getElementById('myAddress')?.value || localStorage.getItem('walletAddress') || '');
            resultsDiv.innerHTML = `
                <div class="action-card">
                    <h5 class="mb-3"><i class="fas fa-file-contract me-2 text-primary"></i>${t.smart_contracts || 'Smart Contracts'}</h5>
                    <div class="card mb-3"><div class="card-body">
                        <h6 class="fw-bold">Deploy ERC20 Token</h6>
                        <div class="row g-2">
                            <div class="col-md-4"><input id="dtName" class="form-control" placeholder="Name (e.g. My Token)"></div>
                            <div class="col-md-2"><input id="dtSymbol" class="form-control" placeholder="Symbol"></div>
                            <div class="col-md-2"><input id="dtDecimals" class="form-control" type="number" value="18" placeholder="Decimals"></div>
                            <div class="col-md-4"><input id="dtSupply" class="form-control" type="number" placeholder="Total supply"></div>
                        </div>
                        <input id="dtCreator" class="form-control mt-2" placeholder="Creator wallet address (0x...)" value="${addr}">
                        <button class="btn btn-success btn-sm mt-2" onclick="deployTokenUI()"><i class="fas fa-rocket me-1"></i>Deploy Token</button>
                        <div id="dtResult" class="small mt-2"></div>
                    </div></div>
                    <h6 class="fw-bold">Deployed Tokens</h6>
                    <div id="contractsList"><i class="fas fa-spinner fa-spin"></i></div>
                </div>`;
            refreshContractsList();
        }

        async function deployTokenUI() {
            const name = document.getElementById('dtName').value.trim();
            const symbol = document.getElementById('dtSymbol').value.trim();
            const decimals = parseInt(document.getElementById('dtDecimals').value) || 18;
            const total_supply = parseFloat(document.getElementById('dtSupply').value);
            const creator = document.getElementById('dtCreator').value.trim();
            const out = document.getElementById('dtResult');
            if (!name || !symbol || !creator || !(total_supply > 0)) { out.innerHTML = '<span class="text-danger">Fill all fields</span>'; return; }
            out.innerHTML = '<i class="fas fa-spinner fa-spin"></i> deploying...';
            try {
                const r = await fetch('wallet_api.php', { method:'POST', headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({ action:'deploy_token', creator, name, symbol, decimals, total_supply }) });
                const d = await r.json();
                if (d.success && d.deployed) {
                    out.innerHTML = `<span class="text-success">Deployed ${d.symbol} at ${d.address}</span>`;
                    refreshContractsList();
                } else { out.innerHTML = `<span class="text-danger">${d.error || 'Failed'}</span>`; }
            } catch (e) { out.innerHTML = `<span class="text-danger">${e.message}</span>`; }
        }

        async function refreshContractsList() {
            const el = document.getElementById('contractsList');
            if (!el) return;
            try {
                const r = await fetch('wallet_api.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ action:'get_contracts' }) });
                const d = await r.json();
                const list = d.contracts || [];
                if (!list.length) { el.innerHTML = '<p class="text-muted">No tokens deployed yet.</p>'; return; }
                el.innerHTML = list.map(c => `
                    <div class="card mb-2"><div class="card-body py-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <div><strong>${c.symbol}</strong> — ${c.name}<br><small class="text-muted">${c.address}</small></div>
                            <div class="text-end"><small>Supply: ${c.total_supply}</small></div>
                        </div>
                        <div class="input-group input-group-sm mt-2">
                            <input class="form-control" id="bal_${c.address}" placeholder="address to check balance">
                            <button class="btn btn-outline-secondary" onclick="checkTokenBalanceUI('${c.address}')">Balance</button>
                            <span class="input-group-text" id="balres_${c.address}">-</span>
                        </div>
                    </div></div>`).join('');
            } catch (e) { el.innerHTML = `<span class="text-danger">${e.message}</span>`; }
        }

        async function checkTokenBalanceUI(contract) {
            const addr = document.getElementById('bal_' + contract).value.trim()
                || document.getElementById('myAddress')?.value || '';
            const res = document.getElementById('balres_' + contract);
            if (!addr) { res.textContent = 'no addr'; return; }
            try {
                const r = await fetch('wallet_api.php', { method:'POST', headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({ action:'call_contract', contract, method:'balanceOf', args:[addr] }) });
                const d = await r.json();
                res.textContent = (d.result !== undefined ? (+d.result).toLocaleString() : (d.error || '-'));
            } catch (e) { res.textContent = 'err'; }
        }

        // Show settings — language, network/RPC info (real).
        async function showSettings() {
            const resultsDiv = document.getElementById('results');
            let cfg = {};
            try {
                cfg = await fetch('wallet_api.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ action:'get_metamask_config' }) }).then(r => r.json());
            } catch (e) {}
            const nc = cfg.network_config || {};
            const rpc = `${window.location.origin}/wallet/wallet_api.php`;
            resultsDiv.innerHTML = `
                <div class="action-card">
                    <h5 class="mb-3"><i class="fas fa-cogs me-2"></i>${t.settings || 'Settings'}</h5>
                    <div class="mb-3">
                        <label class="form-label fw-bold">${t.language || 'Language'}</label>
                        <div class="btn-group d-block">
                            <a class="btn btn-sm btn-outline-primary" href="?lang=en">English</a>
                            <a class="btn btn-sm btn-outline-primary" href="?lang=ru">Русский</a>
                        </div>
                    </div>
                    <table class="table table-sm">
                        <tr><td class="text-muted">Network name</td><td class="text-end fw-bold">${nc.name || nc.network_name || '-'}</td></tr>
                        <tr><td class="text-muted">Chain ID</td><td class="text-end fw-bold">${nc.chainId || nc.chain_id || '-'}</td></tr>
                        <tr><td class="text-muted">Token symbol</td><td class="text-end fw-bold">${nc.symbol || nc.token_symbol || '-'}</td></tr>
                        <tr><td class="text-muted">RPC endpoint</td><td class="text-end"><code>${rpc}</code></td></tr>
                    </table>
                </div>`;
        }

        // Show about
        function showAbout() {
            const resultsDiv = document.getElementById('results');
            resultsDiv.innerHTML = `
                <div class="action-card text-center">
                    <div class="action-icon icon-info mx-auto">
                        <i class="fas fa-info-circle"></i>
                    </div>
                    <h5>${t.about || 'About'}</h5>
                    <p class="text-muted">${t.about_description || 'About this blockchain wallet'}</p>
                    <div class="alert alert-info alert-modern mt-3">
                        <i class="fas fa-wallet me-2"></i>
                        ${t.about_info || 'Secure blockchain wallet for managing your digital assets'}
                    </div>
                </div>
            `;
        }

        // Make functions globally accessible
        window.startWalletCreation = startWalletCreation;
        window.showRestoreModal = showRestoreModal;
        window.showMyWallets = showMyWallets;
        window.showTransactionHistory = showTransactionHistory;
        window.showStakingModal = showStakingModal;
        window.showDashboard = showDashboard;
        window.showBlockchainInfo = showBlockchainInfo;
        window.showSmartContracts = showSmartContracts;
        window.showSettings = showSettings;
        window.showAbout = showAbout;
        window.confirmStaking = confirmStaking;
        window.confirmRestore = confirmRestore;
        window.closeModal = closeModal;
        window.closeMnemonicModal = closeMnemonicModal;
        window.copyToClipboard = copyToClipboard;
        window.generateMnemonic = generateMnemonic;
        window.updateAvailableBalance = updateAvailableBalance;
        window.loadWalletHistory = loadWalletHistory;
        window.filterTransactions = filterTransactions;
        window.clearFilters = clearFilters;
        window.viewTransactionDetails = viewTransactionDetails;
        window.attemptAutoDecrypt = attemptAutoDecrypt;
        window.tryDecryptWithKey = tryDecryptWithKey;
        window.showInlineDecryptedMessage = showInlineDecryptedMessage;
        window.decryptInModal = decryptInModal;
        window.displayTransactions = displayTransactions;
        window.displayTransactionHistory = displayTransactionHistory;
        window.renderTransactionPage = renderTransactionPage;
        window.changePage = changePage;
        window.changeItemsPerPage = changeItemsPerPage;
        window.getCurrentWalletAddress = getCurrentWalletAddress;
        window.showDecryptModal = showDecryptModal;
        window.decryptMessageFromModal = decryptMessageFromModal;
        window.utf8ToBase64 = utf8ToBase64;
        window.base64ToUtf8 = base64ToUtf8;
        
        // Setup memo character counter
        document.addEventListener('DOMContentLoaded', function() {
            const memoField = document.getElementById('transferMemo');
            const charCount = document.getElementById('memoCharCount');
            
            if (memoField && charCount) {
                function updateCharCount() {
                    const count = memoField.value.length;
                    charCount.textContent = count;
                    
                    // Change color based on length
                    if (count > 900) {
                        charCount.style.color = '#dc3545'; // danger red
                    } else if (count > 800) {
                        charCount.style.color = '#fd7e14'; // warning orange
                    } else {
                        charCount.style.color = '#6c757d'; // muted gray
                    }
                }
                
                memoField.addEventListener('input', updateCharCount);
                memoField.addEventListener('paste', function() {
                    setTimeout(updateCharCount, 10);
                });
                
                // Initial count
                updateCharCount();
            }
            
            // Setup event listeners for encrypted message icons
            document.addEventListener('click', function(e) {
                if (e.target.classList.contains('encrypted-message-icon')) {
                    const encryptedMessage = e.target.getAttribute('data-encrypted-message');
                    if (encryptedMessage) {
                        attemptAutoDecrypt(encryptedMessage);
                    }
                }
                
                // Setup event listeners for view details buttons
                if (e.target.closest('.view-details-btn')) {
                    const button = e.target.closest('.view-details-btn');
                    const txHash = button.getAttribute('data-tx-hash');
                    const encryptedMessage = button.getAttribute('data-encrypted-message');
                    
                    if (encryptedMessage) {
                        viewTransactionDetails(txHash, encryptedMessage);
                    } else {
                        viewTransactionDetails(txHash);
                    }
                }
                
                // Setup event listeners for decrypt message buttons
                if (e.target.closest('.decrypt-message-btn')) {
                    const button = e.target.closest('.decrypt-message-btn');
                    const encryptedMessage = button.getAttribute('data-encrypted-message');
                    const txHash = button.getAttribute('data-tx-hash');
                    
                    if (encryptedMessage) {
                        attemptAutoDecrypt(encryptedMessage);
                    }
                }
                
                // Setup event listeners for decrypt in modal buttons
                if (e.target.closest('.decrypt-in-modal-btn')) {
                    const button = e.target.closest('.decrypt-in-modal-btn');
                    const encryptedMessage = button.getAttribute('data-encrypted-message');
                    const txHash = button.getAttribute('data-tx-hash');
                    
                    if (encryptedMessage) {
                        decryptInModal(encryptedMessage, button);
                    }
                }
                
                // Setup event listeners for copy message buttons
                if (e.target.closest('.copy-message-btn')) {
                    const button = e.target.closest('.copy-message-btn');
                    const message = button.getAttribute('data-message');
                    
                    if (message) {
                        copyToClipboard(message);
                    }
                }
            });
        });
        
        // Delete wallet from saved list
        async function deleteWalletFromList(address) {
            if (!confirm(t.confirm_delete_wallet || 'Are you sure you want to delete this wallet from your saved list?')) {
                return;
            }
            
            try {
                // Get current saved wallets (используем myWallets как основное хранилище)
                const myWallets = JSON.parse(localStorage.getItem('myWallets') || '[]');
                
                // Filter out the wallet to delete
                const updatedWallets = myWallets.filter(wallet => wallet.address !== address);
                
                // Save updated list
                localStorage.setItem('myWallets', JSON.stringify(updatedWallets));
                
                // Refresh the display
                showMyWallets();
                
                showNotification(t.wallet_deleted || 'Wallet deleted from saved list', 'success');
            } catch (error) {
                console.error('Error deleting wallet:', error);
                showNotification(t.error + ' ' + error.message, 'danger');
            }
        }
        
        // Function to save wallet to localStorage
        function saveWalletToList(walletData) {
            try {
                const savedWallets = JSON.parse(localStorage.getItem('savedWallets') || '[]');
                
                // Check if wallet already exists
                const existingIndex = savedWallets.findIndex(w => w.address === walletData.address);
                
                if (existingIndex >= 0) {
                    // Update existing wallet
                    savedWallets[existingIndex] = {
                        ...savedWallets[existingIndex],
                        ...walletData,
                        savedAt: new Date().toISOString()
                    };
                } else {
                    // Add new wallet
                    savedWallets.push({
                        ...walletData,
                        savedAt: new Date().toISOString()
                    });
                }
                
                localStorage.setItem('savedWallets', JSON.stringify(savedWallets));
                showNotification(t.wallet_saved || 'Wallet saved successfully', 'success');
            } catch (error) {
                console.error('Error saving wallet:', error);
                showNotification(t.error + ' ' + error.message, 'danger');
            }
        }
        
        // DeFi Liquidity Functions
        function showLiquidityModal() {
            const modal = new bootstrap.Modal(document.getElementById('liquidityModal'));
            modal.show();
            loadAvailableTokens();
        }
        
        function showPoolsModal() {
            const modal = new bootstrap.Modal(document.getElementById('poolsModal'));
            modal.show();
            loadAllPools();
        }
        
        function showMyPositions() {
            const modal = new bootstrap.Modal(document.getElementById('positionsModal'));
            modal.show();
        }
        
        async function loadAllPools() {
            const loadingEl = document.getElementById('poolsLoading');
            const contentEl = document.getElementById('poolsContent');
            const errorEl = document.getElementById('poolsError');
            
            loadingEl.style.display = 'block';
            contentEl.style.display = 'none';
            errorEl.style.display = 'none';
            
            try {
                const response = await fetch('wallet_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'get_all_pairs' })
                });
                
                const data = await response.json();
                
                if (data.success && data.pairs) {
                    const tbody = document.getElementById('poolsTableBody');
                    tbody.innerHTML = '';
                    
                    data.pairs.forEach(pool => {
                        const row = `
                            <tr>
                                <td><strong>${pool.name || 'Pool'}</strong><br>
                                    <small class="text-muted">${pool.address}</small></td>
                                <td>${pool.token0} / ${pool.token1}</td>
                                <td>${pool.reserves0} / ${pool.reserves1}</td>
                                <td>${pool.total_supply} LP</td>
                                <td>~5.2%</td>
                                <td>
                                    <button class="btn btn-sm btn-primary" onclick="addLiquidityToExisting('${pool.token0}', '${pool.token1}')">
                                        <i class="fas fa-plus"></i> Add
                                    </button>
                                </td>
                            </tr>
                        `;
                        tbody.innerHTML += row;
                    });
                    
                    loadingEl.style.display = 'none';
                    contentEl.style.display = 'block';
                } else {
                    throw new Error(data.error || 'Failed to load pools');
                }
            } catch (error) {
                loadingEl.style.display = 'none';
                errorEl.style.display = 'block';
                document.getElementById('poolsErrorMessage').textContent = error.message;
            }
        }
        
        async function loadUserPositions() {
            const address = document.getElementById('positionsAddress').value;
            if (!address) {
                showNotification('Please enter your address', 'warning');
                return;
            }
            
            const loadingEl = document.getElementById('positionsLoading');
            const contentEl = document.getElementById('positionsContent');
            const errorEl = document.getElementById('positionsError');
            
            loadingEl.style.display = 'block';
            contentEl.style.display = 'none';
            errorEl.style.display = 'none';
            
            try {
                const response = await fetch('wallet_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ 
                        action: 'get_user_positions',
                        address: address 
                    })
                });
                
                const data = await response.json();
                
                if (data.success && data.positions) {
                    const tbody = document.getElementById('positionsTableBody');
                    tbody.innerHTML = '';
                    
                    if (data.positions.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="5" class="text-center">No positions found</td></tr>';
                    } else {
                        data.positions.forEach(position => {
                            const providedDate = new Date(position.provided_at * 1000).toLocaleDateString();
                            const row = `
                                <tr>
                                    <td><strong>${position.token0} / ${position.token1}</strong><br>
                                        <small class="text-muted">${position.pool_address}</small></td>
                                    <td>${position.lp_tokens}</td>
                                    <td>${position.share_percent || '0.00'}%</td>
                                    <td>${providedDate}</td>
                                    <td>
                                        <button class="btn btn-sm btn-danger" onclick="removeLiquidityFromPosition('${position.pool_address}', '${position.lp_tokens}', '${position.token0}', '${position.token1}')">
                                            <i class="fas fa-minus"></i> Remove
                                        </button>
                                    </td>
                                </tr>
                            `;
                            tbody.innerHTML += row;
                        });
                    }
                    
                    loadingEl.style.display = 'none';
                    contentEl.style.display = 'block';
                } else {
                    throw new Error(data.error || 'Failed to load positions');
                }
            } catch (error) {
                loadingEl.style.display = 'none';
                errorEl.style.display = 'block';
                document.getElementById('positionsErrorMessage').textContent = error.message;
            }
        }
        
        function addLiquidityToExisting(tokenA, tokenB) {
            // Закрываем модал пулов и открываем модал добавления ликвидности
            bootstrap.Modal.getInstance(document.getElementById('poolsModal')).hide();
            
            // Заполняем форму
            setTimeout(() => {
                document.getElementById('tokenA').value = tokenA;
                document.getElementById('tokenB').value = tokenB;
                showLiquidityModal();
            }, 300);
        }
        
        async function removeLiquidityFromPosition(poolAddress, lpTokens, token0, token1) {
            const address = (document.getElementById('positionsAddress') || {}).value
                || document.getElementById('myAddress')?.value
                || prompt('Enter your wallet address:');
            if (!address) return;
            const amountStr = prompt(`How many LP tokens to remove? (you have ${lpTokens})`, lpTokens);
            if (amountStr === null) return;
            const liquidity = parseFloat(amountStr);
            if (!(liquidity > 0)) { showNotification('Invalid LP amount', 'warning'); return; }
            try {
                const response = await fetch('wallet_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'remove_liquidity',
                        address: address,
                        tokenA: token0,
                        tokenB: token1,
                        liquidity: liquidity,
                        amountAMin: 0,
                        amountBMin: 0
                    })
                });
                const data = await response.json();
                if (data.success && data.liquidity_removed) {
                    showNotification(`Removed liquidity: ${(+data.amountA).toFixed(6)} ${token0} + ${(+data.amountB).toFixed(6)} ${token1}`, 'success');
                    if (typeof showMyPositions === 'function') showMyPositions();
                } else {
                    showNotification('Error: ' + (data.error || 'Unknown error'), 'danger');
                }
            } catch (error) {
                showNotification('Error: ' + error.message, 'danger');
            }
        }
        
        // Shared token list for all DEX UIs. Returns [{id, label}] with native normalized to 'native'.
        let __dexTokensCache = null;
        async function dexFetchTokens() {
            if (__dexTokensCache) return __dexTokensCache;
            const out = [];
            try {
                const response = await fetch('wallet_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'get_available_tokens' })
                });
                const data = await response.json();
                (data.tokens || []).forEach(tk => {
                    // Backend treats the native coin strictly as 'native'.
                    const id = (tk.address === 'native_token' || tk.address === 'native' || tk.address === 'main_token')
                        ? 'native' : tk.address;
                    out.push({ id: id, label: `${tk.symbol} — ${tk.name}` });
                });
            } catch (e) { console.error('dexFetchTokens', e); }
            if (!out.length) out.push({ id: 'native', label: 'Native coin' });
            __dexTokensCache = out;
            return out;
        }

        function dexFillSelect(selectEl, tokens, placeholder) {
            if (!selectEl) return;
            selectEl.innerHTML = (placeholder ? `<option value="">${placeholder}</option>` : '')
                + tokens.map(t => `<option value="${t.id}">${t.label}</option>`).join('');
        }

        async function loadAvailableTokens() {
            const tokens = await dexFetchTokens();
            dexFillSelect(document.getElementById('tokenA'), tokens, 'Select Token A');
            dexFillSelect(document.getElementById('tokenB'), tokens, 'Select Token B');
        }

        // ---------------- Swap UI ----------------
        let __swapQuoteTimer = null;
        async function showSwapModal() {
            const tokens = await dexFetchTokens();
            dexFillSelect(document.getElementById('swapTokenIn'), tokens, null);
            dexFillSelect(document.getElementById('swapTokenOut'), tokens, null);
            // default: native -> first non-native
            const inSel = document.getElementById('swapTokenIn');
            const outSel = document.getElementById('swapTokenOut');
            inSel.value = 'native';
            const other = tokens.find(t => t.id !== 'native');
            if (other) outSel.value = other.id;
            const addr = (document.getElementById('myAddress')?.value || localStorage.getItem('walletAddress') || '');
            document.getElementById('swapAddress').value = addr;
            document.getElementById('swapAmountIn').value = '';
            document.getElementById('swapAmountOut').value = '';
            document.getElementById('swapQuoteBox').style.display = 'none';
            document.getElementById('swapError').textContent = '';
            document.getElementById('swapBtn').disabled = true;
            ['swapAmountIn','swapTokenIn','swapTokenOut'].forEach(id => {
                const el = document.getElementById(id);
                el.oninput = el.onchange = () => { scheduleSwapQuote(); refreshSwapBalance(); };
            });
            refreshSwapBalance();
            new bootstrap.Modal(document.getElementById('swapModal')).show();
        }

        function swapFlipTokens() {
            const a = document.getElementById('swapTokenIn');
            const b = document.getElementById('swapTokenOut');
            const tmp = a.value; a.value = b.value; b.value = tmp;
            scheduleSwapQuote(); refreshSwapBalance();
        }

        async function refreshSwapBalance() {
            const addr = document.getElementById('swapAddress').value.trim();
            const token = document.getElementById('swapTokenIn').value;
            const el = document.getElementById('swapBalanceIn');
            if (!addr) { el.textContent = '-'; return; }
            try {
                const r = await fetch('wallet_api.php', { method:'POST', headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({ action:'get_token_balance', address: addr, token: token }) });
                const d = await r.json();
                el.textContent = (d.balance !== undefined ? (+d.balance).toFixed(6) : '-');
            } catch (e) { el.textContent = '-'; }
        }

        function scheduleSwapQuote() {
            clearTimeout(__swapQuoteTimer);
            __swapQuoteTimer = setTimeout(updateSwapQuote, 300);
        }

        async function updateSwapQuote() {
            const tokenIn = document.getElementById('swapTokenIn').value;
            const tokenOut = document.getElementById('swapTokenOut').value;
            const amountIn = parseFloat(document.getElementById('swapAmountIn').value);
            const errEl = document.getElementById('swapError');
            const box = document.getElementById('swapQuoteBox');
            const btn = document.getElementById('swapBtn');
            errEl.textContent = '';
            if (tokenIn === tokenOut) { errEl.textContent = 'Select two different tokens'; box.style.display='none'; btn.disabled=true; return; }
            if (!(amountIn > 0)) { document.getElementById('swapAmountOut').value=''; box.style.display='none'; btn.disabled=true; return; }
            try {
                const r = await fetch('wallet_api.php', { method:'POST', headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({ action:'get_swap_quote', tokenIn, tokenOut, amountIn }) });
                const d = await r.json();
                if (d.error || !d.pair_exists) {
                    document.getElementById('swapAmountOut').value=''; box.style.display='none'; btn.disabled=true;
                    errEl.textContent = d.error || 'No pool for this pair';
                    return;
                }
                document.getElementById('swapAmountOut').value = (+d.amountOut).toFixed(8);
                document.getElementById('swapFee').textContent = (+d.fee).toFixed(6);
                document.getElementById('swapImpact').textContent = (+(d.price_impact||0)).toFixed(4);
                document.getElementById('swapMinOut').textContent = (+(d.minimum_received ?? d.amountOut*0.995)).toFixed(8);
                box.style.display='block';
                btn.disabled = false;
            } catch (e) {
                errEl.textContent = 'Quote error: ' + e.message; btn.disabled=true;
            }
        }

        async function executeSwap() {
            const address = document.getElementById('swapAddress').value.trim();
            const tokenIn = document.getElementById('swapTokenIn').value;
            const tokenOut = document.getElementById('swapTokenOut').value;
            const amountIn = parseFloat(document.getElementById('swapAmountIn').value);
            const amountOut = parseFloat(document.getElementById('swapAmountOut').value) || 0;
            const amountOutMin = amountOut * 0.995; // 0.5% slippage
            if (!address) { showNotification('Enter wallet address', 'warning'); return; }
            if (!(amountIn > 0) || tokenIn === tokenOut) { showNotification('Invalid swap', 'warning'); return; }
            const btn = document.getElementById('swapBtn');
            btn.disabled = true; const orig = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Swapping...';
            try {
                const r = await fetch('wallet_api.php', { method:'POST', headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({ action:'swap_tokens', from: address, to: address, tokenIn, tokenOut, amountIn, amountOutMin }) });
                const d = await r.json();
                if (d.success && d.swap_executed) {
                    showNotification(`Swapped ${amountIn} for ${(+d.amountOut).toFixed(6)} (fee ${(+d.fee).toFixed(4)})`, 'success');
                    refreshSwapBalance();
                    document.getElementById('swapAmountIn').value='';
                    document.getElementById('swapAmountOut').value='';
                    document.getElementById('swapQuoteBox').style.display='none';
                } else {
                    showNotification('Swap failed: ' + (d.error || 'Unknown error'), 'danger');
                }
            } catch (e) {
                showNotification('Swap error: ' + e.message, 'danger');
            } finally {
                btn.innerHTML = orig; btn.disabled = false;
            }
        }
        
        // NOTE: addLiquidityToPool() is defined once below (supports manual + MetaMask).
    </script>

    <!-- Swap Modal -->
    <div class="modal fade" id="swapModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-exchange-alt me-2"></i><?php echo $t['swap_tokens_title'] ?? 'Swap Tokens'; ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold"><?php echo $t['wallet_address'] ?? 'Wallet address'; ?></label>
                        <input type="text" class="form-control" id="swapAddress" placeholder="0x...">
                    </div>
                    <div class="mb-2">
                        <label class="form-label fw-bold"><?php echo $t['from'] ?? 'From'; ?></label>
                        <div class="input-group">
                            <input type="number" class="form-control" id="swapAmountIn" placeholder="0.0" min="0" step="any">
                            <select class="form-select" id="swapTokenIn" style="max-width:45%"></select>
                        </div>
                        <small class="text-muted">Balance: <span id="swapBalanceIn">-</span></small>
                    </div>
                    <div class="text-center my-1">
                        <button class="btn btn-sm btn-outline-secondary" type="button" onclick="swapFlipTokens()" title="Flip"><i class="fas fa-arrows-up-down"></i> ⬍</button>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold"><?php echo $t['to'] ?? 'To'; ?></label>
                        <div class="input-group">
                            <input type="text" class="form-control" id="swapAmountOut" placeholder="0.0" readonly>
                            <select class="form-select" id="swapTokenOut" style="max-width:45%"></select>
                        </div>
                    </div>
                    <div class="alert alert-secondary py-2 small" id="swapQuoteBox" style="display:none;">
                        <div>Rate fee: <span id="swapFee">0</span></div>
                        <div>Price impact: <span id="swapImpact">0</span>%</div>
                        <div>Min received (0.5% slippage): <span id="swapMinOut">0</span></div>
                    </div>
                    <div id="swapError" class="text-danger small mb-2"></div>
                    <button class="btn btn-success w-100" id="swapBtn" onclick="executeSwap()" disabled>
                        <i class="fas fa-exchange-alt me-2"></i><?php echo $t['swap_tokens_title'] ?? 'Swap'; ?>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Liquidity Modal -->
    <div class="modal fade" id="liquidityModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-plus-circle me-2"></i><?php echo $t['add_liquidity']; ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- MetaMask Connection Status -->
                    <div class="metamask-status mb-3">
                        <div class="alert alert-info">
                            <i class="fas fa-wallet me-2"></i>
                            Connect your MetaMask wallet to add liquidity
                        </div>
                    </div>
                    
                    <div class="metamask-connect-btn mb-3">
                        <button type="button" class="btn btn-warning w-100" onclick="connectMetaMask()">
                            <i class="fab fa-ethereum me-2"></i><?php echo $t['connect_metamask']; ?>
                        </button>
                    </div>
                    
                    <!-- Method Selection -->
                    <div class="card mb-3">
                        <div class="card-body">
                            <h6 class="card-title"><i class="fas fa-cogs me-2"></i>Choose Method:</h6>
                            <div class="btn-group w-100" role="group">
                                <input type="radio" class="btn-check" name="liquidityMethod" id="methodMetaMask" value="metamask" checked>
                                <label class="btn btn-outline-primary" for="methodMetaMask">
                                    <i class="fab fa-ethereum me-2"></i>MetaMask
                                </label>
                                
                                <input type="radio" class="btn-check" name="liquidityMethod" id="methodBackend" value="backend">
                                <label class="btn btn-outline-secondary" for="methodBackend">
                                    <i class="fas fa-server me-2"></i>Manual Address
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <form id="liquidityForm">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><?php echo $t['token_a']; ?></label>
                                <select class="form-select" id="tokenA" required>
                                    <option value=""><?php echo $t['select_token']; ?></option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><?php echo $t['token_b']; ?></label>
                                <select class="form-select" id="tokenB" required>
                                    <option value=""><?php echo $t['select_token']; ?></option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><?php echo $t['amount_a']; ?></label>
                                <input type="number" class="form-control" id="amountA" 
                                       step="0.01" min="0.01" placeholder="0.00" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><?php echo $t['amount_b']; ?></label>
                                <input type="number" class="form-control" id="amountB" 
                                       step="0.01" min="0.01" placeholder="0.00" required>
                            </div>
                        </div>
                        
                        <!-- Address field - only shown for manual method -->
                        <div class="mb-3" id="addressField" style="display: none;">
                            <label class="form-label"><?php echo $t['address']; ?></label>
                            <input type="text" class="form-control" id="liquidityAddress" 
                                   placeholder="0x..." value="">
                            <small class="form-text text-muted">
                                Enter your wallet address manually (not recommended if MetaMask is available)
                            </small>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label"><?php echo $t['slippage_tolerance']; ?></label>
                            <select class="form-select" id="slippage">
                                <option value="0.5">0.5%</option>
                                <option value="1" selected>1%</option>
                                <option value="3">3%</option>
                                <option value="5">5%</option>
                            </select>
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Info:</strong> By adding liquidity, you'll receive LP tokens representing your share in the pool. You'll earn trading fees from all swaps in this pool.
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo $t['cancel']; ?></button>
                    <button type="button" class="btn btn-primary" id="addLiquidityBtn" onclick="addLiquidityToPool()">
                        <i class="fas fa-plus me-2"></i><?php echo $t['add_liquidity']; ?>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Pools Modal -->
    <div class="modal fade" id="poolsModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-swimming-pool me-2"></i><?php echo $t['liquidity_pools']; ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="poolsLoading" class="text-center">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-2">Loading pools...</p>
                    </div>
                    <div id="poolsContent" style="display: none;">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Pool</th>
                                        <th>Tokens</th>
                                        <th>Reserves</th>
                                        <th>Total Liquidity</th>
                                        <th>APY</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="poolsTableBody">
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div id="poolsError" style="display: none;" class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <span id="poolsErrorMessage"></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Positions Modal -->
    <div class="modal fade" id="positionsModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-chart-line me-2"></i><?php echo $t['your_positions']; ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Your Address:</label>
                        <input type="text" class="form-control" id="positionsAddress" 
                               placeholder="Enter your wallet address..." value="">
                        <button class="btn btn-primary mt-2" onclick="loadUserPositions()">
                            <i class="fas fa-search me-2"></i>Load Positions
                        </button>
                    </div>
                    
                    <div id="positionsLoading" class="text-center" style="display: none;">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-2">Loading your positions...</p>
                    </div>
                    <div id="positionsContent" style="display: none;">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Pool</th>
                                        <th>Your LP Tokens</th>
                                        <th><?php echo $t['pool_share']; ?></th>
                                        <th>Provided At</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="positionsTableBody">
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div id="positionsError" style="display: none;" class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <span id="positionsErrorMessage"></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Web3 and MetaMask Integration -->
    <script src="https://cdn.jsdelivr.net/npm/web3@1.8.0/dist/web3.min.js"></script>
    <script>
    // MetaMask and Web3 Integration
    class MetaMaskIntegration {
        constructor() {
            this.web3 = null;
            this.account = null;
            this.chainId = null;
            this.contracts = {};
            this.networkConfig = null;
            
            this.init();
        }
        
        async init() {
            if (typeof window.ethereum !== 'undefined') {
                this.web3 = new Web3(window.ethereum);
                await this.loadNetworkConfig();
                this.setupEventListeners();
                this.updateConnectionStatus();
            } else {
                console.log('MetaMask not detected');
            }
        }
        
        async loadNetworkConfig() {
            try {
                const response = await fetch('wallet_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'get_metamask_config' })
                });
                
                const data = await response.json();
                if (data.success) {
                    this.networkConfig = data.network_config;
                    this.contracts = data.contracts || {};
                }
            } catch (error) {
                console.error('Failed to load network config:', error);
            }
        }
        
        setupEventListeners() {
            // Account change listener
            if (window.ethereum && window.ethereum.on) {
                window.ethereum.on('accountsChanged', (accounts) => {
                    this.account = accounts[0] || null;
                    this.updateConnectionStatus();
                });
                
                // Chain change listener
                window.ethereum.on('chainChanged', (chainId) => {
                    this.chainId = chainId;
                    window.location.reload();
                });
            }
        }
        
        async connectWallet() {
            try {
                if (!window.ethereum) {
                    throw new Error('MetaMask not installed');
                }
                
                // Request account access
                const accounts = await window.ethereum.request({
                    method: 'eth_requestAccounts'
                });
                
                this.account = accounts[0];
                
                // Get chain ID
                this.chainId = await window.ethereum.request({
                    method: 'eth_chainId'
                });
                
                this.updateConnectionStatus();
                return this.account;
            } catch (error) {
                console.error('Failed to connect wallet:', error);
                throw error;
            }
        }
        
        updateConnectionStatus() {
            const statusElements = document.querySelectorAll('.metamask-status');
            const connectButtons = document.querySelectorAll('.metamask-connect-btn');
            const addressFields = document.querySelectorAll('#liquidityAddress');
            
            statusElements.forEach(el => {
                if (this.account) {
                    el.innerHTML = `
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle me-2"></i>
                            <strong>Connected:</strong> ${this.account.substring(0, 10)}...${this.account.substring(this.account.length - 4)}
                        </div>
                    `;
                } else {
                    el.innerHTML = `
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            MetaMask not connected
                        </div>
                    `;
                }
            });
            
            connectButtons.forEach(btn => {
                if (this.account) {
                    btn.style.display = 'none';
                } else {
                    btn.style.display = 'block';
                }
            });
            
            // Auto-fill address if connected
            addressFields.forEach(field => {
                if (this.account && !field.value) {
                    field.value = this.account;
                }
            });
        }
        
        async addLiquidityWithMetaMask(tokenA, tokenB, amountA, amountB) {
            if (!this.account) {
                throw new Error('Please connect MetaMask first');
            }
            
            // For now, use the backend API but with MetaMask address
            return await this.addLiquidityViaBackend(tokenA, tokenB, amountA, amountB);
        }
        
        async addLiquidityViaBackend(tokenA, tokenB, amountA, amountB) {
            const response = await fetch('wallet_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'create_liquidity_pool',
                    address: this.account,
                    tokenA: tokenA,
                    tokenB: tokenB,
                    amountA: amountA,
                    amountB: amountB
                })
            });
            
            const data = await response.json();
            if (!data.success) {
                throw new Error(data.error || 'Failed to add liquidity');
            }
            
            return data;
        }
        
        isConnected() {
            return !!this.account;
        }
        
        getAccount() {
            return this.account;
        }
    }

    // Initialize MetaMask integration
    let metaMask;
    document.addEventListener('DOMContentLoaded', function() {
        metaMask = new MetaMaskIntegration();
        
        // Setup method selection change handler
        const methodInputs = document.querySelectorAll('input[name="liquidityMethod"]');
        methodInputs.forEach(input => {
            input.addEventListener('change', function() {
                toggleMethodFields(this.value);
            });
        });
    });

    // Global functions for MetaMask
    async function connectMetaMask() {
        try {
            if (!metaMask) {
                throw new Error('MetaMask integration not initialized');
            }
            
            await metaMask.connectWallet();
            showNotification('MetaMask connected successfully!', 'success');
        } catch (error) {
            console.error('MetaMask connection error:', error);
            showNotification('Failed to connect MetaMask: ' + error.message, 'danger');
        }
    }

    function toggleMethodFields(method) {
        const addressField = document.getElementById('addressField');
        const liquidityAddress = document.getElementById('liquidityAddress');
        
        if (method === 'metamask') {
            addressField.style.display = 'none';
            liquidityAddress.required = false;
            
            // Auto-fill if MetaMask connected
            if (metaMask && metaMask.isConnected()) {
                liquidityAddress.value = metaMask.getAccount();
            }
        } else {
            addressField.style.display = 'block';
            liquidityAddress.required = true;
        }
    }

    // Enhanced add liquidity function with method selection
    async function addLiquidityToPool() {
        const tokenA = document.getElementById('tokenA').value;
        const tokenB = document.getElementById('tokenB').value;
        const amountA = parseFloat(document.getElementById('amountA').value);
        const amountB = parseFloat(document.getElementById('amountB').value);
        const method = document.querySelector('input[name="liquidityMethod"]:checked').value;
        
        if (!tokenA || !tokenB || !amountA || !amountB) {
            showNotification('Please fill all fields', 'warning');
            return;
        }
        
        const button = document.getElementById('addLiquidityBtn');
        const originalText = button.innerHTML;
        button.disabled = true;
        
        try {
            let result;
            
            if (method === 'metamask') {
                if (!metaMask || !metaMask.isConnected()) {
                    throw new Error('Please connect MetaMask first');
                }
                
                button.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Processing with MetaMask...';
                result = await metaMask.addLiquidityWithMetaMask(tokenA, tokenB, amountA, amountB);
            } else {
                const address = document.getElementById('liquidityAddress').value;
                if (!address) {
                    throw new Error('Please enter wallet address');
                }
                
                button.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Processing...';
                
                const response = await fetch('wallet_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'create_liquidity_pool',
                        address: address,
                        tokenA: tokenA,
                        tokenB: tokenB,
                        amountA: amountA,
                        amountB: amountB
                    })
                });
                
                result = await response.json();
                if (!result.success) {
                    throw new Error(result.error || 'Unknown error');
                }
            }
            
            showNotification(`Liquidity added successfully! LP Tokens: ${result.lp_tokens || 0}`, 'success');
            
            // Reset form
            document.getElementById('liquidityForm').reset();
            
            // Close modal
            bootstrap.Modal.getInstance(document.getElementById('liquidityModal')).hide();
            
        } catch (error) {
            console.error('Error adding liquidity:', error);
            showNotification('Error: ' + error.message, 'danger');
        } finally {
            button.disabled = false;
            button.innerHTML = originalText;
        }
    }

    // Make functions globally available
    window.connectMetaMask = connectMetaMask;
    window.toggleMethodFields = toggleMethodFields;
    </script>
</body>
</html>
