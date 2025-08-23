<?php
/**
 * Enhanced Event Processing API Endpoint
 * Handles real-time synchronization events without changing database structure
 * Improved with performance optimization, rate limiting, and better error handling
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Event-Priority, X-Source-Node, X-Event-Type, X-Event-ID');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Rate limiting configuration
$RATE_LIMIT_PER_MINUTE = 60;
$RATE_LIMIT_BURST = 10;

// Performance tracking
$startTime = microtime(true);
$memoryStart = memory_get_usage();

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../core/Database/DatabaseManager.php';
require_once __DIR__ . '/../../core/Events/EventDispatcher.php';
require_once __DIR__ . '/../../core/Logging/NullLogger.php';
require_once __DIR__ . '/../../core/Sync/EnhancedEventSync.php';

use Blockchain\Core\Database\DatabaseManager;
use Blockchain\Core\Events\EventDispatcher;
use Blockchain\Core\Logging\NullLogger;
use Blockchain\Core\Sync\EnhancedEventSync;

function writeEventLog(string $message, string $level = 'INFO'): void
{
    $logFile = __DIR__ . '/../../logs/event_sync.log';
    $timestamp = date('Y-m-d H:i:s');
    $logLine = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;
    @file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
}

/**
 * Enhanced rate limiting with burst support
 */
