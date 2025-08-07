<?php
/**
 * API Documentation Page
 * Comprehensive documentation for all blockchain API endpoints
 */

// Get language from URL parameter or default to English
$lang = $_GET['lang'] ?? 'en';
if (!in_array($lang, ['en', 'ru'])) {
    $lang = 'en';
}

// Language translations
$translations = [
    'en' => [
        'title' => '🔌 API Documentation',
        'subtitle' => 'Comprehensive guide to blockchain API endpoints',
        'backToDashboard' => '← Back to Dashboard',
        'explorerApi' => '📊 Explorer API',
        'walletApi' => '💰 Wallet API',
        'nodeApi' => '🔗 Node & System API',
        'apiStatsDesc' => 'Get blockchain statistics (blocks, transactions, nodes, hash rate)',
        'apiBlocksDesc' => 'Get recent blocks with pagination',
        'apiBlockDesc' => 'Get specific block by height or hash',
        'apiTxDesc' => 'Get recent transactions with pagination',
        'apiNodesDesc' => 'Get list of network nodes',
        'apiHealthDesc' => 'Node health check and status',
        'apiStatusDesc' => 'Full node status with network information',
        'apiExample' => '💡 Example Usage:',
        'commonParams' => '📋 Common Parameters',
        'responseFormat' => '🔒 Response Format',
        'successResponse' => 'Success Response:',
        'errorResponse' => 'Error Response:',
        'authentication' => '🔐 Authentication',
        'authDesc' => 'Most API endpoints are public and do not require authentication. Private endpoints require API key in header.',
        'rateLimit' => '⚡ Rate Limiting',
        'rateLimitDesc' => 'API requests are limited to 100 requests per minute per IP address.',
        'supportedFormats' => '📄 Supported Formats',
        'formatsDesc' => 'All endpoints return JSON format. Some endpoints support additional query parameters for filtering.',
        'errorCodes' => '❌ Error Codes',
        'method' => 'Method',
        'endpoint' => 'Endpoint',
        'description' => 'Description',
        'parameters' => 'Parameters',
        'response' => 'Response'
    ],
    'ru' => [
        'title' => '🔌 Документация API',
        'subtitle' => 'Полное руководство по конечным точкам блокчейн API',
        'backToDashboard' => '← Назад к панели управления',
        'explorerApi' => '📊 API обозревателя',
        'walletApi' => '💰 API кошелька',
        'nodeApi' => '🔗 API ноды и системы',
        'apiStatsDesc' => 'Получить статистику блокчейна (блоки, транзакции, ноды, хешрейт)',
        'apiBlocksDesc' => 'Получить последние блоки с пагинацией',
        'apiBlockDesc' => 'Получить конкретный блок по высоте или хешу',
        'apiTxDesc' => 'Получить последние транзакции с пагинацией',
        'apiNodesDesc' => 'Получить список сетевых нод',
        'apiHealthDesc' => 'Проверка состояния и статуса ноды',
        'apiStatusDesc' => 'Полный статус ноды с информацией о сети',
        'apiExample' => '💡 Пример использования:',
        'commonParams' => '📋 Общие параметры',
        'responseFormat' => '🔒 Формат ответа',
        'successResponse' => 'Успешный ответ:',
        'errorResponse' => 'Ответ с ошибкой:',
        'authentication' => '🔐 Аутентификация',
        'authDesc' => 'Большинство конечных точек API являются публичными и не требуют аутентификации. Приватные конечные точки требуют API ключ в заголовке.',
        'rateLimit' => '⚡ Ограничение скорости',
        'rateLimitDesc' => 'API запросы ограничены до 100 запросов в минуту на IP адрес.',
        'supportedFormats' => '📄 Поддерживаемые форматы',
        'formatsDesc' => 'Все конечные точки возвращают JSON формат. Некоторые конечные точки поддерживают дополнительные параметры запроса для фильтрации.',
        'errorCodes' => '❌ Коды ошибок',
        'method' => 'Метод',
        'endpoint' => 'Конечная точка',
        'description' => 'Описание',
        'parameters' => 'Параметры',
        'response' => 'Ответ'
    ]
];

$t = $translations[$lang];

