<?php
/**
 * Standalone initialization script without database dependency
 */

echo "🔗 Blockchain Standalone Initialization\n";
echo "=====================================\n\n";

// Create necessary directories
$dirs = [
    'storage',
    'storage/blockchain', 
    'storage/state',
    'storage/test',
    'storage/trustwallet',
    'logs',
    'config'
];

foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
        echo "✅ Created directory: $dir\n";
    } else {
        echo "✓ Directory exists: $dir\n";
    }
}

// Create blockchain files with proper headers
$genesisBlock = json_encode([
    'genesis' => true,
    'timestamp' => time(),
    'hash' => hash('sha256', 'genesis_block'),
    'previous_hash' => '0',
    'transactions' => [],
    'nonce' => 0
], JSON_PRETTY_PRINT);

// Create blockchain.bin with proper binary header (MAGIC_BYTES + VERSION + TIMESTAMP + BLOCK_COUNT)
$binaryHeader = pack('A4NNN', 'BLKC', 1, time(), 0);

$files = [
    'storage/blockchain.bin' => $binaryHeader,
    'storage/blockchain.db' => '',
    'storage/blockchain.idx' => '', // Index file
    'storage/state/genesis.json' => $genesisBlock
];

foreach ($files as $file => $content) {
    if (!file_exists($file)) {
        if (file_put_contents($file, $content) !== false) {
            echo "✅ Created file: $file\n";
        } else {
            echo "❌ Failed to create: $file\n";
        }
    } else {
        echo "✓ File exists: $file\n";
    }
}

// Create .env if missing
if (!file_exists('config/.env') && !file_exists('.env')) {
    $envContent = <<<ENV
# Database Configuration
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=blockchain
DB_USERNAME=root
DB_PASSWORD=

# Application Settings
APP_DEBUG=true
APP_ENV=development
WALLET_LOGGING_ENABLED=true

# Network Settings
NETWORK_PORT=8545
NETWORK_HOST=0.0.0.0
ENV;
    
    file_put_contents('config/.env', $envContent);
    echo "✅ Created config/.env\n";
}

// Set permissions
$chmodFiles = [
    'storage',
    'storage/blockchain.bin',
    'storage/blockchain.db',
    'storage/blockchain.idx', 
    'storage/state/genesis.json',
    'logs'
];

foreach ($chmodFiles as $file) {
    if (file_exists($file)) {
        if (is_dir($file)) {
            chmod($file, 0755);
            echo "✅ Set permissions 755 for directory: $file\n";
        } else {
            chmod($file, 0666);
            echo "✅ Set permissions 666 for file: $file\n";
        }
    }
}

echo "\n🎉 Standalone initialization completed!\n";
echo "📝 Next steps:\n";
echo "   1. Configure database in config/.env\n";
echo "   2. Import database schema\n";
echo "   3. Run: php cli.php blockchain init (after DB setup)\n";
echo "\n✨ Basic blockchain files are ready!\n";
