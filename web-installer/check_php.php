<?php
header('Content-Type: application/json');

try {
    $phpVersion = PHP_VERSION;
    $required = '8.1.0';
    $isValid = version_compare($phpVersion, $required, '>=');
    
    echo json_encode([
        'status' => $isValid ? 'available' : 'unavailable',
        'version' => $phpVersion,
        'required' => $required,
        'message' => $isValid ? "PHP $phpVersion ✓" : "Need PHP $required+",
        'details' => $isValid ? "PHP version $phpVersion is compatible" : "Current PHP $phpVersion, need $required or higher"
    ]);
} catch (Exception $e) {
    echo json_encode([
        'status' => 'unavailable',
        'message' => 'PHP check failed: ' . $e->getMessage()
    ]);
}
?>