?><!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $t['title']; ?> - Blockchain Node</title>
    <style>
        :root {
            --primary-color: #667eea;
            --secondary-color: #764ba2;
            --success-color: #4CAF50;
            --error-color: #f44336;
            --warning-color: #ff9800;
            --info-color: #2196F3;
            --light-bg: #f8f9fb;
            --card-bg: #ffffff;
            --text-primary: #2c3e50;
            --text-secondary: #7f8c8d;
            --border-color: #e1e8ed;
            --shadow: 0 2px 10px rgba(0,0,0,0.1);
            --border-radius: 12px;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, var(--light-bg) 0%, #e3f2fd 100%);
            color: var(--text-primary);
            line-height: 1.6;
            min-height: 100vh;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .header {
            text-align: center;
            margin-bottom: 40px;
            position: relative;
        }
        
        .header h1 {
            font-size: 2.5rem;
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 10px;
        }
        
        .subtitle {
            color: var(--text-secondary);
            font-size: 1.2rem;
            margin-bottom: 20px;
        }
        
        .back-btn {
            position: absolute;
            top: 0;
            left: 0;
            background: var(--primary-color);
            color: white;
            padding: 12px 20px;
            border: none;
            border-radius: 25px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .back-btn:hover {
            background: var(--secondary-color);
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }
        
        .lang-switch {
            position: absolute;
            top: 0;
            right: 0;
            display: flex;
            gap: 10px;
        }
        
        .lang-btn {
            padding: 8px 16px;
            border: 2px solid var(--primary-color);
            background: transparent;
            color: var(--primary-color);
            border-radius: 20px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .lang-btn.active, .lang-btn:hover {
            background: var(--primary-color);
            color: white;
        }
        
        .api-section {
            background: var(--card-bg);
            margin: 30px 0;
            padding: 30px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--border-color);
        }
        
        .api-section h2 {
            color: var(--primary-color);
            margin-bottom: 20px;
            font-size: 1.8rem;
        }
        
        .api-endpoint {
            margin: 20px 0;
            padding: 20px;
            background: #fafafa;
            border-radius: 8px;
            border-left: 4px solid var(--info-color);
        }
        
        .api-endpoint h4 {
            margin-bottom: 10px;
            color: var(--text-primary);
        }
        
        .api-endpoint code {
            background: #2d2d2d;
            color: #f8f8f2;
            padding: 8px 12px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            font-weight: 600;
            display: inline-block;
            margin: 5px 0;
        }
        
        .api-endpoint .method {
            background: var(--success-color);
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: bold;
            margin-right: 10px;
        }
        
        .api-endpoint .method.post {
            background: var(--warning-color);
        }
        
        .api-endpoint .method.put {
            background: var(--info-color);
        }
        
        .api-endpoint .method.delete {
            background: var(--error-color);
        }
        
        .api-endpoint p {
            margin: 10px 0;
            color: var(--text-secondary);
        }
        
        .example-section {
            background: #1e1e1e;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }
        
        .example-section h4 {
            color: #61dafb;
            margin-bottom: 15px;
        }
        
        .code-block {
            background: #2d2d2d;
            color: #f8f8f2;
            padding: 15px;
            border-radius: 6px;
            font-family: 'Courier New', monospace;
            margin: 10px 0;
            overflow-x: auto;
            cursor: pointer;
            transition: background 0.2s ease;
        }
        
        .code-block:hover {
            background: #3d3d3d;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin: 30px 0;
        }
        
        .info-card {
            background: var(--card-bg);
            padding: 20px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            border-left: 4px solid var(--primary-color);
        }
        
        .info-card h3 {
            color: var(--primary-color);
            margin-bottom: 10px;
        }
        
        .table-responsive {
            overflow-x: auto;
            margin: 20px 0;
        }
        
        .api-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: var(--shadow);
        }
        
        .api-table th,
        .api-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }
        
        .api-table th {
            background: var(--primary-color);
            color: white;
            font-weight: 600;
        }
        
        .api-table tr:hover {
            background: #f8f9fa;
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 15px;
            }
            
            .header h1 {
                font-size: 2rem;
            }
            
            .back-btn,
            .lang-switch {
                position: static;
                margin: 10px auto;
            }
            
            .api-section {
                padding: 20px;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
        }
        
        .fade-in {
            animation: fadeIn 0.6s ease-in;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
    <script>
        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(() => {
                // Visual feedback
                event.target.style.background = '#4CAF50';
                setTimeout(() => {
                    event.target.style.background = '#2d2d2d';
                }, 1000);
            });
        }
        
        // Add click to copy functionality
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.code-block').forEach(block => {
                block.addEventListener('click', () => {
                    copyToClipboard(block.textContent);
                });
            });
            
            // Add fade-in animation
            document.querySelectorAll('.api-section').forEach((section, index) => {
                setTimeout(() => {
                    section.classList.add('fade-in');
                }, index * 100);
            });
        });
    </script>
