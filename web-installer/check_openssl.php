<?php
header('Content-Type: application/json');

try {
    $hasOpenSSL = extension_loaded('openssl');
    
    if ($hasOpenSSL) {
        $version = OPENSSL_VERSION_TEXT ?? 'Unknown version';
        echo json_encode([
            'status' => 'available',
            'message' => "OpenSSL ✓",
            'version' => $version,
            'details' => "OpenSSL extension available: $version"
        ]);
    } else {
        echo json_encode([
            'status' => 'unavailable',
            'message' => 'OpenSSL extension not found',
            'solution' => 'Install php-openssl extension'
        ]);
    }
} catch (Exception $e) {
    echo json_encode([
        'status' => 'unavailable',
        'message' => 'OpenSSL check failed: ' . $e->getMessage()
    ]);
}
?>
