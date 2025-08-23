<?php
/**
 * Enhanced Heartbeat Service
 * Maintains network awareness and triggers synchronization events
 * Improved with adaptive intervals, connection pooling, and better performance monitoring
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../core/Database/DatabaseManager.php';

use Blockchain\Core\Database\DatabaseManager;

// Enhanced configuration with adaptive features
$HEARTBEAT_INTERVAL = 30; // seconds - base interval
$SYNC_CHECK_INTERVAL = 60; // seconds
$NODE_TIMEOUT = 120; // seconds before marking node as inactive
$ADAPTIVE_INTERVALS = true; // Enable adaptive intervals based on network health
$CONNECTION_POOL_SIZE = 5; // Maximum concurrent connections
$PERFORMANCE_MONITORING = true; // Enable performance tracking

function writeHeartbeatLog(string $message, string $level = 'INFO'): void
{
    $logFile = __DIR__ . '/../logs/heartbeat.log';
    $timestamp = date('Y-m-d H:i:s');
    $logLine = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;
    @file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
}

/**
 * Get adaptive heartbeat interval based on network health
 */
function getAdaptiveHeartbeatInterval(PDO $pdo, int $baseInterval): int
{
    global $ADAPTIVE_INTERVALS;
    
    if (!$ADAPTIVE_INTERVALS) {
        return $baseInterval;
    }
    
    try {
        // Check recent network activity
        $stmt = $pdo->query("
            SELECT COUNT(*) as active_nodes,
                   AVG(CASE WHEN metric_type = 'response_time' THEN metric_value END) as avg_response_time,
                   COUNT(CASE WHEN metric_type = 'events_sent_failed' THEN 1 END) as failures
            FROM broadcast_stats 
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        ");
        
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        $activeNodes = (int)($stats['active_nodes'] ?? 0);
        $avgResponseTime = (float)($stats['avg_response_time'] ?? 1.0);
        $failures = (int)($stats['failures'] ?? 0);
        
        // Adjust interval based on network conditions
        $multiplier = 1.0;
        
        if ($activeNodes < 3) {
            $multiplier = 0.5; // More frequent heartbeats with few nodes
        } elseif ($avgResponseTime > 3.0) {
            $multiplier = 1.5; // Less frequent with slow network
        } elseif ($failures > 10) {
            $multiplier = 0.7; // More frequent with network issues
        }
        
        $adaptiveInterval = (int)($baseInterval * $multiplier);
        return max(15, min(120, $adaptiveInterval)); // Clamp between 15s-2m
        
    } catch (Exception $e) {
        writeHeartbeatLog("Adaptive interval calculation failed: " . $e->getMessage(), 'ERROR');
        return $baseInterval;
    }
}

function getCurrentNodeInfo(PDO $pdo): array
{
    try {
        $height = 0;
        $stmt = $pdo->query("SELECT MAX(height) FROM blocks");
        if ($stmt) {
            $height = (int)$stmt->fetchColumn();
        }
        
        $mempoolSize = 0;
        $stmt = $pdo->query("SELECT COUNT(*) FROM mempool WHERE status = 'pending'");
        if ($stmt) {
            $mempoolSize = (int)$stmt->fetchColumn();
        }
        
        $nodeId = gethostname() . '_' . substr(md5(gethostname()), 0, 8);
        
        return [
            'node_id' => $nodeId,
            'block_height' => $height,
            'mempool_size' => $mempoolSize,
            'timestamp' => time(),
            'uptime' => getUptime()
        ];
    } catch (Exception $e) {
        writeHeartbeatLog("Error getting node info: " . $e->getMessage(), 'ERROR');
        return [
            'node_id' => 'unknown',
            'block_height' => 0,
            'mempool_size' => 0,
            'timestamp' => time(),
            'uptime' => 0
        ];
    }
}

function getUptime(): int
{
    $uptimeString = @file_get_contents('/proc/uptime');
    if ($uptimeString) {
        $parts = explode(' ', trim($uptimeString));
        return (int)floatval($parts[0]);
    }
    return 0;
}

function sendHeartbeat(PDO $pdo, array $nodeInfo): void
{
    global $CONNECTION_POOL_SIZE;
    
    try {
        $startTime = microtime(true);
        $nodes = getActiveNodes($pdo);
        
        if (empty($nodes)) {
            writeHeartbeatLog("No active nodes for heartbeat", 'WARNING');
            return;
        }
        
        $heartbeatPayload = [
            'type' => 'heartbeat',
            'priority' => 4, // Low priority
            'data' => array_merge($nodeInfo, [
                'sent_at' => microtime(true),
                'version' => '1.0',
                'capabilities' => ['sync', 'events', 'mining']
            ])
        ];
        
        $payload = json_encode($heartbeatPayload, JSON_UNESCAPED_SLASHES);
        
        // Process nodes in batches for connection pooling
        $nodeChunks = array_chunk($nodes, $CONNECTION_POOL_SIZE);
        $totalSuccess = 0;
        $totalNodes = count($nodes);
        
        foreach ($nodeChunks as $nodeChunk) {
            $results = sendHeartbeatBatch($nodeChunk, $payload, $nodeInfo['node_id']);
            $totalSuccess += $results['success'];
        }
        
        $duration = microtime(true) - $startTime;
        
        writeHeartbeatLog("Heartbeat sent in {$duration}s: {$totalSuccess}/{$totalNodes} nodes");
        
        // Record performance metrics
        recordHeartbeatMetrics($pdo, $nodeInfo['node_id'], $totalSuccess, $totalNodes - $totalSuccess, $duration);
        
    } catch (Exception $e) {
        writeHeartbeatLog("Error sending heartbeat: " . $e->getMessage(), 'ERROR');
    }
}

/**
 * Send heartbeat to a batch of nodes using multi-curl for better performance
 */
function sendHeartbeatBatch(array $nodes, string $payload, string $nodeId): array
{
    $multiHandle = curl_multi_init();
    $curlHandles = [];
    
    // Set up curl handles
    foreach ($nodes as $node) {
        $nodeUrl = $node['url'] ?? '';
        if (!$nodeUrl) continue;
        
        $eventUrl = rtrim($nodeUrl, '/') . '/api/sync/events.php';
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $eventUrl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'BlockchainHeartbeat/1.0',
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Event-Priority: 4',
                'X-Source-Node: ' . $nodeId,
                'X-Event-Type: heartbeat'
            ]
        ]);
        
        curl_multi_add_handle($multiHandle, $ch);
        $curlHandles[$nodeUrl] = $ch;
    }
    
    // Execute all requests
    $running = null;
    do {
        $status = curl_multi_exec($multiHandle, $running);
        if ($running) {
            curl_multi_select($multiHandle, 0.1);
        }
    } while ($running > 0 && $status === CURLM_OK);
    
    // Process results
    $successCount = 0;
    
    foreach ($curlHandles as $nodeUrl => $ch) {
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        if ($httpCode >= 200 && $httpCode < 300 && empty($error)) {
            $successCount++;
        }
        
        curl_multi_remove_handle($multiHandle, $ch);
        curl_close($ch);
    }
    
    curl_multi_close($multiHandle);
    
    return ['success' => $successCount, 'total' => count($nodes)];
}

