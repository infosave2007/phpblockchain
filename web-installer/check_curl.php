<?php
header('Content-Type: application/json');

try {
    $hasCurl = extension_loaded('curl');
    
    if ($hasCurl) {
        $version = curl_version();
        echo json_encode([
            'status' => 'available',
            'message' => "cURL ✓",
            'version' => $version['version'],
            'details' => "cURL extension available (v{$version['version']})"
        ]);
    } else {
        echo json_encode([
            'status' => 'unavailable',
            'message' => 'cURL extension not found',
            'solution' => 'Install php-curl extension'
        ]);
    }
} catch (Exception $e) {
    echo json_encode([
        'status' => 'unavailable',
        'message' => 'cURL check failed: ' . $e->getMessage()
    ]);
}
?>
