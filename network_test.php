#!/usr/bin/env php
<?php
/**
 * Network Test Script
 * Test blockchain network connectivity and API endpoints
 */

function testNetworkApi() {
    echo "🌐 Testing Blockchain Network API\n";
    echo "=================================\n\n";
    
    $baseUrl = 'https://wallet.coursefactory.pro/api/explorer/';
    $endpoints = [
        'get_network_stats' => 'Network Statistics',
        'get_blocks?limit=2' => 'Recent Blocks',
        'get_transactions?limit=2' => 'Recent Transactions',
        'get_nodes_list' => 'Nodes List',
        'get_validators_list' => 'Validators List',
        'get_smart_contracts?limit=2' => 'Smart Contracts',
        'get_staking_records?limit=2' => 'Staking Records'
    ];
    
    $results = [];
    
    foreach ($endpoints as $endpoint => $description) {
        echo "🔍 Testing: $description\n";
        
        $url = $baseUrl . '?action=' . $endpoint;
        $startTime = microtime(true);
        
        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'method' => 'GET'
            ]
        ]);
        
        $response = @file_get_contents($url, false, $context);
        $responseTime = (microtime(true) - $startTime) * 1000;
        
        if ($response === false) {
            echo "   ❌ Failed to connect\n";
            $results[$endpoint] = ['success' => false, 'error' => 'Connection failed'];
            continue;
        }
        
        $data = json_decode($response, true);
        if (!$data) {
            echo "   ❌ Invalid JSON response\n";
            $results[$endpoint] = ['success' => false, 'error' => 'Invalid JSON'];
            continue;
        }
        
        if (!isset($data['success']) || !$data['success']) {
            echo "   ⚠️  API returned error: " . ($data['message'] ?? 'Unknown error') . "\n";
            $results[$endpoint] = ['success' => false, 'error' => $data['message'] ?? 'API error'];
            continue;
        }
        
        $recordCount = 0;
        if (isset($data['data'])) {
            if (is_array($data['data'])) {
                if (isset($data['data']['blocks'])) {
                    $recordCount = count($data['data']['blocks']);
                } elseif (isset($data['data']['transactions'])) {
                    $recordCount = count($data['data']['transactions']);
                } elseif (isset($data['data']['contracts'])) {
                    $recordCount = count($data['data']['contracts']);
                } elseif (isset($data['data']['staking'])) {
                    $recordCount = count($data['data']['staking']);
                } else {
                    $recordCount = count($data['data']);
                }
            }
        }
        
        echo "   ✅ Success - " . round($responseTime) . "ms";
        if ($recordCount > 0) {
            echo " ({$recordCount} records)";
        }
        echo "\n";
        
        $results[$endpoint] = [
            'success' => true,
            'response_time' => $responseTime,
            'records' => $recordCount,
            'data_sample' => array_slice($data, 0, 2)
        ];
        
        usleep(100000); // 100ms delay between requests
    }
    
    // Summary
    echo "\n📊 Test Summary\n";
    echo "===============\n";
    
    $successful = array_filter($results, function($r) { return $r['success']; });
    $failed = array_filter($results, function($r) { return !$r['success']; });
    
    echo sprintf("✅ Successful: %d/%d endpoints\n", count($successful), count($results));
    echo sprintf("❌ Failed: %d/%d endpoints\n", count($failed), count($results));
    
    if (!empty($successful)) {
        $avgResponseTime = array_sum(array_column($successful, 'response_time')) / count($successful);
        echo sprintf("⚡ Average response time: %.0fms\n", $avgResponseTime);
    }
    
    if (!empty($failed)) {
        echo "\n❌ Failed endpoints:\n";
        foreach ($failed as $endpoint => $result) {
            echo "   - $endpoint: {$result['error']}\n";
        }
    }
    
    return count($failed) === 0;
}

function testSpecificEndpoint($action) {
    $url = "https://wallet.coursefactory.pro/api/explorer/?action=$action";
    
    echo "🔍 Testing endpoint: $action\n";
    echo "URL: $url\n\n";
    
    $response = @file_get_contents($url);
    if ($response === false) {
        echo "❌ Connection failed\n";
        return;
    }
    
    $data = json_decode($response, true);
    if (!$data) {
        echo "❌ Invalid JSON response\n";
        echo "Raw response: " . substr($response, 0, 200) . "...\n";
        return;
    }
    
    echo "✅ Response received\n";
    echo "JSON Structure:\n";
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}

function showUsage() {
    echo "Network API Test Tool\n";
    echo "=====================\n\n";
    echo "Usage: php network_test.php [command] [options]\n\n";
    echo "Commands:\n";
    echo "  test      - Test all API endpoints (default)\n";
    echo "  endpoint  - Test specific endpoint\n";
    echo "  help      - Show this help\n\n";
    echo "Examples:\n";
    echo "  php network_test.php test\n";
    echo "  php network_test.php endpoint get_blocks\n";
    echo "  php network_test.php endpoint get_transactions\n\n";
}

// Main execution
if (php_sapi_name() !== 'cli') {
    echo "This script must be run from command line\n";
    exit(1);
}

$command = $argv[1] ?? 'test';
$options = array_slice($argv, 2);

switch ($command) {
    case 'test':
        $success = testNetworkApi();
        exit($success ? 0 : 1);
        
    case 'endpoint':
        $action = $options[0] ?? '';
        if (empty($action)) {
            echo "Error: Please specify an endpoint action\n";
            echo "Example: php network_test.php endpoint get_blocks\n";
            exit(1);
        }
        testSpecificEndpoint($action);
        break;
        
    case 'help':
    case '--help':
    case '-h':
    default:
        showUsage();
        break;
}
?>
