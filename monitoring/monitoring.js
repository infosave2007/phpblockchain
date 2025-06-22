class MonitoringDashboard {
    constructor() {
        this.currentNetwork = 'mainnet';
        this.apiEndpoint = '/api/monitoring';
        this.refreshInterval = 30; // seconds
        this.charts = {};
        this.refreshTimer = null;
        
        this.initializeDashboard();
    }

    async initializeDashboard() {
        this.bindEvents();
        this.initializeCharts();
        await this.loadDashboardData();
        this.startAutoRefresh();
    }

    bindEvents() {
        // Network selection
        document.getElementById('networkSelect').addEventListener('change', (e) => {
            this.currentNetwork = e.target.value;
            this.refreshDashboard();
        });

        // Manual refresh
        window.refreshDashboard = () => {
            this.refreshDashboard();
        };
    }

    initializeCharts() {
        // Blockchain performance chart
        this.charts.blockchain = new Chart(document.getElementById('blockchainChart'), {
            type: 'line',
            data: {
                labels: [],
                datasets: [{
                    label: 'Block Time (sec)',
                    data: [],
                    borderColor: '#667eea',
                    backgroundColor: 'rgba(102, 126, 234, 0.1)',
                    fill: true
                }, {
                    label: 'Transactions/block',
                    data: [],
                    borderColor: '#764ba2',
                    backgroundColor: 'rgba(118, 75, 162, 0.1)',
                    yAxisID: 'y1'
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        title: { display: true, text: 'Block Time (sec)' }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        title: { display: true, text: 'Transactions' },
                        grid: { drawOnChartArea: false }
                    }
                }
            }
        });

        // Node performance chart
        this.charts.node = new Chart(document.getElementById('nodeChart'), {
            type: 'line',
            data: {
                labels: [],
                datasets: [{
                    label: 'CPU %',
                    data: [],
                    borderColor: '#28a745',
                    backgroundColor: 'rgba(40, 167, 69, 0.1)'
                }, {
                    label: 'Memory %',
                    data: [],
                    borderColor: '#ffc107',
                    backgroundColor: 'rgba(255, 193, 7, 0.1)'
                }, {
                    label: 'Disk %',
                    data: [],
                    borderColor: '#dc3545',
                    backgroundColor: 'rgba(220, 53, 69, 0.1)'
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                        title: { display: true, text: 'Usage (%)' }
                    }
                }
            }
        });

        // Transaction throughput chart
        this.charts.transaction = new Chart(document.getElementById('transactionChart'), {
            type: 'bar',
            data: {
                labels: [],
                datasets: [{
                    label: 'TPS',
                    data: [],
                    backgroundColor: 'rgba(102, 126, 234, 0.8)',
                    borderColor: '#667eea',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: { display: true, text: 'Transactions per Second' }
                    }
                }
            }
        });

        // Network activity chart
        this.charts.network = new Chart(document.getElementById('networkChart'), {
            type: 'doughnut',
            data: {
                labels: ['Active Peers', 'Disconnected Peers'],
                datasets: [{
                    data: [0, 0],
                    backgroundColor: ['#28a745', '#6c757d'],
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
    }

    async loadDashboardData() {
        try {
            this.showLoading(true);
            
            const response = await fetch(`${this.apiEndpoint}/dashboard?network=${this.currentNetwork}&range=3600`);
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            
            const data = await response.json();
            
            this.updateSystemHealth(data.system_health);
            this.updateMetrics(data.blockchain_metrics, data.node_metrics);
            this.updateCharts(data);
            this.updateAlerts(data.alerts);
            this.updateSecurityEvents(data.security_events);
            this.updateRecentEvents(data.recent_events);
            
        } catch (error) {
            console.error('Failed to load dashboard data:', error);
            this.showError('Failed to load monitoring data');
        } finally {
            this.showLoading(false);
        }
    }

    updateSystemHealth(health) {
        if (!health) return;

        // Overall status
        const overallEl = document.getElementById('overallStatus');
        const statusClass = `status-${health.overall_status}`;
        const statusText = this.getStatusText(health.overall_status);
        
        overallEl.innerHTML = `<span class="status-indicator ${statusClass}"></span>${statusText}`;

        // Component statuses
        if (health.components) {
            this.updateComponentStatus('blockchainStatus', health.components.blockchain);
            this.updateComponentStatus('nodeStatus', health.components.node);
            this.updateComponentStatus('apiStatus', health.components.api);
        }
    }

    updateComponentStatus(elementId, component) {
        if (!component) return;
        
        const element = document.getElementById(elementId);
        const statusClass = `status-${component.status}`;
        const statusText = this.getStatusText(component.status);
        
        element.innerHTML = `<span class="status-indicator ${statusClass}"></span>${statusText}`;
        element.title = component.message || '';
    }

    updateMetrics(blockchainMetrics, nodeMetrics) {
        // Blockchain metrics
        if (blockchainMetrics && blockchainMetrics.length > 0) {
            const latest = blockchainMetrics[blockchainMetrics.length - 1];
            
            document.getElementById('blockHeight').textContent = 
                latest.block_height?.toLocaleString() || '-';
            document.getElementById('txCount').textContent = 
                latest.total_transactions?.toLocaleString() || '-';
            document.getElementById('pendingTx').textContent = 
                latest.pending_transactions?.toLocaleString() || '-';
        }

        // Node metrics
        if (nodeMetrics && nodeMetrics.length > 0) {
            const latest = nodeMetrics[nodeMetrics.length - 1];
            
            document.getElementById('peerCount').textContent = 
                latest.peer_count?.toString() || '-';
        }
    }

    updateCharts(data) {
        this.updateBlockchainChart(data.blockchain_metrics);
        this.updateNodeChart(data.node_metrics);
        this.updateTransactionChart(data.blockchain_metrics);
        this.updateNetworkChart(data.node_metrics);
    }

    updateBlockchainChart(metrics) {
        if (!metrics || metrics.length === 0) return;

        const chart = this.charts.blockchain;
        const times = metrics.map(m => new Date(m.timestamp * 1000).toLocaleTimeString());
        const blockTimes = metrics.map(m => m.block_time_avg || 0);
        const txCounts = metrics.map(m => m.transaction_throughput || 0);

        chart.data.labels = times.slice(-20); // Last 20 points
        chart.data.datasets[0].data = blockTimes.slice(-20);
        chart.data.datasets[1].data = txCounts.slice(-20);
        chart.update('none');
    }

    updateNodeChart(metrics) {
        if (!metrics || metrics.length === 0) return;

        const chart = this.charts.node;
        const times = metrics.map(m => new Date(m.timestamp * 1000).toLocaleTimeString());
        const cpuUsage = metrics.map(m => m.cpu_usage || 0);
        const memoryUsage = metrics.map(m => m.memory_usage || 0);
        const diskUsage = metrics.map(m => m.disk_usage || 0);

        chart.data.labels = times.slice(-20);
        chart.data.datasets[0].data = cpuUsage.slice(-20);
        chart.data.datasets[1].data = memoryUsage.slice(-20);
        chart.data.datasets[2].data = diskUsage.slice(-20);
        chart.update('none');
    }

    updateTransactionChart(metrics) {
        if (!metrics || metrics.length === 0) return;

        const chart = this.charts.transaction;
        const last10 = metrics.slice(-10);
        const labels = last10.map(m => new Date(m.timestamp * 1000).toLocaleTimeString());
        const tps = last10.map(m => m.transaction_throughput || 0);

        chart.data.labels = labels;
        chart.data.datasets[0].data = tps;
        chart.update('none');
    }

    updateNetworkChart(metrics) {
        if (!metrics || metrics.length === 0) return;

        const latest = metrics[metrics.length - 1];
        const activePeers = latest.peer_count || 0;
        const maxPeers = 25; // Default max peers
        const inactivePeers = Math.max(0, maxPeers - activePeers);

        const chart = this.charts.network;
        chart.data.datasets[0].data = [activePeers, inactivePeers];
        chart.update('none');
    }

    updateAlerts(alerts) {
        const container = document.getElementById('alertsList');
        
        if (!alerts || alerts.length === 0) {
            container.innerHTML = `
                <div class="text-center text-muted">
                    <i class="fas fa-check-circle fa-2x"></i>
                    <p class="mt-2">No active alerts</p>
                </div>
            `;
            return;
        }

        container.innerHTML = alerts.map(alert => `
            <div class="alert-item alert-${this.getAlertSeverity(alert.type)}">
                <div class="d-flex justify-content-between">
                    <strong>${alert.message}</strong>
                    <small>${this.timeAgo(alert.timestamp)}</small>
                </div>
                ${alert.data ? `<small class="text-muted">${JSON.stringify(alert.data)}</small>` : ''}
            </div>
        `).join('');
    }

    updateSecurityEvents(events) {
        const container = document.getElementById('securityEvents');
        
        if (!events || events.length === 0) {
            container.innerHTML = `
                <div class="text-center text-muted">
                    <i class="fas fa-shield-check fa-2x"></i>
                    <p class="mt-2">No security events</p>
                </div>
            `;
            return;
        }

        container.innerHTML = events.slice(-5).map(event => `
            <div class="alert-item alert-${this.getAlertSeverity(event.severity)}">
                <div class="d-flex justify-content-between">
                    <strong>${event.type}</strong>
                    <small>${this.timeAgo(event.timestamp)}</small>
                </div>
                <small class="text-muted">${JSON.stringify(event.details)}</small>
            </div>
        `).join('');
    }

    updateRecentEvents(events) {
        const container = document.getElementById('recentEvents');
        
        if (!events || events.length === 0) {
            container.innerHTML = '<p class="text-muted">No recent events</p>';
            return;
        }

        container.innerHTML = events.slice(-10).map(event => `
            <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                <div>
                    <strong>${event.type}</strong>
                    <br><small class="text-muted">${event.message}</small>
                </div>
                <small class="text-muted">${this.timeAgo(event.timestamp)}</small>
            </div>
        `).join('');
    }

    startAutoRefresh() {
        let countdown = this.refreshInterval;
        
        const updateTimer = () => {
            document.getElementById('refreshTimer').textContent = countdown;
            
            if (countdown <= 0) {
                this.refreshDashboard();
                countdown = this.refreshInterval;
            } else {
                countdown--;
            }
        };

        updateTimer();
        this.refreshTimer = setInterval(updateTimer, 1000);
    }

    async refreshDashboard() {
        const icon = document.getElementById('refreshIcon');
        icon.classList.add('fa-spin');
        
        await this.loadDashboardData();
        
        setTimeout(() => {
            icon.classList.remove('fa-spin');
        }, 500);
    }

    // Utility functions
    getStatusText(status) {
        const statusMap = {
            'healthy': 'Healthy',
            'warning': 'Warning',
            'critical': 'Critical',
            'unknown': 'Unknown'
        };
        return statusMap[status] || status;
    }

    getAlertSeverity(type) {
        if (type === 'security' || type === 'critical') return 'critical';
        if (type === 'warning' || type === 'performance') return 'warning';
        return 'info';
    }

    timeAgo(timestamp) {
        const now = Math.floor(Date.now() / 1000);
        const diff = now - timestamp;
        
        if (diff < 60) return `${diff} sec. ago`;
        if (diff < 3600) return `${Math.floor(diff / 60)} min. ago`;
        if (diff < 86400) return `${Math.floor(diff / 3600)} h. ago`;
        return `${Math.floor(diff / 86400)} d. ago`;
    }

    showLoading(show) {
        // Implementation for loading indicator
        console.log(show ? 'Loading...' : 'Loading complete');
    }

    showError(message) {
        console.error(message);
        // Could show a toast notification here
    }
}

// Initialize dashboard when page loads
document.addEventListener('DOMContentLoaded', function() {
    window.dashboard = new MonitoringDashboard();
});
