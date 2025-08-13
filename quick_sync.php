#!/usr/bin/env php
<?php
/**
 * Quick Sync Script
 * Simple script for emergency blockchain synchronization
 */

require_once __DIR__ . '/network_sync.php';

// Add necessary uses for inline raw queue processing
use Blockchain\Core\Transaction\Transaction;
use Blockchain\Core\Transaction\MempoolManager;
use Blockchain\Core\Transaction\FeePolicy;
use Blockchain\Core\Crypto\EthereumTx;

function showUsage() {
    echo "Blockchain Quick Sync Tool\n";
    echo "==========================\n\n";
    echo "Usage: php quick_sync.php [command] [options]\n\n";
    echo "Commands:\n";
    echo "  sync           - Start full synchronization\n";
    echo "  enhanced-sync  - Enhanced sync with mempool processing and recovery\n";
    echo "  status         - Show current database status\n";
    echo "  check          - Quick network connectivity check\n";
    echo "  repair         - Repair and re-sync missing data\n";
    echo "  mempool-status - Show detailed mempool status\n";
    echo "  help           - Show this help message\n\n";
    echo "Options:\n";
    echo "  --verbose  - Show detailed output\n";
    echo "  --quiet    - Minimal output\n";
    echo "  --force    - Force sync even if up-to-date\n\n";
    echo "Examples:\n";
    echo "  php quick_sync.php sync --verbose\n";
    echo "  php quick_sync.php enhanced-sync\n";
    echo "  php quick_sync.php status\n";
    echo "  php quick_sync.php repair --force\n\n";
}

function formatBytes($bytes, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    
    for ($i = 0; $bytes > 1024; $i++) {
        $bytes /= 1024;
    }
    
    return round($bytes, $precision) . ' ' . $units[$i];
}

function showStatus($syncManager, $verbose = false) {
    echo "📊 Blockchain Database Status\n";
    echo "============================\n\n";
    
    $status = $syncManager->getStatus();
    
    // Show table statistics
    $totalRecords = 0;
    foreach ($status['tables'] as $table => $count) {
        $totalRecords += $count;
        printf("%-20s: %s records\n", 
            ucfirst(str_replace('_', ' ', $table)), 
            number_format($count)
        );
    }
    
    echo "\n";
    printf("Total records: %s\n", number_format($totalRecords));
    printf("Latest block: #%s\n", $status['latest_block']);
    printf("Latest timestamp: %s\n", $status['latest_timestamp'] ?? 'Unknown');
    
    if ($verbose) {
        echo "\n📈 Additional Information\n";
        echo "========================\n";
        
        // Memory usage
        printf("Memory usage: %s\n", formatBytes(memory_get_usage(true)));
        printf("Peak memory: %s\n", formatBytes(memory_get_peak_usage(true)));
        
        // Log file size
        $logFile = 'logs/network_sync.log';
        if (file_exists($logFile)) {
            printf("Log file size: %s\n", formatBytes(filesize($logFile)));
        }
    }
}

function showMempoolStatus($syncManager, $verbose = false) {
    echo "🧹 Mempool Status\n";
    echo "=================\n\n";
    
    try {
        // Get pdo safely
        $reflection = new ReflectionClass($syncManager);
        if ($reflection->hasProperty('pdo')) {
            $pdoProperty = $reflection->getProperty('pdo');
            $pdoProperty->setAccessible(true);
            $pdo = $pdoProperty->getValue($syncManager);
            
            // Local mempool status
            $stmt = $pdo->prepare("
                SELECT status, COUNT(*) as count 
                FROM mempool 
                GROUP BY status
            ");
            $stmt->execute();
            $mempoolStats = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $mempoolStats[$row['status']] = $row['count'];
            }
            
            echo "📊 Local Mempool:\n";
            if (empty($mempoolStats)) {
                echo "  No transactions in mempool\n";
            } else {
                foreach ($mempoolStats as $status => $count) {
                    printf("  %-10s: %s transactions\n", ucfirst($status), number_format($count));
                }
            }
            
            // Network mempool comparison
            if ($verbose) {
                echo "\n🌐 Network Mempool Comparison:\n";
                
                // Get nodes from database instead of hardcoding
                $stmt = $pdo->query("SELECT node_id, ip_address, port, metadata FROM nodes WHERE status = 'active'");
                $nodeRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($nodeRows as $row) {
                    $metadata = json_decode($row['metadata'], true);
                    $domain = $metadata['domain'] ?? $row['ip_address'];
                    $protocol = $metadata['protocol'] ?? 'https';
                    $node = "$protocol://$domain";
                    
                    try {
                        $url = rtrim($node, '/') . '/api/explorer/index.php?action=get_mempool';
                        $response = $syncManager->makeApiCall($url);
                        
                        if ($response && isset($response['total'])) {
                            printf("  %-30s: %s pending\n", $node, $response['total']);
                        } else {
                            printf("  %-30s: Unable to fetch\n", $node);
                        }
                    } catch (Exception $e) {
                        printf("  %-30s: Error - %s\n", $node, $e->getMessage());
                    }
                }
            }
            
            // Recent mempool activity
            $stmt = $pdo->prepare("
                SELECT 
                    status,
                    COUNT(*) as count,
                    MIN(created_at) as earliest,
                    MAX(created_at) as latest
                FROM mempool 
                WHERE created_at > NOW() - INTERVAL 1 HOUR
                GROUP BY status
            ");
            $stmt->execute();
            $recentActivity = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($recentActivity)) {
                echo "\n📈 Recent Activity (Last Hour):\n";
                foreach ($recentActivity as $activity) {
                    printf("  %s: %s transactions (from %s to %s)\n", 
                        ucfirst($activity['status']), 
                        $activity['count'],
                        $activity['earliest'],
                        $activity['latest']
                    );
                }
            }
        }
        
    } catch (Exception $e) {
        echo "❌ Error retrieving mempool status: " . $e->getMessage() . "\n";
    }
}

