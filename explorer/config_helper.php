<?php
/**
 * Generic configuration + DB helper for explorer.
 * Avoids hardcoding project/network specific values.
 */

use PDO; // Explicit import for clarity

// Lazy load environment (if available) once
if (!class_exists('Blockchain\\Core\\Environment\\EnvironmentLoader')) {
    $root = dirname(__DIR__, 1); // project root
    $envLoader = $root . '/core/Environment/EnvironmentLoader.php';
    if (file_exists($envLoader)) {
        require_once $envLoader;
        \Blockchain\Core\Environment\EnvironmentLoader::load($root);
    }
}

function explorerDbConfig(): array {
    // Try config/config.php if present
    $baseDir = dirname(__DIR__, 1);
    $cfg = [];
    $cfgFile = $baseDir . '/config/config.php';
    if (file_exists($cfgFile)) {
        try { $loaded = include $cfgFile; if (is_array($loaded) && isset($loaded['database'])) { $cfg = $loaded['database']; } } catch (\Throwable $e) { /* ignore */ }
    }

    // Environment overrides / fallback
    $env = function($k, $d=null){
        if (class_exists('Blockchain\\Core\\Environment\\EnvironmentLoader')) {
            return \Blockchain\Core\Environment\EnvironmentLoader::get($k, $d);
        }
        return $_ENV[$k] ?? getenv($k) ?: $d;
    };

    $cfg = array_filter($cfg, fn($v)=>$v!==null && $v!=='');
    $cfg += [
        'host' => $env('DB_HOST', 'database'),
        'port' => (int)$env('DB_PORT', 3306),
        'database' => $env('DB_DATABASE', $env('DB_NAME', 'blockchain')),
        'username' => $env('DB_USERNAME', $env('DB_USER', 'blockchain')),
        'password' => $env('DB_PASSWORD', $env('DB_PASS', 'blockchain123')),
        'charset' => 'utf8mb4'
    ];
    return $cfg;
}

function getDbConnection(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) { return $pdo; }

    $cfg = explorerDbConfig();
    // Build DSN once (host + optional port)
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $cfg['host'], $cfg['port'], $cfg['database'], $cfg['charset']);
    try {
        $pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    } catch (\Throwable $e) {
        die('Explorer DB connect failed: ' . htmlspecialchars($e->getMessage()));
    }
    return $pdo;
}

function getNetworkConfig(): array {
    static $cache = null;
    if ($cache !== null) { return $cache; }

    $pdo = getDbConnection();
    $cache = [];
    try {
        $stmt = $pdo->query("SELECT key_name, value FROM config WHERE key_name LIKE 'network.%'");
        while ($row = $stmt->fetch()) {
            $k = substr($row['key_name'], strlen('network.'));
            $cache[$k] = $row['value'];
        }
    } catch (\Throwable $e) {
        // If config table missing just proceed with defaults
    }

    // Neutral generic defaults (not project specific)
    $cache += [
        'name' => 'Blockchain',
        'token_symbol' => 'COIN',
        'token_name' => 'Native Coin',
        'decimals' => '18',
        'chain_id' => '0'
    ];
    return $cache;
}

function getConfigValue(string $key, $default = null) {
    $pdo = getDbConnection();
    try {
        $stmt = $pdo->prepare('SELECT value FROM config WHERE key_name = ? LIMIT 1');
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return $val === false ? $default : $val;
    } catch (\Throwable $e) {
        return $default;
    }
}
?>