/**
 * Record heartbeat performance metrics
 */
function recordHeartbeatMetrics(PDO $pdo, string $nodeId, int $success, int $failed, float $duration): void
{
    try {
        $metrics = [
            'heartbeat_sent_success' => $success,
            'heartbeat_sent_failed' => $failed,
            'heartbeat_duration' => $duration
        ];
        
        foreach ($metrics as $metric => $value) {
            $stmt = $pdo->prepare("
                INSERT INTO broadcast_stats (node_id, metric_type, metric_value, created_at) 
                VALUES (?, ?, ?, NOW())
            ");
            $stmt->execute([$nodeId, $metric, $value]);
        }
    } catch (Exception $e) {
        // Ignore metrics errors
    }
}

function getActiveNodes(PDO $pdo): array
{
    try {
        $stmt = $pdo->prepare("
            SELECT node_id, ip_address, port, metadata, reputation_score 
            FROM nodes 
            WHERE status = 'active' 
            AND reputation_score >= 50
            ORDER BY reputation_score DESC
        ");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $nodes = [];
        foreach ($rows as $row) {
            $metadata = json_decode($row['metadata'] ?? '{}', true);
            $protocol = $metadata['protocol'] ?? 'https';
            $domain = $metadata['domain'] ?? $row['ip_address'];
            $port = $row['port'] ?? ($protocol === 'https' ? 443 : 80);
            
            $url = sprintf('%s://%s%s', 
                $protocol, 
                $domain, 
                ($port != 443 && $port != 80) ? ':' . $port : ''
            );
            
            $nodes[] = [
                'id' => $row['node_id'],
                'url' => $url,
                'reputation' => $row['reputation_score']
            ];
        }
        
        return $nodes;
    } catch (Exception $e) {
        writeHeartbeatLog("Error getting active nodes: " . $e->getMessage(), 'ERROR');
        return [];
    }
}

function checkSyncStatus(PDO $pdo): void
{
    try {
        $nodes = getActiveNodes($pdo);
        if (empty($nodes)) {
            writeHeartbeatLog("No active nodes for sync check", 'WARNING');
            return;
        }
        
        $localHeight = 0;
        $stmt = $pdo->query("SELECT MAX(height) FROM blocks");
        if ($stmt) {
            $localHeight = (int)$stmt->fetchColumn();
        }
        
        $networkHeights = [];
        $responsiveNodes = 0;
        
        foreach ($nodes as $node) {
            $nodeUrl = $node['url'] ?? '';
            $statsUrl = rtrim($nodeUrl, '/') . '/api/explorer/index.php?action=get_network_stats';
            
            $context = stream_context_create([
                'http' => [
                    'timeout' => 5,
                    'ignore_errors' => true
                ]
            ]);
            
            $response = @file_get_contents($statsUrl, false, $context);
            if ($response) {
                $data = json_decode($response, true);
                if ($data && isset($data['current_height'])) {
                    $networkHeights[] = (int)$data['current_height'];
                    $responsiveNodes++;
                } elseif ($data && isset($data['success']) && isset($data['data']['current_height'])) {
                    $networkHeights[] = (int)$data['data']['current_height'];
                    $responsiveNodes++;
                }
            }
        }
        
        if (!empty($networkHeights)) {
            $maxNetworkHeight = max($networkHeights);
            $heightDifference = $maxNetworkHeight - $localHeight;
            
            writeHeartbeatLog("Sync check: local={$localHeight}, network_max={$maxNetworkHeight}, diff={$heightDifference}, responsive={$responsiveNodes}/" . count($nodes));
            
            // Record sync monitoring
            $stmt = $pdo->prepare("
                INSERT INTO sync_monitoring (
                    event_type, local_height, network_max_height, height_difference, 
                    nodes_checked, nodes_responding, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                'height_check',
                $localHeight,
                $maxNetworkHeight,
                $heightDifference,
                count($nodes),
                $responsiveNodes
            ]);
            
            // Trigger sync if significantly behind
            if ($heightDifference > 5) {
                writeHeartbeatLog("Significant height difference detected, triggering sync", 'WARNING');
                triggerEmergencySync($pdo, $maxNetworkHeight, $heightDifference);
            }
        }
        
    } catch (Exception $e) {
        writeHeartbeatLog("Error checking sync status: " . $e->getMessage(), 'ERROR');
    }
}

function triggerEmergencySync(PDO $pdo, int $networkHeight, int $heightDiff): void
{
    try {
        writeHeartbeatLog("Triggering emergency sync: network_height={$networkHeight}, diff={$heightDiff}");
        
        // Record sync trigger
        $stmt = $pdo->prepare("
            INSERT INTO sync_monitoring (
                event_type, local_height, network_max_height, height_difference, 
                error_message, metadata, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            'sync_triggered',
            $networkHeight - $heightDiff,
            $networkHeight,
            $heightDiff,
            'Emergency sync triggered by heartbeat service',
            json_encode(['trigger' => 'height_difference', 'threshold' => 5])
        ]);
        
        // Try to trigger network sync via existing mechanism
        $syncScript = __DIR__ . '/../network_sync.php';
        if (file_exists($syncScript)) {
            $command = "php {$syncScript} > /dev/null 2>&1 &";
            shell_exec($command);
            writeHeartbeatLog("Emergency sync process started in background");
        }
        
    } catch (Exception $e) {
        writeHeartbeatLog("Error triggering emergency sync: " . $e->getMessage(), 'ERROR');
    }
}

function cleanupInactiveNodes(PDO $pdo): void
{
    try {
        global $NODE_TIMEOUT;
        
        $threshold = time() - $NODE_TIMEOUT;
        
        $stmt = $pdo->prepare("
            UPDATE nodes 
            SET status = 'inactive' 
            WHERE status = 'active' 
            AND last_seen < FROM_UNIXTIME(?)
        ");
        $stmt->execute([$threshold]);
        
        $inactiveCount = $stmt->rowCount();
        if ($inactiveCount > 0) {
            writeHeartbeatLog("Marked {$inactiveCount} nodes as inactive due to timeout");
        }
        
    } catch (Exception $e) {
        writeHeartbeatLog("Error cleaning up inactive nodes: " . $e->getMessage(), 'ERROR');
    }
}

// Enhanced main heartbeat loop with adaptive intervals
function runHeartbeatService(): void
{
    global $HEARTBEAT_INTERVAL, $SYNC_CHECK_INTERVAL, $PERFORMANCE_MONITORING;
    
    writeHeartbeatLog("Starting enhanced heartbeat service with adaptive intervals");
    writeHeartbeatLog("Base intervals - Heartbeat: {$HEARTBEAT_INTERVAL}s, Sync check: {$SYNC_CHECK_INTERVAL}s");
    
    $lastHeartbeat = 0;
    $lastSyncCheck = 0;
    $lastPerformanceReport = 0;
    $performanceReportInterval = 300; // 5 minutes
    
    $serviceStartTime = microtime(true);
    $totalHeartbeats = 0;
    $totalSyncChecks = 0;
    
    while (true) {
        $currentTime = time();
        $loopStartTime = microtime(true);
        
        try {
            $pdo = DatabaseManager::getConnection();
            
            // Get adaptive intervals based on network conditions
            $adaptiveHeartbeatInterval = getAdaptiveHeartbeatInterval($pdo, $HEARTBEAT_INTERVAL);
            
            // Send heartbeat with adaptive interval
            if (($currentTime - $lastHeartbeat) >= $adaptiveHeartbeatInterval) {
                $nodeInfo = getCurrentNodeInfo($pdo);
                sendHeartbeat($pdo, $nodeInfo);
                cleanupInactiveNodes($pdo);
                $lastHeartbeat = $currentTime;
                $totalHeartbeats++;
            }
            
            // Check sync status
            if (($currentTime - $lastSyncCheck) >= $SYNC_CHECK_INTERVAL) {
                checkSyncStatus($pdo);
                $lastSyncCheck = $currentTime;
                $totalSyncChecks++;
            }
            
            // Performance reporting
            if ($PERFORMANCE_MONITORING && ($currentTime - $lastPerformanceReport) >= $performanceReportInterval) {
                $uptime = microtime(true) - $serviceStartTime;
                $avgHeartbeatInterval = $totalHeartbeats > 0 ? $uptime / $totalHeartbeats : 0;
                
                writeHeartbeatLog(sprintf(
                    "Performance report: uptime=%.1fs, heartbeats=%d (avg %.1fs), sync_checks=%d, adaptive_interval=%ds",
                    $uptime, $totalHeartbeats, $avgHeartbeatInterval, $totalSyncChecks, $adaptiveHeartbeatInterval
                ));
                
                // Record service performance
                recordServicePerformance($pdo, $uptime, $totalHeartbeats, $totalSyncChecks);
                
                $lastPerformanceReport = $currentTime;
            }
            
        } catch (Exception $e) {
            writeHeartbeatLog("Heartbeat service error: " . $e->getMessage(), 'ERROR');
        }
        
        // Adaptive sleep - shorter sleep if network is active
        $loopDuration = microtime(true) - $loopStartTime;
        $sleepTime = max(0.5, 1.0 - $loopDuration); // At least 500ms, adjust for processing time
        usleep((int)($sleepTime * 1000000));
    }
}

/**
 * Record service performance metrics
 */
function recordServicePerformance(PDO $pdo, float $uptime, int $heartbeats, int $syncChecks): void
{
    try {
        $nodeId = gethostname() . '_heartbeat_service';
        
        $metrics = [
            'service_uptime' => $uptime,
            'total_heartbeats' => $heartbeats,
            'total_sync_checks' => $syncChecks,
            'heartbeats_per_hour' => $uptime > 0 ? ($heartbeats * 3600 / $uptime) : 0
        ];
        
        foreach ($metrics as $metric => $value) {
            $stmt = $pdo->prepare("
                INSERT INTO broadcast_stats (node_id, metric_type, metric_value, created_at) 
                VALUES (?, ?, ?, NOW())
            ");
            $stmt->execute([$nodeId, $metric, $value]);
        }
    } catch (Exception $e) {
        // Ignore metrics errors
    }
}

// Check if running as CLI
if (php_sapi_name() === 'cli') {
    if ($argc > 1 && $argv[1] === '--daemon') {
        // Run as daemon
        runHeartbeatService();
    } else {
        // Single run for testing
        try {
            $pdo = DatabaseManager::getConnection();
            $nodeInfo = getCurrentNodeInfo($pdo);
            
            echo "Node Info:\n";
            echo "- ID: " . $nodeInfo['node_id'] . "\n";
            echo "- Height: " . $nodeInfo['block_height'] . "\n";
            echo "- Mempool: " . $nodeInfo['mempool_size'] . "\n";
            echo "- Uptime: " . $nodeInfo['uptime'] . "s\n\n";
            
            echo "Sending heartbeat...\n";
            sendHeartbeat($pdo, $nodeInfo);
            
            echo "Checking sync status...\n";
            checkSyncStatus($pdo);
            
            echo "Cleaning up inactive nodes...\n";
            cleanupInactiveNodes($pdo);
            
            echo "Done.\n";
            
        } catch (Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
            exit(1);
        }
    }
}
?>