<?php
header('Content-Type: application/json');

try {
    // Node.js is optional for this blockchain platform
    $hasNode = false;
    $nodeVersion = null;
    
    // Try to check if node is available (but it's not required)
    if (function_exists('shell_exec')) {
        $output = @shell_exec('node --version 2>/dev/null');
        if ($output && preg_match('/v(\d+\.\d+\.\d+)/', trim($output), $matches)) {
            $nodeVersion = $matches[1];
            $hasNode = version_compare($nodeVersion, '16.0.0', '>=');
        }
    }
    
    echo json_encode([
        'status' => 'optional',
        'message' => $hasNode ? "Node.js ✓" : 'Node.js optional',
        'version' => $nodeVersion,
        'details' => $hasNode ? "Node.js v$nodeVersion available" : 'Node.js not found (optional feature)',
        'note' => 'Node.js is optional - blockchain works with PHP only'
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'status' => 'optional',
        'message' => 'Node.js not available (Optional - not required)'
    ]);
}
?>
