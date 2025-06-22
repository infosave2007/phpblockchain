<?php
declare(strict_types=1);

namespace Blockchain\Core\Security;

use Exception;

/**
 * Rate Limiting System
 */
class RateLimiter
{
    private string $storageFile;
    private array $limits;
    private array $requests;

    public function __construct(string $storageFile = null)
    {
        $this->storageFile = $storageFile ?? __DIR__ . '/../../storage/rate_limits.json';
        $this->limits = [
            'api' => ['requests' => 100, 'window' => 3600], // 100 req/hour
            'auth' => ['requests' => 5, 'window' => 900],   // 5 req/15min
            'install' => ['requests' => 3, 'window' => 3600], // 3 req/hour
            'wallet' => ['requests' => 50, 'window' => 3600], // 50 req/hour
            'transaction' => ['requests' => 20, 'window' => 300], // 20 req/5min
            'mining' => ['requests' => 10, 'window' => 60],   // 10 req/min
        ];
        $this->loadRequests();
    }

    /**
     * Check if request is allowed
     */
    public function isAllowed(string $identifier, string $type = 'api'): bool
    {
        if (!isset($this->limits[$type])) {
            return true; // No limit defined
        }

        $limit = $this->limits[$type];
        $key = $this->getKey($identifier, $type);
        $now = time();

        // Clean old requests
        $this->cleanOldRequests($key, $now, $limit['window']);

        // Check current request count
        $requestCount = count($this->requests[$key] ?? []);

        return $requestCount < $limit['requests'];
    }

    /**
     * Record a request
     */
    public function recordRequest(string $identifier, string $type = 'api'): void
    {
        if (!isset($this->limits[$type])) {
            return;
        }

        $key = $this->getKey($identifier, $type);
        $now = time();

        if (!isset($this->requests[$key])) {
            $this->requests[$key] = [];
        }

        $this->requests[$key][] = $now;
        $this->saveRequests();
    }

    /**
     * Get remaining requests for identifier
     */
    public function getRemainingRequests(string $identifier, string $type = 'api'): int
    {
        if (!isset($this->limits[$type])) {
            return PHP_INT_MAX;
        }

        $limit = $this->limits[$type];
        $key = $this->getKey($identifier, $type);
        $now = time();

        $this->cleanOldRequests($key, $now, $limit['window']);
        $requestCount = count($this->requests[$key] ?? []);

        return max(0, $limit['requests'] - $requestCount);
    }

    /**
     * Get time until reset
     */
    public function getResetTime(string $identifier, string $type = 'api'): int
    {
        if (!isset($this->limits[$type])) {
            return 0;
        }

        $limit = $this->limits[$type];
        $key = $this->getKey($identifier, $type);
        $requests = $this->requests[$key] ?? [];

        if (empty($requests)) {
            return 0;
        }

        $oldestRequest = min($requests);
        return max(0, $oldestRequest + $limit['window'] - time());
    }

    /**
     * Block IP temporarily (for suspicious activity)
     */
    public function blockIP(string $ip, int $duration = 3600): void
    {
        $blockKey = "blocked_ip_{$ip}";
        $this->requests[$blockKey] = [time() + $duration];
        $this->saveRequests();
    }

    /**
     * Check if IP is blocked
     */
    public function isBlocked(string $ip): bool
    {
        $blockKey = "blocked_ip_{$ip}";
        $blockData = $this->requests[$blockKey] ?? [];

        if (empty($blockData)) {
            return false;
        }

        $blockUntil = $blockData[0];
        if (time() >= $blockUntil) {
            unset($this->requests[$blockKey]);
            $this->saveRequests();
            return false;
        }

        return true;
    }

