<?php
declare(strict_types=1);

namespace Blockchain\Core\Network;

/**
 * Efficient class for handling multiple HTTP requests
 */
class MultiCurl
{
    private int $maxConcurrent;
    private int $timeout;
    private int $connectTimeout;
    private array $defaultOptions;
    private array $stats;

    public function __construct(int $maxConcurrent = 50, int $timeout = 30, int $connectTimeout = 5)
    {
        $this->maxConcurrent = $maxConcurrent;
        $this->timeout = $timeout;
        $this->connectTimeout = $connectTimeout;
        $this->stats = ['requests' => 0, 'successful' => 0, 'failed' => 0];
        
        $this->defaultOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_USERAGENT => 'Blockchain-MultiCurl/2.0',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_ENCODING => 'gzip, deflate',
            CURLOPT_FRESH_CONNECT => false,
            CURLOPT_FORBID_REUSE => false
        ];
    }

    /**
     * Execute multiple HTTP requests
     */
    public function executeRequests(array $requests): array
    {
        if (empty($requests)) {
            return [];
        }

        $results = [];
        $requestChunks = array_chunk($requests, $this->maxConcurrent, true);

        foreach ($requestChunks as $chunk) {
            $chunkResults = $this->executeChunk($chunk);
            $results = array_merge($results, $chunkResults);
        }

        return $results;
    }

    /**
     * Execute chunk of requests
     */
    private function executeChunk(array $requests): array
    {
        $multiHandle = curl_multi_init();
        $curlHandles = [];
        $results = [];

        // Configure multi-curl
        curl_multi_setopt($multiHandle, CURLMOPT_MAX_TOTAL_CONNECTIONS, $this->maxConcurrent);
        curl_multi_setopt($multiHandle, CURLMOPT_PIPELINING, CURLPIPE_MULTIPLEX);

        // Create and add handlers
        foreach ($requests as $id => $request) {
            $handle = $this->createCurlHandle($request);
            $curlHandles[$id] = $handle;
            curl_multi_add_handle($multiHandle, $handle);
        }

        // Execute requests
        $running = null;
        do {
            $status = curl_multi_exec($multiHandle, $running);
            
            if ($running > 0) {
                curl_multi_select($multiHandle, 0.1);
            }
        } while ($running > 0 && $status === CURLM_OK);

        // Collect results
        foreach ($curlHandles as $id => $handle) {
            $results[$id] = $this->processResponse($handle, $requests[$id]);
            curl_multi_remove_handle($multiHandle, $handle);
            curl_close($handle);
        }

        curl_multi_close($multiHandle);

        return $results;
    }

    /**
     * Create cURL handler
     */
    private function createCurlHandle(array $request): \CurlHandle
    {
        $handle = curl_init();
        $options = $this->defaultOptions;

        // URL
        $options[CURLOPT_URL] = $request['url'];

        // HTTP method
        $method = strtoupper($request['method'] ?? 'GET');
        switch ($method) {
            case 'POST':
                $options[CURLOPT_POST] = true;
                if (isset($request['data'])) {
                    $options[CURLOPT_POSTFIELDS] = $request['data'];
                }
                break;
            case 'PUT':
                $options[CURLOPT_CUSTOMREQUEST] = 'PUT';
                if (isset($request['data'])) {
                    $options[CURLOPT_POSTFIELDS] = $request['data'];
                }
                break;
            case 'DELETE':
                $options[CURLOPT_CUSTOMREQUEST] = 'DELETE';
                break;
            case 'PATCH':
                $options[CURLOPT_CUSTOMREQUEST] = 'PATCH';
                if (isset($request['data'])) {
                    $options[CURLOPT_POSTFIELDS] = $request['data'];
                }
                break;
        }

        // Headers
        if (isset($request['headers']) && is_array($request['headers'])) {
            $options[CURLOPT_HTTPHEADER] = $request['headers'];
        }

        // Custom timeout
        if (isset($request['timeout'])) {
            $options[CURLOPT_TIMEOUT] = (int)$request['timeout'];
        }

        // Custom connection timeout
        if (isset($request['connect_timeout'])) {
            $options[CURLOPT_CONNECTTIMEOUT] = (int)$request['connect_timeout'];
        }

        // Additional options
        if (isset($request['options']) && is_array($request['options'])) {
            $options = array_merge($options, $request['options']);
        }

        curl_setopt_array($handle, $options);

        return $handle;
    }

    /**
     * Process response
     */
    private function processResponse(\CurlHandle $handle, array $request): array
    {
        $startTime = microtime(true);
        $response = curl_multi_getcontent($handle);
        $info = curl_getinfo($handle);
        $error = curl_error($handle);
        $endTime = microtime(true);

        $this->stats['requests']++;

        $result = [
            'success' => false,
            'data' => null,
            'response' => $response,
            'http_code' => $info['http_code'],
            'error' => $error,
            'info' => $info,
            'time' => round(($endTime - $startTime) * 1000, 2), // ms
            'request' => $request
        ];

        if ($error) {
            $result['error'] = $error;
            $this->stats['failed']++;
        } elseif ($info['http_code'] >= 200 && $info['http_code'] < 300) {
            $result['success'] = true;
            $this->stats['successful']++;
            
            // Attempt to parse JSON
            if ($response) {
                $decodedData = json_decode($response, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $result['data'] = $decodedData;
                }
            }
        } else {
            $result['error'] = "HTTP {$info['http_code']}";
            $this->stats['failed']++;
        }

        return $result;
    }

    /**
     * Execute single GET request
     */
    public function get(string $url, array $headers = [], array $options = []): array
    {
        $request = [
            'url' => $url,
            'method' => 'GET',
            'headers' => $headers,
            'options' => $options
        ];

        $results = $this->executeRequests(['single' => $request]);
        return $results['single'] ?? [];
    }

    /**
     * Execute single POST request
     */
    public function post(string $url, $data = null, array $headers = [], array $options = []): array
    {
        $request = [
            'url' => $url,
            'method' => 'POST',
            'data' => $data,
            'headers' => $headers,
            'options' => $options
        ];

        $results = $this->executeRequests(['single' => $request]);
        return $results['single'] ?? [];
    }

    /**
     * Execute single PUT request
     */
    public function put(string $url, $data = null, array $headers = [], array $options = []): array
    {
        $request = [
            'url' => $url,
            'method' => 'PUT',
            'data' => $data,
            'headers' => $headers,
            'options' => $options
        ];

        $results = $this->executeRequests(['single' => $request]);
        return $results['single'] ?? [];
    }

    /**
     * Execute single DELETE request
     */
    public function delete(string $url, array $headers = [], array $options = []): array
    {
        $request = [
            'url' => $url,
            'method' => 'DELETE',
            'headers' => $headers,
            'options' => $options
        ];

        $results = $this->executeRequests(['single' => $request]);
        return $results['single'] ?? [];
    }

    /**
     * Parallel execution of GET requests
     */
    public function getMultiple(array $urls, array $headers = [], array $options = []): array
    {
        $requests = [];
        foreach ($urls as $id => $url) {
            $requests[$id] = [
                'url' => $url,
                'method' => 'GET',
                'headers' => $headers,
                'options' => $options
            ];
        }

        return $this->executeRequests($requests);
    }

    /**
     * Parallel execution of POST requests
     */
    public function postMultiple(array $requests, array $headers = [], array $options = []): array
    {
        $formattedRequests = [];
        foreach ($requests as $id => $request) {
            $formattedRequests[$id] = [
                'url' => $request['url'],
                'method' => 'POST',
                'data' => $request['data'] ?? null,
                'headers' => array_merge($headers, $request['headers'] ?? []),
                'options' => array_merge($options, $request['options'] ?? [])
            ];
        }

        return $this->executeRequests($formattedRequests);
    }

    /**
     * Set maximum number of concurrent connections
     */
    public function setMaxConcurrent(int $max): void
    {
        $this->maxConcurrent = $max;
    }

    /**
     * Set timeout
     */
    public function setTimeout(int $timeout): void
    {
        $this->timeout = $timeout;
        $this->defaultOptions[CURLOPT_TIMEOUT] = $timeout;
    }

    /**
     * Set connection timeout
     */
    public function setConnectTimeout(int $timeout): void
    {
        $this->connectTimeout = $timeout;
        $this->defaultOptions[CURLOPT_CONNECTTIMEOUT] = $timeout;
    }

    /**
     * Set User-Agent
     */
    public function setUserAgent(string $userAgent): void
    {
        $this->defaultOptions[CURLOPT_USERAGENT] = $userAgent;
    }

    /**
     * Add global header
     */
    public function addGlobalHeader(string $header): void
    {
        if (!isset($this->defaultOptions[CURLOPT_HTTPHEADER])) {
            $this->defaultOptions[CURLOPT_HTTPHEADER] = [];
        }
        $this->defaultOptions[CURLOPT_HTTPHEADER][] = $header;
    }

    /**
     * Set global cURL option
     */
    public function setGlobalOption(int $option, $value): void
    {
        $this->defaultOptions[$option] = $value;
    }

    /**
     * Get statistics
     */
    public function getStats(): array
    {
        $successRate = $this->stats['requests'] > 0 
            ? round(($this->stats['successful'] / $this->stats['requests']) * 100, 2) 
            : 0;

        return [
            'total_requests' => $this->stats['requests'],
            'successful_requests' => $this->stats['successful'],
            'failed_requests' => $this->stats['failed'],
            'success_rate' => $successRate . '%',
            'max_concurrent' => $this->maxConcurrent,
            'timeout' => $this->timeout,
            'connect_timeout' => $this->connectTimeout
        ];
    }

    /**
     * Reset statistics
     */
    public function resetStats(): void
    {
        $this->stats = ['requests' => 0, 'successful' => 0, 'failed' => 0];
    }

    /**
     * Check URL availability
     */
    public function checkAvailability(array $urls): array
    {
        $requests = [];
        foreach ($urls as $id => $url) {
            $requests[$id] = [
                'url' => $url,
                'method' => 'HEAD',
                'timeout' => 5,
                'connect_timeout' => 2
            ];
        }

        $results = $this->executeRequests($requests);
        
        $availability = [];
        foreach ($results as $id => $result) {
            $availability[$id] = [
                'url' => $urls[$id],
                'available' => $result['success'] && $result['http_code'] < 400,
                'response_time' => $result['time'],
                'http_code' => $result['http_code'],
                'error' => $result['error'] ?? null
            ];
        }

        return $availability;
    }
}
