<?php
/**
 * Compatibility Functions
 * Provides backward compatibility and polyfills for missing functions
 */

// Hash function compatibility
if (!function_exists('hash_pbkdf2')) {
    function hash_pbkdf2($algo, $password, $salt, $iterations, $length = 0, $raw_output = false) {
        // Basic PBKDF2 implementation
        $hash_length = strlen(hash($algo, '', true));
        $block_count = ceil($length / $hash_length);
        
        $output = '';
        for ($i = 1; $i <= $block_count; $i++) {
            $last = $salt . pack('N', $i);
            $last = $xorsum = hash_hmac($algo, $last, $password, true);
            for ($j = 1; $j < $iterations; $j++) {
                $xorsum ^= ($last = hash_hmac($algo, $last, $password, true));
            }
            $output .= $xorsum;
        }
        
        if ($length) {
            $output = substr($output, 0, $length);
        }
        
        return $raw_output ? $output : bin2hex($output);
    }
}

// Keccak256 compatibility (for Ethereum)
if (!function_exists('keccak256')) {
    function keccak256($data) {
        // Simple implementation - in production use proper keccak256
        return hash('sha3-256', $data);
    }
}

// Secp256k1 compatibility check
if (!function_exists('secp256k1_context_create')) {
    function secp256k1_context_create($flags) {
        return null; // Fallback when extension not available
    }
}

// JSON compatibility
if (!function_exists('json_validate')) {
    function json_validate($json) {
        json_decode($json);
        return json_last_error() === JSON_ERROR_NONE;
    }
}

// String functions
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        return strpos($haystack, $needle) === 0;
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle) {
        return substr($haystack, -strlen($needle)) === $needle;
    }
}

if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle) {
        return strpos($haystack, $needle) !== false;
    }
}

// Array functions
if (!function_exists('array_is_list')) {
    function array_is_list($array) {
        return array_keys($array) === range(0, count($array) - 1);
    }
}

// Random functions
if (!function_exists('random_int')) {
    function random_int($min, $max) {
        return mt_rand($min, $max);
    }
}

if (!function_exists('random_bytes')) {
    function random_bytes($length) {
        $bytes = '';
        for ($i = 0; $i < $length; $i++) {
            $bytes .= chr(mt_rand(0, 255));
        }
        return $bytes;
    }
}

// Define constants
if (!defined('OPENSSL_RAW_DATA')) {
    define('OPENSSL_RAW_DATA', 1);
}

if (!defined('OPENSSL_ZERO_PADDING')) {
    define('OPENSSL_ZERO_PADDING', 2);
}
