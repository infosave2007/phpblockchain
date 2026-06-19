<?php
/**
 * Smart-contract / ERC20 token module (real, ledger-backed).
 *
 * Tokens deployed here are first-class: balances live in `token_balances` (the same ledger the
 * DEX settles against), so a deployed token is immediately tradable on the AMM, shows real
 * balances, and is queryable via ERC20 calls and eth_call. Allowances live in `token_allowances`.
 *
 * Global functions (no namespace) — required by wallet_api.php. Reuses helpers from dex.php
 * (toWei/fromWei/tokenBalanceGet/tokenBalanceAdd/recordDexTransactionOnChain/writeLog).
 */

// ---- ERC20 method selectors (keccak256 of signature, first 4 bytes) ----
if (!defined('ERC20_SELECTORS')) {
    define('ERC20_SELECTORS', [
        '06fdde03' => 'name',
        '95d89b41' => 'symbol',
        '313ce567' => 'decimals',
        '18160ddd' => 'totalSupply',
        '70a08231' => 'balanceOf',
        'a9059cbb' => 'transfer',
        '095ea7b3' => 'approve',
        'dd62ed3e' => 'allowance',
        '23b872dd' => 'transferFrom',
    ]);
}

/** Big-decimal integer string -> 32-byte hex (no 0x). */
if (!function_exists('abiUint')) {
    function abiUint(string $dec): string {
        $dec = explode('.', $dec)[0];
        if ($dec === '' || $dec === '-') $dec = '0';
        $neg = false;
        if ($dec[0] === '-') { $dec = substr($dec, 1); $neg = true; }
        $hex = '';
        if (function_exists('gmp_init')) {
            $hex = gmp_strval(gmp_init($dec, 10), 16);
        } else {
            // bcmath fallback
            $n = $dec;
            if ($n === '0') $hex = '0';
            while (bccomp($n, '0', 0) > 0) {
                $rem = (int)bcmod($n, '16');
                $hex = dechex($rem) . $hex;
                $n = bcdiv($n, '16', 0);
            }
        }
        if ($neg) { $hex = '0'; } // we don't return negative values
        return str_pad($hex, 64, '0', STR_PAD_LEFT);
    }
}

/** ABI-encode a string (dynamic): offset + length + padded data. */
if (!function_exists('abiString')) {
    function abiString(string $s): string {
        $hex = bin2hex($s);
        $len = strlen($s);
        $padded = str_pad($hex, (int)(ceil(strlen($hex) / 64) * 64), '0', STR_PAD_RIGHT);
        if ($padded === '') $padded = str_repeat('0', 64);
        return str_pad('20', 64, '0', STR_PAD_LEFT)   // offset
             . str_pad(dechex($len), 64, '0', STR_PAD_LEFT) // length
             . $padded;
    }
}

/** Extract the i-th 32-byte word (as address / uint) from calldata hex (without selector). */
if (!function_exists('abiWord')) {
    function abiWord(string $argsHex, int $i): string {
        $word = substr($argsHex, $i * 64, 64);
        return $word === '' ? str_repeat('0', 64) : $word;
    }
}
if (!function_exists('abiWordAddress')) {
    function abiWordAddress(string $argsHex, int $i): string {
        return '0x' . substr(abiWord($argsHex, $i), 24); // last 20 bytes
    }
}
if (!function_exists('abiWordUintDec')) {
    function abiWordUintDec(string $argsHex, int $i): string {
        $hex = ltrim(abiWord($argsHex, $i), '0');
        if ($hex === '') return '0';
        if (function_exists('gmp_init')) return gmp_strval(gmp_init($hex, 16), 10);
        // bcmath fallback
        $dec = '0';
        foreach (str_split($hex) as $c) { $dec = bcadd(bcmul($dec, '16', 0), (string)hexdec($c), 0); }
        return $dec;
    }
}

