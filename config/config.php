<?php

// Auto-generated configuration file
// Generated on: 2025-06-30 19:03:47

return array (
  'app' => 
  array (
    'name' => 'Blockchain Platform',
    'version' => '1.0.0',
    'debug' => false,
    'timezone' => 'UTC',
    'installed' => true,
    'key' => '96ea04cc4ae951d00de5d656a2a5921e30c7e5ee083d6419974a409bbe4c5681',
    'installation_date' => '2025-06-30 19:03:47',
  ),
  'debug_mode' => true, // Set to true for development, false for production
  'database' => 
  array (
      ),
  'blockchain' => 
  array (
    'genesis_created' => true,
    'last_block_check' => 1751299427,
    'binary_storage' => 
    array (
      'enabled' => true,
      'data_dir' => 'storage/blockchain',
      'encryption_enabled' => false,
      'encryption_key' => '',
      'backup_enabled' => true,
      'backup_interval' => 86400,
      'max_backups' => 10,
    ),
    'sync' => 
    array (
      'db_to_binary' => true,
      'binary_to_db' => true,
      'auto_sync' => true,
      'sync_interval' => 3600,
    ),
  ),
  'network' => 
  array (
  ),
  'security' => 
  array (
    'jwt_secret' => '5a4d3a34e19137a100922df25424ab38685c847dffef63742ae5109311eead5d',
    'session_lifetime' => 86400,
    'rate_limit' => 
    array (
      'enabled' => true,
      'max_requests' => 100,
      'time_window' => 3600,
    ),
  ),
  'storage_path' => __DIR__ . '/../storage',
  'logging' => 
  array (
    'level' => 'info',
    'file' => '../logs/app.log',
    'max_size' => '10MB',
    'max_files' => 5,
  ),
);
