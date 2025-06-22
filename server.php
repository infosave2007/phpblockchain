#!/usr/bin/env php
<?php
/**
 * Blockchain Development Server
 * 
 * Starts a local development server for blockchain platform
 */

// Get configuration
$config = [];
if (file_exists(__DIR__ . '/config/config.php')) {
    $config = require __DIR__ . '/config/config.php';
}

$host = '127.0.0.1';
$port = 8080;

// Parse command line arguments
$args = getopt('h:p:', ['host:', 'port:']);

if (isset($args['h'])) {
    $host = $args['h'];
} elseif (isset($args['host'])) {
    $host = $args['host'];
}

if (isset($args['p'])) {
    $port = (int)$args['p'];
} elseif (isset($args['port'])) {
    $port = (int)$args['port'];
}

$networkName = $config['blockchain']['network_name'] ?? 'Modern Blockchain Platform';

echo "Starting $networkName development server...\n";
echo "Host: $host\n";
echo "Port: $port\n";
echo "Document root: " . __DIR__ . "\n";
echo "URL: http://$host:$port\n";
echo "Press Ctrl+C to stop\n\n";

// Start the server
$command = "php -S $host:$port -t " . __DIR__;
system($command);