/** Load a token contract row by address; null if not a known token. */
if (!function_exists('loadTokenContract')) {
    function loadTokenContract(\PDO $pdo, string $address): ?array {
        $stmt = $pdo->prepare("SELECT address, name, metadata FROM smart_contracts WHERE address = ? AND status='active' LIMIT 1");
        $stmt->execute([$address]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return null;
        $meta = json_decode((string)($row['metadata'] ?? '{}'), true) ?: [];
        $type = $meta['type'] ?? '';
        if (!in_array($type, ['token', 'erc20', 'wrapped_token'], true)) return null;
        return ['address' => $row['address'], 'name' => $row['name'], 'meta' => $meta];
    }
}

if (!function_exists('tokenAllowanceGet')) {
    function tokenAllowanceGet(\PDO $pdo, string $token, string $owner, string $spender): string {
        $stmt = $pdo->prepare("SELECT amount FROM token_allowances WHERE token=? AND owner=? AND spender=?");
        $stmt->execute([$token, strtolower($owner), strtolower($spender)]);
        $v = $stmt->fetchColumn();
        return $v === false ? '0' : (string)$v;
    }
}
if (!function_exists('tokenAllowanceSet')) {
    function tokenAllowanceSet(\PDO $pdo, string $token, string $owner, string $spender, string $amount): void {
        $pdo->prepare("INSERT INTO token_allowances (token, owner, spender, amount) VALUES (?,?,?,?)
                       ON DUPLICATE KEY UPDATE amount = VALUES(amount)")
            ->execute([$token, strtolower($owner), strtolower($spender), $amount]);
    }
}

/**
 * Deploy a real ERC20 token. Total supply is minted to the creator in the token ledger.
 */
if (!function_exists('deployToken')) {
    function deployToken($walletManager, string $creator, string $name, string $symbol, int $decimals, float $totalSupply, $blockchainManager = null): array {
        try {
            $pdo = $walletManager->getDatabase();
            $name = trim($name); $symbol = strtoupper(trim($symbol));
            if ($name === '' || $symbol === '') return ['error' => 'name and symbol are required'];
            if (strlen($symbol) > 16) return ['error' => 'symbol too long'];
            if ($decimals < 0 || $decimals > 18) return ['error' => 'decimals must be 0..18'];
            if ($totalSupply <= 0) return ['error' => 'totalSupply must be positive'];
            $creator = strtolower($creator);

            // Deterministic-ish Ethereum-style address.
            $address = '0x' . substr(hash('sha256', 'token|' . $symbol . '|' . $creator . '|' . microtime(true) . '|' . bin2hex(random_bytes(8))), 0, 40);

            $abi = [
                ['name'=>'name','type'=>'function','stateMutability'=>'view','inputs'=>[],'outputs'=>[['type'=>'string']]],
                ['name'=>'symbol','type'=>'function','stateMutability'=>'view','inputs'=>[],'outputs'=>[['type'=>'string']]],
                ['name'=>'decimals','type'=>'function','stateMutability'=>'view','inputs'=>[],'outputs'=>[['type'=>'uint8']]],
                ['name'=>'totalSupply','type'=>'function','stateMutability'=>'view','inputs'=>[],'outputs'=>[['type'=>'uint256']]],
                ['name'=>'balanceOf','type'=>'function','stateMutability'=>'view','inputs'=>[['name'=>'owner','type'=>'address']],'outputs'=>[['type'=>'uint256']]],
                ['name'=>'transfer','type'=>'function','stateMutability'=>'nonpayable','inputs'=>[['name'=>'to','type'=>'address'],['name'=>'amount','type'=>'uint256']],'outputs'=>[['type'=>'bool']]],
                ['name'=>'approve','type'=>'function','stateMutability'=>'nonpayable','inputs'=>[['name'=>'spender','type'=>'address'],['name'=>'amount','type'=>'uint256']],'outputs'=>[['type'=>'bool']]],
                ['name'=>'allowance','type'=>'function','stateMutability'=>'view','inputs'=>[['name'=>'owner','type'=>'address'],['name'=>'spender','type'=>'address']],'outputs'=>[['type'=>'uint256']]],
                ['name'=>'transferFrom','type'=>'function','stateMutability'=>'nonpayable','inputs'=>[['name'=>'from','type'=>'address'],['name'=>'to','type'=>'address'],['name'=>'amount','type'=>'uint256']],'outputs'=>[['type'=>'bool']]],
            ];
            $metadata = [
                'type' => 'token',
                'standard' => 'ERC20',
                'symbol' => $symbol,
                'name' => $name,
                'decimals' => $decimals,
                'total_supply' => (string)$totalSupply,
                'creator' => $creator,
                'created_at' => time(),
            ];

            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO smart_contracts
                (address, creator, name, version, bytecode, abi, source_code, deployment_tx, deployment_block, gas_used, status, storage, metadata)
                VALUES (?, ?, ?, '1.0.0', ?, ?, ?, ?, ?, 0, 'active', ?, ?)")
                ->execute([
                    $address, $creator, $name,
                    '0x' . hash('sha256', 'erc20bytecode|' . $symbol), // marker bytecode (ledger-backed token)
                    json_encode($abi), 'ERC20 (ledger-backed)',
                    'deploy_token_' . bin2hex(random_bytes(8)), getCurrentBlockHeight($pdo),
                    json_encode([]), json_encode($metadata),
                ]);
            // Mint total supply to creator.
            tokenBalanceAdd($pdo, $creator, $address, (string)$totalSupply);
            $pdo->commit();

            // Record on-chain (real block).
            $txHash = recordDexTransactionOnChain($blockchainManager, $pdo, 'deploy_token', $creator, $address, (float)$totalSupply, [
                'action' => 'deploy_token', 'symbol' => $symbol, 'name' => $name,
                'decimals' => $decimals, 'total_supply' => $totalSupply, 'contract' => $address,
            ]);

            return [
                'deployed' => true, 'address' => $address, 'symbol' => $symbol, 'name' => $name,
                'decimals' => $decimals, 'total_supply' => $totalSupply, 'creator' => $creator,
                'transaction_hash' => $txHash,
            ];
        } catch (\Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
            writeLog('deployToken failed: ' . $e->getMessage(), 'ERROR');
            return ['error' => $e->getMessage()];
        }
    }
}

/**
 * Execute an ERC20 method against the ledger. Read methods return values; write methods mutate.
 * Returns ['result' => mixed] or ['error' => string].
 */
if (!function_exists('callTokenMethod')) {
    function callTokenMethod($walletManager, string $contractAddress, string $method, array $args = [], string $caller = '', $blockchainManager = null): array {
        $pdo = $walletManager->getDatabase();
        $tk = loadTokenContract($pdo, $contractAddress);
        if (!$tk) return ['error' => 'Not a known token contract'];
        $meta = $tk['meta'];
        $decimals = (int)($meta['decimals'] ?? 18);

        switch ($method) {
            case 'name':        return ['result' => $meta['name'] ?? $tk['name']];
            case 'symbol':      return ['result' => $meta['symbol'] ?? ''];
            case 'decimals':    return ['result' => $decimals];
            case 'totalSupply': return ['result' => (string)($meta['total_supply'] ?? '0')];
            case 'balanceOf':
                return ['result' => tokenBalanceGet($pdo, (string)($args[0] ?? ''), $contractAddress)];
            case 'allowance':
                return ['result' => tokenAllowanceGet($pdo, $contractAddress, (string)($args[0] ?? ''), (string)($args[1] ?? ''))];
            case 'approve': {
                if ($caller === '') return ['error' => 'caller (owner) required'];
                tokenAllowanceSet($pdo, $contractAddress, $caller, (string)($args[0] ?? ''), (string)($args[1] ?? '0'));
                return ['result' => true];
            }
            case 'transfer': {
                if ($caller === '') return ['error' => 'caller (from) required'];
                $to = (string)($args[0] ?? ''); $amount = (string)($args[1] ?? '0');
                if ((float)$amount <= 0) return ['error' => 'amount must be positive'];
                $pdo->beginTransaction();
                try {
                    if (!tokenBalanceAdd($pdo, $caller, $contractAddress, '-' . $amount)) { throw new \Exception('Insufficient balance'); }
                    tokenBalanceAdd($pdo, $to, $contractAddress, $amount);
                    $pdo->commit();
                } catch (\Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); return ['error' => $e->getMessage()]; }
                recordDexTransactionOnChain($blockchainManager, $pdo, 'token_transfer', $caller, $to, (float)$amount, ['token'=>$contractAddress,'symbol'=>$meta['symbol']??'']);
                return ['result' => true];
            }
            case 'transferFrom': {
                $from = (string)($args[0] ?? ''); $to = (string)($args[1] ?? ''); $amount = (string)($args[2] ?? '0');
                if ($caller === '') return ['error' => 'caller (spender) required'];
                if ((float)$amount <= 0) return ['error' => 'amount must be positive'];
                $allow = tokenAllowanceGet($pdo, $contractAddress, $from, $caller);
                if (bccomp($allow, $amount, 18) < 0) return ['error' => 'Insufficient allowance'];
                $pdo->beginTransaction();
                try {
                    if (!tokenBalanceAdd($pdo, $from, $contractAddress, '-' . $amount)) { throw new \Exception('Insufficient balance'); }
                    tokenBalanceAdd($pdo, $to, $contractAddress, $amount);
                    tokenAllowanceSet($pdo, $contractAddress, $from, $caller, bcsub($allow, $amount, 18));
                    $pdo->commit();
                } catch (\Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); return ['error' => $e->getMessage()]; }
                recordDexTransactionOnChain($blockchainManager, $pdo, 'token_transfer', $from, $to, (float)$amount, ['token'=>$contractAddress,'symbol'=>$meta['symbol']??'','spender'=>$caller]);
                return ['result' => true];
            }
        }
        return ['error' => 'Unknown method: ' . $method];
    }
}

