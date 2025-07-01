#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * System Check Tool for Modern Blockchain Platform
 * 
 * Verifies that the system is properly configured and ready to run
 */

echo "Modern Blockchain Platform - System Check\n";
echo "=========================================\n\n";

$checks = [];
$errors = [];
$warnings = [];

// PHP Version Check
$phpVersion = PHP_VERSION;
if (version_compare($phpVersion, '8.0.0', '>=')) {
    $checks[] = "✓ PHP Version: $phpVersion (OK)";
} else {
    $errors[] = "✗ PHP Version: $phpVersion (Requires 8.0+)";
}

// Required Extensions
$requiredExtensions = ['pdo', 'pdo_mysql', 'mysqli', 'openssl', 'curl', 'json', 'mbstring', 'hash'];
foreach ($requiredExtensions as $ext) {
    if (extension_loaded($ext)) {
        $checks[] = "✓ Extension: $ext";
    } else {
        $errors[] = "✗ Extension: $ext (Missing)";
    }
}

// File Permissions
$writableDirs = ['storage', 'logs'];
foreach ($writableDirs as $dir) {
    if (is_dir($dir) && is_writable($dir)) {
        $checks[] = "✓ Directory: $dir (Writable)";
    } else {
        $errors[] = "✗ Directory: $dir (Not writable or missing)";
    }
}

// Configuration Files
$configFiles = [
    'composer.json' => 'Composer configuration',
    'package.json' => 'NPM configuration',
    '.env.example' => 'Environment template',
    'config/config.php' => 'Main configuration',
    'web-installer/index.html' => 'Web installer'
];

foreach ($configFiles as $file => $desc) {
    if (file_exists($file)) {
        $checks[] = "✓ File: $file ($desc)";
    } else {
        $errors[] = "✗ File: $file ($desc) - Missing";
    }
}

// Composer Dependencies
if (file_exists('vendor/autoload.php')) {
    $checks[] = "✓ Composer: Dependencies installed";
} else {
    $warnings[] = "⚠ Composer: Dependencies not installed (run: composer install)";
}

// Configuration Check
if (file_exists('config/.env') || file_exists('.env')) {
    $checks[] = "✓ Environment: .env file exists";
} else {
    $warnings[] = "⚠ Environment: No .env file (copy from .env.example to config/.env)";
}

// Database Configuration Check
$envPath = file_exists('config/.env') ? 'config/.env' : '.env';
if (file_exists($envPath)) {
    $envContent = file_get_contents($envPath);
    if (strpos($envContent, 'DB_HOST=') !== false) {
        $checks[] = "✓ Database: Configuration present";
    } else {
        $warnings[] = "⚠ Database: Configuration incomplete";
    }
}

// Display Results
echo "SYSTEM CHECKS:\n";
foreach ($checks as $check) {
    echo "$check\n";
}

if (!empty($warnings)) {
    echo "\nWARNINGS:\n";
    foreach ($warnings as $warning) {
        echo "$warning\n";
    }
}

if (!empty($errors)) {
    echo "\nERRORS:\n";
    foreach ($errors as $error) {
        echo "$error\n";
    }
    echo "\n❌ System not ready. Please fix the errors above.\n";
    exit(1);
} elseif (!empty($warnings)) {
    echo "\n⚠️  System mostly ready, but check warnings above.\n";
    exit(0);
} else {
    echo "\n✅ System ready! You can now:\n";
    echo "   1. Run web installer: Visit /web-installer/ in browser\n";
    echo "   2. Or configure manually: Copy .env.example to config/.env\n";
    echo "   3. Initialize: php cli.php blockchain init\n";
    echo "   4. Start node: php cli.php node start\n";
    exit(0);
}