</head>
<body>
    <div class="container">
        <div class="header">
            <a href="/?lang=<?php echo $lang; ?>" class="back-btn"><?php echo $t['backToDashboard']; ?></a>
            
            <div class="lang-switch">
                <a href="?lang=en" class="lang-btn <?php echo $lang === 'en' ? 'active' : ''; ?>">🇺🇸 EN</a>
                <a href="?lang=ru" class="lang-btn <?php echo $lang === 'ru' ? 'active' : ''; ?>">🇷🇺 RU</a>
            </div>
            
            <h1><?php echo $t['title']; ?></h1>
            <p class="subtitle"><?php echo $t['subtitle']; ?></p>
        </div>
        
        <!-- General Information -->
        <div class="info-grid">
            <div class="info-card">
                <h3><?php echo $t['authentication']; ?></h3>
                <p><?php echo $t['authDesc']; ?></p>
            </div>
            <div class="info-card">
                <h3><?php echo $t['rateLimit']; ?></h3>
                <p><?php echo $t['rateLimitDesc']; ?></p>
            </div>
            <div class="info-card">
                <h3><?php echo $t['supportedFormats']; ?></h3>
                <p><?php echo $t['formatsDesc']; ?></p>
            </div>
        </div>
        
        <!-- Explorer API -->
        <div class="api-section">
            <h2><?php echo $t['explorerApi']; ?></h2>
            
            <div class="api-endpoint">
                <h4><span class="method">GET</span> /api/explorer/stats</h4>
                <p><?php echo $t['apiStatsDesc']; ?></p>
                <p><strong><?php echo $t['parameters']; ?>:</strong> network (mainnet, testnet, devnet)</p>
                <div class="code-block" title="Click to copy">curl -s "https://wallet.coursefactory.pro/api/explorer/stats?network=mainnet" | jq .</div>
            </div>
            
            <div class="api-endpoint">
                <h4><span class="method">GET</span> /api/explorer/blocks</h4>
                <p><?php echo $t['apiBlocksDesc']; ?></p>
                <p><strong><?php echo $t['parameters']; ?>:</strong> network, limit (1-100), offset</p>
                <div class="code-block" title="Click to copy">curl -s "https://wallet.coursefactory.pro/api/explorer/blocks?limit=10&offset=0" | jq .</div>
            </div>
            
            <div class="api-endpoint">
                <h4><span class="method">GET</span> /api/explorer/transactions</h4>
                <p><?php echo $t['apiTxDesc']; ?></p>
                <p><strong><?php echo $t['parameters']; ?>:</strong> network, limit (1-100), offset</p>
                <div class="code-block" title="Click to copy">curl -s "https://wallet.coursefactory.pro/api/explorer/transactions?limit=10" | jq .</div>
            </div>
            
            <div class="api-endpoint">
                <h4><span class="method">GET</span> /api/explorer/?action=get_block</h4>
                <p><?php echo $t['apiBlockDesc']; ?></p>
                <p><strong><?php echo $t['parameters']; ?>:</strong> block_id (height or hash)</p>
                <div class="code-block" title="Click to copy">curl -s "https://wallet.coursefactory.pro/api/explorer/?action=get_block&block_id=1" | jq .</div>
            </div>
            
            <div class="api-endpoint">
                <h4><span class="method">GET</span> /api/explorer/?action=get_nodes_list</h4>
                <p><?php echo $t['apiNodesDesc']; ?></p>
                <div class="code-block" title="Click to copy">curl -s "https://wallet.coursefactory.pro/api/explorer/?action=get_nodes_list" | jq .</div>
            </div>
            
            <div class="api-endpoint">
                <h4><span class="method">GET</span> /api/explorer/?action=get_validators_list</h4>
                <p>Get list of network validators</p>
                <div class="code-block" title="Click to copy">curl -s "https://wallet.coursefactory.pro/api/explorer/?action=get_validators_list" | jq .</div>
            </div>
            
            <div class="api-endpoint">
                <h4><span class="method">GET</span> /api/explorer/?action=get_mempool</h4>
                <p>Get pending transactions in mempool</p>
                <div class="code-block" title="Click to copy">curl -s "https://wallet.coursefactory.pro/api/explorer/?action=get_mempool" | jq .</div>
            </div>
        </div>
        
        <!-- Wallet API -->
        <div class="api-section">
            <h2><?php echo $t['walletApi']; ?></h2>
            
            <div class="api-endpoint">
                <h4><span class="method post">POST</span> /wallet/wallet_api.php</h4>
                <p><strong>action: create_wallet</strong> - Create new wallet</p>
                <div class="code-block" title="Click to copy">curl -X POST "https://wallet.coursefactory.pro/wallet/wallet_api.php" -H "Content-Type: application/json" -d '{"action":"create_wallet"}' | jq .</div>
            </div>
            
            <div class="api-endpoint">
                <h4><span class="method post">POST</span> /wallet/wallet_api.php</h4>
                <p><strong>action: get_balance</strong> - Get wallet balance</p>
                <p><strong><?php echo $t['parameters']; ?>:</strong> address</p>
                <div class="code-block" title="Click to copy">curl -X POST "https://wallet.coursefactory.pro/wallet/wallet_api.php" -H "Content-Type: application/json" -d '{"action":"get_balance","address":"YOUR_ADDRESS"}' | jq .</div>
            </div>
            
            <div class="api-endpoint">
                <h4><span class="method post">POST</span> /wallet/wallet_api.php</h4>
                <p><strong>action: transfer_tokens</strong> - Transfer tokens between wallets</p>
                <p><strong><?php echo $t['parameters']; ?>:</strong> from, to, amount, private_key</p>
                <div class="code-block" title="Click to copy">curl -X POST "https://wallet.coursefactory.pro/wallet/wallet_api.php" -H "Content-Type: application/json" -d '{"action":"transfer_tokens","from":"FROM_ADDRESS","to":"TO_ADDRESS","amount":"100","private_key":"PRIVATE_KEY"}' | jq .</div>
            </div>
            
            <div class="api-endpoint">
                <h4><span class="method post">POST</span> /wallet/wallet_api.php</h4>
                <p><strong>action: generate_mnemonic</strong> - Generate new mnemonic phrase</p>
                <div class="code-block" title="Click to copy">curl -X POST "https://wallet.coursefactory.pro/wallet/wallet_api.php" -H "Content-Type: application/json" -d '{"action":"generate_mnemonic"}' | jq .</div>
            </div>
            
            <div class="api-endpoint">
                <h4><span class="method post">POST</span> /wallet/wallet_api.php</h4>
                <p><strong>action: get_transaction_history</strong> - Get transaction history for address</p>
                <p><strong><?php echo $t['parameters']; ?>:</strong> address</p>
                <div class="code-block" title="Click to copy">curl -X POST "https://wallet.coursefactory.pro/wallet/wallet_api.php" -H "Content-Type: application/json" -d '{"action":"get_transaction_history","address":"YOUR_ADDRESS"}' | jq .</div>
            </div>
        </div>
        
        <!-- Node API -->
        <div class="api-section">
            <h2><?php echo $t['nodeApi']; ?></h2>
            
            <div class="api-endpoint">
                <h4><span class="method">GET</span> /api/health</h4>
                <p><?php echo $t['apiHealthDesc']; ?></p>
                <div class="code-block" title="Click to copy">curl -s "https://wallet.coursefactory.pro/api/health" | jq .</div>
            </div>
            
            <div class="api-endpoint">
                <h4><span class="method">GET</span> /api/status</h4>
                <p><?php echo $t['apiStatusDesc']; ?></p>
                <div class="code-block" title="Click to copy">curl -s "https://wallet.coursefactory.pro/api/status" | jq .</div>
            </div>
            
            <div class="api-endpoint">
                <h4><span class="method">GET</span> /api/blockchain/info</h4>
                <p>Get blockchain information and configuration</p>
                <div class="code-block" title="Click to copy">curl -s "https://wallet.coursefactory.pro/api/blockchain/info" | jq .</div>
            </div>
        </div>
        
        <!-- Response Format -->
        <div class="api-section">
            <h2><?php echo $t['responseFormat']; ?></h2>
            
            <div class="example-section">
                <h4><?php echo $t['successResponse']; ?></h4>
                <div class="code-block">{
  "success": true,
  "data": {
    "blocks": 1523,
    "transactions": 3847,
    "active_nodes": 2,
    "hash_rate": "0.2 H"
  },
  "timestamp": 1640995200
}</div>
            </div>
            
            <div class="example-section">
                <h4><?php echo $t['errorResponse']; ?></h4>
                <div class="code-block">{
  "success": false,
  "error": "Invalid parameters",
  "code": 400,
  "timestamp": 1640995200
}</div>
            </div>
        </div>
        
        <!-- Common Parameters -->
        <div class="api-section">
            <h2><?php echo $t['commonParams']; ?></h2>
            
            <div class="table-responsive">
                <table class="api-table">
                    <thead>
                        <tr>
                            <th><?php echo $t['parameters']; ?></th>
                            <th>Type</th>
                            <th><?php echo $t['description']; ?></th>
                            <th>Example</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>network</td>
                            <td>string</td>
                            <td>Network type</td>
                            <td>mainnet, testnet, devnet</td>
                        </tr>
                        <tr>
                            <td>limit</td>
                            <td>integer</td>
                            <td>Number of results (max: 100)</td>
                            <td>10, 50, 100</td>
                        </tr>
                        <tr>
                            <td>offset</td>
                            <td>integer</td>
                            <td>Number of results to skip</td>
                            <td>0, 10, 20</td>
                        </tr>
                        <tr>
                            <td>address</td>
                            <td>string</td>
                            <td>Wallet address</td>
                            <td>1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa</td>
                        </tr>
                        <tr>
                            <td>block_id</td>
                            <td>string|integer</td>
                            <td>Block height or hash</td>
                            <td>123 or 0x1a2b3c...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>
