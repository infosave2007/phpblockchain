<?php
/**
 * Web Interface for Network Synchronization
 * Simple PHP interface for blockchain synchronization with progress tracking
 */

require_once 'network_sync.php';

// Set output buffering off for real-time progress
if (ob_get_level()) {
    ob_end_clean();
}

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    try {
        $syncManager = new NetworkSyncManager(true);
        
        switch ($_GET['action']) {
            case 'start_sync':
                // Start synchronization with real-time progress
                header('Content-Type: text/plain');
                
                echo "Starting blockchain synchronization...\n";
                flush();
                
                $result = $syncManager->syncAll();
                
                echo "\nSYNC_COMPLETE:" . json_encode($result) . "\n";
                break;
                
            case 'get_status':
                $status = $syncManager->getStatus();
                echo json_encode(['status' => 'success', 'data' => $status]);
                break;
                
            case 'check_progress':
                // Check log file for latest progress
                $logFile = 'logs/network_sync.log';
                if (file_exists($logFile)) {
                    $lines = file($logFile);
                    $lastLines = array_slice($lines, -10);
                    echo json_encode(['status' => 'success', 'logs' => $lastLines]);
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Log file not found']);
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

// Main page
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Blockchain Synchronization</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background-color: #1a1a1a; color: #fff; }
        .container { max-width: 800px; margin: 0 auto; }
        .header { text-align: center; margin-bottom: 30px; }
        .status-box { background: #2d2d2d; padding: 20px; border-radius: 8px; margin: 20px 0; }
        .progress-bar { width: 100%; height: 20px; background: #333; border-radius: 10px; overflow: hidden; margin: 10px 0; }
        .progress-fill { height: 100%; background: linear-gradient(90deg, #4caf50, #45a049); width: 0%; transition: width 0.3s; }
        .log-container { background: #000; padding: 15px; border-radius: 5px; height: 300px; overflow-y: auto; font-family: monospace; font-size: 12px; }
        .btn { background: #4caf50; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; margin: 5px; }
        .btn:hover { background: #45a049; }
        .btn:disabled { background: #666; cursor: not-allowed; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin: 20px 0; }
        .stat-item { background: #333; padding: 15px; border-radius: 5px; text-align: center; }
        .stat-number { font-size: 24px; font-weight: bold; color: #4caf50; }
        .error { color: #f44336; }
        .success { color: #4caf50; }
        .warning { color: #ff9800; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔗 Blockchain Network Synchronization</h1>
            <p>Synchronize blockchain data from network nodes</p>
        </div>

        <div class="status-box">
            <h3>Current Status</h3>
            <div id="currentStatus">Loading...</div>
            
            <div class="progress-bar">
                <div class="progress-fill" id="progressBar"></div>
            </div>
            <div id="progressText">Ready to sync</div>
            
            <div style="text-align: center; margin-top: 20px;">
                <button class="btn" onclick="startSync()" id="syncBtn">Start Synchronization</button>
                <button class="btn" onclick="refreshStatus()" id="statusBtn">Refresh Status</button>
                <button class="btn" onclick="clearLogs()" id="clearBtn">Clear Logs</button>
            </div>
        </div>

        <div class="status-box">
            <h3>Database Statistics</h3>
            <div class="stats-grid" id="statsGrid">
                <div class="stat-item">
                    <div class="stat-number" id="blocks-count">-</div>
                    <div>Blocks</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number" id="transactions-count">-</div>
                    <div>Transactions</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number" id="nodes-count">-</div>
                    <div>Nodes</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number" id="validators-count">-</div>
                    <div>Validators</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number" id="smart_contracts-count">-</div>
                    <div>Smart Contracts</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number" id="staking-count">-</div>
                    <div>Staking Records</div>
                </div>
            </div>
        </div>

        <div class="status-box">
            <h3>Synchronization Log</h3>
            <div class="log-container" id="logContainer"></div>
        </div>
    </div>

    <script>
        let syncInProgress = false;
        let logUpdateInterval;

        // Load initial status
        document.addEventListener('DOMContentLoaded', function() {
            refreshStatus();
        });

        function startSync() {
            if (syncInProgress) return;
            
            syncInProgress = true;
            document.getElementById('syncBtn').disabled = true;
            document.getElementById('progressText').textContent = 'Initializing synchronization...';
            
            // Clear previous logs
            document.getElementById('logContainer').innerHTML = '';
            
            // Start progress monitoring
            startProgressMonitoring();
            
            // Start sync via server-sent events simulation
            fetch('?action=start_sync')
                .then(response => {
                    if (!response.body) {
                        throw new Error('ReadableStream not supported');
                    }
                    
                    const reader = response.body.getReader();
                    const decoder = new TextDecoder();
                    
                    function readStream() {
                        return reader.read().then(({ done, value }) => {
                            if (done) {
                                syncCompleted();
                                return;
                            }
                            
                            const text = decoder.decode(value);
                            const lines = text.split('\n');
                            
                            lines.forEach(line => {
                                line = line.trim();
                                if (!line) return;
                                
                                if (line.startsWith('SYNC_COMPLETE:')) {
                                    const result = JSON.parse(line.substring(14));
                                    handleSyncComplete(result);
                                } else if (line.startsWith('{')) {
                                    try {
                                        const progress = JSON.parse(line);
                                        updateProgress(progress);
                                    } catch (e) {
                                        addLogEntry(line);
                                    }
                                } else {
                                    addLogEntry(line);
                                }
                            });
                            
                            return readStream();
                        });
                    }
                    
                    return readStream();
                })
                .catch(error => {
                    console.error('Sync error:', error);
                    addLogEntry('ERROR: ' + error.message, 'error');
                    syncCompleted();
                });
        }

        function startProgressMonitoring() {
            logUpdateInterval = setInterval(() => {
                if (!syncInProgress) {
                    clearInterval(logUpdateInterval);
                    return;
                }
                
                fetch('?action=check_progress')
                    .then(response => response.json())
                    .then(data => {
                        if (data.status === 'success' && data.logs) {
                            // Update logs without replacing existing ones
                            const logContainer = document.getElementById('logContainer');
                            data.logs.forEach(log => {
                                if (logContainer.innerHTML.indexOf(log.trim()) === -1) {
                                    addLogEntry(log.trim());
                                }
                            });
                        }
                    })
                    .catch(error => console.error('Progress check error:', error));
            }, 2000);
        }

        function updateProgress(progress) {
            if (progress.percent !== undefined) {
                document.getElementById('progressBar').style.width = progress.percent + '%';
            }
            
            if (progress.message) {
                document.getElementById('progressText').textContent = progress.message;
                addLogEntry(`[${progress.current}/${progress.total}] ${progress.message}`);
            }
        }

        function handleSyncComplete(result) {
            document.getElementById('progressBar').style.width = '100%';
            document.getElementById('progressText').textContent = 'Synchronization completed!';
            
            addLogEntry('=== SYNCHRONIZATION COMPLETED ===', 'success');
            addLogEntry(`Status: ${result.status}`, 'success');
            addLogEntry(`Node: ${result.node}`, 'success');
            addLogEntry(`Blocks synced: ${result.blocks_synced}`, 'success');
            addLogEntry(`Transactions synced: ${result.transactions_synced}`, 'success');
            addLogEntry(`Completed at: ${result.completion_time}`, 'success');
            
            syncCompleted();
            refreshStatus();
        }

        function syncCompleted() {
            syncInProgress = false;
            document.getElementById('syncBtn').disabled = false;
            if (logUpdateInterval) {
                clearInterval(logUpdateInterval);
            }
        }

        function refreshStatus() {
            fetch('?action=get_status')
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        updateStatus(data.data);
                    } else {
                        document.getElementById('currentStatus').innerHTML = 
                            '<span class="error">Error loading status: ' + data.message + '</span>';
                    }
                })
                .catch(error => {
                    document.getElementById('currentStatus').innerHTML = 
                        '<span class="error">Error: ' + error.message + '</span>';
                });
        }

        function updateStatus(status) {
            // Update main status
            const latestBlock = status.latest_block || 0;
            const latestTime = status.latest_timestamp || 'Unknown';
            
            document.getElementById('currentStatus').innerHTML = `
                <div><strong>Latest Block:</strong> #${latestBlock}</div>
                <div><strong>Latest Timestamp:</strong> ${latestTime}</div>
                <div><strong>Last Check:</strong> ${status.sync_time}</div>
            `;
            
            // Update statistics
            Object.keys(status.tables).forEach(table => {
                const element = document.getElementById(table + '-count');
                if (element) {
                    element.textContent = status.tables[table].toLocaleString();
                }
            });
        }

        function addLogEntry(message, type = 'info') {
            const logContainer = document.getElementById('logContainer');
            const timestamp = new Date().toLocaleTimeString();
            const className = type === 'error' ? 'error' : type === 'success' ? 'success' : type === 'warning' ? 'warning' : '';
            
            const logEntry = document.createElement('div');
            logEntry.className = className;
            logEntry.textContent = `[${timestamp}] ${message}`;
            
            logContainer.appendChild(logEntry);
            logContainer.scrollTop = logContainer.scrollHeight;
        }

        function clearLogs() {
            document.getElementById('logContainer').innerHTML = '';
            document.getElementById('progressBar').style.width = '0%';
            document.getElementById('progressText').textContent = 'Ready to sync';
        }
    </script>
</body>
</html>
<?php
// Command line usage information
if (php_sapi_name() === 'cli') {
    echo "Web interface for blockchain synchronization\n";
    echo "Usage: Access via web browser or use network_sync.php directly\n";
    echo "Web URL: http://your-domain/sync_interface.php\n";
    echo "\nFor CLI usage:\n";
    echo "php network_sync.php sync    - Start synchronization\n";
    echo "php network_sync.php status  - Check status\n";
}
?>
