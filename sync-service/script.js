/**
 * Blockchain Sync Service JavaScript
 * Handles UI interactions and real-time updates
 */

let syncInProgress = false;
let logUpdateInterval = null;
let autoScroll = true;

// Initialize when page loads
document.addEventListener('DOMContentLoaded', function() {
    console.log('Blockchain Sync Service initialized');
    refreshStatus();
    refreshLogs();
});

/**
 * Start blockchain synchronization
 */
async function startSync() {
    if (syncInProgress) {
        console.log('Sync already in progress');
        return;
    }
    
    syncInProgress = true;
    
    // Update UI
    const syncBtn = document.getElementById('syncBtn');
    const progressText = document.getElementById('progressText');
    const logContainer = document.getElementById('logContainer');
    
    syncBtn.disabled = true;
    syncBtn.innerHTML = '<span class="btn-icon">⏳</span> Synchronizing...';
    progressText.textContent = 'Initializing synchronization...';
    
    // Clear previous logs
    logContainer.innerHTML = '<div class="log-placeholder">Starting synchronization...</div>';
    
    // Start progress monitoring
    startProgressMonitoring();
    
    try {
        // Start sync with streaming response
        const response = await fetch('?action=start_sync');
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        
        // Read streaming response
        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        
        while (true) {
            const { done, value } = await reader.read();
            
            if (done) {
                console.log('Sync stream completed');
                break;
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
                        if (progress.type === 'progress') {
                            updateProgress(progress);
                        }
                    } catch (e) {
                        addLogEntry(line, 'info');
                    }
                } else {
                    addLogEntry(line, 'info');
                }
            });
        }
        
    } catch (error) {
        console.error('Sync error:', error);
        addLogEntry('ERROR: ' + error.message, 'error');
        handleSyncError(error);
    }
}

/**
 * Handle sync completion
 */
function handleSyncComplete(result) {
    console.log('Sync completed:', result);
    
    // Update progress to 100%
    updateProgress({ percent: 100, message: 'Synchronization completed successfully!' });
    
    // Add completion logs
    addLogEntry('=== SYNCHRONIZATION COMPLETED ===', 'success');
    addLogEntry(`Status: ${result.status}`, 'success');
    addLogEntry(`Node: ${result.node}`, 'info');
    addLogEntry(`New blocks: ${result.new_blocks}`, 'info');
    addLogEntry(`New transactions: ${result.new_transactions}`, 'info');
    addLogEntry(`New nodes: ${result.new_nodes}`, 'info');
    addLogEntry(`New contracts: ${result.new_contracts}`, 'info');
    addLogEntry(`New staking records: ${result.new_staking}`, 'info');
    addLogEntry(`Total new records: ${result.total_new_records}`, 'success');
    addLogEntry(`Sync time: ${result.sync_time}s`, 'info');
    addLogEntry(`Completed at: ${result.completion_time}`, 'info');
    
    // Reset UI
    syncCompleted();
    
    // Refresh status to show updated counts
    setTimeout(refreshStatus, 1000);
}

/**
 * Handle sync error
 */
function handleSyncError(error) {
    console.error('Sync error:', error);
    
    addLogEntry('=== SYNCHRONIZATION FAILED ===', 'error');
    addLogEntry(`Error: ${error.message}`, 'error');
    
    // Reset UI
    syncCompleted();
}

/**
 * Reset UI after sync completion/error
 */
function syncCompleted() {
    syncInProgress = false;
    
    const syncBtn = document.getElementById('syncBtn');
    const progressText = document.getElementById('progressText');
    
    syncBtn.disabled = false;
    syncBtn.innerHTML = '<span class="btn-icon">▶️</span> Start Synchronization';
    progressText.textContent = 'Ready to sync';
    
    // Stop progress monitoring
    if (logUpdateInterval) {
        clearInterval(logUpdateInterval);
        logUpdateInterval = null;
    }
}

/**
 * Update progress bar and text
 */
function updateProgress(progress) {
    const progressBar = document.getElementById('progressBar');
    const progressText = document.getElementById('progressText');
    
    if (progress.percent !== undefined) {
        progressBar.style.width = progress.percent + '%';
    }
    
    if (progress.message) {
        progressText.textContent = progress.message;
        addLogEntry(`[Progress] ${progress.message}`, 'info');
    }
}

/**
 * Start monitoring progress through logs
 */
function startProgressMonitoring() {
    logUpdateInterval = setInterval(() => {
        if (!syncInProgress) {
            clearInterval(logUpdateInterval);
            logUpdateInterval = null;
            return;
        }
        
        refreshLogs();
    }, 2000); // Check every 2 seconds
}

/**
 * Refresh current status
 */
async function refreshStatus() {
    try {
        const response = await fetch('?action=get_status');
        const data = await response.json();
        
        if (data.status === 'success') {
            updateStatusDisplay(data.data);
        } else {
            console.error('Status error:', data.message);
            document.getElementById('currentStatus').innerHTML = 
                '<div class="text-error">Error loading status: ' + data.message + '</div>';
        }
        
    } catch (error) {
        console.error('Status fetch error:', error);
        document.getElementById('currentStatus').innerHTML = 
            '<div class="text-error">Error: ' + error.message + '</div>';
    }
}

/**
 * Update status display
 */
