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

// Main interface
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
        <header class="header">
            <h1>🔗 Blockchain Synchronization Service</h1>
            <p>Sync blockchain data from network nodes | Recovery & Manual Sync Tool</p>
        </header>

        <div class="status-section">
            <div class="status-card">
                <h2>🔍 Current Status</h2>
                <div id="currentStatus" class="status-content">
                    <div class="loading">Loading status...</div>
                </div>
                
                <div class="progress-container">
                    <div class="progress-bar">
                        <div class="progress-fill" id="progressBar"></div>
                    </div>
                    <div class="progress-text" id="progressText">Ready to sync</div>
                </div>
                
                <div class="control-buttons">
                    <button id="syncBtn" class="btn btn-primary" onclick="startSync()">
                        <span class="btn-icon">▶️</span> Start Synchronization
                    </button>
                    <button id="refreshBtn" class="btn btn-secondary" onclick="refreshStatus()">
                        <span class="btn-icon">🔄</span> Refresh Status
                    </button>
                    <button id="clearBtn" class="btn btn-danger" onclick="clearLogs()">
                        <span class="btn-icon">🗑️</span> Clear Logs
                    </button>
                </div>
            </div>
        </div>

        <div class="stats-section">
            <h2>📊 Database Statistics</h2>
            <div class="stats-grid" id="statsGrid">
                <div class="stat-card">
                    <div class="stat-number" id="blocks-count">-</div>
                    <div class="stat-label">Blocks</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" id="transactions-count">-</div>
                    <div class="stat-label">Transactions</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" id="nodes-count">-</div>
                    <div class="stat-label">Nodes</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" id="validators-count">-</div>
                    <div class="stat-label">Validators</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" id="smart_contracts-count">-</div>
                    <div class="stat-label">Smart Contracts</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" id="staking-count">-</div>
                    <div class="stat-label">Staking Records</div>
                </div>
            </div>
        </div>

        <div class="logs-section">
            <div class="logs-card">
                <h2>📋 Synchronization Log</h2>
                <div class="log-container" id="logContainer">
                    <div class="log-placeholder">Logs will appear here during synchronization...</div>
                </div>
                <div class="log-controls">
                    <button class="btn btn-small" onclick="refreshLogs()">
                        <span class="btn-icon">🔄</span> Refresh Logs
                    </button>
                    <button class="btn btn-small" onclick="autoScrollToggle()">
                        <span class="btn-icon">📜</span> Auto-scroll: <span id="autoScrollStatus">ON</span>
                    </button>
                </div>
            </div>
        </div>

        <footer class="footer">
            <p>Universal Blockchain Sync Service | For recovery and manual synchronization</p>
        </footer>
    </div>

    <script src="script.js"></script>
</body>
</html>
