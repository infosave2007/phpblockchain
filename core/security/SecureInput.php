<?php

namespace Core\Security;

use Exception;

/**
 * Secure input of passwords and sensitive data
 */
class SecureInput 
{
    /**
     * Secure password reading without screen display
     */
    public static function readPassword(string $prompt = "Password: "): string 
    {
        echo $prompt;
        
        if (PHP_OS_FAMILY === 'Windows') {
            $password = self::readPasswordWindows();
        } else {
            $password = self::readPasswordUnix();
        }
        
        echo "\n";
        return $password;
    }
    
    /**
     * Secure seed phrase reading
     */
    public static function readSeedPhrase(int $expectedWords = 12): array 
    {
        echo "Enter seed phrase ({$expectedWords} words):\n";
        echo "Press Enter after each word, empty line to finish:\n";
        
        $words = [];
        $wordCount = 1;
        
        while (count($words) < $expectedWords) {
            $word = self::readSecureWord("Word {$wordCount}: ");
            
            if (empty($word)) {
                if (count($words) >= 12) {
                    break; // Minimum 12 words
                }
                echo "Need at least 12 words. Continue...\n";
                continue;
            }
            
            if (!self::isValidBIP39Word($word)) {
                echo "⚠️  Warning: '{$word}' is not a valid BIP39 word\n";
                $confirm = self::readSecureWord("Continue anyway? (y/N): ");
                if (strtolower($confirm) !== 'y') {
                    continue;
                }
            }
            
            $words[] = trim($word);
            $wordCount++;
        }
        
        return $words;
    }
    
    /**
     * Secure private key reading
     */
    public static function readPrivateKey(string $prompt = "Private key (hex): "): string 
    {
        echo $prompt;
        
        $key = self::readPasswordUnix();
        echo "\n";
        
        // Check private key format
        if (!self::isValidPrivateKey($key)) {
            throw new Exception("Invalid private key format");
        }
        
        return $key;
    }
    
    /**
     * Translate Windows
     */
    private static function readPasswordWindows(): string 
    {
        $password = '';
        
        while (true) {
            $char = fgetc(STDIN);
            
            if ($char === "\r" || $char === "\n") {
                break;
            }
            
            if ($char === "\x08") { // Backspace
                if (strlen($password) > 0) {
                    $password = substr($password, 0, -1);
                    echo "\x08 \x08"; // Remove character from screen
                }
            } else {
                $password .= $char;
                echo '*'; // Show asterisk
            }
        }
        
        return $password;
    }
    
    /**
     * Translate Unix/Linux/macOS
     */
    private static function readPasswordUnix(): string 
    {
        // Disable echo in terminal
        $oldStty = shell_exec('stty -g');
        shell_exec('stty -echo');
        
        $password = '';
        
        try {
            $handle = fopen("php://stdin", "r");
            $password = trim(fgets($handle));
            fclose($handle);
        } finally {
            // Restore terminal settings
            shell_exec("stty $oldStty");
        }
        
        return $password;
    }
    
    /**
     * Read secure word
     */
    private static function readSecureWord(string $prompt): string 
    {
        echo $prompt;
        $word = trim(fgets(STDIN));
        
        // Clear readline history if available
        if (function_exists('readline_clear_history')) {
            readline_clear_history();
        }
        
        return $word;
    }
    
    /**
     * Validate BIP39 word
     */
    private static function isValidBIP39Word(string $word): bool 
    {
        // BIP39 word list (shortened for example)
        $bip39Words = [
            'abandon', 'ability', 'able', 'about', 'above', 'absent', 'absorb', 'abstract',
            'absurd', 'abuse', 'access', 'accident', 'account', 'accuse', 'achieve', 'acid',
            'acoustic', 'acquire', 'across', 'act', 'action', 'actor', 'actress', 'actual',
            // ... remaining 2044 words
        ];
        
        return in_array(strtolower($word), $bip39Words);
    }
    
    /**
     * Validate private key
     */
    private static function isValidPrivateKey(string $key): bool 
    {
        // Remove 0x prefix if present
        $key = str_replace('0x', '', $key);
        
        // Check that it is 64 hex characters (32 bytes)
        if (strlen($key) !== 64) {
            return false;
        }
        
        // Check that it is valid hex
        if (!ctype_xdigit($key)) {
            return false;
        }
        
        // Check that key is not zero
        if ($key === str_repeat('0', 64)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Generate secure password
     */
    public static function generateSecurePassword(int $length = 32): string 
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()';
        $password = '';
        
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }
        
        return $password;
    }
    
    /**
     * Check password strength
     */
    public static function checkPasswordStrength(string $password): array 
    {
        $score = 0;
        $feedback = [];
        
        // Length
        if (strlen($password) >= 8) {
            $score += 1;
        } else {
            $feedback[] = "Password should be at least 8 characters";
        }
        
        // Different character types
        if (preg_match('/[a-z]/', $password)) $score += 1;
        else $feedback[] = "Add lowercase letters";
        
        if (preg_match('/[A-Z]/', $password)) $score += 1;
        else $feedback[] = "Add uppercase letters";
        
        if (preg_match('/[0-9]/', $password)) $score += 1;
        else $feedback[] = "Add numbers";
        
        if (preg_match('/[^a-zA-Z0-9]/', $password)) $score += 1;
        else $feedback[] = "Add special characters";
        
        $strength = match ($score) {
            0, 1 => 'Very Weak',
            2 => 'Weak',
            3 => 'Fair',
            4 => 'Good',
            5 => 'Strong',
            default => 'Unknown'
        };
        
        return [
            'score' => $score,
            'strength' => $strength,
            'feedback' => $feedback
        ];
    }
}
