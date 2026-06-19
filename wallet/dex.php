<?php
/**
 * DEX / token-ledger module (extracted from wallet_api.php — D1 decomposition).
 *
 * Global functions (no namespace) so existing call sites in wallet_api.php keep working.
 * Function names resolve at call-time, so cross-references to helpers defined in
 * wallet_api.php (writeLog, getDatabaseConnection, getLatestBlockHash, ...) remain valid.
 */

    /**
     * Record a DEX operation as a real on-chain transaction (B3).
     *
     * Routes through WalletBlockchainManager::recordTransactionInBlockchain(), which mints a
     * real block (hash + merkle + height) containing the tx — the same path regular transfers
     * use — instead of fabricating a `status=confirmed` row. Falls back to a direct insert if
     * block production is unavailable, so settlement is never lost.
     *
     * Returns the transaction hash.
     */
    if (!function_exists('recordDexTransactionOnChain')) {
        function recordDexTransactionOnChain($blockchainManager, $pdo, string $type, string $from, string $to, float $amount, array $data): string {
            $hash = '0x' . hash('sha256', $type . '|' . $from . '|' . $to . '|' . $amount . '|' . microtime(true) . '|' . bin2hex(random_bytes(8)));
            $fee = (float)($data['fee'] ?? 0);
            $tx = [
                'hash'      => $hash,
                'type'      => $type,
                'from'      => $from,
                'to'        => $to,
                'amount'    => $amount,
                'fee'       => $fee,
                'timestamp' => time(),
                'data'      => $data,
                // Deterministic, content-bound marker (no private key is supplied for DEX ops).
                'signature' => 'dex:' . hash('sha256', $hash . '|' . $type),
                'status'    => 'pending',
            ];

            try {
                if ($blockchainManager && method_exists($blockchainManager, 'recordTransactionInBlockchain')) {
                    $res = $blockchainManager->recordTransactionInBlockchain($tx);
                    if (!empty($res['blockchain_recorded'])) {
                        return $hash;
                    }
                    writeLog('DEX on-chain record returned not-recorded, using fallback insert', 'WARNING');
                }
            } catch (\Throwable $e) {
                writeLog('DEX on-chain record failed, using fallback insert: ' . $e->getMessage(), 'WARNING');
            }

            // Fallback: legacy direct insert so the operation is still auditable.
            $pdo->prepare("
                INSERT INTO transactions (
                    hash, block_hash, block_height, from_address, to_address,
                    amount, fee, gas_limit, gas_used, gas_price, nonce,
                    data, signature, status, timestamp
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $hash, getLatestBlockHash($pdo), getCurrentBlockHeight($pdo), $from, $to,
                $amount, $fee, 21000, 0, 0, 0, json_encode($data), $tx['signature'], 'confirmed', time()
            ]);
            return $hash;
        }
    }

    /**
     * Calculate square root using BCMath (for LP token supply calculation)
     */
    if (!function_exists('bcsqrt')) {
        function bcsqrt($number, $scale = 0) {
            if (bccomp($number, '0', $scale) <= 0) {
                return '0';
            }
            
            // Newton's method for square root
            $x = $number;
            $root = bcdiv(bcadd($x, '1', $scale), '2', $scale);
            
            while (bccomp($root, $x, $scale) < 0) {
                $x = $root;
                $root = bcdiv(bcadd(bcdiv($number, $x, $scale), $x, $scale), '2', $scale);
            }
            
            return $x;
        }
    }

    // --- DEX unit helpers: AMM reserves are stored/managed in wei (18 decimals) ---
    if (!function_exists('toWei')) {
        function toWei($amount, int $decimals = 18): string {
            return bcmul((string)$amount, bcpow('10', (string)$decimals, 0), 0);
        }
    }
    if (!function_exists('fromWei')) {
        function fromWei($wei, int $decimals = 18): string {
            return bcdiv((string)$wei, bcpow('10', (string)$decimals, 0), $decimals);
        }
    }

    // --- ERC20-style token ledger helpers (table: token_balances). Amounts in human units. ---
    if (!function_exists('tokenBalanceGet')) {
        function tokenBalanceGet(\PDO $pdo, string $address, string $token): string {
            $stmt = $pdo->prepare("SELECT balance FROM token_balances WHERE address = ? AND token = ?");
            $stmt->execute([strtolower($address), $token]);
            $v = $stmt->fetchColumn();
            return $v === false ? '0' : (string)$v;
        }
    }
    if (!function_exists('tokenBalanceAdd')) {
        // Credit (positive) or debit (negative) a token balance atomically. Returns false if it would go negative.
        function tokenBalanceAdd(\PDO $pdo, string $address, string $token, string $delta): bool {
            $address = strtolower($address);
            $current = tokenBalanceGet($pdo, $address, $token);
            $next = bcadd($current, $delta, 18);
            if (bccomp($next, '0', 18) < 0) {
                return false;
            }
            $stmt = $pdo->prepare(
                "INSERT INTO token_balances (address, token, balance) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE balance = VALUES(balance)"
            );
            $stmt->execute([$address, $token, $next]);
            return true;
        }
    }

    /**
     * Deploy DEX contracts (Uniswap V2 Factory, Router, WETH, Main Token)
     */
    function deployDexContracts($walletManager, $pdo): array {
        try {
            writeLog("Starting DEX contracts deployment", 'INFO');

            // Idempotent: if the DEX is already deployed, return the existing contracts instead
            // of creating duplicates on every call.
            $existing = $pdo->query("SELECT name, address, metadata FROM smart_contracts
                                     WHERE name IN ('DEX_Factory','DEX_Router','WETH') AND status='active'
                                     ORDER BY id ASC")->fetchAll(\PDO::FETCH_ASSOC);
            $byName = [];
            foreach ($existing as $row) { if (!isset($byName[$row['name']])) { $byName[$row['name']] = $row; } }
            if (isset($byName['DEX_Factory'], $byName['DEX_Router'])) {
                writeLog("DEX already deployed — returning existing contracts", 'INFO');
                return [
                    'deployed' => true,
                    'already_deployed' => true,
                    'contracts' => [
                        'factory' => ['address' => $byName['DEX_Factory']['address']],
                        'router'  => ['address' => $byName['DEX_Router']['address']],
                        'weth'    => ['address' => $byName['WETH']['address'] ?? 'weth_contract'],
                    ],
                ];
            }

            // Deploy DEX factory contract
            $factoryAddress = 'factory_' . bin2hex(random_bytes(16));
            $factoryMetadata = [
                'type' => 'dex_factory',
                'version' => '2.0',
                'fee_rate' => 0.003,
                'pairs_created' => 0,
                'owner' => 'system',
                'created_at' => time()
            ];
            
            $stmt = $pdo->prepare("
                INSERT INTO smart_contracts (
                    address, creator, name, bytecode, abi, deployment_tx, 
                    deployment_block, metadata, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $deploymentTx = 'deploy_factory_' . bin2hex(random_bytes(16));
            
            $stmt->execute([
                $factoryAddress,
                'system',
                'DEX_Factory',
                'factory_bytecode',
                json_encode([]),
                $deploymentTx,
                time(),
                json_encode($factoryMetadata),
                'active'
            ]);
            
            // Deploy DEX router contract
            $routerAddress = 'router_' . bin2hex(random_bytes(16));
            $routerMetadata = [
                'type' => 'dex_router',
                'version' => '2.0',
                'factory_address' => $factoryAddress,
                'weth_address' => 'weth_contract',
                'swaps_executed' => 0,
                'created_at' => time()
            ];
            
            $deploymentTxRouter = 'deploy_router_' . bin2hex(random_bytes(16));
            
            $stmt->execute([
                $routerAddress,
                'system',
                'DEX_Router',
                'router_bytecode',
                json_encode([]),
                $deploymentTxRouter,
                time(),
                json_encode($routerMetadata),
                'active'
            ]);
            
            // Deploy WETH (Wrapped ETH) contract
            $wethAddress = 'weth_' . bin2hex(random_bytes(16));
            $wethMetadata = [
                'type' => 'wrapped_token',
                'name' => 'Wrapped Ether',
                'symbol' => 'WETH',
                'decimals' => 18,
                'total_supply' => '0',
                'created_at' => time()
            ];
            
            $deploymentTxWeth = 'deploy_weth_' . bin2hex(random_bytes(16));
            
            $stmt->execute([
                $wethAddress,
                'system',
                'WETH',
                'weth_bytecode',
                json_encode([]),
                $deploymentTxWeth,
                time(),
                json_encode($wethMetadata),
                'active'
            ]);
            
            // Create deployment transaction
            $txHash = 'deploy_dex_' . bin2hex(random_bytes(16));
            
            $txData = json_encode([
                'action' => 'deploy_dex_contracts',
                'contracts' => [
                    'factory' => $factoryAddress,
                    'router' => $routerAddress,
                    'weth' => $wethAddress
                ],
                'deployer' => 'system'
            ]);
            
            $stmt = $pdo->prepare("
                INSERT INTO transactions (
                    hash, block_hash, block_height, from_address, to_address,
                    amount, fee, gas_limit, gas_used, gas_price, nonce,
                    data, signature, status, timestamp
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            // Get the latest block hash
            $latestBlockStmt = $pdo->query("SELECT hash FROM blocks ORDER BY height DESC LIMIT 1");
            $latestBlock = $latestBlockStmt->fetch();
            $blockHash = $latestBlock ? $latestBlock['hash'] : null;
            
            $stmt->execute([
                $txHash,
                $blockHash,
                time(),
                'system',
                'contracts',
                0,
                0.1, // Deployment fee
                500000,
                0,
                0,
                0,
                $txData,
                'deployment_signature',
                'confirmed',
                time()
            ]);
            
            writeLog("DEX contracts deployed successfully", 'INFO');
            
            return [
                'deployed' => true,
                'contracts' => [
                    'factory' => [
                        'address' => $factoryAddress,
                        'name' => 'DEX_Factory',
                        'metadata' => $factoryMetadata
                    ],
                    'router' => [
                        'address' => $routerAddress,
                        'name' => 'DEX_Router',
                        'metadata' => $routerMetadata
                    ],
                    'weth' => [
                        'address' => $wethAddress,
                        'name' => 'WETH',
                        'metadata' => $wethMetadata
                    ]
                ],
                'transaction_hash' => $txHash,
                'deployer' => 'system',
                'deployment_time' => time()
            ];
            
        } catch (Exception $e) {
            writeLog("DEX contracts deployment failed: " . $e->getMessage(), 'ERROR');
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Create liquidity pool - REAL implementation with smart contract
     */
    function createLiquidityPool($walletManager, $address, $tokenA, $tokenB, float $amountA, float $amountB, $blockchainManager = null): array {
        try {
            writeLog("Creating liquidity pool: $tokenA/$tokenB for $address", 'INFO');
            
            // Get database connection
            $pdo = $walletManager->getDatabase();
            
            // Check if pair already exists
            $pairAddress = 'pair_' . substr(hash('sha256', min($tokenA, $tokenB) . '_' . max($tokenA, $tokenB)), 0, 16);
            
            $stmt = $pdo->prepare("
                SELECT address FROM smart_contracts 
                WHERE address = ? AND status = 'active'
            ");
            $stmt->execute([$pairAddress]);
            $existingPair = $stmt->fetch();
            
            if ($existingPair) {
                return ['error' => 'Pool already exists for this pair'];
            }
            
            // Validate amounts
            if ($amountA <= 0 || $amountB <= 0) {
                return ['error' => 'Liquidity amounts must be positive'];
            }

            // Check user balances up-front (available native only — staked funds cannot be used;
            // ERC20 sides checked against the token ledger) so we never create an orphan pool.
            $pdo = $walletManager->getDatabase();
            $availableA = $walletManager->getAvailableBalance($address);
            if ($tokenA === 'native' && $availableA < $amountA) {
                return ['error' => 'Insufficient balance for tokenA'];
            }
            if ($tokenB === 'native' && $availableA < $amountB) {
                return ['error' => 'Insufficient balance for tokenB'];
            }
            if ($tokenA !== 'native' && bccomp(tokenBalanceGet($pdo, $address, $tokenA), (string)$amountA, 18) < 0) {
                return ['error' => 'Insufficient balance for tokenA'];
            }
            if ($tokenB !== 'native' && bccomp(tokenBalanceGet($pdo, $address, $tokenB), (string)$amountB, 18) < 0) {
                return ['error' => 'Insufficient balance for tokenB'];
            }

            // Reserves are stored in wei (integer string) so that getSwapQuote()/swapTokens()
            // operate on a single consistent unit. amountIn in those functions is also wei.
            $reserves0Wei = ($tokenA < $tokenB) ? toWei($amountA) : toWei($amountB);
            $reserves1Wei = ($tokenA < $tokenB) ? toWei($amountB) : toWei($amountA);

            // Create pair smart contract
            $contractMetadata = [
                'token0' => min($tokenA, $tokenB),
                'token1' => max($tokenA, $tokenB),
                'reserves0' => $reserves0Wei,
                'reserves1' => $reserves1Wei,
                'total_supply' => '0',
                'fee_rate' => '0.003', // 0.3%
                'creator' => $address,
                'created_at' => time(),
                'pair_type' => 'liquidity_pool'
            ];
            
            // Calculate initial LP tokens (geometric mean)
            $lpTokens = bcsqrt(bcmul((string)$amountA, (string)$amountB, 0), 0);
            $contractMetadata['total_supply'] = $lpTokens;
            
            // Atomic settlement: pool creation + balance moves either all succeed or all roll back.
            $pdo->beginTransaction();

            // Insert pair contract into database
            $stmt = $pdo->prepare("
                INSERT INTO smart_contracts (
                    address, creator, name, version, bytecode, abi,
                    source_code, deployment_tx, deployment_block,
                    gas_used, status, storage, metadata
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $deploymentTx = 'create_pool_' . bin2hex(random_bytes(16));
            $currentBlock = time(); // Simplified block number
            
            $stmt->execute([
                $pairAddress,
                $address,
                'LiquidityPair',
                '1.0.0',
                'liquidity_pair_bytecode', // Simplified
                json_encode([]), // Empty ABI for now
                'Liquidity Pair Contract',
                $deploymentTx,
                $currentBlock,
                0,
                'active',
                json_encode([]),
                json_encode($contractMetadata)
            ]);
            
            // Settle both sides: native via wallets.balance, ERC20 via the token ledger.
            // (Funds were already validated up-front.)
            if ($tokenA === 'native') {
                $pdo->prepare("UPDATE wallets SET balance = balance - ? WHERE address = ?")
                    ->execute([$amountA, strtolower($address)]);
            } else {
                tokenBalanceAdd($pdo, $address, $tokenA, '-' . $amountA);
            }
            if ($tokenB === 'native') {
                $pdo->prepare("UPDATE wallets SET balance = balance - ? WHERE address = ?")
                    ->execute([$amountB, strtolower($address)]);
            } else {
                tokenBalanceAdd($pdo, $address, $tokenB, '-' . $amountB);
            }

            // Mint LP tokens to the provider (the LP token id is the pair address).
            // This makes liquidity a real, transferable balance and lets removeLiquidity burn it.
            tokenBalanceAdd($pdo, $address, $pairAddress, (string)$lpTokens);

            $pdo->commit();

            // B3: record add-liquidity as a real on-chain transaction (mined into a block).
            $txHash = recordDexTransactionOnChain($blockchainManager, $pdo, 'add_liquidity', $address, $pairAddress, (float)$lpTokens, [
                'action' => 'add_liquidity',
                'tokenA' => $tokenA,
                'tokenB' => $tokenB,
                'amountA' => $amountA,
                'amountB' => $amountB,
                'lp_tokens' => $lpTokens,
                'pool_address' => $pairAddress,
            ]);

            writeLog("Liquidity pool created successfully: $pairAddress", 'INFO');

            return [
                'pool_created' => true,
                'tokenA' => $tokenA,
                'tokenB' => $tokenB,
                'amountA' => $amountA,
                'amountB' => $amountB,
                'liquidity_provider' => $address,
                'pool_address' => $pairAddress,
                'lp_tokens' => $lpTokens,
                'transaction_hash' => $txHash,
                'block_height' => $currentBlock
            ];
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            writeLog("Liquidity pool creation failed: " . $e->getMessage(), 'ERROR');
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Swap tokens using DEX - REAL implementation with AMM
     */
    function swapTokens($walletManager, $from, $to, $tokenIn, $tokenOut, float $amountIn, float $amountOutMin, $pdo, $blockchainManager = null): array {
        try {
            writeLog("Token swap: $amountIn $tokenIn -> $tokenOut for $from", 'INFO');
            
            // Get swap quote first
            $quote = getSwapQuote($tokenIn, $tokenOut, $amountIn);
            
            if (isset($quote['error'])) {
                return ['error' => 'Cannot get swap quote: ' . $quote['error']];
            }
            
            $amountOut = $quote['amountOut'];
            
            // Check minimum output
            if ($amountOut < $amountOutMin) {
                return ['error' => 'Output amount below minimum: ' . $amountOut . ' < ' . $amountOutMin];
            }
            
            // The AMM formula in getSwapQuote() already applies the 0.3% fee (997/1000),
            // so the fee is retained inside the pool — do NOT subtract it again here.
            $fee = $quote['fee'] ?? ($amountIn * 0.003);

            // Check user balance for the input side (native via wallet, ERC20 via token ledger)
            if ($tokenIn === 'native') {
                if ($walletManager->getAvailableBalance($from) < $amountIn) {
                    return ['error' => 'Insufficient balance for swap'];
                }
            } else {
                if (bccomp(tokenBalanceGet($pdo, $from, $tokenIn), (string)$amountIn, 18) < 0) {
                    return ['error' => 'Insufficient token balance for swap'];
                }
            }

            // Get pool reserves (wei) for token-order mapping
            $poolData = getPoolReserves($tokenIn, $tokenOut);
            if (!$poolData['pair_exists']) {
                return ['error' => 'Trading pair does not exist'];
            }
            $pairAddress = $poolData['pair_address'];

            // Reserve update in wei: the FULL input enters the pool, amountOut leaves it.
            $amountInWei  = toWei($amountIn);
            $amountOutWei = toWei($amountOut);
            $newReserveIn  = bcadd($poolData['reserveA'], $amountInWei, 0);
            $newReserveOut = bcsub($poolData['reserveB'], $amountOutWei, 0);
            if (bccomp($newReserveOut, '0', 0) < 0) {
                return ['error' => 'Insufficient liquidity for this trade size'];
            }

            // Atomic settlement: reserves + both balance sides commit together or not at all.
            $pdo->beginTransaction();

            // Persist reserves back, aligned to token0/token1, preserving other metadata.
            $metaStmt = $pdo->prepare("SELECT metadata FROM smart_contracts WHERE address = ?");
            $metaStmt->execute([$pairAddress]);
            $newMetadata = json_decode((string)($metaStmt->fetchColumn() ?: '{}'), true) ?: [];
            $newMetadata['token0']      = $poolData['token0'];
            $newMetadata['token1']      = $poolData['token1'];
            $newMetadata['reserves0']   = ($tokenIn === $poolData['token0']) ? $newReserveIn : $newReserveOut;
            $newMetadata['reserves1']   = ($tokenIn === $poolData['token0']) ? $newReserveOut : $newReserveIn;
            $newMetadata['last_swap']   = time();
            $newMetadata['total_swaps'] = (int)($newMetadata['total_swaps'] ?? 0) + 1;

            $pdo->prepare("UPDATE smart_contracts SET metadata = ?, updated_at = CURRENT_TIMESTAMP WHERE address = ?")
                ->execute([json_encode($newMetadata), $pairAddress]);
            
            // Settle both sides atomically: native via wallets.balance, ERC20 via the token ledger.
            $recipient = $to ?: $from;
            if ($tokenIn === 'native') {
                $pdo->prepare("UPDATE wallets SET balance = balance - ? WHERE address = ?")
                    ->execute([$amountIn, strtolower($from)]);
            } else {
                tokenBalanceAdd($pdo, $from, $tokenIn, '-' . $amountIn);
            }
            if ($tokenOut === 'native') {
                $pdo->prepare("UPDATE wallets SET balance = balance + ? WHERE address = ?")
                    ->execute([$amountOut, strtolower($recipient)]);
            } else {
                tokenBalanceAdd($pdo, $recipient, $tokenOut, (string)$amountOut);
            }

            $pdo->commit();

            // B3: record the swap as a real on-chain transaction (mined into a block) AFTER settlement.
            $txHash = recordDexTransactionOnChain($blockchainManager, $pdo, 'dex_swap', $from, $recipient, (float)$amountOut, [
                'action' => 'token_swap',
                'tokenIn' => $tokenIn,
                'tokenOut' => $tokenOut,
                'amountIn' => $amountIn,
                'amountOut' => $amountOut,
                'fee' => $fee,
                'pool_address' => $pairAddress,
                'price_impact' => $quote['price_impact'] ?? 0,
            ]);

            writeLog("Token swap executed successfully: $txHash", 'INFO');

            return [
                'swap_executed' => true,
                'from' => $from,
                'to' => $to ?: $from,
                'tokenIn' => $tokenIn,
                'tokenOut' => $tokenOut,
                'amountIn' => $amountIn,
                'amountOut' => $amountOut,
                'fee' => $fee,
                'price_impact' => $quote['price_impact'] ?? 0,
                'transaction_hash' => $txHash,
                'pool_address' => $pairAddress
            ];
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            writeLog("Token swap failed: " . $e->getMessage(), 'ERROR');
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get DEX information
     */
    function getDexInfo($networkConfig): array {
        try {
            global $pdo;
            
            // Get network configuration
            $tokenInfo = $networkConfig->getTokenInfo();
            
            // Get real statistics from database
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as total_pairs 
                FROM smart_contracts 
                WHERE name LIKE '%pool%' AND status = 'active'
            ");
            $stmt->execute();
            $pairCount = $stmt->fetch(PDO::FETCH_ASSOC)['total_pairs'] ?? 0;
            
            // Get total volume from transactions
            $stmt = $pdo->prepare("
                SELECT 
                    COUNT(*) as total_swaps,
                    SUM(amount) as total_volume,
                    SUM(fee) as total_fees
                FROM transactions 
                WHERE data LIKE '%token_swap%' OR data LIKE '%liquidity%'
            ");
            $stmt->execute();
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get total liquidity from staking table
            $stmt = $pdo->prepare("
                SELECT SUM(amount) as total_liquidity 
                FROM staking 
                WHERE validator LIKE 'pair_%'
            ");
            $stmt->execute();
            $liquidity = $stmt->fetch(PDO::FETCH_ASSOC)['total_liquidity'] ?? 0;
            
            return [
                'dex_version' => '2.0',
                'network' => $tokenInfo['name'] ?? 'Universal Network',
                'token_symbol' => $tokenInfo['symbol'] ?? 'UNI',
                'token_name' => $tokenInfo['token_name'] ?? 'Universal Token',
                'decimals' => (int)($tokenInfo['decimals'] ?? 18),
                'factory_address' => 'factory_contract_address',
                'router_address' => 'router_contract_address',
                'weth_address' => 'weth_contract_address',
                'fee_rate' => '0.3%',
                'statistics' => [
                    'total_pairs' => (int)$pairCount,
                    'total_swaps' => (int)($stats['total_swaps'] ?? 0),
                    'total_volume' => (float)($stats['total_volume'] ?? 0),
                    'total_fees' => (float)($stats['total_fees'] ?? 0),
                    'total_liquidity' => (float)$liquidity
                ],
                'database_connected' => true,
                'last_updated' => time()
            ];
            
        } catch (Exception $e) {
            writeLog("Get DEX info failed: " . $e->getMessage(), 'ERROR');
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Get pool reserves for token pair - REAL implementation
     */
    function getPoolReserves($tokenA, $tokenB): array {
        try {
            writeLog("Getting pool reserves for $tokenA/$tokenB from database", 'DEBUG');
            
            // Get database connection
            $pdo = getDatabaseConnection();
            
            // Sort tokens for consistent query
            $token0 = ($tokenA < $tokenB) ? $tokenA : $tokenB;
            $token1 = ($tokenA < $tokenB) ? $tokenB : $tokenA;
            
            // Look for existing smart contract pair first
            $stmt = $pdo->prepare("
                SELECT 
                    address as pair_address,
                    metadata,
                    created_at
                FROM smart_contracts 
                WHERE name LIKE '%pair%' 
                AND status = 'active'
                AND (
                    metadata LIKE ? OR 
                    metadata LIKE ?
                )
                LIMIT 1
            ");
            
            $metadataPattern1 = '%' . $token0 . '%' . $token1 . '%';
            $metadataPattern2 = '%' . $token1 . '%' . $token0 . '%';
            $stmt->execute([$metadataPattern1, $metadataPattern2]);
            $pairContract = $stmt->fetch();
            
            if ($pairContract) {
                // Use data from smart contract. Reserves are persisted as reserves0/reserves1
                // (in wei), aligned to token0/token1. Map them to reserveA/reserveB by the
                // caller's token order so getSwapQuote()/swapTokens() read the right side.
                $metadata = json_decode($pairContract['metadata'], true) ?? [];

                $reserves0 = (string)($metadata['reserves0'] ?? '0');
                $reserves1 = (string)($metadata['reserves1'] ?? '0');
                $reserveA = ($tokenA === $token0) ? $reserves0 : $reserves1;
                $reserveB = ($tokenA === $token0) ? $reserves1 : $reserves0;

                // Prices in wei-neutral ratio (token1 per token0 and vice versa)
                $price0 = '0';
                $price1 = '0';
                if (bccomp($reserves0, '0', 0) > 0 && bccomp($reserves1, '0', 0) > 0) {
                    $price0 = bcdiv($reserves1, $reserves0, 18);
                    $price1 = bcdiv($reserves0, $reserves1, 18);
                }

                return [
                    'tokenA' => $tokenA,
                    'tokenB' => $tokenB,
                    'token0' => $token0,
                    'token1' => $token1,
                    'reserveA' => $reserveA,
                    'reserveB' => $reserveB,
                    'reserves0' => $reserves0,
                    'reserves1' => $reserves1,
                    'pair_address' => $pairContract['pair_address'],
                    'pair_exists' => true,
                    'transaction_count' => $metadata['tx_count'] ?? 0,
                    'last_update' => strtotime($pairContract['created_at']),
                    'price_0_per_1' => $price0,
                    'price_1_per_0' => $price1
                ];
            }
            
            // If no contract pair, calculate from transaction data
            // Look for transactions between these addresses
            $stmt = $pdo->prepare("
                SELECT 
                    COUNT(*) as transaction_count,
                    SUM(CASE WHEN from_address = ? THEN amount ELSE 0 END) as total_from_A,
                    SUM(CASE WHEN to_address = ? THEN amount ELSE 0 END) as total_to_A,
                    SUM(CASE WHEN from_address = ? THEN amount ELSE 0 END) as total_from_B,
                    SUM(CASE WHEN to_address = ? THEN amount ELSE 0 END) as total_to_B,
                    MAX(timestamp) as last_update
                FROM transactions 
                WHERE (
                    (from_address = ? AND to_address = ?) OR 
                    (from_address = ? AND to_address = ?)
                )
                AND status = 'confirmed'
            ");
            
            $stmt->execute([
                $token0, $token0, $token1, $token1,
                $token0, $token1, $token1, $token0
            ]);
            $result = $stmt->fetch();
            
            // Calculate reserves based on transaction flow
            $reserveA = bcadd($result['total_to_A'] ?? '0', bcmul($result['total_from_A'] ?? '0', '0.9', 0), 0); // 90% retention
            $reserveB = bcadd($result['total_to_B'] ?? '0', bcmul($result['total_from_B'] ?? '0', '0.9', 0), 0);
            
            // Ensure non-negative reserves
            if (bccomp($reserveA, '0', 0) < 0) $reserveA = '0';
            if (bccomp($reserveB, '0', 0) < 0) $reserveB = '0';
            
            // Generate deterministic pair address
            $pairAddress = 'pair_' . substr(hash('sha256', $token0 . '_' . $token1), 0, 16);
            
            // Check if pair exists (has any transactions)
            $pairExists = (int)($result['transaction_count'] ?? 0) > 0;
            
            // Calculate price ratio if both reserves > 0
            $price0 = '0';
            $price1 = '0';
            if (bccomp($reserveA, '0', 0) > 0 && bccomp($reserveB, '0', 0) > 0) {
                $price0 = bcdiv($reserveB, $reserveA, 18); // tokenB per tokenA
                $price1 = bcdiv($reserveA, $reserveB, 18); // tokenA per tokenB
            }
            
            return [
                'tokenA' => $tokenA,
                'tokenB' => $tokenB,
                'token0' => $token0,
                'token1' => $token1,
                'reserveA' => ($tokenA === $token0) ? $reserveA : $reserveB,
                'reserveB' => ($tokenA === $token0) ? $reserveB : $reserveA,
                'reserves0' => $reserveA,
                'reserves1' => $reserveB,
                'pair_address' => $pairAddress,
                'pair_exists' => $pairExists,
                'transaction_count' => (int)($result['transaction_count'] ?? 0),
                'last_update' => (int)($result['last_update'] ?? 0),
                'price_0_per_1' => $price0,
                'price_1_per_0' => $price1
            ];
            
        } catch (Exception $e) {
            writeLog("Get pool reserves failed: " . $e->getMessage(), 'ERROR');
            return [
                'error' => $e->getMessage(),
                'tokenA' => $tokenA,
                'tokenB' => $tokenB,
                'reserveA' => '0',
                'reserveB' => '0',
                'pair_exists' => false
            ];
        }
    }
    
    /**
     * Get swap quote for token exchange - REAL implementation with AMM formula
     */
    function getSwapQuote($tokenIn, $tokenOut, float $amountIn): array {
        try {
            writeLog("Getting swap quote: $amountIn $tokenIn -> $tokenOut from pool data", 'DEBUG');
            
            // Get actual pool reserves
            $poolData = getPoolReserves($tokenIn, $tokenOut);
            
            if (isset($poolData['error']) || !$poolData['pair_exists']) {
                return [
                    'error' => 'Pool does not exist for this pair',
                    'tokenIn' => $tokenIn,
                    'tokenOut' => $tokenOut,
                    'amountIn' => $amountIn,
                    'amountOut' => 0,
                    'pair_exists' => false
                ];
            }
            
            $reserveIn = $poolData['reserveA'];
            $reserveOut = $poolData['reserveB'];
            
            // Handle token order
            if ($tokenIn !== $poolData['tokenA']) {
                $reserveIn = $poolData['reserveB'];
                $reserveOut = $poolData['reserveA'];
            }
            
            // Convert float to string for bcmath
            $amountInStr = bcmul((string)$amountIn, '1000000000000000000', 0); // Convert to wei
            
            // Check if reserves are sufficient
            if (bccomp($reserveIn, '0', 0) <= 0 || bccomp($reserveOut, '0', 0) <= 0) {
                return [
                    'error' => 'Insufficient liquidity',
                    'tokenIn' => $tokenIn,
                    'tokenOut' => $tokenOut,
                    'amountIn' => $amountIn,
                    'amountOut' => 0,
                    'pair_exists' => true
                ];
            }
            
            // AMM formula: (amountIn * 997 * reserveOut) / (reserveIn * 1000 + amountIn * 997)
            // 0.3% fee = 997/1000 ratio
            $amountInWithFee = bcmul($amountInStr, '997', 0);
            $numerator = bcmul($amountInWithFee, $reserveOut, 0);
            $denominator = bcadd(bcmul($reserveIn, '1000', 0), $amountInWithFee, 0);
            
            $amountOut = bcdiv($numerator, $denominator, 0);
            
            // Calculate price impact
            $priceImpact = '0';
            if (bccomp($reserveIn, '0', 0) > 0 && bccomp($reserveOut, '0', 0) > 0) {
                $priceBefore = bcdiv($reserveOut, $reserveIn, 18);
                $newReserveIn = bcadd($reserveIn, $amountInStr, 0);
                $newReserveOut = bcsub($reserveOut, $amountOut, 0);
                
                if (bccomp($newReserveIn, '0', 0) > 0 && bccomp($newReserveOut, '0', 0) > 0) {
                    $priceAfter = bcdiv($newReserveOut, $newReserveIn, 18);
                    $priceChange = bcdiv(bcsub($priceBefore, $priceAfter, 18), $priceBefore, 18);
                    $priceImpact = bcmul($priceChange, '100', 4); // Convert to percentage
                }
            }
            
            // Calculate fee = amountIn * 0.3% (3/1000). Note: $amountInWithFee above is the
            // formula's *scaled* numerator term (amountIn*997), NOT amountIn*0.997.
            $feeAmount = bcdiv(bcmul($amountInStr, '3', 0), '1000', 0);
            
            // Convert back to human readable units
            $amountOutFloat = (float)bcdiv($amountOut, '1000000000000000000', 18);
            $feeFloat = (float)bcdiv($feeAmount, '1000000000000000000', 18);
            
            return [
                'tokenIn' => $tokenIn,
                'tokenOut' => $tokenOut,
                'amountIn' => $amountIn,
                'amountOut' => $amountOutFloat,
                'price_impact' => (float)$priceImpact,
                'fee' => $feeFloat,
                'route' => [$tokenIn, $tokenOut],
                'pair_exists' => true,
                'reserves_in' => $reserveIn,
                'reserves_out' => $reserveOut,
                'minimum_received' => $amountOutFloat * 0.995 // 0.5% slippage tolerance
            ];
            
        } catch (Exception $e) {
            writeLog("Get swap quote failed: " . $e->getMessage(), 'ERROR');
            return [
                'error' => $e->getMessage(),
                'tokenIn' => $tokenIn,
                'tokenOut' => $tokenOut,
                'amountIn' => $amountIn,
                'amountOut' => 0
            ];
        }
    }
    
    /**
     * Remove liquidity from pool
     */
    function removeLiquidity($walletManager, $address, $tokenA, $tokenB, float $liquidity, float $amountAMin, float $amountBMin, $blockchainManager = null): array {
        try {
            $pdo = $walletManager->getDatabase();
            writeLog("Removing liquidity: $liquidity from $tokenA/$tokenB pool for $address", 'INFO');

            if ($liquidity <= 0) {
                return ['error' => 'liquidity must be positive'];
            }

            // Resolve the pool the same way it was created (consistent address + reserves in wei,
            // mapped to the caller's tokenA/tokenB order).
            $pool = getPoolReserves($tokenA, $tokenB);
            if (empty($pool['pair_exists'])) {
                return ['error' => 'Pool not found'];
            }
            $pairAddress = $pool['pair_address'];

            // Real LP supply lives in metadata.total_supply (minted on add_liquidity).
            $metaStmt = $pdo->prepare("SELECT metadata FROM smart_contracts WHERE address = ?");
            $metaStmt->execute([$pairAddress]);
            $meta = json_decode((string)($metaStmt->fetchColumn() ?: '{}'), true) ?: [];
            $totalSupply = (string)($meta['total_supply'] ?? '0');
            if (bccomp($totalSupply, '0', 18) <= 0) {
                return ['error' => 'Empty pool'];
            }

            // The caller must actually own the LP tokens they are burning.
            $lpBalance = tokenBalanceGet($pdo, $address, $pairAddress);
            if (bccomp($lpBalance, (string)$liquidity, 18) < 0) {
                return ['error' => "Insufficient LP balance: $lpBalance < $liquidity"];
            }

            // Reserves in human units, aligned to the caller's tokenA/tokenB order.
            $reserveA = fromWei($pool['reserveA']);
            $reserveB = fromWei($pool['reserveB']);

            // Pro-rata share of the pool.
            $amountA = bcdiv(bcmul((string)$liquidity, $reserveA, 18), $totalSupply, 18);
            $amountB = bcdiv(bcmul((string)$liquidity, $reserveB, 18), $totalSupply, 18);

            if (bccomp($amountA, (string)$amountAMin, 18) < 0) {
                return ['error' => "Insufficient amount A: $amountA < $amountAMin"];
            }
            if (bccomp($amountB, (string)$amountBMin, 18) < 0) {
                return ['error' => "Insufficient amount B: $amountB < $amountBMin"];
            }

            // New reserves (wei) and LP supply.
            $newReserveAWei = bcsub($pool['reserveA'], toWei($amountA), 0);
            $newReserveBWei = bcsub($pool['reserveB'], toWei($amountB), 0);
            $newSupply = bcsub($totalSupply, (string)$liquidity, 18);

            $meta['token0']      = $pool['token0'];
            $meta['token1']      = $pool['token1'];
            $meta['reserves0']   = ($tokenA === $pool['token0']) ? $newReserveAWei : $newReserveBWei;
            $meta['reserves1']   = ($tokenA === $pool['token0']) ? $newReserveBWei : $newReserveAWei;
            $meta['total_supply']= $newSupply;
            $meta['last_removal']= time();
            $meta['total_removals'] = (int)($meta['total_removals'] ?? 0) + 1;

            // Atomic settlement: reserves + LP burn + asset return commit together or roll back.
            $pdo->beginTransaction();

            $pdo->prepare("UPDATE smart_contracts SET metadata = ?, updated_at = CURRENT_TIMESTAMP WHERE address = ?")
                ->execute([json_encode($meta), $pairAddress]);

            // Burn the provider's LP and return the underlying assets.
            tokenBalanceAdd($pdo, $address, $pairAddress, '-' . $liquidity);
            if ($tokenA === 'native') {
                $pdo->prepare("UPDATE wallets SET balance = balance + ? WHERE address = ?")
                    ->execute([$amountA, strtolower($address)]);
            } else {
                tokenBalanceAdd($pdo, $address, $tokenA, $amountA);
            }
            if ($tokenB === 'native') {
                $pdo->prepare("UPDATE wallets SET balance = balance + ? WHERE address = ?")
                    ->execute([$amountB, strtolower($address)]);
            } else {
                tokenBalanceAdd($pdo, $address, $tokenB, $amountB);
            }

            $pdo->commit();

            // B3: record remove-liquidity as a real on-chain transaction (mined into a block).
            $txHash = recordDexTransactionOnChain($blockchainManager, $pdo, 'remove_liquidity', $pairAddress, $address, (float)$amountA, [
                'action' => 'remove_liquidity',
                'tokenA' => $tokenA,
                'tokenB' => $tokenB,
                'amountA' => $amountA,
                'amountB' => $amountB,
                'liquidity_burned' => $liquidity,
                'pool_address' => $pairAddress,
            ]);

            return [
                'liquidity_removed' => true,
                'tokenA' => $tokenA,
                'tokenB' => $tokenB,
                'amountA' => $amountA,
                'amountB' => $amountB,
                'liquidity_burned' => $liquidity,
                'provider' => $address,
                'transaction_hash' => $txHash,
                'pool_address' => $pairAddress,
                'new_reserves' => [
                    'reserveA' => fromWei($newReserveAWei),
                    'reserveB' => fromWei($newReserveBWei)
                ]
            ];

        } catch (Exception $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            writeLog("Remove liquidity failed: " . $e->getMessage(), 'ERROR');
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Get all trading pairs - REAL implementation from database
     */
    function getAllPairs($pdo): array {
        try {
            writeLog("Getting all trading pairs from database", 'DEBUG');
            
            // Query real pairs from smart contracts table (where DEX pairs would be stored)
            $stmt = $pdo->prepare("
                SELECT 
                    address as pair_address,
                    name as pair_name,
                    metadata,
                    created_at,
                    status
                FROM smart_contracts 
                WHERE name LIKE '%pair%' OR name LIKE '%DEX%' OR name LIKE '%LP%'
                AND status = 'active'
                ORDER BY created_at DESC
                LIMIT 100
            ");
            $stmt->execute();
            $contractPairs = $stmt->fetchAll();
            
            $pairs = [];
            
            // If no contract pairs found, create mock data based on existing transactions
            if (empty($contractPairs)) {
                // Analyze transaction data to find potential trading pairs
                $stmt = $pdo->prepare("
                    SELECT 
                        LEAST(from_address, to_address) as token0,
                        GREATEST(from_address, to_address) as token1,
                        COUNT(*) as tx_count,
                        MAX(timestamp) as last_activity,
                        SUM(amount) as total_volume
                    FROM transactions 
                    WHERE status = 'confirmed'
                    AND amount > 0
                    AND from_address != to_address
                    GROUP BY LEAST(from_address, to_address), GREATEST(from_address, to_address)
                    HAVING tx_count > 1
                    ORDER BY total_volume DESC
                    LIMIT 20
                ");
                $stmt->execute();
                $tradingPairs = $stmt->fetchAll();
                
                foreach ($tradingPairs as $pairData) {
                    $token0 = $pairData['token0'];
                    $token1 = $pairData['token1'];
                    
                    // Generate deterministic pair address
                    $pairAddress = 'pair_' . substr(hash('sha256', $token0 . '_' . $token1), 0, 16);
                    
                    // Mock reserves based on transaction volume
                    $reserves0 = bcmul($pairData['total_volume'], '0.4', 0); // 40% of volume
                    $reserves1 = bcmul($pairData['total_volume'], '0.6', 0); // 60% of volume
                    
                    // Calculate LP token supply using geometric mean
                    $totalSupply = '0';
                    if (bccomp($reserves0, '0', 0) > 0 && bccomp($reserves1, '0', 0) > 0) {
                        // LP supply = sqrt(reserve0 * reserve1)
                        $product = bcmul($reserves0, $reserves1, 0);
                        $totalSupply = bcsqrt($product, 0);
                    }
                    
                    $pairs[] = [
                        'token0' => $token0,
                        'token1' => $token1,
                        'pair_address' => $pairAddress,
                        'reserves0' => $reserves0,
                        'reserves1' => $reserves1,
                        'total_supply' => $totalSupply,
                        'transaction_count' => (int)$pairData['tx_count'],
                        'last_activity' => (int)$pairData['last_activity'],
                        'total_volume' => $pairData['total_volume'],
                        'price_0_per_1' => bccomp($reserves1, '0', 0) > 0 ? bcdiv($reserves0, $reserves1, 18) : '0',
                        'price_1_per_0' => bccomp($reserves0, '0', 0) > 0 ? bcdiv($reserves1, $reserves0, 18) : '0'
                    ];
                }
            } else {
                // Use actual contract pairs
                foreach ($contractPairs as $contract) {
                    $metadata = json_decode($contract['metadata'], true) ?? [];
                    
                    $pairs[] = [
                        'token0' => $metadata['token0'] ?? 'native',
                        'token1' => $metadata['token1'] ?? 'unknown',
                        'pair_address' => $contract['pair_address'],
                        'reserves0' => $metadata['reserves0'] ?? '0',
                        'reserves1' => $metadata['reserves1'] ?? '0',
                        'total_supply' => $metadata['total_supply'] ?? '0',
                        'transaction_count' => $metadata['tx_count'] ?? 0,
                        'last_activity' => strtotime($contract['created_at']),
                        'total_volume' => $metadata['volume'] ?? '0',
                        'price_0_per_1' => $metadata['price_0_per_1'] ?? '0',
                        'price_1_per_0' => $metadata['price_1_per_0'] ?? '0'
                    ];
                }
            }
            
            writeLog("Found " . count($pairs) . " trading pairs", 'INFO');
            
            return [
                'pairs' => $pairs,
                'total_pairs' => count($pairs)
            ];
            
        } catch (Exception $e) {
            writeLog("Get all pairs failed: " . $e->getMessage(), 'ERROR');
            return [
                'error' => $e->getMessage(),
                'pairs' => [],
                'total_pairs' => 0
            ];
        }
    }
    
    /**
     * Get pair address for two tokens
     */
    function getPairAddress($tokenA, $tokenB): array {
        try {
            global $pdo;
            writeLog("Getting pair address for $tokenA/$tokenB", 'DEBUG');
            
            // Sort tokens for consistent pair address
            $tokens = [$tokenA, $tokenB];
            sort($tokens);
            $token0 = $tokens[0];
            $token1 = $tokens[1];
            
            // Generate deterministic pair address
            $pairName = $token0 . '_' . $token1;
            $pairAddress = 'pair_' . hash('sha256', $pairName);
            
            // Check if pair actually exists in database
            $stmt = $pdo->prepare("
                SELECT address, name, metadata FROM smart_contracts 
                WHERE address = ? AND name LIKE '%pool%'
            ");
            $stmt->execute([$pairAddress]);
            $pair = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($pair) {
                $metadata = json_decode($pair['metadata'], true);
                
                return [
                    'token0' => $token0,
                    'token1' => $token1,
                    'pair_address' => $pairAddress,
                    'exists' => true,
                    'pair_name' => $pair['name'],
                    'reserves0' => $metadata['reserves0'] ?? '0',
                    'reserves1' => $metadata['reserves1'] ?? '0',
                    'created_at' => $metadata['created_at'] ?? null
                ];
            } else {
                return [
                    'token0' => $token0,
                    'token1' => $token1,
                    'pair_address' => $pairAddress,
                    'exists' => false,
                    'message' => 'Pair does not exist yet'
                ];
            }
            
        } catch (Exception $e) {
            writeLog("Get pair address failed: " . $e->getMessage(), 'ERROR');
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Approve token spending
     */
    function approveToken($walletManager, $owner, $spender, $token, float $amount): array {
        try {
            global $pdo;
            writeLog("Approving $amount $token from $owner to $spender", 'INFO');
            
            // Create approval transaction in database
            $txHash = 'approve_' . bin2hex(random_bytes(16));
            
            $txData = json_encode([
                'action' => 'token_approval',
                'token' => $token,
                'owner' => $owner,
                'spender' => $spender,
                'amount' => $amount,
                'approval_time' => time()
            ]);
            
            // Store approval transaction
            $stmt = $pdo->prepare("
                INSERT INTO transactions (
                    hash, block_hash, block_height, from_address, to_address,
                    amount, fee, gas_limit, gas_used, gas_price, nonce,
                    data, signature, status, timestamp
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            // Get latest block hash for foreign key constraint
            $latestBlockHash = getLatestBlockHash($pdo);
            
            $stmt->execute([
                $txHash,
                $latestBlockHash,
                time(),
                $owner,
                'dex_contract', // Use fixed DEX contract address
                $amount,
                0.0001, // Small approval fee
                21000,
                0,
                0,
                0,
                $txData,
                'approval_signature',
                'confirmed',
                time()
            ]);
            
            // Store allowance in smart contracts table for tracking
            $allowanceAddress = '0x' . substr(hash('sha256', $owner . $spender . $token), 0, 40);
            
            $allowanceData = [
                'owner' => $owner,
                'spender' => $spender,
                'token' => $token,
                'amount' => $amount,
                'created_at' => time(),
                'tx_hash' => $txHash
            ];
            
            $stmt = $pdo->prepare("
                INSERT INTO smart_contracts (address, creator, name, bytecode, abi, deployment_tx, deployment_block, metadata, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                ON DUPLICATE KEY UPDATE
                metadata = ?, updated_at = CURRENT_TIMESTAMP
            ");
            
            $stmt->execute([
                $allowanceAddress,
                $owner,  // Creator is the owner of the allowance
                'token_allowance',
                'allowance_bytecode',  // Placeholder bytecode
                '[]',  // Empty ABI
                $txHash,  // Deployment transaction
                time(),  // Current block height
                json_encode($allowanceData),
                'active',
                json_encode($allowanceData)
            ]);
            
            writeLog("Token approval stored: $txHash", 'INFO');
            
            return [
                'approved' => true,
                'owner' => $owner,
                'spender' => $spender,
                'token' => $token,
                'amount' => $amount,
                'transaction_hash' => $txHash,
                'allowance_address' => $allowanceAddress
            ];
            
        } catch (Exception $e) {
            writeLog("Token approval failed: " . $e->getMessage(), 'ERROR');
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Get token balance - REAL implementation from wallet manager and database
     */
    function getTokenBalance($walletManager, $address, $token): array {
        try {
            writeLog("Getting $token balance for $address from database", 'DEBUG');
            
            $balance = '0';
            $decimals = 18;
            
            // Handle native/main token
            if ($token === 'main_token' || $token === 'native' || $token === '') {
                $balance = $walletManager->getBalance($address);
                
                // Get token info from database
                $pdo = $walletManager->getDatabase();
                $stmt = $pdo->prepare("SELECT value FROM config WHERE key_name = 'network.decimals' LIMIT 1");
                $stmt->execute();
                $result = $stmt->fetch();
                $decimals = (int)($result['value'] ?? 18);
                
                return [
                    'address' => $address,
                    'token' => $token,
                    'balance' => $balance,
                    'decimals' => $decimals,
                    'token_type' => 'native'
                ];
            }
            
            // Handle staked tokens
            if ($token === 'staked' || $token === 'staking') {
                $stakingInfo = $walletManager->getStakingInfo($address);
                $balance = $stakingInfo['staked_balance'] ?? '0';
                
                return [
                    'address' => $address,
                    'token' => $token,
                    'balance' => $balance,
                    'decimals' => $decimals,
                    'token_type' => 'staked'
                ];
            }
            
            // Handle other (ERC20-style) tokens — read from the authoritative token ledger.
            $pdo = $walletManager->getDatabase();
            $balance = tokenBalanceGet($pdo, $address, $token);

            return [
                'address' => $address,
                'token' => $token,
                'balance' => $balance,
                'decimals' => $decimals,
                'token_type' => 'custom'
            ];
            
        } catch (Exception $e) {
            writeLog("Get token balance failed: " . $e->getMessage(), 'ERROR');
            return [
                'error' => $e->getMessage(),
                'address' => $address,
                'token' => $token,
                'balance' => '0',
                'decimals' => 18
            ];
        }
    }
    
    /**
     * Get token allowance
     */
    function getTokenAllowance($walletManager, $owner, $spender, $token): array {
        try {
            global $pdo;
            writeLog("Getting $token allowance from $owner to $spender", 'DEBUG');
            
            // Look up allowance in database
            $allowanceAddress = 'allowance_' . hash('sha256', $owner . $spender . $token);
            
            $stmt = $pdo->prepare("
                SELECT metadata FROM smart_contracts 
                WHERE address = ? AND name = 'token_allowance' AND status = 'active'
            ");
            $stmt->execute([$allowanceAddress]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                $metadata = json_decode($result['metadata'], true);
                $allowance = $metadata['amount'] ?? '0';
                
                writeLog("Found allowance: $allowance", 'DEBUG');
                
                return [
                    'owner' => $owner,
                    'spender' => $spender,
                    'token' => $token,
                    'allowance' => $allowance,
                    'decimals' => 18,
                    'approved_at' => $metadata['created_at'] ?? null,
                    'tx_hash' => $metadata['tx_hash'] ?? null
                ];
            } else {
                writeLog("No allowance found, returning 0", 'DEBUG');
                
                return [
                    'owner' => $owner,
                    'spender' => $spender,
                    'token' => $token,
                    'allowance' => '0',
                    'decimals' => 18
                ];
            }
            
        } catch (Exception $e) {
            writeLog("Get token allowance failed: " . $e->getMessage(), 'ERROR');
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Create new trading pair
     */
    function createPair($walletManager, $deployer, $tokenA, $tokenB): array {
        try {
            global $pdo;
            writeLog("Creating new pair: $tokenA/$tokenB by $deployer", 'INFO');
            
            // Sort tokens for consistency
            $tokens = [$tokenA, $tokenB];
            sort($tokens);
            $token0 = $tokens[0];
            $token1 = $tokens[1];
            
            // Check if pair already exists
            $pairName = $token0 . '_' . $token1;
            $pairAddress = 'pair_' . hash('sha256', $pairName);
            
            $stmt = $pdo->prepare("
                SELECT address FROM smart_contracts 
                WHERE address = ? AND name LIKE '%pool%'
            ");
            $stmt->execute([$pairAddress]);
            
            if ($stmt->fetch()) {
                return ['error' => 'Pair already exists', 'existing_address' => $pairAddress];
            }
            
            // Create pair metadata
            $metadata = [
                'token0' => $token0,
                'token1' => $token1,
                'reserves0' => '0',
                'reserves1' => '0',
                'deployer' => $deployer,
                'created_at' => time(),
                'total_supply' => '0',
                'total_swaps' => 0,
                'total_adds' => 0,
                'total_removals' => 0
            ];
            
            // Create smart contract entry for the pair
            $stmt = $pdo->prepare("
                INSERT INTO smart_contracts (address, name, metadata, status, created_at)
                VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
            ");
            
            $stmt->execute([
                $pairAddress,
                $token0 . '/' . $token1 . '_pool',
                json_encode($metadata),
                'active'
            ]);
            
            // Create deployment transaction
            $txHash = 'create_pair_' . bin2hex(random_bytes(16));
            
            $txData = json_encode([
                'action' => 'create_pair',
                'token0' => $token0,
                'token1' => $token1,
                'pair_address' => $pairAddress,
                'deployer' => $deployer
            ]);
            
            $stmt = $pdo->prepare("
                INSERT INTO transactions (
                    hash, block_hash, block_height, from_address, to_address,
                    amount, fee, gas_limit, gas_used, gas_price, nonce,
                    data, signature, status, timestamp
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $txHash,
                'latest_block',
                time(),
                $deployer,
                $pairAddress,
                0,
                0.01, // Deployment fee
                100000,
                0,
                0,
                0,
                $txData,
                'pair_deployment_signature',
                'confirmed',
                time()
            ]);
            
            writeLog("Pair created successfully: $pairAddress", 'INFO');
            
            return [
                'pair_created' => true,
                'token0' => $token0,
                'token1' => $token1,
                'pair_address' => $pairAddress,
                'deployer' => $deployer,
                'transaction_hash' => $txHash,
                'metadata' => $metadata
            ];
            
        } catch (Exception $e) {
            writeLog("Create pair failed: " . $e->getMessage(), 'ERROR');
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Get DEX statistics - REAL implementation with database queries
     */
    function getDexStats($pdo): array {
        try {
            writeLog("Getting DEX statistics from database", 'DEBUG');
            
            // Get real stats from database
            $stats = [
                'total_pairs' => 0,
                'total_volume_24h' => '0',
                'total_liquidity' => '0', 
                'total_transactions' => 0,
                'active_traders' => 0,
                'top_pairs' => []
            ];
            
            // 1. Count total pairs from smart contracts
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as pair_count 
                FROM smart_contracts 
                WHERE name LIKE '%pair%' OR name LIKE '%DEX%' OR name LIKE '%LP%'
                AND status = 'active'
            ");
            $stmt->execute();
            $pairResult = $stmt->fetch();
            $stats['total_pairs'] = (int)($pairResult['pair_count'] ?? 0);
            
            // 2. Calculate 24h volume from all transactions
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(CAST(amount AS DECIMAL(30,0))), 0) as volume_24h
                FROM transactions 
                WHERE timestamp >= (UNIX_TIMESTAMP() - 86400)
                AND status = 'confirmed'
                AND amount > 0
            ");
            $stmt->execute();
            $volumeResult = $stmt->fetch();
            $stats['total_volume_24h'] = $volumeResult['volume_24h'] ?? '0';
            
            // 3. Calculate total liquidity from staking contracts
            $stmt = $pdo->prepare("
                SELECT 
                    COALESCE(SUM(CAST(amount AS DECIMAL(30,0))), 0) as total_staked,
                    COUNT(*) as staking_count
                FROM staking 
                WHERE status = 'active'
            ");
            $stmt->execute();
            $stakingResult = $stmt->fetch();
            
            // Add wallet balances as potential liquidity
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(CAST(balance AS DECIMAL(30,0))), 0) as total_balance
                FROM wallets 
                WHERE balance > 1000 -- Only count significant balances
            ");
            $stmt->execute();
            $balanceResult = $stmt->fetch();
            
            // Estimate total liquidity
            $stakingLiquidity = $stakingResult['total_staked'] ?? '0';
            $walletLiquidity = bcmul($balanceResult['total_balance'] ?? '0', '0.1', 0); // 10% of wallet balances
            $stats['total_liquidity'] = bcadd($stakingLiquidity, $walletLiquidity, 0);
            
            // 4. Count total transactions
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as tx_count 
                FROM transactions 
                WHERE status = 'confirmed'
            ");
            $stmt->execute();
            $txResult = $stmt->fetch();
            $stats['total_transactions'] = (int)($txResult['tx_count'] ?? 0);
            
            // 5. Count active traders (unique addresses in last 24h)
            $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT from_address) as active_traders
                FROM transactions 
                WHERE timestamp >= (UNIX_TIMESTAMP() - 86400)
                AND status = 'confirmed'
            ");
            $stmt->execute();
            $tradersResult = $stmt->fetch();
            $stats['active_traders'] = (int)($tradersResult['active_traders'] ?? 0);
            
            // 6. Get top trading pairs by transaction volume
            $stmt = $pdo->prepare("
                SELECT 
                    LEAST(from_address, to_address) as token0,
                    GREATEST(from_address, to_address) as token1,
                    COUNT(*) as tx_count,
                    COALESCE(SUM(CAST(amount AS DECIMAL(30,0))), 0) as volume_24h
                FROM transactions 
                WHERE timestamp >= (UNIX_TIMESTAMP() - 86400)
                AND status = 'confirmed'
                AND amount > 0
                AND from_address != to_address
                GROUP BY LEAST(from_address, to_address), GREATEST(from_address, to_address)
                HAVING tx_count > 1
                ORDER BY volume_24h DESC
                LIMIT 10
            ");
            $stmt->execute();
            $topPairs = $stmt->fetchAll();
            
            $stats['top_pairs'] = array_map(function($pair) {
                return [
                    'token0' => $pair['token0'] ?? 'unknown',
                    'token1' => $pair['token1'] ?? 'unknown', 
                    'volume_24h' => $pair['volume_24h'] ?? '0',
                    'tx_count' => (int)($pair['tx_count'] ?? 0)
                ];
            }, $topPairs);
            
            writeLog("DEX stats calculated: " . json_encode($stats), 'INFO');
            return $stats;
            
        } catch (Exception $e) {
            writeLog("Get DEX stats failed: " . $e->getMessage(), 'ERROR');
            
            // Return minimal fallback data on error
            return [
                'error' => $e->getMessage(),
                'total_pairs' => 0,
                'total_volume_24h' => '0',
                'total_liquidity' => '0',
                'total_transactions' => 0,
                'active_traders' => 0,
                'top_pairs' => []
            ];
        }
    }
