<?php
/**
 * Blockchain Synchronization Service
 * Web interface for managing blockchain data synchronization
 */

require_once 'SyncManager.php';

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    try {
        $syncManager = new SyncManager(true);
        
        switch ($_GET['action']) {
            case 'start_sync':
                // Set content type for streaming
                header('Content-Type: text/plain; charset=utf-8');
                header('Cache-Control: no-cache');
                
                // Disable output buffering for real-time streaming
                if (ob_get_level()) {
                    ob_end_clean();
                }
                
                echo "Starting blockchain synchronization...\n";
                flush();
                
                $result = $syncManager->syncAll();
                
                echo "\nSYNC_COMPLETE:" . json_encode($result) . "\n";
                break;
                
            case 'get_status':
                $status = $syncManager->getStatus();
                echo json_encode(['status' => 'success', 'data' => $status]);
                break;
                
            case 'check_logs':
                $logFile = '../logs/sync_service.log';
                if (file_exists($logFile)) {
                    $lines = file($logFile);
                    $lastLines = array_slice($lines, -20); // Get last 20 lines
                    echo json_encode(['status' => 'success', 'logs' => $lastLines]);
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Log file not found']);
                }
                break;
                
            case 'clear_logs':
                $logFile = '../logs/sync_service.log';
                if (file_exists($logFile)) {
                    file_put_contents($logFile, '');
                    echo json_encode(['status' => 'success', 'message' => 'Logs cleared']);
                } else {
                    echo json_encode(['status' => 'success', 'message' => 'No logs to clear']);
                }
                break;
                
            default:
                echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
                break;
        }
        exit;
        
    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => $e->getMessage()
        ]);
        exit;
    }
}

// Main page - simple PHP interface
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Blockchain Sync Service</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container">
        <header>
            <h1>🔗 Blockchain Synchronization Service</h1>
            <p>Universal service for blockchain data synchronization</p>
        </header>

        <div class="card">
            <h3>📊 Current Status</h3>
            <div id="currentStatus" class="status-info">Loading...</div>
            
            <div class="progress-wrapper">
                <div class="progress-bar">
                    <div class="progress-fill" id="progressBar"></div>
                </div>
                <div id="progressText" class="progress-text">Ready to sync</div>
            </div>
            
            <div class="button-group">
                <button class="btn btn-primary" onclick="startSync()" id="syncBtn">
                    <span class="btn-icon">▶️</span> Start Sync
                </button>
                <button class="btn btn-secondary" onclick="refreshStatus()" id="statusBtn">
                    <span class="btn-icon">🔄</span> Refresh
                </button>
                <button class="btn btn-danger" onclick="clearLogs()" id="clearBtn">
                    <span class="btn-icon">🗑️</span> Clear Logs
                </button>
            </div>
        </div>

        <div class="card">
            <h3>📈 Database Statistics</h3>
            <div class="stats-grid" id="statsGrid">
                <div class="stat-item">
                    <div class="stat-number" id="blocks-count">-</div>
                    <div class="stat-label">Blocks</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number" id="transactions-count">-</div>
                    <div class="stat-label">Transactions</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number" id="nodes-count">-</div>
                    <div class="stat-label">Nodes</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number" id="validators-count">-</div>
                    <div class="stat-label">Validators</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number" id="smart_contracts-count">-</div>
                    <div class="stat-label">Smart Contracts</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number" id="staking-count">-</div>
                    <div class="stat-label">Staking Records</div>
                </div>
            </div>
        </div>

        <div class="card">
            <h3>📋 Synchronization Log</h3>
            <div class="log-container" id="logContainer">
                <div class="log-placeholder">No logs available. Start synchronization to see activity.</div>
            </div>
        </div>

        <footer>
            <p>Universal Sync Service • Last updated: <span id="lastUpdate"><?= date('Y-m-d H:i:s') ?></span></p>
        </footer>
    </div>

    <script src="script.js"></script>
</body>
</html>
