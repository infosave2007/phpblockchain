<?php
declare(strict_types=1);

namespace Blockchain\Config;

/**
 * Security Configuration
 */
class Security
{
    /**
     * Force HTTPS redirect
     */
    public static function enforceHTTPS(): void
    {
        if (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] !== 'on') {
            if (!headers_sent()) {
                $redirectURL = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
                header("Location: $redirectURL", true, 301);
                exit();
            }
        }
    }

    /**
     * Set secure headers
     */
    public static function setSecureHeaders(): void
    {
        if (!headers_sent()) {
            // HTTPS Strict Transport Security
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
            
            // Content Security Policy
            header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; font-src 'self' https://cdnjs.cloudflare.com; img-src 'self' data: https:; connect-src 'self'");
            
            // X-Frame-Options
            header('X-Frame-Options: DENY');
            
            // X-Content-Type-Options
            header('X-Content-Type-Options: nosniff');
            
            // X-XSS-Protection
            header('X-XSS-Protection: 1; mode=block');
            
            // Referrer Policy
            header('Referrer-Policy: strict-origin-when-cross-origin');
            
            // Remove server signature
            header_remove('X-Powered-By');
            header_remove('Server');
        }
    }

    /**
     * Validate CSRF token
     */
    public static function validateCSRF(string $token): bool
    {
        if (!isset($_SESSION['csrf_token'])) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * Generate CSRF token
     */
    public static function generateCSRF(): string
    {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}
