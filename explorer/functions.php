<?php
/**
 * Common functions for explorer pages
 */

// Load language strings
function loadLanguage($lang, $cryptoName = 'Blockchain') {
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
            'language' => 'Language',
            'block_details' => 'Block Details',
            'block_hash' => 'Block Hash',
            'previous_hash' => 'Previous Hash',
            'timestamp' => 'Timestamp',
            'seconds_ago' => 'seconds ago',
            'genesis' => 'GENESIS',
            'transaction_details' => 'Transaction Details',
            'transaction' => 'Transaction',
            'status' => 'Status',
            'fee' => 'Fee',
            'gas_limit' => 'Gas Limit',
            'gas_price' => 'Gas Price',
            'nonce' => 'Nonce',
            'data' => 'Data',
            'signature' => 'Signature',
            'unknown' => 'Unknown',
            'no_data' => 'No data',
            'raw_data' => 'Raw Data',
            'parsed_data' => 'Parsed Data'
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
            'language' => 'Язык',
            'block_details' => 'Детали блока',
            'block_hash' => 'Хеш блока',
            'previous_hash' => 'Предыдущий хеш',
            'timestamp' => 'Временная метка',
            'seconds_ago' => 'секунд назад',
            'genesis' => 'ГЕНЕЗИС',
            'transaction_details' => 'Детали транзакции',
            'transaction' => 'Транзакция',
            'status' => 'Статус',
            'fee' => 'Комиссия',
            'gas_limit' => 'Лимит газа',
            'gas_price' => 'Цена газа',
            'nonce' => 'Нонс',
            'data' => 'Данные',
            'signature' => 'Подпись',
            'unknown' => 'Неизвестно',
            'no_data' => 'Нет данных',
            'raw_data' => 'Сырые данные',
            'parsed_data' => 'Разобранные данные'
        ]
    ];
    
    return $translations[$lang] ?? $translations['en'];
}

// Language selector helper
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
