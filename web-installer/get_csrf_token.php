<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/security.php';

use Blockchain\Config\Security;

// Start session securely (cookie_secure disabled for HTTP in development)
$sessionConfig = [
    'cookie_httponly' => true,
    'cookie_samesite' => 'Strict',
    'use_strict_mode' => true
];

// Only enable secure cookies in production
if (isset($_ENV['PHP_ENV']) && $_ENV['PHP_ENV'] !== 'development') {
    $sessionConfig['cookie_secure'] = true;
}

session_start($sessionConfig);

// Enforce HTTPS and set security headers (with development awareness)
Security::enforceHTTPS();
Security::setSecureHeaders();

header('Content-Type: application/json');

try {
    $token = Security::generateCSRF();
    echo json_encode([
        'status' => 'success',
        'token' => $token
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Failed to generate CSRF token'
    ]);
}
