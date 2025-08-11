<?php

// Auto-generated configuration file
// Generated on: 2025-08-11 12:16:49

return array (
  'app' => 
  array (
    'name' => 'Blockchain Platform',
    'version' => '1.0.0',
    'debug' => false,
    'timezone' => 'UTC',
    'installed' => true,
    'key' => '79e550704f20eb1cf4b7ba16bed62f87fc62e415a3467d5903b19e138f73b606',
    'installation_date' => '2025-08-11 12:16:49',
  ),
  'database' => 
  array (
  ),
  'blockchain' => 
  array (
    'genesis_created' => true,
    'last_block_check' => 1754914609,
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
    'jwt_secret' => '0ecd8143193a5ac4599013699c7a2b7a1cefbde742a2fb0f781b10fcc0523d67',
    'session_lifetime' => 86400,
    'rate_limit' => 
    array (
      'enabled' => true,
      'max_requests' => 100,
      'time_window' => 3600,
    ),
  ),
  'logging' => 
  array (
    'level' => 'info',
    'file' => '../logs/app.log',
    'max_size' => '10MB',
    'max_files' => 5,
  ),
);
