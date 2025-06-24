<?php
header('Content-Type: application/json');

try {
    $checks = [];
    $allGood = true;
    
    // Check directories that need to be writable
    $directories = [
        '../storage' => 'Storage directory',
        '../logs' => 'Logs directory', 
        '../config' => 'Config directory',
        '..' => 'Root directory'
    ];
    
    foreach ($directories as $dir => $description) {
        if (!is_dir($dir)) {
            $created = @mkdir($dir, 0755, true);
            $writable = $created ? is_writable($dir) : false;
        } else {
            $writable = is_writable($dir);
        }
        
        $checks[$description] = $writable;
        if (!$writable) $allGood = false;
    }
    
    echo json_encode([
        'status' => $allGood ? 'available' : 'partial',
        'message' => $allGood ? 'Write access ✓' : 'Limited access',
        'details' => $allGood ? 'All directories are writable' : 'Some permission issues found',
        'checks' => $checks,
        'solution' => $allGood ? null : 'Run: chmod 755 /path/to/blockchain && chmod 777 /path/to/blockchain/storage'
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'status' => 'unavailable',
        'message' => 'Permissions check failed: ' . $e->getMessage()
    ]);
}
?>
