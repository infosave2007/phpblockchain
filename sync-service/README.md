# 🔗 Blockchain Synchronization Service

Universal blockchain synchronization service for recovery and manual sync operations.

## 📋 Features

- **Smart Synchronization**: Only syncs new data, avoiding duplicates
- **Real-time Progress**: Live progress tracking with detailed logs  
- **Web Interface**: Beautiful responsive web UI
- **Console Tool**: Command-line interface for automation
- **Recovery Ready**: Perfect for disaster recovery scenarios
- **Universal**: Works with any blockchain network configuration

## 🚀 Quick Start

### From Installation Page
After completing blockchain installation:
1. Look for "Network Synchronization" section (for regular nodes)
2. Click "Start Network Sync" button
3. Sync service will open in new tab with automatic configuration

### Web Interface
1. Open your browser and navigate to: `http://your-domain/sync-service/index.php`
2. Click "Start Synchronization"
3. Monitor progress in real-time

### Advanced Interface
Navigate to: `http://your-domain/sync-service/sync.php` for advanced options

### Console Usage
```bash
# Start full synchronization
php console.php sync

# Check database status
php console.php status

# View recent logs
php console.php logs

# Show help
php console.php help
```

## 📁 Files Structure

```
sync-service/
├── SyncManager.php    # Core synchronization logic
├── sync.php          # Web interface
├── console.php       # Command-line tool
├── style.css         # Web interface styles
├── script.js         # Web interface JavaScript
└── README.md         # This file
```

## ⚙️ Configuration

The service automatically loads configuration from:
1. `../config/installation.json` (if exists)
2. Database `config` table with key-value format (primary)
3. Legacy database `config` table format (fallback)

### Database Configuration Storage
Network nodes are stored in the database `config` table as:
- **Key**: `network.nodes` 
- **Value**: List of network nodes (one per line)
- **Key**: `node.selection_strategy`
- **Value**: Strategy for node selection

### Required Configuration
- `network_nodes`: List of network nodes (one per line)
- `node_selection_strategy`: How to select best node

Example database values:
```sql
INSERT INTO config (key_name, value) VALUES 
('network.nodes', 'https://node1.example.com\nhttps://node2.example.com'),
('node.selection_strategy', 'fastest_response');
```

Example JSON configuration:
```json
{
    "network_nodes": "https://node1.example.com\nhttps://node2.example.com",
    "node_selection_strategy": "fastest_response"
}
```

## 🔄 Synchronization Process

1. **Node Selection**: Automatically selects the fastest responding node
2. **State Check**: Examines current database state
3. **Smart Sync**: Only downloads new data since last sync
4. **Data Validation**: Ensures data integrity during sync
5. **Progress Tracking**: Real-time progress updates

### Synchronized Data

- ✅ Blocks (with parent chain validation)
- ✅ Transactions (deduplicated by hash)
- ✅ Network nodes and validators  
- ✅ Smart contracts
- ✅ Staking records
- ✅ Network configuration

## 🛠️ Usage Scenarios

### 🔧 Manual Synchronization
When you need to manually sync blockchain data:
```bash
php console.php sync
```

### 🆘 Disaster Recovery
After database corruption or server failure:
1. Restore database structure
2. Run sync service to repopulate data
3. Verify data integrity

### 🔄 Scheduled Sync
Add to crontab for regular synchronization:
```bash
# Sync every hour
0 * * * * cd /path/to/sync-service && php console.php sync
```

### 🖥️ Web Management
Use the web interface for:
- Real-time monitoring
- Progress visualization
- Log analysis
- Manual sync triggers

## 📊 Monitoring & Logs

### Log Files
- `../logs/sync_service.log` - Detailed sync operations
- Automatic log rotation and cleanup
- Color-coded console output

### Status Information
- Database record counts
- Latest block information
- Sync timestamps
- Node connectivity status

## 🔍 Troubleshooting

### Common Issues

**No network nodes configured**
```
Error: No network nodes configured
```
**Solutions:**
1. Check database config table for `network.nodes` key:
   ```sql
   SELECT * FROM config WHERE key_name = 'network.nodes';
   ```
2. If missing, add network nodes:
   ```sql
   INSERT INTO config (key_name, value, description) VALUES 
   ('network.nodes', 'https://your-node.com', 'Network nodes list for synchronization');
   ```
3. For multiple nodes, separate with newlines:
   ```sql
   UPDATE config SET value = 'https://node1.com\nhttps://node2.com' WHERE key_name = 'network.nodes';
   ```

**Database connection failed**
```
Database connection failed: SQLSTATE[HY000] [2002]
```
Solution: Check `../config/.env` database settings

**API request failed**
```
API request failed: HTTP 404
```
Solution: Verify network node URLs are correct and accessible

### Debug Mode
Enable detailed logging by checking the log files:
```bash
tail -f ../logs/sync_service.log
```

## 🔒 Security

- No sensitive data exposed in web interface
- Database credentials loaded from secure config files
- API requests use standard HTTP methods
- Input validation on all user interactions

## 🚀 Performance

- **Batch Processing**: Processes data in configurable batches
- **Memory Efficient**: Streams large datasets
- **Connection Pooling**: Reuses database connections
- **Smart Caching**: Avoids re-downloading existing data

## 📈 Scalability

- Supports networks with millions of transactions
- Handles multiple blockchain networks
- Configurable batch sizes for different server specs
- Automatic retry logic for failed requests

## 🤝 Integration

### API Endpoints (Internal)
- `?action=start_sync` - Start synchronization
- `?action=get_status` - Get current status
- `?action=check_logs` - Get recent logs
- `?action=clear_logs` - Clear log files

### Exit Codes (Console)
- `0` - Success
- `1` - Error occurred

## 📝 License

This is part of the universal blockchain platform. Use according to your project's license terms.

---

**Note**: This service is designed to be network-agnostic and will work with any properly configured blockchain network. No hardcoded domains or network-specific code.
