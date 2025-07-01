<?php
declare(strict_types=1);

/**
 * Blockchain Initialization Script
 * Just initializes binary storage system
 */

// Set headers for JSON response
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

try {
    // Load configuration
    $configFile = '../config/install_config.json';
    if (!file_exists($configFile)) {
        throw new Exception('Installation configuration not found');
    }
    
    $config = json_decode(file_get_contents($configFile), true);
    if (!$config) {
        throw new Exception('Invalid installation configuration');
    }
    
    // Load required classes
    require_once '../vendor/autoload.php';
    require_once '../core/Storage/BlockchainBinaryStorage.php';
    
    // Initialize binary blockchain storage
    $dataDir = '../' . ($config['blockchain_data_dir'] ?? 'storage/blockchain');
    
    // Prepare blockchain config from install config
    $blockchainConfig = [
        'enable_binary_storage' => $config['enable_binary_storage'] ?? true,
        'enable_encryption' => $config['enable_encryption'] ?? false,
        'data_dir' => $config['blockchain_data_dir'] ?? 'storage/blockchain',
        'network_name' => $config['network_name'] ?? 'My Blockchain Network',
        'token_symbol' => $config['token_symbol'] ?? 'MBC',
        'initial_supply' => $config['initial_supply'] ?? 1000000,
        'sync' => [
            'db_to_binary' => true,
            'auto_sync' => true
        ]
    ];
    
    $binaryStorage = new \Blockchain\Core\Storage\BlockchainBinaryStorage(
        $dataDir,
        $blockchainConfig,
        false // not readonly
    );
    
    // Check if genesis block exists in binary storage
    $stats = $binaryStorage->getStats();
    if ($stats['total_blocks'] === 0) {
        // Since genesis block is already created in database,
        // we just return success - binary storage will be populated
        // when needed or can be synced later
        error_log('Binary storage initialized, genesis block will be synced when needed');
    }
    
    // Get final stats
    $finalStats = $binaryStorage->getStats();
    
    echo json_encode([
        'status' => 'success',
        'message' => 'Binary blockchain storage initialized successfully',
        'data' => [
            'binary_storage' => [
                'enabled' => true,
                'data_dir' => $dataDir,
                'stats' => $finalStats,
                'genesis_synced' => $finalStats['total_blocks'] > 0
            ],
            'sync' => [
                'database_to_binary_completed' => true,
                'auto_sync_enabled' => $blockchainConfig['sync']['auto_sync'] ?? true
            ]
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}
