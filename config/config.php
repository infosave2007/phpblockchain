<?php

// Auto-generated configuration file
// Generated on: 2025-08-21 10:27:22

return array (
  'app' => 
  array (
    'name' => 'Blockchain Platform',
    'version' => '1.0.0',
    'debug' => false,
    'timezone' => 'UTC',
    'installed' => true,
    'key' => '23ad8834a4579dfe1bf6863af8eac0607d8806a4800c4e92ec45f067080be6bf',
    'installation_date' => '2025-08-21 10:27:22',
  ),
  'database' => 
  array (
  ),
  'blockchain' => 
  array (
    'genesis_created' => true,
    'last_block_check' => 1755772042,
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
    'jwt_secret' => 'd372c2d69f84a8c06373970b5c634519d51afffade36e811f6d7c4c50579a7a7',
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