function checkConnectivity($syncManager) {
    echo "🌐 Network Connectivity Check\n";
    echo "=============================\n\n";
    
    try {
        // This is a simplified check - we'll use the selectBestNode method
        $reflection = new ReflectionClass($syncManager);
        $method = $reflection->getMethod('selectBestNode');
        $method->setAccessible(true);
        
        $bestNode = $method->invoke($syncManager);
        echo "✅ Successfully connected to: $bestNode\n";
        echo "🔗 Network is accessible and responsive\n";
        
    } catch (Exception $e) {
        echo "❌ Network check failed: " . $e->getMessage() . "\n";
        echo "🔧 Try checking your internet connection or network configuration\n";
        return false;
    }
    
    return true;
}

function performSync($syncManager, $options = []) {
    $verbose = in_array('--verbose', $options);
    $quiet = in_array('--quiet', $options);
    $force = in_array('--force', $options);
    
    if (!$quiet) {
        echo "🚀 Starting Enhanced Blockchain Synchronization\n";
        echo "================================================\n\n";
        
        if ($force) {
            echo "⚠️  Force mode enabled - will re-sync all data\n\n";
        }
    }
    
    try {
        $startTime = microtime(true);
        
        // Step 1: Check current status before sync
        if ($verbose) {
            echo "📊 Pre-sync status check...\n";
            $preStatus = $syncManager->getStatus();
            echo "Current blocks: " . $preStatus['latest_block'] . "\n";
            echo "Current transactions: " . $preStatus['tables']['transactions'] . "\n";
        }
        
        // Step 2: Perform main synchronization
        if (!$quiet) echo "🔄 Performing blockchain synchronization...\n";
        $result = $syncManager->syncAll();

        // Step 2.5: Inline processing of raw Ethereum-style queued transactions (storage/raw_mempool)
        if (in_array($command ?? '', ['enhanced-sync','sync']) || $verbose) {
            if (!$quiet) echo "📥 Processing raw transaction queue (inline)...\n";
            try {
                // Temporarily force verbose raw queue diagnostics (override quiet)
                processRawQueueInline($syncManager, false, true);
            } catch (Exception $e) {
                if (!$quiet) echo "Raw queue processing error: " . $e->getMessage() . "\n";
            }
        }
        
        // Step 3: Enhanced mempool processing and recovery
        if (!$quiet) echo "🧹 Processing mempool and pending transactions...\n";
        
        try {
            // Check for pending transactions in mempool - use reflection to access private methods safely
            $reflection = new ReflectionClass($syncManager);
            
            // Get pdo instance safely
            if ($reflection->hasProperty('pdo')) {
                $pdoProperty = $reflection->getProperty('pdo');
                $pdoProperty->setAccessible(true);
                $pdo = $pdoProperty->getValue($syncManager);
                
                $stmt = $pdo->query("SELECT COUNT(*) FROM mempool WHERE status = 'pending'");
                $pendingCount = $stmt->fetchColumn();
                
                if ($pendingCount > 0) {
                    if ($verbose) echo "Found {$pendingCount} pending transactions in mempool\n";
                    
                    // Try to mine pending transactions if this node should be mining
                    if ($reflection->hasMethod('shouldThisNodeMine')) {
                        $shouldMineMethod = $reflection->getMethod('shouldThisNodeMine');
                        $shouldMineMethod->setAccessible(true);
                        $shouldMine = $shouldMineMethod->invoke($syncManager);
                        
                        if ($shouldMine && $reflection->hasMethod('mineNewBlock')) {
                            if ($verbose) echo "This node is designated for mining, processing pending transactions...\n";
                            $mineMethod = $reflection->getMethod('mineNewBlock');
                            $mineMethod->setAccessible(true);
                            $miningResult = $mineMethod->invoke($syncManager, min($pendingCount, 100));
                            
                            if ($miningResult['success']) {
                                if (!$quiet) echo "✅ Mined block #{$miningResult['block_height']} with {$miningResult['transactions_count']} transactions\n";
                                
                                // Broadcast the new block - check if block data exists
                                if (isset($miningResult['block']) && $reflection->hasMethod('enhancedBlockBroadcast')) {
                                    $broadcastMethod = $reflection->getMethod('enhancedBlockBroadcast');
                                    $broadcastMethod->setAccessible(true);
                                    $broadcastMethod->invoke($syncManager, $miningResult['block']);
                                } else if ($verbose) {
                                    echo "Block data not available for broadcast\n";
                                }
                            }
                        } else if ($verbose) {
                            echo "This node is not designated for mining\n";
                        }
                    }
                    
                    // Clean up mempool
                    if ($reflection->hasMethod('cleanupMempool')) {
                        $cleanupMethod = $reflection->getMethod('cleanupMempool');
                        $cleanupMethod->setAccessible(true);
                        $cleanupMethod->invoke($syncManager);
                    }
                }
            }
        } catch (Exception $e) {
            if ($verbose) echo "Mempool processing warning: " . $e->getMessage() . "\n";
        }
        
        // Step 3.5: Fix pending transactions
        if (!$quiet) echo "🔧 Fixing pending transactions...\n";
        
        try {
            $fixResult = fixPendingTransactions($syncManager, $quiet, $verbose);
            if (isset($fixResult['fixed']) && $fixResult['fixed'] > 0) {
                if (!$quiet) echo "✅ Fixed {$fixResult['fixed']} pending transactions\n";
            }
        } catch (Exception $e) {
            if ($verbose) echo "Pending transactions fix warning: " . $e->getMessage() . "\n";
        }
        
        // Step 3.6: Check network synchronization
        if (!$quiet) echo "🌐 Checking network synchronization...\n";
        
        try {
            $networkResult = syncLaggingNodes($syncManager, $quiet, $verbose);
            if (!$quiet && isset($networkResult['message'])) {
                echo "📡 {$networkResult['message']}\n";
            }
        } catch (Exception $e) {
            if ($verbose) echo "Network sync warning: " . $e->getMessage() . "\n";
        }
        
        // Step 4: Data consistency check and recovery
        if (!$quiet) echo "🔍 Checking data consistency and performing recovery...\n";
        
        try {
            // Simplified recovery check using available methods
            $currentStatus = $syncManager->getStatus();
            
            if ($verbose) {
                printf("Current status: %d blocks, %d transactions\n", 
                    $currentStatus['latest_block'], 
                    $currentStatus['tables']['transactions'] ?? 0
                );
            }
            
            // Force one more sync to ensure consistency
            if ($force || $verbose) {
                if (!$quiet) echo "🔄 Performing final consistency sync...\n";
                $finalSync = $syncManager->syncAll();
                if (!$quiet) echo "✅ Final sync completed\n";
            }
            
        } catch (Exception $e) {
            if ($verbose) echo "Recovery check warning: " . $e->getMessage() . "\n";
        }
        
        $duration = microtime(true) - $startTime;
        
        // Final status check
        $postStatus = $syncManager->getStatus();
        
        if (!$quiet) {
            echo "\n✅ Enhanced Synchronization Completed Successfully!\n";
            echo "==================================================\n\n";
            printf("📊 Blocks synced: %s\n", number_format($result['blocks_synced']));
            printf("💰 Transactions synced: %s\n", number_format($result['transactions_synced']));
            printf("🌐 Source node: %s\n", $result['node']);
            printf("📈 Final block height: %s\n", $postStatus['latest_block']);
            printf("📈 Final transaction count: %s\n", number_format($postStatus['tables']['transactions']));
            printf("⏱️  Duration: %.2f seconds\n", $duration);
            printf("📅 Completed at: %s\n", $result['completion_time']);
        }
        
        return true;
        
    } catch (Exception $e) {
        echo "\n❌ Synchronization Failed!\n";
        echo "==========================\n";
        echo "Error: " . $e->getMessage() . "\n";
        
        if ($verbose) {
            echo "\nStack trace:\n";
            echo $e->getTraceAsString() . "\n";
        }
        
        echo "\n🔧 Troubleshooting tips:\n";
        echo "- Check your internet connection\n";
        echo "- Verify database credentials\n";
        echo "- Check if network nodes are accessible\n";
        echo "- Try running: php quick_sync.php check\n";
        
        return false;
    }
}

