class BlockchainExplorer {
    constructor() {
        this.currentNetwork = 'mainnet';
        this.apiEndpoint = '../api/explorer';
        this.currentBlockPage = 1;
        this.currentTxPage = 1;
        this.pageSize = 5; // Reduced for better UX
        this.totalBlocks = 0;
        this.totalTransactions = 0;
        
        this.initializeExplorer();
    }

    async initializeExplorer() {
        this.bindEvents();
        
        // Load configuration first
        await this.loadConfig();
        
        // Load initial data
        await this.loadNetworkStats();
        await this.loadLatestBlocks();
        await this.loadLatestTransactions();
        
        // Auto-refresh every 30 seconds
        setInterval(() => {
            this.refreshData();
        }, 30000);
    }

    async loadConfig() {
        try {
            const response = await fetch('../wallet/wallet_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'get_config' })
            });
            
            const data = await response.json();
            if (data.success && data.config) {
                // Update global symbols
                if (typeof CRYPTO_SYMBOL !== 'undefined') {
                    window.CRYPTO_SYMBOL = data.config.crypto_symbol || CRYPTO_SYMBOL;
                }
                if (typeof CRYPTO_NAME !== 'undefined') {
                    window.CRYPTO_NAME = data.config.crypto_name || CRYPTO_NAME;
                }
                console.log('Explorer config loaded:', data.config);
            } else {
                console.log('Config load failed, using defaults');
            }
        } catch (error) {
            console.log('Config load failed, using defaults:', error);
        }
    }

    refreshData() {
        this.loadNetworkStats();
        this.loadLatestBlocks();
        this.loadLatestTransactions();
    }

    bindEvents() {
        // Network selection
        document.getElementById('networkSelect').addEventListener('change', (e) => {
            this.currentNetwork = e.target.value;
            this.currentBlockPage = 1;
            this.currentTxPage = 1;
            this.refreshData();
        });

        // Search on Enter key
        document.getElementById('searchInput').addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                this.performSearch();
            }
        });
    }

    // New methods for pagination
    async loadBlocks(direction = 'current') {
        if (direction === 'prev' && this.currentBlockPage > 1) {
            this.currentBlockPage--;
        } else if (direction === 'next') {
            this.currentBlockPage++;
        }
        
        await this.loadLatestBlocks();
        this.updateBlocksPagination();
    }

    async loadTransactions(direction = 'current') {
        if (direction === 'prev' && this.currentTxPage > 1) {
            this.currentTxPage--;
        } else if (direction === 'next') {
            this.currentTxPage++;
        }
        
        await this.loadLatestTransactions();
        this.updateTransactionsPagination();
    }

    updateBlocksPagination() {
        const prevBtn = document.getElementById('prevBlocksBtn');
        const nextBtn = document.getElementById('nextBlocksBtn');
        const pageInfo = document.getElementById('blocksPageInfo');
        
        prevBtn.disabled = this.currentBlockPage <= 1;
        
        // Get page text from translations or fallback
        const pageText = (typeof t !== 'undefined' && t.page) ? t.page : 'Page';
        pageInfo.textContent = `${pageText} ${this.currentBlockPage}`;
        
        // Show "Next" button only if there's data on current page
        const blocksContainer = document.getElementById('latestBlocks');
        const hasBlocks = blocksContainer.children.length > 0;
        nextBtn.disabled = !hasBlocks;
    }

    updateTransactionsPagination() {
        const prevBtn = document.getElementById('prevTxBtn');
        const nextBtn = document.getElementById('nextTxBtn');
        const pageInfo = document.getElementById('transactionsPageInfo');
        
        prevBtn.disabled = this.currentTxPage <= 1;
        
        // Get page text from translations or fallback
        const pageText = (typeof t !== 'undefined' && t.page) ? t.page : 'Page';
        pageInfo.textContent = `${pageText} ${this.currentTxPage}`;
        
        // Show "Next" button only if there's data on current page
        const txContainer = document.getElementById('latestTransactions');
        const hasTx = txContainer.children.length > 0;
        nextBtn.disabled = !hasTx;
    }

    async refreshBlocks() {
        this.showLoading(true);
        await this.loadLatestBlocks();
        this.showLoading(false);
    }

    async refreshTransactions() {
        this.showLoading(true);
        await this.loadLatestTransactions();
        this.showLoading(false);
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
            const offset = (this.currentBlockPage - 1) * this.pageSize;
            const response = await fetch(`${this.apiEndpoint}/blocks?network=${this.currentNetwork}&offset=${offset}&limit=${this.pageSize}`);
            const data = await response.json();
            const blocks = data.blocks || [];
            
            const container = document.getElementById('latestBlocks');
            container.innerHTML = '';
            
            if (blocks.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <div class="empty-icon">
                            <i class="fas fa-cube"></i>
                        </div>
                        <p>${this.getTranslation('no_blocks_found', 'No blocks found')}</p>
                    </div>
                `;
            } else {
                blocks.forEach(block => {
                    container.appendChild(this.createBlockCard(block));
                });
            }
            
            this.updateBlocksPagination();
        } catch (error) {
            console.error('Failed to load blocks:', error);
            this.showErrorInContainer('latestBlocks', this.getTranslation('error_loading_blocks', 'Error loading blocks'));
        }
    }

    async loadLatestTransactions() {
        try {
            const offset = (this.currentTxPage - 1) * this.pageSize;
            const response = await fetch(`${this.apiEndpoint}/transactions?network=${this.currentNetwork}&offset=${offset}&limit=${this.pageSize}`);
            const data = await response.json();
            const transactions = data.transactions || [];
            
            const container = document.getElementById('latestTransactions');
            container.innerHTML = '';
            
            if (transactions.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <div class="empty-icon">
                            <i class="fas fa-exchange-alt"></i>
                        </div>
                        <p>${this.getTranslation('no_transactions_found', 'No transactions found')}</p>
                    </div>
                `;
            } else {
                transactions.forEach(tx => {
                    container.appendChild(this.createTransactionCard(tx));
                });
            }
            
            this.updateTransactionsPagination();
        } catch (error) {
            console.error('Failed to load transactions:', error);
            this.showErrorInContainer('latestTransactions', this.getTranslation('error_loading_transactions', 'Error loading transactions'));
        }
    }

    getTranslation(key, fallback) {
        return (typeof t !== 'undefined' && t[key]) ? t[key] : fallback;
    }

    showErrorInContainer(containerId, message) {
        const container = document.getElementById(containerId);
        container.innerHTML = `
            <div class="empty-state">
                <div class="empty-icon">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <p>${message}</p>
            </div>
        `;
    }

    createBlockCard(block) {
        const card = document.createElement('div');
        card.className = 'data-card';
        
        const ageText = this.timeAgo(block.timestamp);
        const txCount = block.transactions ? block.transactions.length : 0;
        
        const blockText = this.getTranslation('block', 'Block');
        const confirmedText = this.getTranslation('confirmed', 'Confirmed');
        const hashText = this.getTranslation('hash', 'Hash');
        const validatorText = this.getTranslation('validator', 'Validator');
        const sizeText = this.getTranslation('size', 'Size');
        const detailsText = this.getTranslation('details', 'Details');
        const transactionsText = this.getTranslation('transactions', 'transactions');
        
        card.innerHTML = `
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <div class="d-flex align-items-center mb-2">
                        <div class="stat-icon" style="width: 30px; height: 30px; font-size: 0.9rem; margin-right: 0.75rem;">
                            <i class="fas fa-cube"></i>
                        </div>
                        <h6 class="mb-0 fw-bold">${blockText} #${block.index || block.height || '?'}</h6>
                    </div>
                    <div class="d-flex gap-3 text-muted small">
                        <span><i class="fas fa-clock me-1"></i>${ageText}</span>
                        <span><i class="fas fa-exchange-alt me-1"></i>${txCount} ${transactionsText}</span>
                    </div>
                </div>
                <div class="text-end">
                    <span class="status-badge status-confirmed">${confirmedText}</span>
                </div>
            </div>
            
            <div class="row g-2 mb-3">
                <div class="col-6">
                    <small class="text-muted d-block">${hashText}</small>
                    <div class="hash-display small">${this.truncateHash(block.hash, 16)}</div>
                </div>
                <div class="col-6">
                    <small class="text-muted d-block">${validatorText}</small>
                    <div class="hash-display small">${this.truncateHash(block.validator || 'N/A', 16)}</div>
                </div>
            </div>
            
            <div class="d-flex justify-content-between align-items-center">
                <small class="text-muted">
                    <i class="fas fa-weight-hanging me-1"></i>
                    ${sizeText}: ${(JSON.stringify(block).length / 1024).toFixed(2)} KB
                </small>
                <button class="btn btn-outline-primary btn-sm" onclick="explorer.viewBlock('${block.hash}')">
                    <i class="fas fa-eye me-1"></i>${detailsText}
                </button>
            </div>
        `;
        
        return card;
    }

    createTransactionCard(tx) {
        const card = document.createElement('div');
        card.className = 'data-card';
        
        const ageText = this.timeAgo(tx.timestamp);
        const statusClass = this.getStatusClass(tx.status);
        const amount = parseFloat(tx.amount || 0);
        
        const transactionText = this.getTranslation('transaction', 'Transaction');
        const fromText = this.getTranslation('from', 'From');
        const toText = this.getTranslation('to', 'To');
        const feeText = this.getTranslation('fee', 'Fee');
        const blockText = this.getTranslation('block', 'Block');
        const pendingText = this.getTranslation('pending', 'Pending');
        const detailsText = this.getTranslation('details', 'Details');
        const transactionHashText = this.getTranslation('transaction_hash', 'Transaction Hash');
        
        card.innerHTML = `
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <div class="d-flex align-items-center mb-2">
                        <div class="stat-icon" style="width: 30px; height: 30px; font-size: 0.9rem; margin-right: 0.75rem;">
                            <i class="fas fa-exchange-alt"></i>
                        </div>
                        <h6 class="mb-0 fw-bold">${transactionText}</h6>
                    </div>
                    <div class="d-flex gap-3 text-muted small">
                        <span><i class="fas fa-clock me-1"></i>${ageText}</span>
                        <span class="status-badge ${statusClass}">${this.getStatusText(tx.status)}</span>
                    </div>
                </div>
                <div class="text-end">
                    <div class="fw-bold text-primary">${this.formatAmount(amount)}</div>
                    <small class="text-muted">${feeText}: ${this.formatAmount(tx.fee || 0)}</small>
                </div>
            </div>
            
            <div class="row g-2 mb-3">
                <div class="col-6">
                    <small class="text-muted d-block">${fromText}</small>
                    <div class="hash-display small">${this.truncateHash(tx.from, 16)}</div>
                </div>
                <div class="col-6">
                    <small class="text-muted d-block">${toText}</small>
                    <div class="hash-display small">${this.truncateHash(tx.to, 16)}</div>
                </div>
            </div>
            
            <div class="row g-2 mb-3">
                <div class="col-12">
                    <small class="text-muted d-block">${transactionHashText}</small>
                    <div class="hash-display small">${this.truncateHash(tx.hash, 20)}</div>
                </div>
            </div>
            
            <div class="d-flex justify-content-between align-items-center">
                <small class="text-muted">
                    <i class="fas fa-layer-group me-1"></i>
                    ${blockText}: #${tx.block_height || pendingText}
                </small>
                <button class="btn btn-outline-primary btn-sm" onclick="explorer.viewTransaction('${tx.hash}')">
                    <i class="fas fa-eye me-1"></i>${detailsText}
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
        const formattedAmount = parseFloat(amount).toLocaleString('ru-RU', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 8
        });
        
        // Используем символ валюты из конфигурации или fallback
        const symbol = (typeof CRYPTO_SYMBOL !== 'undefined') ? CRYPTO_SYMBOL : 'COIN';
        return `${formattedAmount} ${symbol}`;
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
        const statusMap = {
            'confirmed': this.getTranslation('confirmed', 'Confirmed'),
            'pending': this.getTranslation('pending', 'Pending'),
            'failed': this.getTranslation('failed', 'Failed')
        };
        return statusMap[status] || this.getTranslation('unknown', 'Unknown');
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

    getStatusClass(status) {
        switch (status?.toLowerCase()) {
            case 'confirmed': return 'status-confirmed';
            case 'pending': return 'status-pending';
            case 'failed': return 'status-failed';
            default: return 'status-pending';
        }
    }

    getStatusText(status) {
        const statusMap = {
            'confirmed': this.getTranslation('confirmed', 'Confirmed'),
            'pending': this.getTranslation('pending', 'Pending'),
            'failed': this.getTranslation('failed', 'Failed')
        };
        return statusMap[status?.toLowerCase()] || this.getTranslation('unknown', 'Unknown');
    }

    truncateHash(hash, length = 12) {
        if (!hash) return 'N/A';
        if (hash.length <= length) return hash;
        return `${hash.substring(0, length/2)}...${hash.substring(hash.length - length/2)}`;
    }

    showError(message) {
        // Simple error display - could be enhanced with a proper modal
        alert(`Ошибка: ${message}`);
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

function loadBlocks(direction) {
    explorer.loadBlocks(direction);
}

function loadTransactions(direction) {
    explorer.loadTransactions(direction);
}

function refreshBlocks() {
    explorer.refreshBlocks();
}

function refreshTransactions() {
    explorer.refreshTransactions();
}

function loadMoreBlocks() {
    explorer.loadMoreBlocks();
}

function loadMoreTransactions() {
    explorer.loadMoreTransactions();
}
