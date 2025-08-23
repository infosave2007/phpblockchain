<?php
/**
 * Enhanced Sync Manager CLI Tool
 * Monitor and control the improved event-driven synchronization system
 * Provides real-time statistics, health monitoring, and management commands
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../core/Database/DatabaseManager.php';
require_once __DIR__ . '/../core/Events/EventDispatcher.php';
require_once __DIR__ . '/../core/Logging/NullLogger.php';
require_once __DIR__ . '/../core/Sync/EnhancedEventSync.php';

use Blockchain\Core\Database\DatabaseManager;
use Blockchain\Core\Events\EventDispatcher;
use Blockchain\Core\Logging\NullLogger;
use Blockchain\Core\Sync\EnhancedEventSync;

class SyncManager
{
    private PDO $pdo;
    private EnhancedEventSync $enhancedSync;
    
    public function __construct()
    {
        $this->pdo = DatabaseManager::getConnection();
        $eventDispatcher = new EventDispatcher(new NullLogger());
        $this->enhancedSync = new EnhancedEventSync($eventDispatcher, new NullLogger());
    }
    
    /**
     * Display real-time sync status dashboard
     */
    public function showDashboard(): void
    {
        while (true) {
            $this->clearScreen();
            echo "🔄 Enhanced Blockchain Sync Manager Dashboard\n";
            echo "=" . str_repeat("=", 60) . "\n\n";
            
            $this->displaySyncStatus();
            $this->displayNetworkHealth();
            $this->displayPerformanceMetrics();
            $this->displayRecentEvents();
            
            echo "\n📋 Commands: [q]uit, [r]efresh, [s]tats, [n]odes, [f]lush\n";
            echo "Refreshing in 5s (Press any key for menu)...\n";
            
            // Non-blocking input check
            stream_set_blocking(STDIN, false);
            $input = fgets(STDIN);
            stream_set_blocking(STDIN, true);
            
            if ($input !== false) {
                $cmd = strtolower(trim($input));
                if ($cmd === 'q') {
                    break;
                } elseif ($cmd === 's') {
                    $this->showDetailedStats();
                } elseif ($cmd === 'n') {
                    $this->showNodeStatus();
                } elseif ($cmd === 'f') {
                    $this->flushEvents();
                } elseif ($cmd !== 'r' && $cmd !== '') {
                    $this->showMenu();
                }
            } else {
                sleep(5);
            }
        }
    }
    
    /**
     * Display current synchronization status
     */
    private function displaySyncStatus(): void
    {
        try {
            $stmt = $this->pdo->query("SELECT MAX(height) as local_height FROM blocks");
            $localHeight = (int)$stmt->fetchColumn();
            
            $stmt = $this->pdo->query("
                SELECT AVG(metric_value) as avg_network_height 
                FROM broadcast_stats 
                WHERE metric_type = 'node_block_height' 
                AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
            ");
            $networkHeight = (int)$stmt->fetchColumn();
            
            $stmt = $this->pdo->query("SELECT COUNT(*) FROM mempool WHERE status = 'pending'");
            $mempoolSize = (int)$stmt->fetchColumn();
            
            $syncHealth = $networkHeight > 0 ? (($localHeight / $networkHeight) * 100) : 100;
            $healthColor = $syncHealth >= 95 ? "🟢" : ($syncHealth >= 80 ? "🟡" : "🔴");
            
            echo "📊 Sync Status:\n";
            echo "  Local Height: {$localHeight} blocks\n";
            echo "  Network Height: {$networkHeight} blocks\n";
            echo "  Sync Health: {$healthColor} " . number_format($syncHealth, 1) . "%\n";
            echo "  Mempool Size: {$mempoolSize} transactions\n";
            echo "  Status: " . ($syncHealth >= 95 ? "✅ Synchronized" : "⚠️ Synchronizing") . "\n\n";
            
        } catch (Exception $e) {
            echo "❌ Error getting sync status: " . $e->getMessage() . "\n\n";
        }
    }
    
    /**
     * Display network health information
     */
    private function displayNetworkHealth(): void
    {
        try {
            $stmt = $this->pdo->query("
                SELECT 
                    COUNT(DISTINCT node_id) as active_nodes,
                    AVG(CASE WHEN metric_type = 'events_sent_success' THEN metric_value END) as avg_success,
                    AVG(CASE WHEN metric_type = 'events_sent_failed' THEN metric_value END) as avg_failures,
                    AVG(CASE WHEN metric_type = 'response_time' THEN metric_value END) as avg_response_time
                FROM broadcast_stats 
                WHERE created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
            ");
            
            $health = $stmt->fetch(PDO::FETCH_ASSOC);
            $activeNodes = (int)($health['active_nodes'] ?? 0);
            $successRate = $health['avg_success'] ?? 0;
            $failures = $health['avg_failures'] ?? 0;
            $responseTime = $health['avg_response_time'] ?? 0;
            
            $totalRequests = $successRate + $failures;
            $reliabilityPercent = $totalRequests > 0 ? (($successRate / $totalRequests) * 100) : 0;
            
            echo "🌐 Network Health:\n";
            echo "  Active Nodes: {$activeNodes}\n";
            echo "  Reliability: " . number_format($reliabilityPercent, 1) . "%\n";
            echo "  Avg Response: " . number_format($responseTime, 3) . "s\n";
            echo "  Network Status: " . $this->getNetworkStatus($activeNodes, $reliabilityPercent) . "\n\n";
            
        } catch (Exception $e) {
            echo "❌ Error getting network health: " . $e->getMessage() . "\n\n";
        }
    }
    
    /**
     * Display performance metrics
     */
    private function displayPerformanceMetrics(): void
    {
        try {
            $queueStatus = $this->enhancedSync->getQueueStatus();
            $performanceMetrics = $this->enhancedSync->getPerformanceMetrics();
            
            echo "⚡ Performance Metrics:\n";
            echo "  Pending Events: {$queueStatus['pending_events']}\n";
            echo "  Processed Events: {$queueStatus['processed_events']}\n";
            echo "  Failed Nodes: {$queueStatus['failed_nodes']}\n";
            
            if (!empty($performanceMetrics)) {
                $eventsPerSecond = $performanceMetrics['events_per_second'] ?? 0;
                $avgResponseTime = $performanceMetrics['average_response_time'] ?? 0;
                $memoryUsage = ($performanceMetrics['peak_memory_usage'] ?? 0) / 1024 / 1024; // MB
                
                echo "  Events/sec: " . number_format($eventsPerSecond, 2) . "\n";
                echo "  Avg Response: " . number_format($avgResponseTime, 3) . "s\n";
                echo "  Memory Peak: " . number_format($memoryUsage, 1) . " MB\n";
            }
            echo "\n";
            
        } catch (Exception $e) {
            echo "❌ Error getting performance metrics: " . $e->getMessage() . "\n\n";
        }
    }
    
    /**
     * Display recent events
     */
    private function displayRecentEvents(): void
    {
        try {
            $stmt = $this->pdo->query("
                SELECT 
                    event_type, 
                    COUNT(*) as count,
                    MAX(created_at) as last_event
                FROM sync_monitoring 
                WHERE created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)
                GROUP BY event_type
                ORDER BY last_event DESC
                LIMIT 5
            ");
            
            $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo "📝 Recent Events (10 min):\n";
            if (empty($events)) {
                echo "  No recent events\n";
            } else {
                foreach ($events as $event) {
                    $time = date('H:i:s', strtotime($event['last_event']));
                    echo "  {$time} - {$event['event_type']}: {$event['count']} events\n";
                }
            }
            echo "\n";
            
        } catch (Exception $e) {
            echo "❌ Error getting recent events: " . $e->getMessage() . "\n\n";
        }
    }
    
    /**
     * Show detailed statistics
     */
    public function showDetailedStats(): void
    {
        $this->clearScreen();
        echo "📈 Detailed Statistics\n";
        echo "=" . str_repeat("=", 50) . "\n\n";
        
        // Hourly event statistics
        try {
            echo "📊 Event Statistics (24 hours):\n";
            $stmt = $this->pdo->query("
                SELECT 
                    event_type,
                    COUNT(*) as total_events,
                    COUNT(CASE WHEN created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR) THEN 1 END) as recent_events
                FROM sync_monitoring 
                WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                GROUP BY event_type
                ORDER BY total_events DESC
            ");
            
            $stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($stats as $stat) {
                echo sprintf("  %-20s: %5d total, %3d recent\n", 
                    $stat['event_type'], $stat['total_events'], $stat['recent_events']);
            }
            
            echo "\n📡 Network Statistics:\n";
            $stmt = $this->pdo->query("
                SELECT 
                    metric_type,
                    COUNT(*) as count,
                    AVG(metric_value) as avg_value,
                    MAX(metric_value) as max_value
                FROM broadcast_stats 
                WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
                AND metric_type IN ('events_sent_success', 'events_sent_failed', 'response_time')
                GROUP BY metric_type
            ");
            
            $networkStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($networkStats as $stat) {
                echo sprintf("  %-20s: %5d events, avg %.3f, max %.3f\n", 
                    $stat['metric_type'], $stat['count'], $stat['avg_value'], $stat['max_value']);
            }
            
        } catch (Exception $e) {
            echo "❌ Error getting statistics: " . $e->getMessage() . "\n";
        }
        
        echo "\nPress any key to return...";
        fgets(STDIN);
    }
    
    /**
     * Show node status
     */
    public function showNodeStatus(): void
    {
        $this->clearScreen();
        echo "🌐 Node Status\n";
        echo "=" . str_repeat("=", 50) . "\n\n";
        
        try {
            $stmt = $this->pdo->query("
                SELECT 
                    ip_address,
                    port,
                    status,
                    reputation_score,
                    last_seen,
                    JSON_EXTRACT(metadata, '$.domain') as domain
                FROM nodes 
                ORDER BY status DESC, reputation_score DESC
                LIMIT 20
            ");
            
            $nodes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo sprintf("%-20s %-6s %-10s %-4s %-20s\n", 
                "NODE", "PORT", "STATUS", "REP", "LAST SEEN");
            echo str_repeat("-", 70) . "\n";
            
            foreach ($nodes as $node) {
                $address = $node['domain'] ?: $node['ip_address'];
                $port = $node['port'];
                $status = $node['status'];
                $reputation = $node['reputation_score'];
                $lastSeen = $node['last_seen'] ? 
                    date('H:i:s', strtotime($node['last_seen'])) : 'Never';
                
                $statusIcon = $status === 'active' ? '🟢' : '🔴';
                
                echo sprintf("%s %-18s %-6s %-10s %-4d %-20s\n", 
                    $statusIcon, $address, $port, $status, $reputation, $lastSeen);
            }
            
        } catch (Exception $e) {
            echo "❌ Error getting node status: " . $e->getMessage() . "\n";
        }
        
        echo "\nPress any key to return...";
        fgets(STDIN);
    }
    
    /**
     * Flush pending events
     */
    public function flushEvents(): void
    {
        echo "\n🚀 Flushing pending events...\n";
        
        try {
            $result = $this->enhancedSync->flushEventQueue();
            
            if ($result) {
                echo "✅ Events flushed successfully\n";
            } else {
                echo "⚠️ No pending events to flush\n";
            }
            
        } catch (Exception $e) {
            echo "❌ Error flushing events: " . $e->getMessage() . "\n";
        }
        
        echo "Press any key to continue...";
        fgets(STDIN);
    }
    
    /**
     * Show interactive menu
     */
    public function showMenu(): void
    {
        $this->clearScreen();
        echo "🔧 Sync Manager Menu\n";
        echo "=" . str_repeat("=", 30) . "\n\n";
        echo "1. Dashboard (real-time)\n";
        echo "2. Detailed Statistics\n";
        echo "3. Node Status\n";
        echo "4. Flush Events\n";
        echo "5. Trigger Manual Sync\n";
        echo "6. Export Logs\n";
        echo "7. System Health Check\n";
        echo "0. Exit\n\n";
        echo "Choose option: ";
        
        $choice = trim(fgets(STDIN));
        
        switch ($choice) {
            case '1':
                $this->showDashboard();
                break;
            case '2':
                $this->showDetailedStats();
                break;
            case '3':
                $this->showNodeStatus();
                break;
            case '4':
                $this->flushEvents();
                break;
            case '5':
                $this->triggerManualSync();
                break;
            case '6':
                $this->exportLogs();
                break;
            case '7':
                $this->healthCheck();
                break;
            case '0':
                echo "Goodbye!\n";
                break;
            default:
                echo "Invalid option. Press any key to continue...";
                fgets(STDIN);
                $this->showMenu();
        }
    }
    
    /**
     * Trigger manual synchronization
     */
    public function triggerManualSync(): void
    {
        echo "\n🔄 Triggering manual sync...\n";
        
        try {
            // Get network height
            $stmt = $this->pdo->query("
                SELECT MAX(CAST(metric_value AS UNSIGNED)) as max_height 
                FROM broadcast_stats 
                WHERE metric_type = 'node_block_height' 
                AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
            ");
            $networkHeight = (int)$stmt->fetchColumn();
            
            $stmt = $this->pdo->query("SELECT MAX(height) FROM blocks");
            $localHeight = (int)$stmt->fetchColumn();
            
            if ($networkHeight > $localHeight) {
                echo "📊 Local: {$localHeight}, Network: {$networkHeight}\n";
                echo "🚀 Starting sync process...\n";
                
                // Trigger sync event
                $this->enhancedSync->processEvent('sync.manual_trigger', [
                    'local_height' => $localHeight,
                    'network_height' => $networkHeight,
                    'trigger_time' => time()
                ], EnhancedEventSync::PRIORITY_HIGH);
                
                echo "✅ Sync triggered successfully\n";
            } else {
                echo "✅ Already synchronized\n";
            }
            
        } catch (Exception $e) {
            echo "❌ Error triggering sync: " . $e->getMessage() . "\n";
        }
        
        echo "Press any key to continue...";
        fgets(STDIN);
    }
    
    /**
     * Export logs for analysis
     */
    public function exportLogs(): void
    {
        echo "\n📄 Exporting logs...\n";
        
        try {
            $logDir = __DIR__ . '/../logs';
            $exportFile = $logDir . '/sync_export_' . date('Y-m-d_H-i-s') . '.json';
            
            // Collect various log data
            $exportData = [
                'export_time' => date('c'),
                'sync_events' => [],
                'broadcast_stats' => [],
                'node_status' => []
            ];
            
            // Get recent sync events
            $stmt = $this->pdo->query("
                SELECT * FROM sync_monitoring 
                WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                ORDER BY created_at DESC
                LIMIT 1000
            ");
            $exportData['sync_events'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get broadcast statistics
            $stmt = $this->pdo->query("
                SELECT * FROM broadcast_stats 
                WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                ORDER BY created_at DESC
                LIMIT 1000
            ");
            $exportData['broadcast_stats'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get node status
            $stmt = $this->pdo->query("SELECT * FROM nodes");
            $exportData['node_status'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            file_put_contents($exportFile, json_encode($exportData, JSON_PRETTY_PRINT));
            
            echo "✅ Logs exported to: {$exportFile}\n";
            echo "📊 Records exported: " . 
                 count($exportData['sync_events']) . " events, " .
                 count($exportData['broadcast_stats']) . " stats, " .
                 count($exportData['node_status']) . " nodes\n";
            
        } catch (Exception $e) {
            echo "❌ Error exporting logs: " . $e->getMessage() . "\n";
        }
        
        echo "Press any key to continue...";
        fgets(STDIN);
    }
    
    /**
     * Perform system health check
     */
    public function healthCheck(): void
    {
        echo "\n🏥 System Health Check\n";
        echo "=" . str_repeat("=", 30) . "\n\n";
        
        $issues = [];
        
        // Check database connectivity
        echo "🔍 Checking database connectivity... ";
        try {
            $this->pdo->query("SELECT 1");
            echo "✅ OK\n";
        } catch (Exception $e) {
            echo "❌ FAILED: " . $e->getMessage() . "\n";
            $issues[] = "Database connectivity issue";
        }
        
        // Check event queue health
        echo "🔍 Checking event queue... ";
        try {
            $queueStatus = $this->enhancedSync->getQueueStatus();
            $pendingEvents = $queueStatus['pending_events'];
            
            if ($pendingEvents < 100) {
                echo "✅ OK ({$pendingEvents} pending)\n";
            } else {
                echo "⚠️ WARNING: High queue ({$pendingEvents} pending)\n";
                $issues[] = "High event queue backlog";
            }
        } catch (Exception $e) {
            echo "❌ FAILED: " . $e->getMessage() . "\n";
            $issues[] = "Event queue check failed";
        }
        
        // Check network connectivity
        echo "🔍 Checking network nodes... ";
        try {
            $stmt = $this->pdo->query("
                SELECT COUNT(*) FROM nodes WHERE status = 'active'
            ");
            $activeNodes = (int)$stmt->fetchColumn();
            
            if ($activeNodes >= 3) {
                echo "✅ OK ({$activeNodes} active)\n";
            } elseif ($activeNodes >= 1) {
                echo "⚠️ WARNING: Few nodes ({$activeNodes} active)\n";
                $issues[] = "Limited network connectivity";
            } else {
                echo "❌ CRITICAL: No active nodes\n";
                $issues[] = "No network connectivity";
            }
        } catch (Exception $e) {
            echo "❌ FAILED: " . $e->getMessage() . "\n";
            $issues[] = "Network check failed";
        }
        
        // Check sync health
        echo "🔍 Checking sync status... ";
        try {
            $stmt = $this->pdo->query("
                SELECT 
                    COUNT(CASE WHEN event_type = 'sync_failed' THEN 1 END) as failures,
                    COUNT(*) as total
                FROM sync_monitoring 
                WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ");
            $syncStats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $failureRate = $syncStats['total'] > 0 ? 
                ($syncStats['failures'] / $syncStats['total']) * 100 : 0;
            
            if ($failureRate < 10) {
                echo "✅ OK ({$failureRate}% failure rate)\n";
            } else {
                echo "⚠️ WARNING: High failure rate ({$failureRate}%)\n";
                $issues[] = "High sync failure rate";
            }
        } catch (Exception $e) {
            echo "❌ FAILED: " . $e->getMessage() . "\n";
            $issues[] = "Sync health check failed";
        }
        
        echo "\n📋 Summary:\n";
        if (empty($issues)) {
            echo "✅ System is healthy\n";
        } else {
            echo "⚠️ Issues detected:\n";
            foreach ($issues as $issue) {
                echo "  • {$issue}\n";
            }
        }
        
        echo "\nPress any key to continue...";
        fgets(STDIN);
    }
    
    /**
     * Helper methods
     */
    private function clearScreen(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            system('cls');
        } else {
            system('clear');
        }
    }
    
    private function getNetworkStatus(int $nodes, float $reliability): string
    {
        if ($nodes >= 5 && $reliability >= 90) {
            return "🟢 Excellent";
        } elseif ($nodes >= 3 && $reliability >= 80) {
            return "🟡 Good";
        } elseif ($nodes >= 1 && $reliability >= 60) {
            return "🟠 Fair";
        } else {
            return "🔴 Poor";
        }
    }
}

// CLI entry point
if (php_sapi_name() === 'cli') {
    $manager = new SyncManager();
    
    if ($argc > 1) {
        $command = strtolower($argv[1]);
        
        switch ($command) {
            case 'dashboard':
            case 'dash':
                $manager->showDashboard();
                break;
                
            case 'stats':
                $manager->showDetailedStats();
                break;
                
            case 'nodes':
                $manager->showNodeStatus();
                break;
                
            case 'flush':
                $manager->flushEvents();
                break;
                
            case 'health':
                $manager->healthCheck();
                break;
                
            case 'sync':
                $manager->triggerManualSync();
                break;
                
            case 'export':
                $manager->exportLogs();
                break;
                
            default:
                echo "Unknown command: {$command}\n";
                echo "Available commands: dashboard, stats, nodes, flush, health, sync, export\n";
                exit(1);
        }
    } else {
        $manager->showMenu();
    }
} else {
    echo "This tool must be run from the command line.\n";
    exit(1);
}