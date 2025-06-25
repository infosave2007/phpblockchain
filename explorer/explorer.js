class BlockchainExplorer {
    constructor() {
        this.currentNetwork = 'mainnet';
        this.apiEndpoint = '/api/explorer';
        this.currentBlockPage = 0;
        this.currentTxPage = 0;
        this.pageSize = 10;
        
        this.initializeExplorer();
    }

    async initializeExplorer() {
        this.bindEvents();
        await this.loadNetworkStats();
        await this.loadLatestBlocks();
        await this.loadLatestTransactions();
        
        // Auto-refresh every 30 seconds
        setInterval(() => {
            this.refreshData();
        }, 30000);
    }

    bindEvents() {
        // Network selection
        document.getElementById('networkSelect').addEventListener('change', (e) => {
            this.currentNetwork = e.target.value;
            this.refreshData();
        });

        // Search on Enter key
        document.getElementById('searchInput').addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                this.performSearch();
            }
        });
    }

    async performSearch() {
        const query = document.getElementById('searchInput').value.trim();
        if (!query) return;

        this.showLoading(true);
        
        try {
            const response = await fetch(`${this.apiEndpoint}/search`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    query: query,
                    network: this.currentNetwork
                })
            });

            const data = await response.json();
            this.displaySearchResults(data);
        } catch (error) {
            console.error('Search failed:', error);
            this.showError('Search failed. Please try again.');
        } finally {
            this.showLoading(false);
        }
    }

    async loadNetworkStats() {
        try {
            const response = await fetch(`${this.apiEndpoint}/stats?network=${this.currentNetwork}`);
            const stats = await response.json();
            
            document.getElementById('blockHeight').textContent = (stats.current_height || 0).toLocaleString();
            document.getElementById('totalTx').textContent = (stats.total_transactions || 0).toLocaleString();
            document.getElementById('hashRate').textContent = this.formatHashRate(stats.hash_rate || '0 H/s');
            document.getElementById('activeNodes').textContent = stats.active_nodes || 0;
        } catch (error) {
            console.error('Failed to load network stats:', error);
        }
    }

    async loadLatestBlocks() {
        try {
            const response = await fetch(`${this.apiEndpoint}/blocks?network=${this.currentNetwork}&page=${this.currentBlockPage}&limit=${this.pageSize}`);
            const data = await response.json();
            const blocks = data.blocks || [];
            
            const container = document.getElementById('latestBlocks');
            if (this.currentBlockPage === 0) {
                container.innerHTML = '';
            }
            
            blocks.forEach(block => {
                container.appendChild(this.createBlockCard(block));
            });
        } catch (error) {
            console.error('Failed to load blocks:', error);
        }
    }

    async loadLatestTransactions() {
        try {
            const response = await fetch(`${this.apiEndpoint}/transactions?network=${this.currentNetwork}&page=${this.currentTxPage}&limit=${this.pageSize}`);
            const data = await response.json();
            const transactions = data.transactions || [];
            
            const container = document.getElementById('latestTransactions');
            if (this.currentTxPage === 0) {
                container.innerHTML = '';
            }
            
            transactions.forEach(tx => {
                container.appendChild(this.createTransactionCard(tx));
            });
        } catch (error) {
            console.error('Failed to load transactions:', error);
        }
    }

    createBlockCard(block) {
        const card = document.createElement('div');
        card.className = 'block-card';
        
        const ageText = this.timeAgo(block.timestamp);
        const txCount = block.transactions ? block.transactions.length : 0;
        
        card.innerHTML = `
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <h6 class="mb-1">
                        <i class="fas fa-cube text-primary"></i>
                        Block #${block.index}
                    </h6>
                    <p class="text-muted mb-1">
                        <small><i class="fas fa-clock"></i> ${ageText}</small>
                    </p>
                    <p class="text-muted mb-1">
                        <small><i class="fas fa-exchange-alt"></i> ${txCount} transactions</small>
                    </p>
                </div>
                <div class="text-end">
                    <div class="text-muted">
                        <small>Validator:</small><br>
                        <code>${this.truncateHash(block.validator)}</code>
                    </div>
                </div>
            </div>
            <div class="mt-2">
                <div class="hash-display">
                    <strong>Hash:</strong> ${block.hash}
                </div>
            </div>
            <div class="mt-2">
                <button class="btn btn-sm btn-outline-primary" onclick="explorer.viewBlock('${block.hash}')">
                    View Block
                </button>
            </div>
        `;
        
        return card;
    }

    createTransactionCard(tx) {
        const card = document.createElement('div');
        card.className = 'tx-card';
        
        const ageText = this.timeAgo(tx.timestamp);
        const statusClass = this.getStatusClass(tx.status);
        
        card.innerHTML = `
            <div class="d-flex justify-content-between align-items-start">
                <div class="flex-grow-1">
                    <h6 class="mb-1">
                        <i class="fas fa-exchange-alt text-success"></i>
                        Transaction
                        <span class="status-badge ${statusClass}">${this.getStatusText(tx.status)}</span>
                    </h6>
                    <p class="text-muted mb-1">
                        <small><i class="fas fa-clock"></i> ${ageText}</small>
                    </p>
                    <div class="row">
                        <div class="col-6">
                            <small class="text-muted">From:</small><br>
                            <code>${this.truncateHash(tx.from)}</code>
                        </div>
                        <div class="col-6">
                            <small class="text-muted">To:</small><br>
                            <code>${this.truncateHash(tx.to)}</code>
                        </div>
                    </div>
                </div>
                <div class="text-end">
                    <div class="h6 mb-0 text-primary">
                        ${this.formatAmount(tx.amount)} 
                        <small class="text-muted">${this.getCurrentSymbol()}</small>
                    </div>
                </div>
            </div>
            <div class="mt-2">
                <div class="hash-display">
                    <strong>Hash:</strong> ${tx.hash}
                </div>
            </div>
            <div class="mt-2">
                <button class="btn btn-sm btn-outline-primary" onclick="explorer.viewTransaction('${tx.hash}')">
                    View Transaction
                </button>
            </div>
        `;
        
        return card;
    }

    displaySearchResults(data) {
        const resultsContainer = document.getElementById('searchResults');
        const resultContent = document.getElementById('resultContent');
        
        resultContent.innerHTML = '';
        
        if (data.type === 'block') {
            resultContent.appendChild(this.createBlockCard(data.result));
        } else if (data.type === 'transaction') {
            resultContent.appendChild(this.createTransactionCard(data.result));
        } else if (data.type === 'address') {
            resultContent.appendChild(this.createAddressCard(data.result));
        } else {
            resultContent.innerHTML = '<div class="alert alert-warning">Nothing found</div>';
        }
        
        resultsContainer.classList.remove('d-none');
    }

    createAddressCard(address) {
        const card = document.createElement('div');
        card.className = 'block-card';
        
        card.innerHTML = `
            <h6 class="mb-3">
                <i class="fas fa-wallet text-warning"></i>
                Address
            </h6>
            <div class="hash-display mb-3">
                ${address.address}
            </div>
            <div class="row">
                <div class="col-md-3">
                    <div class="text-center">
                        <div class="h4 text-primary">${this.formatAmount(address.balance)}</div>
                        <small class="text-muted">Balance</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="text-center">
                        <div class="h4 text-info">${address.transaction_count}</div>
                        <small class="text-muted">Transactions</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="text-center">
                        <div class="h4 text-success">${address.sent_count}</div>
                        <small class="text-muted">Sent</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="text-center">
                        <div class="h4 text-warning">${address.received_count}</div>
                        <small class="text-muted">Received</small>
                    </div>
                </div>
            </div>
        `;
        
        return card;
    }

    async viewBlock(hash) {
        this.showLoading(true);
        
        try {
            const response = await fetch(`${this.apiEndpoint}/block/${hash}?network=${this.currentNetwork}`);
            const block = await response.json();
            
            // Create detailed block view modal or navigate to block page
            this.showBlockDetails(block);
        } catch (error) {
            console.error('Failed to load block details:', error);
            this.showError('Failed to load block details');
        } finally {
            this.showLoading(false);
        }
    }

    async viewTransaction(hash) {
        this.showLoading(true);
        
        try {
            const response = await fetch(`${this.apiEndpoint}/transaction/${hash}?network=${this.currentNetwork}`);
            const transaction = await response.json();
            
            this.showTransactionDetails(transaction);
        } catch (error) {
            console.error('Failed to load transaction details:', error);
            this.showError('Failed to load transaction details');
        } finally {
            this.showLoading(false);
        }
    }

    showBlockDetails(block) {
        // Create modal or detailed view for block
        alert(`Block #${block.index}\nHash: ${block.hash}\nTime: ${new Date(block.timestamp * 1000).toLocaleString()}`);
    }

    showTransactionDetails(tx) {
        // Create modal or detailed view for transaction
        alert(`Transaction: ${tx.hash}\nFrom: ${tx.from}\nTo: ${tx.to}\nAmount: ${tx.amount}`);
    }

    loadMoreBlocks() {
        this.currentBlockPage++;
        this.loadLatestBlocks();
    }

    loadMoreTransactions() {
        this.currentTxPage++;
        this.loadLatestTransactions();
    }

    async refreshData() {
        this.currentBlockPage = 0;
        this.currentTxPage = 0;
        
        await Promise.all([
            this.loadNetworkStats(),
            this.loadLatestBlocks(),
            this.loadLatestTransactions()
        ]);
    }

    // Utility functions
    truncateHash(hash, length = 8) {
        if (!hash) return 'N/A';
        return hash.length > length ? `${hash.substring(0, length)}...${hash.substring(hash.length - 4)}` : hash;
    }

    timeAgo(timestamp) {
        const now = Math.floor(Date.now() / 1000);
        const diff = now - timestamp;
        
        if (diff < 60) return `${diff} sec. ago`;
        if (diff < 3600) return `${Math.floor(diff / 60)} min. ago`;
        if (diff < 86400) return `${Math.floor(diff / 3600)} h. ago`;
        return `${Math.floor(diff / 86400)} d. ago`;
    }

    formatAmount(amount) {
        return parseFloat(amount).toLocaleString('ru-RU', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 8
        });
    }

    formatHashRate(hashRate) {
        if (hashRate >= 1e12) return `${(hashRate / 1e12).toFixed(2)} TH/s`;
        if (hashRate >= 1e9) return `${(hashRate / 1e9).toFixed(2)} GH/s`;
        if (hashRate >= 1e6) return `${(hashRate / 1e6).toFixed(2)} MH/s`;
        if (hashRate >= 1e3) return `${(hashRate / 1e3).toFixed(2)} KH/s`;
        return `${hashRate} H/s`;
    }

    getStatusClass(status) {
        switch (status) {
            case 'confirmed': return 'status-confirmed';
            case 'pending': return 'status-pending';
            case 'failed': return 'status-failed';
            default: return 'status-pending';
        }
    }

    getStatusText(status) {
        switch (status) {
            case 'confirmed': return 'Confirmed';
            case 'pending': return 'Pending';
            case 'failed': return 'Failed';
            default: return 'Unknown';
        }
    }

    getCurrentSymbol() {
        // Get token symbol based on current network
        const symbols = {
            'mainnet': 'MBC',
            'testnet': 'tMBC',
            'devnet': 'dMBC'
        };
        return symbols[this.currentNetwork] || 'MBC';
    }

    showLoading(show) {
        const spinner = document.getElementById('loadingSpinner');
        if (show) {
            spinner.style.display = 'block';
        } else {
            spinner.style.display = 'none';
        }
    }

    showError(message) {
        // Simple error display - could be enhanced with a proper modal
        alert(`Error: ${message}`);
    }
}

// Global explorer instance
let explorer;

// Initialize explorer when page loads
document.addEventListener('DOMContentLoaded', function() {
    explorer = new BlockchainExplorer();
});

// Global functions for button clicks
function performSearch() {
    explorer.performSearch();
}

function loadMoreBlocks() {
    explorer.loadMoreBlocks();
}

function loadMoreTransactions() {
    explorer.loadMoreTransactions();
}
