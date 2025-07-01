<?php
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

try {
    // Get current domain information
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
    $port = $_SERVER['SERVER_PORT'] ?? '80';
    
    // Clean up domain - remove standard ports
    $domain = $host;
    if (($protocol === 'http' && $port !== '80') || 
        ($protocol === 'https' && $port !== '443')) {
        // Only add port if it's not standard
        if (!str_contains($domain, ':')) {
            $domain .= ':' . $port;
        }
    }
    
    // Remove port if it's standard
    if (($protocol === 'http' && str_ends_with($domain, ':80')) ||
        ($protocol === 'https' && str_ends_with($domain, ':443'))) {
        $domain = preg_replace('/:(80|443)$/', '', $domain);
    }
    
    $fullUrl = $protocol . '://' . $domain;
    
    // Determine if this is a local development environment
    $isLocal = in_array($host, ['localhost', '127.0.0.1', '::1']) || 
               str_starts_with($host, '192.168.') || 
               str_starts_with($host, '10.') || 
               str_contains($host, '.local');
    
    echo json_encode([
        'status' => 'success',
        'data' => [
            'protocol' => $protocol,
            'domain' => $domain,
            'full_url' => $fullUrl,
            'is_local' => $isLocal,
            'detected_at' => date('Y-m-d H:i:s'),
            'server_info' => [
                'host' => $host,
                'port' => $port,
                'https' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
            ]
        ]
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Failed to detect domain information: ' . $e->getMessage()
    ]);
}
?>