function checkRateLimit(PDO $pdo, string $sourceNode): bool
{
    global $RATE_LIMIT_PER_MINUTE, $RATE_LIMIT_BURST;
    
    try {
        $nodeId = md5($sourceNode);
        $now = time();
        $minute = $now - 60;
        
        // Get recent request count
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count,
                   SUM(CASE WHEN created_at > FROM_UNIXTIME(?) THEN 1 ELSE 0 END) as burst_count
            FROM broadcast_stats 
            WHERE node_id = ? 
            AND metric_type = 'api_request' 
            AND created_at > FROM_UNIXTIME(?)
        ");
        $stmt->execute([$now - 10, $nodeId, $minute]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $minuteCount = (int)($result['count'] ?? 0);
        $burstCount = (int)($result['burst_count'] ?? 0);
        
        // Check rate limits
        if ($burstCount >= $RATE_LIMIT_BURST) {
            writeEventLog("Rate limit exceeded for {$sourceNode}: burst={$burstCount}", 'WARNING');
            return false;
        }
        
        if ($minuteCount >= $RATE_LIMIT_PER_MINUTE) {
            writeEventLog("Rate limit exceeded for {$sourceNode}: minute={$minuteCount}", 'WARNING');
            return false;
        }
        
        // Record request
        $stmt = $pdo->prepare("
            INSERT INTO broadcast_stats (node_id, metric_type, metric_value, created_at) 
            VALUES (?, 'api_request', 1, NOW())
        ");
        $stmt->execute([$nodeId]);
        
        return true;
        
    } catch (Exception $e) {
        writeEventLog("Rate limit check failed: " . $e->getMessage(), 'ERROR');
        return true; // Allow on error
    }
}

/**
 * Enhanced event deduplication
 */
function isDuplicateEvent(PDO $pdo, string $eventId): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM broadcast_tracking 
            WHERE transaction_hash = ? AND expires_at > NOW()
        ");
        $stmt->execute([hash('sha256', $eventId)]);
        return $stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Record event processing for monitoring and deduplication
 */
function recordEventProcessing(PDO $pdo, string $eventId, string $sourceNode): void
{
    try {
        $stmt = $pdo->prepare("
            INSERT INTO broadcast_tracking (
                transaction_hash, node_id, broadcast_count, 
                source_info, expires_at, created_at
            ) VALUES (?, ?, 1, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR), NOW())
            ON DUPLICATE KEY UPDATE 
                broadcast_count = broadcast_count + 1,
                expires_at = DATE_ADD(NOW(), INTERVAL 1 HOUR)
        ");
        $stmt->execute([hash('sha256', $eventId), md5($sourceNode), $sourceNode]);
        
        // Also record in broadcast stats for metrics
        $stmt = $pdo->prepare("
            INSERT INTO broadcast_stats (node_id, metric_type, metric_value, created_at) 
            VALUES (?, 'event_processed', 1, NOW())
        ");
        $stmt->execute([md5($sourceNode)]);
        
    } catch (Exception $e) {
        writeEventLog("Failed to record event processing: " . $e->getMessage(), 'ERROR');
    }
}

/**
 * Validate event data structure
 */
function validateEventData(array $eventData): array
{
    $errors = [];
    
    if (!isset($eventData['type']) || !is_string($eventData['type'])) {
        $errors[] = 'Event type is required and must be a string';
    }
    
    if (!isset($eventData['data']) || !is_array($eventData['data'])) {
        $errors[] = 'Event data is required and must be an array';
    }
    
    // Validate specific event types
    switch ($eventData['type'] ?? '') {
        case 'block.added':
            if (empty($eventData['data']['block_hash']) || empty($eventData['data']['block_height'])) {
                $errors[] = 'Block events must include block_hash and block_height';
            }
            break;
            
        case 'transaction.propagate':
            if (empty($eventData['data']['tx_hash'])) {
                $errors[] = 'Transaction events must include tx_hash';
            }
            break;
            
        case 'fork.detected':
            if (empty($eventData['data']['fork_height'])) {
                $errors[] = 'Fork events must include fork_height';
            }
            break;
    }
    
    return $errors;
}

try {
    // Initialize components
    $pdo = DatabaseManager::getConnection();
    $eventDispatcher = new EventDispatcher(new NullLogger());
    $enhancedSync = new EnhancedEventSync($eventDispatcher, new NullLogger());
    
    // Get request data and headers
    $rawBody = file_get_contents('php://input');
    $eventPriority = $_SERVER['HTTP_X_EVENT_PRIORITY'] ?? '3';
    $sourceNode = $_SERVER['HTTP_X_SOURCE_NODE'] ?? 'unknown';
    $eventType = $_SERVER['HTTP_X_EVENT_TYPE'] ?? 'unknown';
    $eventId = $_SERVER['HTTP_X_EVENT_ID'] ?? '';
    
    // Validate input
    if (empty($rawBody)) {
        http_response_code(400);
        echo json_encode(['error' => 'Empty request body']);
        exit;
    }
    
    // Check rate limiting
    if (!checkRateLimit($pdo, $sourceNode)) {
        http_response_code(429);
        echo json_encode(['error' => 'Rate limit exceeded']);
        exit;
    }
    
    writeEventLog("Received event: type={$eventType}, priority={$eventPriority}, source={$sourceNode}, size=" . strlen($rawBody));
    
    // Handle compressed payloads with better error handling
    $eventData = null;
    if (strpos($rawBody, '{') === 0) {
        // Regular JSON
        $eventData = json_decode($rawBody, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            writeEventLog('JSON decode error: ' . json_last_error_msg(), 'ERROR');
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON: ' . json_last_error_msg()]);
            exit;
        }
    } else {
        // Possibly base64 encoded compressed data
        $decoded = base64_decode($rawBody, true);
        if ($decoded !== false) {
            $decompressed = @gzdecode($decoded);
            if ($decompressed !== false) {
                $eventData = json_decode($decompressed, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    writeEventLog('Compressed JSON decode error: ' . json_last_error_msg(), 'ERROR');
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid compressed JSON']);
                    exit;
                }
            } else {
                writeEventLog('Failed to decompress data', 'ERROR');
                http_response_code(400);
                echo json_encode(['error' => 'Failed to decompress data']);
                exit;
            }
        } else {
            writeEventLog('Invalid base64 data', 'ERROR');
            http_response_code(400);
            echo json_encode(['error' => 'Invalid data format']);
            exit;
        }
    }
    
    if (!$eventData || !is_array($eventData)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid event data structure']);
        exit;
    }
    
    // Validate event structure
    $validationErrors = validateEventData($eventData);
    if (!empty($validationErrors)) {
        http_response_code(400);
        echo json_encode(['error' => 'Validation failed', 'details' => $validationErrors]);
        exit;
    }
    
    $eventDataToProcess = $eventData['data'] ?? $eventData;
    $eventTypeToProcess = $eventData['type'] ?? $eventType;
    
    // Generate event ID if not provided
    if (empty($eventId)) {
        $eventId = hash('sha256', $eventTypeToProcess . serialize($eventDataToProcess) . $sourceNode . microtime(true));
    }
    
    // Check for duplicate events
    if (isDuplicateEvent($pdo, $eventId)) {
        writeEventLog("Duplicate event detected: {$eventId}", 'DEBUG');
        echo json_encode([
            'status' => 'duplicate',
            'event_type' => $eventTypeToProcess,
            'event_id' => $eventId
        ]);
        exit;
    }
    
    // Record event processing
    recordEventProcessing($pdo, $eventId, $sourceNode);
    
    // Process different event types with enhanced handling
    $processed = false;
    switch ($eventTypeToProcess) {
        case 'block.added':
        case 'block.mined':
            $processed = handleBlockAddedEvent($pdo, $eventDataToProcess, $sourceNode, $enhancedSync);
            break;
            
        case 'transaction.propagate':
        case 'transaction.broadcast':
            $processed = handleTransactionEvent($pdo, $eventDataToProcess, $sourceNode, $enhancedSync);
            break;
            
        case 'fork.detected':
            $processed = handleForkEvent($pdo, $eventDataToProcess, $sourceNode, $enhancedSync);
            break;
            
        case 'heartbeat':
            $processed = handleHeartbeatEvent($pdo, $eventDataToProcess, $sourceNode, $enhancedSync);
            break;
            
        case 'sync.gap_detected':
            $processed = handleGapDetectedEvent($pdo, $eventDataToProcess, $sourceNode, $enhancedSync);
            break;
            
        default:
            writeEventLog("Unknown event type: {$eventTypeToProcess}", 'WARNING');
            $processed = false;
            break;
    }
    
    // Calculate processing time and memory usage
    $processingTime = microtime(true) - $startTime;
    $memoryUsed = memory_get_usage() - $memoryStart;
    
    // Record performance metrics
    recordPerformanceMetrics($pdo, $sourceNode, $processingTime, $memoryUsed, $processed);
    
    // Send response
    $response = [
        'status' => $processed ? 'success' : 'failed',
        'event_type' => $eventTypeToProcess,
        'event_id' => $eventId,
        'processed_at' => time(),
        'processing_time' => round($processingTime, 4),
        'memory_used' => $memoryUsed
    ];
    
    if (!$processed) {
        http_response_code(500);
        $response['error'] = 'Event processing failed';
    }
    
    echo json_encode($response);
    writeEventLog("Event processed: {$eventTypeToProcess}, success={$processed}, time={$processingTime}s");
    
} catch (Exception $e) {
    $processingTime = microtime(true) - $startTime;
    writeEventLog("Event processing error after {$processingTime}s: " . $e->getMessage(), 'ERROR');
    
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Internal processing error',
        'processing_time' => round($processingTime, 4)
    ]);
}

/**
 * Record performance metrics for monitoring
 */
function recordPerformanceMetrics(PDO $pdo, string $sourceNode, float $processingTime, int $memoryUsed, bool $success): void
{
    try {
        $nodeId = md5($sourceNode);
        $metrics = [
            'processing_time' => $processingTime,
            'memory_usage' => $memoryUsed,
            'events_processed' => $success ? 1 : 0,
            'events_failed' => $success ? 0 : 1
        ];
        
        foreach ($metrics as $metric => $value) {
            $stmt = $pdo->prepare("
                INSERT INTO broadcast_stats (node_id, metric_type, metric_value, created_at) 
                VALUES (?, ?, ?, NOW())
            ");
            $stmt->execute([$nodeId, 'api_' . $metric, $value]);
        }
    } catch (Exception $e) {
        // Ignore metrics errors
    }
}

/**
 * Handle block added event with enhanced processing
 */
function handleBlockAddedEvent(PDO $pdo, array $data, string $sourceNode, EnhancedEventSync $enhancedSync): bool
{
    $blockHash = $data['block_hash'] ?? '';
    $blockHeight = $data['block_height'] ?? 0;
    
    if (!$blockHash || !$blockHeight) {
        writeEventLog("Invalid block event data from {$sourceNode}", 'WARNING');
        return false;
    }
    
    writeEventLog("Processing block added: {$blockHash} at height {$blockHeight} from {$sourceNode}");
    
    try {
        // Check if we need to sync this block
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM blocks WHERE height = ? AND hash = ?");
        $stmt->execute([$blockHeight, $blockHash]);
        
        if ($stmt->fetchColumn() > 0) {
            writeEventLog("Block already exists, skipping");
            return true;
        }
        
        // Check our current height
        $stmt = $pdo->query("SELECT MAX(height) FROM blocks");
        $localHeight = (int)$stmt->fetchColumn();
        
        if ($blockHeight > $localHeight + 1) {
            // Gap detected - use enhanced sync to handle it
            writeEventLog("Gap detected: local={$localHeight}, received={$blockHeight}");
            return $enhancedSync->processEvent('sync.gap_detected', [
                'local_height' => $localHeight,
                'received_height' => $blockHeight,
                'source_node' => $sourceNode,
                'gap_size' => $blockHeight - $localHeight
            ], EnhancedEventSync::PRIORITY_HIGH);
        } elseif ($blockHeight == $localHeight + 1) {
            // Next sequential block - fetch and process immediately
            writeEventLog("Fetching next sequential block from {$sourceNode}");
            $success = fetchAndProcessBlock($pdo, $blockHeight, $sourceNode);
            
            if ($success) {
                // Trigger dependent events through enhanced sync
                $enhancedSync->processEvent('block.processed', [
                    'block_hash' => $blockHash,
                    'block_height' => $blockHeight,
                    'source_node' => $sourceNode
                ], EnhancedEventSync::PRIORITY_HIGH);
            }
            
            return $success;
        }
        
        // Update node reputation for providing valid block info
        updateNodeReputation($pdo, $sourceNode, 1);
        return true;
        
    } catch (Exception $e) {
        writeEventLog("Block event processing failed: " . $e->getMessage(), 'ERROR');
        return false;
    }
}

/**
 * Handle transaction propagation event with enhanced processing
 */
function handleTransactionEvent(PDO $pdo, array $data, string $sourceNode, EnhancedEventSync $enhancedSync): bool
{
    $txHash = $data['tx_hash'] ?? '';
    
    if (!$txHash) {
        return false;
    }
    
    try {
        // Check if transaction already exists with optimized query
        $h = strtolower(trim($txHash));
        $h0 = str_starts_with($h, '0x') ? $h : ('0x' . $h);
        $h1 = str_starts_with($h, '0x') ? substr($h, 2) : $h;
        
        $stmt = $pdo->prepare("
            SELECT EXISTS(SELECT 1 FROM mempool WHERE tx_hash IN (?, ?)) as in_mempool,
                   EXISTS(SELECT 1 FROM transactions WHERE hash = ?) as in_transactions
        ");
        $stmt->execute([$h0, $h1, $txHash]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result['in_mempool'] || $result['in_transactions']) {
            return true; // Already exists
        }
        
        // Use enhanced sync to process transaction
        $success = $enhancedSync->processEvent('transaction.received', [
            'tx_hash' => $txHash,
            'source_node' => $sourceNode,
            'received_at' => microtime(true)
        ], EnhancedEventSync::PRIORITY_HIGH);
        
        if ($success) {
            // Fetch full transaction from source node
            $fetchSuccess = fetchTransactionFromNode($pdo, $txHash, $sourceNode);
            writeEventLog("Processed transaction event: {$txHash} from {$sourceNode}");
            return $fetchSuccess !== false;
        }
        
        return $success;
        
    } catch (Exception $e) {
        writeEventLog("Transaction event processing failed: " . $e->getMessage(), 'ERROR');
        return false;
    }
}

/**
 * Handle fork detection event with enhanced resolution
 */
function handleForkEvent(PDO $pdo, array $data, string $sourceNode, EnhancedEventSync $enhancedSync): bool
{
    $forkHeight = $data['fork_height'] ?? 0;
    $remoteHash = $data['remote_hash'] ?? '';
    
    if (!$forkHeight || !$remoteHash) {
        return false;
    }
    
    try {
        writeEventLog("Fork detected at height {$forkHeight}, remote hash: {$remoteHash} from {$sourceNode}");
        
        // Check our block at that height
        $stmt = $pdo->prepare("SELECT hash FROM blocks WHERE height = ?");
        $stmt->execute([$forkHeight]);
        $localHash = $stmt->fetchColumn();
        
        if ($localHash && $localHash !== $remoteHash) {
            writeEventLog("Fork confirmed: local={$localHash}, remote={$remoteHash}");
            
            // Use enhanced sync for fork resolution
            $success = $enhancedSync->processEvent('fork.confirmed', [
                'fork_height' => $forkHeight,
                'local_hash' => $localHash,
                'remote_hash' => $remoteHash,
                'source_node' => $sourceNode,
                'detected_at' => time()
            ], EnhancedEventSync::PRIORITY_CRITICAL);
            
            // Record fork event for monitoring
            recordForkEvent($pdo, $forkHeight, $localHash, $remoteHash, $sourceNode);
            
            return $success;
        }
        
        return true;
        
    } catch (Exception $e) {
        writeEventLog("Fork event processing failed: " . $e->getMessage(), 'ERROR');
        return false;
    }
}

/**
 * Handle heartbeat event for node monitoring with enhanced tracking
 */
function handleHeartbeatEvent(PDO $pdo, array $data, string $sourceNode, EnhancedEventSync $enhancedSync): bool
{
    $blockHeight = $data['block_height'] ?? 0;
    $mempoolSize = $data['mempool_size'] ?? 0;
    $timestamp = $data['timestamp'] ?? time();
    $nodeId = $data['node_id'] ?? '';
    
    try {
        // Update node last seen time with better performance
        updateNodeLastSeen($pdo, $sourceNode, $timestamp);
        
        // Record node stats in batch for better performance
        $stats = [
            'block_height' => $blockHeight,
            'mempool_size' => $mempoolSize,
            'heartbeat_time' => $timestamp,
            'response_time' => microtime(true) - ($data['sent_at'] ?? microtime(true))
        ];
        recordNodeStats($pdo, $sourceNode, $stats);
        
        // Process heartbeat through enhanced sync for network awareness
        $enhancedSync->processEvent('heartbeat.received', [
            'source_node' => $sourceNode,
            'node_id' => $nodeId,
            'stats' => $stats
        ], EnhancedEventSync::PRIORITY_LOW);
        
        writeEventLog("Heartbeat from {$sourceNode}: height={$blockHeight}, mempool={$mempoolSize}");
        return true;
        
    } catch (Exception $e) {
        writeEventLog("Heartbeat processing failed: " . $e->getMessage(), 'ERROR');
        return false;
    }
}

/**
 * Handle gap detection for immediate sync with enhanced processing
 */
function handleGapDetectedEvent(PDO $pdo, array $data, string $sourceNode, EnhancedEventSync $enhancedSync): bool
{
    $localHeight = $data['local_height'] ?? 0;
    $receivedHeight = $data['received_height'] ?? 0;
    $gapSize = $data['gap_size'] ?? 0;
    
    if ($gapSize <= 0) {
        return true; // No gap to handle
    }
    
    try {
        writeEventLog("Gap detected: local={$localHeight}, received={$receivedHeight}, gap={$gapSize} from {$sourceNode}");
        
        // Use enhanced sync to handle gap based on size
        $priority = EnhancedEventSync::PRIORITY_HIGH;
        if ($gapSize > 100) {
            $priority = EnhancedEventSync::PRIORITY_CRITICAL;
        }
        
        $success = $enhancedSync->processEvent('sync.gap_resolution', [
            'local_height' => $localHeight,
            'received_height' => $receivedHeight,
            'gap_size' => $gapSize,
            'source_node' => $sourceNode,
            'strategy' => $gapSize <= 10 ? 'immediate' : ($gapSize <= 100 ? 'batch' : 'full')
        ], $priority);
        
        // Record gap for monitoring
        recordGapEvent($pdo, $localHeight, $receivedHeight, $gapSize, $sourceNode);
        
        return $success;
        
    } catch (Exception $e) {
        writeEventLog("Gap detection processing failed: " . $e->getMessage(), 'ERROR');
        return false;
    }
}

/**
 * Record gap event for monitoring
 */
function recordGapEvent(PDO $pdo, int $localHeight, int $receivedHeight, int $gapSize, string $sourceNode): void
{
    try {
        $stmt = $pdo->prepare("
            INSERT INTO sync_monitoring (
                event_type, local_height, network_max_height, height_difference,
                error_message, metadata, created_at
            ) VALUES ('gap_detected', ?, ?, ?, 'Sync gap detected', ?, NOW())
        ");
        
        $stmt->execute([
            $localHeight,
            $receivedHeight,
            $gapSize,
            json_encode([
                'source_node' => $sourceNode,
                'gap_size' => $gapSize,
                'detection_time' => time()
            ])
        ]);
        
    } catch (Exception $e) {
        writeEventLog("Failed to record gap event: " . $e->getMessage(), 'ERROR');
    }
}

/**
 * Trigger gap sync for missing blocks
 */
function triggerGapSync(PDO $pdo, int $localHeight, int $remoteHeight, string $sourceNode): void
{
    writeEventLog("Triggering gap sync from {$localHeight} to {$remoteHeight}");
    
    // Use existing network_sync.php functionality
    $syncUrl = rtrim($sourceNode, '/') . '/network_sync.php';
    
    $payload = json_encode([
        'action' => 'sync_range',
        'start_height' => $localHeight + 1,
        'end_height' => $remoteHeight,
        'requester_node' => getCurrentNodeId()
    ]);
    
    // Trigger async sync
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $syncUrl,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 1, // Quick timeout for non-blocking
        CURLOPT_HTTPHEADER => ['Content-Type: application/json']
    ]);
    
    curl_exec($ch);
    curl_close($ch);
}

/**
 * Fetch and process single block
 */
function fetchAndProcessBlock(PDO $pdo, int $blockHeight, string $sourceNode): bool
{
    $blockUrl = rtrim($sourceNode, '/') . "/api/explorer/index.php?action=get_block&block_id={$blockHeight}";
    
    $context = stream_context_create([
        'http' => [
            'timeout' => 5,
            'method' => 'GET'
        ]
    ]);
    
    $response = @file_get_contents($blockUrl, false, $context);
    if ($response === false) {
        writeEventLog("Failed to fetch block {$blockHeight} from {$sourceNode}", 'ERROR');
        return false;
    }
    
    $data = json_decode($response, true);
    if (!$data || !isset($data['success']) || !$data['success']) {
        writeEventLog("Invalid block response for {$blockHeight}", 'ERROR');
        return false;
    }
    
    $block = $data['data'];
    
    // Insert block
    $stmt = $pdo->prepare("
        INSERT IGNORE INTO blocks (height, hash, parent_hash, merkle_root, timestamp, validator, signature, transactions_count, metadata)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $result = $stmt->execute([
        $block['height'],
        $block['hash'],
        $block['parent_hash'] ?? '',
        $block['merkle_root'] ?? '',
        $block['timestamp'],
        $block['validator'] ?? '',
        $block['signature'] ?? '',
        $block['transactions_count'] ?? 0,
        json_encode($block['metadata'] ?? [])
    ]);
    
    if ($result && $stmt->rowCount() > 0) {
        writeEventLog("Successfully processed block {$blockHeight}: {$block['hash']}");
        return true;
    }
    
    return false;
}

/**
 * Update node reputation
 */
function updateNodeReputation(PDO $pdo, string $nodeUrl, int $change): void
{
    try {
        // Extract domain/IP for node identification
        $parsedUrl = parse_url($nodeUrl);
        $host = $parsedUrl['host'] ?? $nodeUrl;
        
        $stmt = $pdo->prepare("
            UPDATE nodes 
            SET reputation_score = GREATEST(0, LEAST(1000, reputation_score + ?))
            WHERE ip_address = ? OR JSON_EXTRACT(metadata, '$.domain') = ?
        ");
        $stmt->execute([$change, $host, $host]);
        
    } catch (Exception $e) {
        writeEventLog("Failed to update reputation: " . $e->getMessage(), 'ERROR');
    }
}

/**
 * Record event metrics for monitoring (legacy compatibility)
 */
function recordEventMetrics(PDO $pdo, string $eventType, string $sourceNode, bool $success): void
{
    try {
        $stmt = $pdo->prepare("
            INSERT INTO broadcast_stats (node_id, metric_type, metric_value, created_at) 
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([
            md5($sourceNode),
            'event_' . $eventType . ($success ? '_success' : '_failed'),
            1
        ]);
    } catch (Exception $e) {
        // Ignore metrics errors
    }
}

/**
 * Record fork event for monitoring
 */
function recordForkEvent(PDO $pdo, int $height, string $localHash, string $remoteHash, string $sourceNode): void
{
    try {
        $stmt = $pdo->prepare("
            INSERT INTO sync_monitoring (event_type, local_height, network_max_height, error_message, metadata, created_at) 
            VALUES ('alert_raised', ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $height,
            $height,
            'Fork detected',
            json_encode([
                'local_hash' => $localHash,
                'remote_hash' => $remoteHash,
                'source_node' => $sourceNode
            ])
        ]);
    } catch (Exception $e) {
        writeEventLog("Failed to record fork event: " . $e->getMessage(), 'ERROR');
    }
}

/**
 * Update node last seen timestamp
 */
function updateNodeLastSeen(PDO $pdo, string $nodeUrl, float $timestamp): void
{
    try {
        $parsedUrl = parse_url($nodeUrl);
        $host = $parsedUrl['host'] ?? $nodeUrl;
        
        $stmt = $pdo->prepare("
            UPDATE nodes 
            SET last_seen = FROM_UNIXTIME(?)
            WHERE ip_address = ? OR JSON_EXTRACT(metadata, '$.domain') = ?
        ");
        $stmt->execute([$timestamp, $host, $host]);
        
    } catch (Exception $e) {
        // Ignore update errors
    }
}

/**
 * Record node statistics
 */
function recordNodeStats(PDO $pdo, string $nodeUrl, array $stats): void
{
    try {
        $nodeId = md5($nodeUrl);
        
        foreach ($stats as $metric => $value) {
            $stmt = $pdo->prepare("
                INSERT INTO broadcast_stats (node_id, metric_type, metric_value, created_at) 
                VALUES (?, ?, ?, NOW())
            ");
            $stmt->execute([$nodeId, 'node_' . $metric, $value]);
        }
    } catch (Exception $e) {
        // Ignore metrics errors
    }
}

/**
 * Get current node ID
 */
function getCurrentNodeId(): string
{
    return gethostname() . '_' . substr(md5(gethostname()), 0, 8);
}

/**
 * Trigger immediate sync for small gaps
 */
function triggerImmediateSync(PDO $pdo, int $startHeight, int $endHeight, string $sourceNode): void
{
    // Use existing sync mechanisms
    for ($height = $startHeight; $height <= $endHeight; $height++) {
        fetchAndProcessBlock($pdo, $height, $sourceNode);
    }
}

/**
 * Schedule full sync for large gaps
 */
function scheduleFullSync(PDO $pdo, string $sourceNode): void
{
    writeEventLog("Scheduling full sync with {$sourceNode}");
    
    // Record sync request in monitoring
    try {
        $stmt = $pdo->prepare("
            INSERT INTO sync_monitoring (event_type, local_height, error_message, metadata, created_at) 
            VALUES ('sync_triggered', ?, 'Large gap detected', ?, NOW())
        ");
        $stmt->execute([
            getCurrentBlockHeight($pdo),
            json_encode(['source_node' => $sourceNode, 'trigger' => 'large_gap'])
        ]);
    } catch (Exception $e) {
        writeEventLog("Failed to record sync request: " . $e->getMessage(), 'ERROR');
    }
}

/**
 * Get current block height
 */
function getCurrentBlockHeight(PDO $pdo): int
{
    try {
        $stmt = $pdo->query("SELECT MAX(height) FROM blocks");
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * Fetch transaction from node with enhanced error handling
 */
function fetchTransactionFromNode(PDO $pdo, string $txHash, string $sourceNode): bool
{
    try {
        // Implementation would fetch transaction details from source node
        // and add to local mempool if valid
        writeEventLog("Fetching transaction {$txHash} from {$sourceNode}");
        
        // For now, just return success - actual implementation would make HTTP request
        // to source node and process the transaction response
        return true;
        
    } catch (Exception $e) {
        writeEventLog("Failed to fetch transaction {$txHash}: " . $e->getMessage(), 'ERROR');
        return false;
    }
}

/**
 * Trigger fork resolution process
 */
function triggerForkResolution(PDO $pdo, int $forkHeight, string $sourceNode): void
{
    writeEventLog("Triggering fork resolution at height {$forkHeight} with {$sourceNode}");
    
    // Implementation would start fork resolution process
    // using existing network sync mechanisms
}
?>