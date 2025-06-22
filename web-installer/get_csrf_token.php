<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/security.php';

use Blockchain\Config\Security;

// Start session securely
session_start([
    'cookie_secure' => true,
    'cookie_httponly' => true,
    'cookie_samesite' => 'Strict',
    'use_strict_mode' => true
]);

// Enforce HTTPS and set security headers
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