    /**
     * Get headers for HTTP responses
     */
    public function getHeaders(string $identifier, string $type = 'api'): array
    {
        if (!isset($this->limits[$type])) {
            return [];
        }

        $limit = $this->limits[$type];
        $remaining = $this->getRemainingRequests($identifier, $type);
        $resetTime = time() + $this->getResetTime($identifier, $type);

        return [
            'X-RateLimit-Limit' => (string)$limit['requests'],
            'X-RateLimit-Remaining' => (string)$remaining,
            'X-RateLimit-Reset' => (string)$resetTime,
            'X-RateLimit-Window' => (string)$limit['window']
        ];
    }

    /**
     * Middleware for rate limiting
     */
    public function middleware(string $type = 'api'): callable
    {
        return function($request, $response, $next) use ($type) {
            $identifier = $this->getClientIdentifier();
            
            // Check if blocked
            if ($this->isBlocked($identifier)) {
                return $this->rateLimitResponse($response, 'IP temporarily blocked', 429);
            }

            // Check rate limit
            if (!$this->isAllowed($identifier, $type)) {
                $headers = $this->getHeaders($identifier, $type);
                return $this->rateLimitResponse($response, 'Rate limit exceeded', 429, $headers);
            }

            // Record request
            $this->recordRequest($identifier, $type);

            // Add rate limit headers to response
            $headers = $this->getHeaders($identifier, $type);
            foreach ($headers as $header => $value) {
                $response = $response->withHeader($header, $value);
            }

            return $next($request, $response);
        };
    }

    /**
     * Get client identifier (IP + User Agent hash)
     */
    private function getClientIdentifier(): string
    {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        
        // Use first IP if there are multiple (proxy chain)
        if (strpos($ip, ',') !== false) {
            $ip = trim(explode(',', $ip)[0]);
        }

        return $ip . '_' . substr(md5($userAgent), 0, 8);
    }

    /**
     * Generate storage key
     */
    private function getKey(string $identifier, string $type): string
    {
        return "{$type}_{$identifier}";
    }

    /**
     * Clean old requests outside the time window
     */
    private function cleanOldRequests(string $key, int $now, int $window): void
    {
        if (!isset($this->requests[$key])) {
            return;
        }

        $cutoff = $now - $window;
        $this->requests[$key] = array_filter(
            $this->requests[$key],
            fn($timestamp) => $timestamp > $cutoff
        );

        if (empty($this->requests[$key])) {
            unset($this->requests[$key]);
        }
    }

    /**
     * Load requests from storage
     */
    private function loadRequests(): void
    {
        if (file_exists($this->storageFile)) {
            $data = file_get_contents($this->storageFile);
            $this->requests = json_decode($data, true) ?? [];
        } else {
            $this->requests = [];
        }
    }

    /**
     * Save requests to storage
     */
    private function saveRequests(): void
    {
        $dir = dirname($this->storageFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($this->storageFile, json_encode($this->requests));
    }

    /**
     * Create rate limit response
     */
    private function rateLimitResponse($response, string $message, int $code, array $headers = []): object
    {
        $body = json_encode([
            'error' => 'Rate limit exceeded',
            'message' => $message,
            'code' => $code
        ]);

        foreach ($headers as $header => $value) {
            $response = $response->withHeader($header, $value);
        }

        return $response
            ->withStatus($code)
            ->withHeader('Content-Type', 'application/json')
            ->write($body);
    }

    /**
     * Cleanup old data (should be called periodically)
     */
    public function cleanup(): void
    {
        $now = time();
        $maxWindow = max(array_column($this->limits, 'window'));

        foreach ($this->requests as $key => $timestamps) {
            if (strpos($key, 'blocked_ip_') === 0) {
                // Handle blocked IPs
                if (!empty($timestamps) && $timestamps[0] < $now) {
                    unset($this->requests[$key]);
                }
            } else {
                // Handle regular rate limits
                $this->requests[$key] = array_filter(
                    $timestamps,
                    fn($timestamp) => $timestamp > ($now - $maxWindow)
                );

                if (empty($this->requests[$key])) {
                    unset($this->requests[$key]);
                }
            }
        }

        $this->saveRequests();
    }
}