/**
 * eth_call handler for ledger-backed tokens. Decodes the 4-byte selector + args, returns ABI hex.
 * Returns a 0x-hex string, or null if `to` is not a known token (caller should fall through).
 */
if (!function_exists('ethCallToken')) {
    function ethCallToken($walletManager, string $to, string $data): ?string {
        $pdo = $walletManager->getDatabase();
        $tk = loadTokenContract($pdo, $to);
        if (!$tk) return null;
        $data = strtolower($data);
        if (str_starts_with($data, '0x')) $data = substr($data, 2);
        $selector = substr($data, 0, 8);
        $argsHex = substr($data, 8);
        $map = ERC20_SELECTORS;
        if (!isset($map[$selector])) return '0x';
        $method = $map[$selector];
        $decimals = (int)($tk['meta']['decimals'] ?? 18);

        switch ($method) {
            case 'name':        return '0x' . abiString((string)($tk['meta']['name'] ?? $tk['name']));
            case 'symbol':      return '0x' . abiString((string)($tk['meta']['symbol'] ?? ''));
            case 'decimals':    return '0x' . abiUint((string)$decimals);
            case 'totalSupply': return '0x' . abiUint(toWei((string)($tk['meta']['total_supply'] ?? '0'), $decimals));
            case 'balanceOf': {
                $owner = abiWordAddress($argsHex, 0);
                $bal = tokenBalanceGet($pdo, $owner, $to);
                return '0x' . abiUint(toWei($bal, $decimals));
            }
            case 'allowance': {
                $owner = abiWordAddress($argsHex, 0); $spender = abiWordAddress($argsHex, 1);
                $a = tokenAllowanceGet($pdo, $to, $owner, $spender);
                return '0x' . abiUint(toWei($a, $decimals));
            }
        }
        return '0x';
    }
}

/** List deployed token contracts (for UI/explorer). */
if (!function_exists('listTokenContracts')) {
    function listTokenContracts(\PDO $pdo, int $limit = 50): array {
        // Use JSON_EXTRACT (metadata is a JSON column, so LIKE on raw text is unreliable —
        // MySQL normalizes JSON with spaces after colons).
        $stmt = $pdo->query("SELECT address, name, creator, metadata, deployment_block FROM smart_contracts
                             WHERE status='active'
                               AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.type')) IN ('token','erc20')
                             ORDER BY id DESC LIMIT " . (int)$limit);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $m = json_decode((string)$r['metadata'], true) ?: [];
            $out[] = [
                'address' => $r['address'], 'name' => $r['name'], 'creator' => $r['creator'],
                'symbol' => $m['symbol'] ?? '', 'decimals' => (int)($m['decimals'] ?? 18),
                'total_supply' => $m['total_supply'] ?? '0', 'standard' => $m['standard'] ?? 'ERC20',
                'block' => (int)($r['deployment_block'] ?? 0),
            ];
        }
        return $out;
    }
}