function updateStatusDisplay(status) {
    const currentStatus = document.getElementById('currentStatus');
    
    // Format latest block info
    const latestBlock = status.latest_block || 0;
    const latestTime = status.latest_timestamp || 'Unknown';
    
    currentStatus.innerHTML = `
        <div><strong>Latest Block:</strong> #${latestBlock.toLocaleString()}</div>
        <div><strong>Latest Timestamp:</strong> ${latestTime}</div>
        <div><strong>Last Check:</strong> ${status.sync_time}</div>
    `;
    
    // Update statistics
    if (status.tables) {
        Object.keys(status.tables).forEach(table => {
            const element = document.getElementById(table + '-count');
            if (element) {
                element.textContent = status.tables[table].toLocaleString();
            }
        });
    }
}

/**
 * Refresh logs
 */
async function refreshLogs() {
    try {
        const response = await fetch('?action=check_logs');
        const data = await response.json();
        
        if (data.status === 'success' && data.logs) {
            const logContainer = document.getElementById('logContainer');
            
            // Clear placeholder if it exists
            if (logContainer.querySelector('.log-placeholder')) {
                logContainer.innerHTML = '';
            }
            
            // Add new logs (avoid duplicates)
            data.logs.forEach(log => {
                const logText = log.trim();
                if (logText && !isLogDuplicate(logText)) {
                    addLogEntry(logText, getLogType(logText));
                }
            });
        }
        
    } catch (error) {
        console.error('Log refresh error:', error);
    }
}

/**
 * Check if log entry already exists
 */
function isLogDuplicate(logText) {
    const logContainer = document.getElementById('logContainer');
    const existingLogs = logContainer.querySelectorAll('.log-entry');
    
    for (let entry of existingLogs) {
        if (entry.textContent.includes(logText.substring(20))) { // Skip timestamp
            return true;
        }
    }
    
    return false;
}

/**
 * Determine log entry type based on content
 */
function getLogType(logText) {
    const lowerText = logText.toLowerCase();
    
    if (lowerText.includes('error') || lowerText.includes('failed')) {
        return 'error';
    } else if (lowerText.includes('warning') || lowerText.includes('warn')) {
        return 'warning';
    } else if (lowerText.includes('success') || lowerText.includes('completed') || lowerText.includes('synced')) {
        return 'success';
    } else {
        return 'info';
    }
}

/**
 * Add log entry to container
 */
function addLogEntry(message, type = 'info') {
    const logContainer = document.getElementById('logContainer');
    const timestamp = new Date().toLocaleTimeString();
    
    // Clear placeholder if it exists
    const placeholder = logContainer.querySelector('.log-placeholder');
    if (placeholder) {
        placeholder.remove();
    }
    
    const logEntry = document.createElement('div');
    logEntry.className = `log-entry ${type}`;
    logEntry.textContent = `[${timestamp}] ${message}`;
    
    logContainer.appendChild(logEntry);
    
    // Auto-scroll if enabled
    if (autoScroll) {
        logContainer.scrollTop = logContainer.scrollHeight;
    }
    
    // Limit log entries to prevent memory issues
    const maxLogs = 500;
    const entries = logContainer.querySelectorAll('.log-entry');
    if (entries.length > maxLogs) {
        entries[0].remove();
    }
}

/**
 * Clear all logs
 */
async function clearLogs() {
    if (confirm('Are you sure you want to clear all logs?')) {
        try {
            const response = await fetch('?action=clear_logs');
            const data = await response.json();
            
            if (data.status === 'success') {
                const logContainer = document.getElementById('logContainer');
                logContainer.innerHTML = '<div class="log-placeholder">Logs cleared. New logs will appear here...</div>';
                
                // Reset progress bar
                document.getElementById('progressBar').style.width = '0%';
                document.getElementById('progressText').textContent = 'Ready to sync';
                
                addLogEntry('Logs cleared successfully', 'info');
            } else {
                addLogEntry('Failed to clear logs: ' + data.message, 'error');
            }
            
        } catch (error) {
            console.error('Clear logs error:', error);
            addLogEntry('Error clearing logs: ' + error.message, 'error');
        }
    }
}

/**
 * Toggle auto-scroll for logs
 */
function autoScrollToggle() {
    autoScroll = !autoScroll;
    const statusElement = document.getElementById('autoScrollStatus');
    statusElement.textContent = autoScroll ? 'ON' : 'OFF';
    
    addLogEntry(`Auto-scroll ${autoScroll ? 'enabled' : 'disabled'}`, 'info');
}

/**
 * Format numbers with separators
 */
function formatNumber(num) {
    return num.toLocaleString();
}

/**
 * Format timestamps
 */
function formatTimestamp(timestamp) {
    if (!timestamp) return 'Unknown';
    
    const date = new Date(timestamp * 1000);
    return date.toLocaleString();
}

/**
 * Show notification (if supported)
 */
function showNotification(title, message, type = 'info') {
    if ('Notification' in window && Notification.permission === 'granted') {
        new Notification(title, {
            body: message,
            icon: type === 'error' ? '❌' : type === 'success' ? '✅' : 'ℹ️'
        });
    }
}

// Request notification permission on load
if ('Notification' in window && Notification.permission === 'default') {
    Notification.requestPermission();
}
