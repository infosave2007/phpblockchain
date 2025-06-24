<?php
header('Content-Type: application/json');

try {
    // Check if MySQL functions are available
    $hasMysqli = extension_loaded('mysqli');
    $hasPdo = extension_loaded('pdo') && extension_loaded('pdo_mysql');
    
    if ($hasMysqli || $hasPdo) {
        echo json_encode([
            'status' => 'available',
            'message' => 'MySQL ✓',
            'details' => 'MySQL support available via ' . ($hasMysqli ? 'MySQLi' : '') . ($hasMysqli && $hasPdo ? ' and ' : '') . ($hasPdo ? 'PDO' : ''),
            'extensions' => [
                'mysqli' => $hasMysqli,
                'pdo_mysql' => $hasPdo
            ]
        ]);
    } else {
        echo json_encode([
            'status' => 'optional',
            'message' => 'File storage',
            'details' => 'MySQL not available, file-based storage will be used',
            'note' => 'The blockchain can work without MySQL'
        ]);
    }
} catch (Exception $e) {
    echo json_encode([
        'status' => 'optional',
        'message' => 'File storage',
        'details' => 'MySQL check failed, file-based storage will be used'
    ]);
}
?>