function performRepair($syncManager, $options = []) {
    echo "🔧 Blockchain Data Repair\n";
    echo "=========================\n\n";
    
    $verbose = in_array('--verbose', $options);
    
    echo "🔍 Checking for missing or corrupted data...\n";
    
    try {
        // Force a complete re-sync
        echo "🔄 Performing complete data re-synchronization...\n";
        $result = performSync($syncManager, array_merge($options, ['--force']));
        
        if ($result) {
            echo "\n✅ Repair completed successfully!\n";
        } else {
            echo "\n❌ Repair failed - manual intervention may be required\n";
        }
        
    } catch (Exception $e) {
        echo "\n❌ Repair failed: " . $e->getMessage() . "\n";
    }
}

/**
 * Inline raw_mempool processor to avoid invoking legacy CLI PHP.
 */
function processRawQueueInline($syncManager, $quiet = false, $verbose = false) {
    // Get PDO via reflection
    $reflection = new ReflectionClass($syncManager);
    if (!$reflection->hasProperty('pdo')) return;
    $pdoProp = $reflection->getProperty('pdo');
    $pdoProp->setAccessible(true);
    $pdo = $pdoProp->getValue($syncManager);
    if (!$pdo) return;

    $baseDir = __DIR__;
    $rawDir = $baseDir . '/storage/raw_mempool';
    $processedDir = $rawDir . '/processed';
    if (!is_dir($rawDir)) return; // nothing queued
    if (!is_dir($processedDir)) @mkdir($processedDir, 0755, true);

    $rate = 0.0;
    try { $rate = FeePolicy::getRate($pdo); } catch (Exception $e) {}

    // Attempt to salvage previously processed files that had empty parsed data (due to older parser)
    $processedSalvaged = 0;
    $processedDirScan = @glob($processedDir . '/*.json');
    if ($processedDirScan) {
        foreach ($processedDirScan as $pf) {
            if ($processedSalvaged >= 25) break; // safety cap per run
            $j = json_decode(@file_get_contents($pf), true);
            if (!is_array($j)) continue;
            if (isset($j['parsed']) && is_array($j['parsed']) && count($j['parsed']) > 0) continue; // already had parsed
            if (!isset($j['raw'])) continue;
            // Move back for reprocessing with improved parser
            @rename($pf, $rawDir . '/' . basename($pf));
            $processedSalvaged++;
        }
        if ($processedSalvaged > 0 && !$quiet) echo "  Salvaged $processedSalvaged previously processed raw file(s) for re-parse\n";
    }

    $files = glob($rawDir . '/*.json');
    if (!$files) { if ($verbose) echo "  (raw queue empty)\n"; return; }

    // Provide explicit min_fee to enforce non-zero baseline
    $mempool = new MempoolManager($pdo, ['min_fee' => 0.001]);
    // Obtain mempool minFee via reflection for logging/enforcement
    $mpRef = new ReflectionClass($mempool);
    $minFee = 0.0;
    if ($mpRef->hasProperty('minFee')) { $pf = $mpRef->getProperty('minFee'); $pf->setAccessible(true); $minFee = (float)$pf->getValue($mempool); }
    $added = 0; $processed = 0;
    foreach ($files as $file) {
        $processed++;
        $json = json_decode(@file_get_contents($file), true);
        if (!is_array($json)) { @rename($file, $processedDir . '/' . basename($file)); continue; }
        $hash = isset($json['hash']) ? $json['hash'] : '';
        $parsed = isset($json['parsed']) && is_array($json['parsed']) ? $json['parsed'] : [];
        $from = isset($parsed['from']) ? $parsed['from'] : null;
        $to = isset($parsed['to']) ? $parsed['to'] : null;
        $valueHex = isset($parsed['value']) ? $parsed['value'] : '0x0';
        // If no parsed data (legacy empty) attempt inline parse of raw
        if ((!$to || !$valueHex || $parsed === [] || strlen($to) !== 42) && isset($json['raw'])) {
            $inlineRaw = $json['raw'];
            if (strpos($inlineRaw, '0x') === 0) $inlineRaw = substr($inlineRaw, 2);
            $binRaw = @hex2bin($inlineRaw);
            if ($binRaw !== false && strlen($binRaw) > 0) {
                $first = ord($binRaw[0]);
                $typed = false; $typeByte = null;
                if ($first <= 0x7f && in_array($first, [0x02], true)) { $typed = true; $typeByte = $first; $binWork = substr($binRaw,1); } else { $binWork = $binRaw; }
                // Minimal RLP decode (single top-level list)
                $off = 0;
                $decode = function($bin,&$o) use (&$decode){
                    $len = strlen($bin); if ($o >= $len) return null; $b0 = ord($bin[$o]);
                    if ($b0 <= 0x7f) { $o++; return $bin[$o-1]; }
                    if ($b0 <= 0xb7) { $l = $b0-0x80; $o++; $v = substr($bin,$o,$l); $o += $l; return $v; }
                    if ($b0 <= 0xbf) { $ll=$b0-0xb7; $o++; $lBytes=substr($bin,$o,$ll); $o+=$ll; $l = intval(bin2hex($lBytes),16); $v=substr($bin,$o,$l); $o+=$l; return $v; }
                    if ($b0 <= 0xf7) { $l=$b0-0xc0; $o++; $end=$o+$l; $arr=[]; while($o<$end){ $arr[]=$decode($bin,$o);} return $arr; }
                    $ll=$b0-0xf7; $o++; $lBytes=substr($bin,$o,$ll); $o+=$ll; $l=intval(bin2hex($lBytes),16); $end=$o+$l; $arr=[]; while($o<$end){ $arr[]=$decode($bin,$o);} return $arr; };
                $list = $decode($binWork,$off);
                if (is_array($list)) {
                    $toHex = function($v){ $h = bin2hex($v ?? ''); $h = ltrim($h,'0'); $h = str_pad($h,40,'0',STR_PAD_LEFT); return '0x'.$h; };
                    $numHex = function($v){ $h=bin2hex($v ?? ''); $h=ltrim($h,'0'); return '0x'.($h===''?'0':$h); };
                    if ($typed && $typeByte === 0x02) {
                        // [chainId, nonce, maxPriorityFeePerGas, maxFeePerGas, gas, to, value, data, accessList, v,r,s]
                        $to = isset($list[5]) ? $toHex($list[5]) : null;
                        $valueHex = isset($list[6]) ? $numHex($list[6]) : '0x0';
                        $parsed = [
                            'nonce' => $numHex($list[1] ?? ''),
                            'maxPriorityFeePerGas' => $numHex($list[2] ?? ''),
                            'maxFeePerGas' => $numHex($list[3] ?? ''),
                            'gas' => $numHex($list[4] ?? ''),
                            'to' => $to,
                            'value' => $valueHex,
                            'type' => '0x2'
                        ];
                    } else {
                        // Legacy: [nonce, gasPrice, gas, to, value, data, v,r,s]
                        $to = isset($list[3]) ? $toHex($list[3]) : null;
                        $valueHex = isset($list[4]) ? $numHex($list[4]) : '0x0';
                        $parsed = [
                            'nonce' => $numHex($list[0] ?? ''),
                            'gasPrice' => $numHex($list[1] ?? ''),
                            'gas' => $numHex($list[2] ?? ''),
                            'to' => $to,
                            'value' => $valueHex,
                            'type' => '0x0'
                        ];
                    }
                }
            }
        }
        // --- Amount extraction (improved big-int safe approximation) ---
        $amount = 0.0; // final floating approximation for internal Transaction
        if (preg_match('/^0x[0-9a-f]+$/i', $valueHex)) {
            $hexDigits = strtolower(substr($valueHex, 2));
            // Remove leading zeros
            $hexDigits = ltrim($hexDigits, '0');
            if ($hexDigits === '') {
                $amount = 0.0;
            } else {
                // If length small enough use native hexdec safely
                if (strlen($hexDigits) <= 14) {
                    $units = (string)hexdec($hexDigits); // fits into int
                } else {
                    // Convert base16 -> base10 string
                    $units = '0';
                    $map = ['0'=>0,'1'=>1,'2'=>2,'3'=>3,'4'=>4,'5'=>5,'6'=>6,'7'=>7,'8'=>8,'9'=>9,'a'=>10,'b'=>11,'c'=>12,'d'=>13,'e'=>14,'f'=>15];
                    for ($i=0,$L=strlen($hexDigits); $i<$L; $i++) {
                        $d = $map[$hexDigits[$i]];
                        // units = units*16 + d (string arithmetic)
                        $carry = $d;
                        $res = '';
                        for ($j=strlen($units)-1; $j>=0; $j--) {
                            $prod = ((int)$units[$j]) * 16 + $carry;
                            $resDigit = $prod % 10;
                            $carry = intdiv($prod, 10);
                            $res = $resDigit . $res;
                        }
                        while ($carry > 0) { $res = ($carry % 10) . $res; $carry = intdiv($carry, 10); }
                        // Trim leading zeros
                        $units = ltrim($res, '0');
                        if ($units === '') $units = '0';
                    }
                }
                // Assume 18 decimals (ERC-20 style). Convert units string to decimal token amount string.
                $decimals = 18;
                if ($units === '0') {
                    $amountStr = '0';
                } else {
                    $len = strlen($units);
                    if ($len <= $decimals) {
                        $pad = str_pad($units, $decimals, '0', STR_PAD_LEFT);
                        $intPart = '0';
                        $fracPart = rtrim($pad, '0');
                        $amountStr = $fracPart === '' ? '0' : ('0.' . $fracPart);
                    } else {
                        $intPart = substr($units, 0, $len - $decimals);
                        $fracPart = rtrim(substr($units, $len - $decimals), '0');
                        $amountStr = $intPart . ($fracPart === '' ? '' : ('.' . $fracPart));
                    }
                }
                // Convert to float for current Transaction model (lossy but adequate for pending mempool)
                if (isset($amountStr)) {
                    $amount = (float)$amountStr;
                }
            }
        }
        if (!is_string($to) || strlen($to) !== 42) { @rename($file, $processedDir . '/' . basename($file)); continue; }
        if (!$from) {
            try { $rec = EthereumTx::recoverAddress(isset($json['raw']) ? $json['raw'] : ''); if ($rec) $from = strtolower($rec); } catch (Exception $e) {}
        }
        if (!$from || strlen($from) !== 42) $from = '0x' . str_repeat('0', 40);

        // Gas / fee
        $gasPriceInt = EthereumTx::effectiveGasPrice(
            isset($parsed['maxPriorityFeePerGas']) ? $parsed['maxPriorityFeePerGas'] : null,
            isset($parsed['maxFeePerGas']) ? $parsed['maxFeePerGas'] : null,
            isset($parsed['gasPrice']) ? $parsed['gasPrice'] : null
        );
        $gasLimit = 0;
        if (isset($parsed['gas'])) {
            $ghex = strtolower($parsed['gas']);
            if (strpos($ghex,'0x')===0) $ghex = substr($ghex,2);
            if ($ghex !== '') $gasLimit = intval($ghex, 16);
        }
        $fee = 0.0;
        if ($gasLimit > 0 && $gasPriceInt > 0) $fee = ($gasLimit * $gasPriceInt) / 1e18;
    if ($rate > 0 && $fee < $rate) $fee = $rate;
    if ($fee < $minFee) $fee = $minFee; // ensure meets mempool requirement

        // Extract nonce (hex) -> int (best effort, capped at PHP int range)
        $nonceInt = 0;
        if (isset($parsed['nonce']) && preg_match('/^0x[0-9a-f]+$/i', $parsed['nonce'])) {
            $nhex = substr($parsed['nonce'], 2);
            if ($nhex !== '') {
                // Use arbitrary-length conversion but clamp to int
                $nonceInt = 0;
                // Safe if length <= 15 (~ hex for 2^60) otherwise take last 15 digits to avoid overflow
                if (strlen($nhex) <= 15) {
                    $nonceInt = intval($nhex, 16);
                } else {
                    $nonceInt = intval(substr($nhex, -15), 16); // best-effort
                }
            }
        }
        // Derive human gas price (token units) from effective gas price integer (wei-like assumption 1e18)
        $gasPriceToken = 0.0;
        if ($gasPriceInt > 0) {
            $gasPriceToken = $gasPriceInt / 1e18; // float approximation
        }
        try {
            $tx = new Transaction($from, $to, $amount, $fee, $nonceInt, null, $gasLimit > 0 ? $gasLimit : 21000, $gasPriceToken);
            $ref = new ReflectionClass($tx);
            if ($ref->hasProperty('signature')) { $p = $ref->getProperty('signature'); $p->setAccessible(true); $p->setValue($tx, 'external_raw'); }
            $addedOk = $mempool->addTransaction($tx);
            if ($addedOk) {
                $added++;
                if ($verbose) echo "  + queued raw $hash -> {$tx->getHash()} from=$from to=$to amount=$amount fee=$fee nonce=$nonceInt gasLimit=".$tx->getGasLimit()." gasPrice=$gasPriceToken\n";
                // Best-effort broadcast of original raw tx to peer wallet APIs so they can also queue it
                if (!empty($json['raw'])) {
                    try {
                        // Get peer nodes (excluding self) via reflection if available
                        $peerNodes = [];
                        if ($reflection->hasMethod('getNetworkNodesForBroadcast')) {
                            $m = $reflection->getMethod('getNetworkNodesForBroadcast');
                            $m->setAccessible(true);
                            $peerNodes = $m->invoke($syncManager) ?: [];
                        }
                        foreach ($peerNodes as $peerUrl) {
                            // Normalize URL (strip trailing slash)
                            $peerUrl = rtrim($peerUrl, '/');
                            // Assume wallet_api path
                            $rpcUrl = $peerUrl . '/wallet/wallet_api.php';
                            $payload = json_encode([
                                'jsonrpc' => '2.0',
                                'id' => substr($hash,0,8),
                                'method' => 'eth_sendRawTransaction',
                                'params' => [$json['raw']]
                            ]);
                            // Suppress warnings; short timeout
                            $ch = curl_init($rpcUrl);
                            if ($ch) {
                                curl_setopt_array($ch, [
                                    CURLOPT_POST => true,
                                    CURLOPT_RETURNTRANSFER => true,
                                    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                                    CURLOPT_POSTFIELDS => $payload,
                                    CURLOPT_CONNECTTIMEOUT => 2,
                                    CURLOPT_TIMEOUT => 4,
                                ]);
                                $resp = curl_exec($ch);
                                curl_close($ch);
                            }
                        }
                    } catch (Exception $e) {
                        if ($verbose) echo "    (broadcast error suppressed: " . $e->getMessage() . ")\n";
                    }
                }
            } else {
                if ($verbose) {
                    // Diagnose reason manually
                    $reason = 'unknown';
                    // Duplicate?
                    try { if ($mempool->hasTransaction($tx->getHash())) $reason = 'duplicate'; } catch (Exception $e) {}
                    if ($reason === 'unknown') {
                        if (!$tx->isValid()) {
                            if ($from === $to) $reason = 'from_equals_to';
                            elseif (!preg_match('/^0x[a-f0-9]{40}$/', $from) || !preg_match('/^0x[a-f0-9]{40}$/', $to)) $reason = 'invalid_address';
                            elseif ($tx->getFee() < $minFee) $reason = 'fee_below_min';
                            elseif ($tx->getAmount() < 0) $reason = 'negative_amount';
                            elseif ($tx->getSignature() === null) $reason = 'missing_signature';
                            else $reason = 'invalid_tx';
                        } else if ($tx->getFee() < $minFee) {
                            $reason = 'fee_below_min';
                        }
                    }
                    echo "  - failed add $hash (reason=$reason) fee=$fee minFee=$minFee amount=$amount from=$from to=$to\n";
                }
            }
        } catch (Exception $e) {
            if ($verbose) echo "  ! error $hash: " . $e->getMessage() . "\n";
        }
        // Persist updated parsed structure (and mark replay count) to prevent endless salvage
        try {
            $json['parsed'] = $parsed;
            $json['reprocessed_at'] = date('c');
            $json['internal_tx_hash'] = isset($tx) ? $tx->getHash() : ($json['internal_tx_hash'] ?? null);
            $outPath = $processedDir . '/' . basename($file);
            $tmpPath = $outPath . '.tmp';
            @file_put_contents($tmpPath, json_encode($json, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            @rename($tmpPath, $outPath);
            @unlink($file);
        } catch (Exception $e) {
            // Fallback to simple rename if write fails
            @rename($file, $processedDir . '/' . basename($file));
        }
    }
    if (!$quiet) echo "  Processed $processed raw file(s), added $added to mempool\n";
}

// Main execution
// Enable detailed error output when running via web (for debugging 500) and via CLI if requested
// Note: Keep verbose output minimal in production; disable after debugging.
if (php_sapi_name() !== 'cli') {
    // Force JSON for HTTP responses
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    // For CLI: allow enabling verbose errors by env var QUICK_SYNC_DEBUG=1
    if (getenv('QUICK_SYNC_DEBUG') === '1') {
        ini_set('display_errors', '1');
        ini_set('display_startup_errors', '1');
        error_reporting(E_ALL);
    }
}

// Detect execution mode and parse input
if (php_sapi_name() === 'cli') {
    $command = $argv[1] ?? 'help';
    $options = array_slice($argv, 2);
} else {
    // HTTP mode: support ?cmd=...&token=... and JSON body
    $input = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = file_get_contents('php://input');
        if ($raw) {
            $json = json_decode($raw, true);
            if (is_array($json)) {
                $input = $json;
            }
        }
    }
    $cmdParam = $_GET['cmd'] ?? $_GET['command'] ?? ($input['cmd'] ?? $input['command'] ?? 'help');
    $command = $cmdParam ?: 'help';
    // Build options array from flags if any (not strictly needed in HTTP)
    $options = [];
}

try {
    // Basic environment diagnostics to help trace 500 errors
    if (getenv('QUICK_SYNC_DEBUG') === '1' && php_sapi_name() === 'cli') {
        echo "[diag] PHP version: " . PHP_VERSION . "\n";
        echo "[diag] SAPI: " . php_sapi_name() . "\n";
        echo "[diag] Working dir: " . getcwd() . "\n";
        echo "[diag] Script: " . __FILE__ . "\n";
    }

    $syncManager = new NetworkSyncManager(false);

    // HTTP auth helper: verify admin users.api_key
    $httpAuthCheck = function (): array {
        // return [ok(bool), message(string)]
        $token = $_GET['token'] ?? null;
        if (!$token && isset($_SERVER['HTTP_AUTHORIZATION'])) {
            if (preg_match('/Bearer\\s+(.*)$/i', $_SERVER['HTTP_AUTHORIZATION'], $m)) {
                $token = trim($m[1]);
            }
        }
        if (!$token) {
            return [false, 'Missing token'];
        }

        // minimal DB bootstrap using .env (same approach as network_sync.php and sync-service)
        $envPath = __DIR__ . '/config/.env';
        $env = [];
        if (file_exists($envPath)) {
            $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if ($line === '' || $line[0] === '#') continue;
                if (strpos($line, '=') !== false) {
                    list($k, $v) = explode('=', $line, 2);
                    $env[trim($k)] = trim($v);
                }
            }
        }
        $dbHost = $env['DB_HOST'] ?? ($_ENV['DB_HOST'] ?? 'database');
        $dbPort = $env['DB_PORT'] ?? ($_ENV['DB_PORT'] ?? '3306');
        $dbName = $env['DB_DATABASE'] ?? ($_ENV['DB_NAME'] ?? 'blockchain');
        $dbUser = $env['DB_USERNAME'] ?? ($_ENV['DB_USER'] ?? 'blockchain');
        $dbPass = $env['DB_PASSWORD'] ?? ($_ENV['DB_PASSWORD'] ?? 'blockchain123');
        $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
        try {
            $pdo = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (Throwable $e) {
            return [false, 'DB connect failed'];
        }

        try {
            $stmt = $pdo->query("SELECT api_key FROM users WHERE role='admin' ORDER BY id ASC LIMIT 1");
            $row = $stmt->fetch();
            if (!$row || empty($row['api_key'])) {
                return [false, 'Admin API key not found'];
            }
            if (!hash_equals($row['api_key'], $token)) {
                return [false, 'Invalid token'];
            }
        } catch (Throwable $e) {
            return [false, 'DB query failed'];
        }

        return [true, 'OK'];
    };

    // HTTP mode handling
    if (php_sapi_name() !== 'cli') {
        // Only allow selected commands via HTTP
        if (!in_array($command, ['sync', 'enhanced-sync', 'status', 'check', 'mempool-status'], true)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Route not found', 'cmd' => $command], JSON_UNESCAPED_UNICODE);
            exit;
        }
        // Auth check
        [$ok, $msg] = $httpAuthCheck();
        if (!$ok) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Unauthorized: ' . $msg], JSON_UNESCAPED_UNICODE);
            exit;
        }
        // Concurrency guard
        $lockFile = '/tmp/phpbc_sync.lock';
        $fp = fopen($lockFile, 'c');
        if ($fp === false) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Cannot open lock file'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $locked = flock($fp, LOCK_EX | LOCK_NB);
        if (!$locked) {
            http_response_code(429);
            echo json_encode(['success' => false, 'error' => 'Sync already running'], JSON_UNESCAPED_UNICODE);
            fclose($fp);
            exit;
        }

        // Execute command and respond JSON
        if ($command === 'sync') {
            $ok = performSync($syncManager, $options);
            $resp = ['success' => (bool)$ok, 'command' => 'sync'];
            if (!$ok) {
                http_response_code(500);
            }
            echo json_encode($resp, JSON_UNESCAPED_UNICODE);
            flock($fp, LOCK_UN);
            fclose($fp);
            exit;
        } elseif ($command === 'status') {
            // Collect status
            $status = $syncManager->getStatus();
            echo json_encode(['success' => true, 'command' => 'status', 'status' => $status], JSON_UNESCAPED_UNICODE);
            flock($fp, LOCK_UN);
            fclose($fp);
            exit;
        } elseif ($command === 'check') {
            $ok = checkConnectivity($syncManager);
            echo json_encode(['success' => (bool)$ok, 'command' => 'check'], JSON_UNESCAPED_UNICODE);
            flock($fp, LOCK_UN);
            fclose($fp);
            exit;
        }
    }
    
    switch ($command) {
        case 'sync':
            performSync($syncManager, $options);
            break;
            
        case 'enhanced-sync':
            // Enhanced sync is the same as regular sync now (enhanced version is default)
            performSync($syncManager, array_merge($options, ['--verbose']));
            break;
            
        case 'status':
            $verbose = in_array('--verbose', $options);
            showStatus($syncManager, $verbose);
            break;
            
        case 'mempool-status':
            $verbose = in_array('--verbose', $options);
            showMempoolStatus($syncManager, $verbose);
            break;
            
        case 'check':
            checkConnectivity($syncManager);
            break;
            
        case 'repair':
            performRepair($syncManager, $options);
            break;

        case 'auto-start':
            // Auto-start synchronization if the installer created a flag
            $flag = __DIR__ . '/storage/sync_autostart.flag';
            if (file_exists($flag)) {
                echo "Auto-start flag found. Starting synchronization...\n";
                @unlink($flag);
                performSync($syncManager, $options);
            } else {
                echo "No auto-start flag present. Nothing to do.\n";
            }
            break;
            
        case 'help':
        case '--help':
        case '-h':
        default:
            showUsage();
            break;
    }

    if (php_sapi_name() !== 'cli') {
        // If HTTP reached here without exiting, send generic response
        echo json_encode(['success' => true, 'message' => 'OK'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
} catch (Throwable $e) {
    // Catch Throwable to include TypeError/FatalError on PHP 7+
    $msg = "💥 Fatal Error: " . $e->getMessage() . "\n";
    $msg .= "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    if (getenv('QUICK_SYNC_DEBUG') === '1') {
        $msg .= "\nStack trace:\n" . $e->getTraceAsString() . "\n";
    }
    echo $msg;

    echo "\nThis usually indicates a configuration or database issue.\n";
    echo "Please check:\n";
    echo "- Database connection settings\n";
    echo "- File permissions\n";
    echo "- PHP requirements\n";

    // Also log to PHP error_log for web runs
    error_log("[quick_sync] " . str_replace("\n", " | ", $msg));

    exit(1);
}

/**
 * Sync lagging nodes to match the highest block height in network
 */
function syncLaggingNodes($syncManager, $quiet = false, $verbose = false) {
    try {
        if (!$quiet) echo "🌐 Checking network synchronization...\n";
        
        // Get nodes from database
        $reflection = new ReflectionClass($syncManager);
        if (!$reflection->hasProperty('pdo')) {
            throw new Exception("PDO property not accessible in NetworkSyncManager");
        }
        
        $property = $reflection->getProperty('pdo');
        $property->setAccessible(true);
        $pdo = $property->getValue($syncManager);
        
        if (!$pdo) {
            throw new Exception("PDO connection not available");
        }
        
        // Get active nodes from database
        $stmt = $pdo->query("SELECT node_id, ip_address, port, metadata FROM nodes WHERE status = 'active'");
        $nodeRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($nodeRows)) {
            if (!$quiet) echo "⚠️ No active nodes found in database\n";
            return ['synced' => false, 'error' => 'No active nodes found'];
        }
        
        $nodes = [];
        foreach ($nodeRows as $row) {
            $metadata = json_decode($row['metadata'], true);
            $domain = $metadata['domain'] ?? $row['ip_address'];
            $protocol = $metadata['protocol'] ?? 'https';
            $name = $domain;
            $url = "$protocol://$domain";
            $nodes[$name] = rtrim($url, '/');
        }
        
        $heights = [];
        $transactions = [];
        
        // Get current heights and transaction counts
        foreach ($nodes as $name => $url) {
            try {
                $blocksResponse = file_get_contents("$url/api/explorer/blocks?limit=1");
                $txResponse = file_get_contents("$url/api/explorer/transactions?limit=1");
                
                if ($blocksResponse && $txResponse) {
                    $blocks = json_decode($blocksResponse, true);
                    $txData = json_decode($txResponse, true);
                    
                    $heights[$name] = $blocks['blocks'][0]['index'] ?? 0;
                    $transactions[$name] = $txData['total'] ?? 0;
                } else {
                    $heights[$name] = 0;
                    $transactions[$name] = 0;
                }
            } catch (Exception $e) {
                if ($verbose) echo "  Warning: Failed to check $name - {$e->getMessage()}\n";
                $heights[$name] = 0;
                $transactions[$name] = 0;
            }
        }
        
        if ($verbose) {
            echo "Current network state:\n";
            foreach ($heights as $name => $height) {
                echo "  $name: height $height, {$transactions[$name]} transactions\n";
            }
        }
        
        $maxHeight = max($heights);
        $maxTransactions = max($transactions);
        $needsSync = false;
        
        foreach ($heights as $name => $height) {
            if ($height < $maxHeight || $transactions[$name] < $maxTransactions) {
                if (!$quiet) echo "  ⚠️  $name is behind (height: $height/$maxHeight, tx: {$transactions[$name]}/$maxTransactions)\n";
                $needsSync = true;
            }
        }
        
        if (!$needsSync) {
            if (!$quiet) echo "✅ All nodes are synchronized\n";
            return ['synced' => true, 'message' => 'All nodes synchronized'];
        }
        
        if (!$quiet) echo "� Network is not synchronized, but proceeding...\n";
        
        return [
            'synced' => false,
            'improved_nodes' => 0,
            'total_nodes' => count($nodes),
            'message' => "Network sync check completed - nodes are not in sync"
        ];
        
    } catch (Exception $e) {
        $error = "Network sync failed: " . $e->getMessage();
        if (!$quiet) echo "❌ $error\n";
        return ['synced' => false, 'error' => $error];
    }
}

/**
 * Fix pending transactions that should be confirmed
 * Updates status from 'pending' to 'confirmed' for transactions that have block_hash but missing block_height
 */
function fixPendingTransactions($syncManager, $quiet = false, $verbose = false) {
    try {
        if (!$quiet) echo "🔧 Fixing pending transactions with missing block heights...\n";
        
        // Get PDO connection via reflection (safe access)
        $reflection = new ReflectionClass($syncManager);
        if (!$reflection->hasProperty('pdo')) {
            throw new Exception("PDO property not accessible in NetworkSyncManager");
        }
        
        $property = $reflection->getProperty('pdo');
        $property->setAccessible(true);
        $pdo = $property->getValue($syncManager);
        
        if (!$pdo) {
            throw new Exception("PDO connection not available");
        }
        
        // Find pending transactions that have block_hash but missing block_height
        $stmt = $pdo->prepare("
            SELECT t.*, b.height as actual_block_height, b.hash as actual_block_hash
            FROM transactions t
            LEFT JOIN blocks b ON t.block_hash = b.hash
            WHERE t.status = 'pending' 
            AND t.block_hash IS NOT NULL 
            AND t.block_hash != ''
            AND b.hash IS NOT NULL
        ");
        $stmt->execute();
        $pendingTxs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($pendingTxs)) {
            if (!$quiet) echo "✅ No pending transactions to fix\n";
            return ['fixed' => 0, 'message' => 'No pending transactions found'];
        }
        
        if ($verbose) {
            echo "Found " . count($pendingTxs) . " pending transactions to fix:\n";
            foreach ($pendingTxs as $tx) {
                echo "  - {$tx['hash']} -> block {$tx['actual_block_height']} ({$tx['actual_block_hash']})\n";
            }
        }
        
        $pdo->beginTransaction();
        $fixedCount = 0;
        
        // Update each pending transaction
        foreach ($pendingTxs as $tx) {
            $updateStmt = $pdo->prepare("
                UPDATE transactions 
                SET status = 'confirmed', 
                    block_height = ?
                WHERE hash = ? 
                AND status = 'pending'
            ");
            
            $result = $updateStmt->execute([
                $tx['actual_block_height'],
                $tx['hash']
            ]);
            
            if ($result && $updateStmt->rowCount() > 0) {
                $fixedCount++;
                if ($verbose) {
                    echo "  ✅ Fixed: {$tx['hash']} -> confirmed at height {$tx['actual_block_height']}\n";
                }
            }
        }
        
        $pdo->commit();
        
        if (!$quiet) {
            echo "✅ Fixed $fixedCount pending transactions\n";
        }
        
        return [
            'fixed' => $fixedCount,
            'total_found' => count($pendingTxs),
            'message' => "Successfully fixed $fixedCount pending transactions"
        ];
        
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        
        $error = "Failed to fix pending transactions: " . $e->getMessage();
        if (!$quiet) echo "❌ $error\n";
        error_log("[quick_sync] fixPendingTransactions error: " . $error);
        
        return [
            'fixed' => 0,
            'error' => $error
        ];
    }
}
?>
