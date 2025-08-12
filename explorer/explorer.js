console.log('Explorer.js loaded successfully');

// Simple test to ensure JavaScript is working
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM loaded - JavaScript is working!');
    
    // Test if we can find elements
    const testElement = document.getElementById('blockHeight');
    if (testElement) {
        console.log('Found blockHeight element:', testElement);
        testElement.textContent = 'TEST - JavaScript is working!';
    } else {
        console.error('blockHeight element not found!');
    }
});

class BlockchainExplorer {
    constructor() {
        console.log('BlockchainExplorer constructor called');
        
        this.currentNetwork = 'mainnet';
        this.apiEndpoint = '/api/explorer';
        this.currentBlockPage = 1;
        this.currentTxPage = 1;
        this.currentContractPage = 1;
        this.pageSize = 5; // Reduced for better UX
        this.totalBlocks = 0;
        this.totalTransactions = 0;
        
        console.log('Constructor completed, calling initializeExplorer...');
        this.initializeExplorer();
    }

    async initializeExplorer() {
        console.log('Initializing explorer...');
        
        this.bindEvents();
        
        // Load configuration first
        console.log('Loading config...');
        await this.loadConfig();
        
        // Load initial data
        console.log('Loading network stats...');
        await this.loadNetworkStats();
        
        console.log('Loading latest blocks...');
        await this.loadLatestBlocks();
        
        console.log('Loading latest transactions...');
        await this.loadLatestTransactions();
        
        console.log('Loading latest contracts...');
        await this.loadLatestContracts();
        
        console.log('Explorer initialization complete');
        
        // Auto-refresh every 30 seconds
        setInterval(() => {
            console.log('Auto-refreshing data...');
            this.refreshData();
        }, 30000);
    }

    async loadConfig() {
        try {
            // Use PHP variables passed from the page
            if (typeof CRYPTO_SYMBOL !== 'undefined') {
                window.CRYPTO_SYMBOL = CRYPTO_SYMBOL;
            }
            if (typeof CRYPTO_NAME !== 'undefined') {
                window.CRYPTO_NAME = CRYPTO_NAME;
            }
            
            console.log('Explorer config loaded from PHP variables:', {
                symbol: window.CRYPTO_SYMBOL,
                name: window.CRYPTO_NAME
            });
        } catch (error) {
            console.log('Config load failed:', error);
        }
    }

    refreshData() {
        this.loadNetworkStats();
        this.loadLatestBlocks();
        this.loadLatestTransactions();
    this.loadLatestContracts();
    }

    bindEvents() {
        console.log('Binding events...');
        
        // Network selection (only if element exists)
        const networkSelect = document.getElementById('networkSelect');
        if (networkSelect) {
            console.log('Found networkSelect, binding change event');
            networkSelect.addEventListener('change', (e) => {
                this.currentNetwork = e.target.value;
                this.currentBlockPage = 1;
                this.currentTxPage = 1;
                this.refreshData();
            });
        } else {
            console.log('networkSelect element not found, skipping');
        }

        // Search on Enter key (only if element exists)
        const searchInput = document.getElementById('searchInput');
        if (searchInput) {
            console.log('Found searchInput, binding keypress event');
            searchInput.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') {
                    this.performSearch();
                }
            });
        } else {
            console.log('searchInput element not found, skipping');
        }
        
        console.log('Events binding completed');
    }

    // New methods for pagination
    async loadBlocks(direction = 'current') {
        console.log('loadBlocks called with direction:', direction, 'current page:', this.currentBlockPage);
        
        if (direction === 'prev' && this.currentBlockPage > 1) {
            this.currentBlockPage--;
            console.log('Going to previous page, new page:', this.currentBlockPage);
        } else if (direction === 'next') {
            this.currentBlockPage++;
            console.log('Going to next page, new page:', this.currentBlockPage);
        }
        
        console.log('Loading blocks for page:', this.currentBlockPage);
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

    async loadContracts(direction = 'current') {
        if (direction === 'prev' && this.currentContractPage > 1) {
            this.currentContractPage--;
        } else if (direction === 'next') {
            this.currentContractPage++;
        }
        await this.loadLatestContracts();
        this.updateContractsPagination();
    }

    updateContractsPagination() {
        const prevBtn = document.getElementById('prevContractsBtn');
        const nextBtn = document.getElementById('nextContractsBtn');
        const pageInfo = document.getElementById('contractsPageInfo');

        if (!prevBtn || !nextBtn || !pageInfo) return;

        prevBtn.disabled = this.currentContractPage <= 1;
        const pageText = (typeof t !== 'undefined' && t.page) ? t.page : 'Page';
        pageInfo.textContent = `${pageText} ${this.currentContractPage}`;

        const container = document.getElementById('latestContracts');
        const hasItems = container && container.children.length > 0;
        nextBtn.disabled = !hasItems;
    }

    updateBlocksPagination() {
        console.log('updateBlocksPagination called, current page:', this.currentBlockPage);
        
        const prevBtn = document.getElementById('prevBlocksBtn');
        const nextBtn = document.getElementById('nextBlocksBtn');
        const pageInfo = document.getElementById('blocksPageInfo');
        
        console.log('Pagination elements found:', {
            prevBtn: !!prevBtn,
            nextBtn: !!nextBtn,
            pageInfo: !!pageInfo
        });
        
        if (prevBtn) {
            prevBtn.disabled = this.currentBlockPage <= 1;
            console.log('Prev button disabled:', prevBtn.disabled);
        }
        
        // Get page text from translations or fallback
        const pageText = (typeof t !== 'undefined' && t.page) ? t.page : 'Page';
        if (pageInfo) {
            // Get total blocks from network stats or use default
            const totalBlocks = window.totalBlocks || 99;
            const totalPages = Math.ceil(totalBlocks / this.pageSize);
            let pageDisplay = `${pageText} ${this.currentBlockPage}`;
            
            if (totalPages > 10) {
                // Show range for many pages
                if (this.currentBlockPage <= 5) {
                    pageDisplay = `${pageText} ${this.currentBlockPage} из 1...${totalPages}`;
                } else if (this.currentBlockPage >= totalPages - 4) {
                    pageDisplay = `${pageText} ${this.currentBlockPage} из 1...${totalPages}`;
                } else {
                    pageDisplay = `${pageText} ${this.currentBlockPage} из 1...${this.currentBlockPage}...${totalPages}`;
                }
            } else {
                pageDisplay = `${pageText} ${this.currentBlockPage} из ${totalPages}`;
            }
            
            pageInfo.textContent = pageDisplay;
            console.log('Page info updated:', pageInfo.textContent);
        }
        
        // Show "Next" button only if there's data on current page
        const blocksContainer = document.getElementById('latestBlocks');
        const hasBlocks = blocksContainer && blocksContainer.children.length > 0;
        if (nextBtn) {
            nextBtn.disabled = !hasBlocks;
            console.log('Next button disabled:', nextBtn.disabled, 'hasBlocks:', hasBlocks);
        }
        
        console.log('Pagination update completed');
    }

    updateTransactionsPagination() {
        const prevBtn = document.getElementById('prevTxBtn');
        const nextBtn = document.getElementById('nextTxBtn');
        const pageInfo = document.getElementById('transactionsPageInfo');
        
        prevBtn.disabled = this.currentTxPage <= 1;
        
        // Get page text from translations or fallback
        const pageText = (typeof t !== 'undefined' && t.page) ? t.page : 'Page';
        
        // Get total transactions from network stats or use default
        const totalTransactions = window.totalTransactions || 222;
        const totalPages = Math.ceil(totalTransactions / this.pageSize);
        let pageDisplay = `${pageText} ${this.currentTxPage}`;
        
        if (totalPages > 10) {
            // Show range for many pages
            if (this.currentTxPage <= 5) {
                pageDisplay = `${pageText} ${this.currentTxPage} из 1...${totalPages}`;
            } else if (this.currentTxPage >= totalPages - 4) {
                pageDisplay = `${pageText} ${this.currentTxPage} из 1...${totalPages}`;
            } else {
                pageDisplay = `${pageText} ${this.currentTxPage} из 1...${this.currentTxPage}...${totalPages}`;
            }
        } else {
            pageDisplay = `${pageText} ${this.currentTxPage} из ${totalPages}`;
        }
        
        pageInfo.textContent = pageDisplay;
        
        // Show "Next" button only if there's data on current page
        const txContainer = document.getElementById('latestTransactions');
        const hasTx = txContainer && txContainer.children.length > 0;
        nextBtn.disabled = !hasTx;
    }

    async refreshBlocks() {
        this.showLoading(true);
        // Reset to first page when manually refreshing
        this.currentBlockPage = 1;
        await this.loadLatestBlocks();
        this.showLoading(false);
    }

    async refreshTransactions() {
        this.showLoading(true);
        // Reset to first page when manually refreshing
        this.currentTxPage = 1;
        await this.loadLatestTransactions();
        this.showLoading(false);
    }

    async refreshContracts() {
        this.showLoading(true);
        await this.loadLatestContracts();
        this.showLoading(false);
    }

    async performSearch() {
        const query = document.getElementById('searchInput').value.trim();
        if (!query) return;

        this.showLoading(true);
        
        try {
            console.log('Searching for:', query);
            const url = `${this.apiEndpoint}/search?query=${encodeURIComponent(query)}&network=${this.currentNetwork}`;
            console.log('Search URL:', url);
            
            const response = await fetch(url);
            console.log('Response status:', response.status);
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const data = await response.json();
            console.log('Search response:', data);
            
            if (data.error) {
                this.showError(data.error);
                return;
            }
            
            this.displaySearchResults(data);
        } catch (error) {
            console.error('Search failed:', error);
            this.showError(`Search failed: ${error.message}`);
        } finally {
            this.showLoading(false);
        }
    }

    async loadNetworkStats() {
        try {
            console.log('Fetching network stats from:', `${this.apiEndpoint}/index.php?action=get_network_stats&network=${this.currentNetwork}`);
            
            // Test if fetch is available
            if (typeof fetch === 'undefined') {
                console.error('Fetch is not available!');
                return;
            }
            
            const response = await fetch(`${this.apiEndpoint}/index.php?action=get_network_stats&network=${this.currentNetwork}`);
            console.log('Response status:', response.status);
            
            if (!response.ok) {
                console.error('Response not ok:', response.status, response.statusText);
                return;
            }
            
            const stats = await response.json();
            console.log('Stats data received:', stats);
            
            const blockHeightEl = document.getElementById('blockHeight');
            const totalTxEl = document.getElementById('totalTx');
            const hashRateEl = document.getElementById('hashRate');
            const activeNodesEl = document.getElementById('activeNodes');
            
            console.log('DOM elements found:', {
                blockHeight: !!blockHeightEl,
                totalTx: !!totalTxEl,
                hashRate: !!hashRateEl,
                activeNodes: !!activeNodesEl
            });
            
            if (blockHeightEl) {
                blockHeightEl.textContent = (stats.current_height || 0).toLocaleString();
                console.log('Updated blockHeight with:', stats.current_height);
            }
            if (totalTxEl) {
                totalTxEl.textContent = (stats.total_transactions || 0).toLocaleString();
                console.log('Updated totalTx with:', stats.total_transactions);
            }
            if (hashRateEl) {
                hashRateEl.textContent = stats.hash_rate || '0 H/s';
                console.log('Updated hashRate with:', stats.hash_rate);
            }
            if (activeNodesEl) {
                activeNodesEl.textContent = stats.active_nodes || 0;
                console.log('Updated activeNodes with:', stats.active_nodes);
            }
            
            // Save stats to global variables for pagination
            window.totalBlocks = stats.current_height || 0;
            window.totalTransactions = stats.total_transactions || 0;
            console.log('Saved global stats:', { totalBlocks: window.totalBlocks, totalTransactions: window.totalTransactions });
            
            console.log('Network stats loaded and updated successfully');
        } catch (error) {
            console.error('Failed to load network stats:', error);
        }
    }

    async loadLatestBlocks() {
        try {
            const page = this.currentBlockPage - 1; // API uses 0-based indexing
            const url = `${this.apiEndpoint}/index.php?action=get_all_blocks&network=${this.currentNetwork}&page=${page}&limit=${this.pageSize}`;
            console.log('Loading blocks from URL:', url, 'page:', this.currentBlockPage);
            
            const response = await fetch(url);
            console.log('Blocks response status:', response.status);
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            const data = await response.json();
            console.log('Blocks response data:', data);
            
            if (data.error) {
                console.error('API returned error:', data.error);
                throw new Error(data.error);
            }
            
            const blocks = data.data || [];
            console.log('Blocks array:', blocks);
            
            const container = document.getElementById('latestBlocks');
            if (!container) {
                console.error('Container latestBlocks not found');
                return;
            }
            
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
                blocks.length > 0 && blocks.forEach(block => {
                    container.appendChild(this.createBlockCard(block));
                });
            }
            
            // Update pagination based on API response
            if (data.pagination) {
                window.totalBlocks = data.pagination.total;
                console.log('Total blocks from API:', data.pagination.total);
            }
            
            this.updateBlocksPagination();
            console.log('Blocks loaded:', blocks.length);
        } catch (error) {
            console.error('Failed to load blocks:', error);
            this.showErrorInContainer('latestBlocks', this.getTranslation('error_loading_blocks', 'Error loading blocks'));
        }
    }

    async loadLatestTransactions() {
        try {
            const page = this.currentTxPage - 1; // API uses 0-based indexing
            const response = await fetch(`${this.apiEndpoint}/index.php?action=get_all_transactions&network=${this.currentNetwork}&page=${page}&limit=${this.pageSize}`);
            const data = await response.json();
            console.log('Transactions response data:', data);
            
            if (data.error) {
                console.error('API returned error:', data.error);
                throw new Error(data.error);
            }
            
            const transactions = data.data || [];
            
            const container = document.getElementById('latestTransactions');
            if (!container) {
                console.error('Container latestTransactions not found');
                return;
            }
            
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
            
            // Update pagination based on API response
            if (data.pagination) {
                window.totalTransactions = data.pagination.total;
                console.log('Total transactions from API:', data.pagination.total);
            }
            
            this.updateTransactionsPagination();
            console.log('Transactions loaded:', transactions.length);
        } catch (error) {
            console.error('Failed to load transactions:', error);
            this.showErrorInContainer('latestTransactions', this.getTranslation('error_loading_transactions', 'Error loading transactions'));
        }
    }

    async loadLatestContracts() {
        try {
            const offset = (this.currentContractPage - 1) * this.pageSize;
            // Explorer API endpoint for smart contracts
            const response = await fetch(`${this.apiEndpoint}/index.php?action=get_smart_contracts&page=${this.currentContractPage - 1}&limit=${this.pageSize}&network=${this.currentNetwork}`);
            const data = await response.json();
            const contracts = data.data || [];

            const container = document.getElementById('latestContracts');
            if (!container) return;
            container.innerHTML = '';

            if (contracts.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <div class="empty-icon">
                            <i class="fas fa-file-code"></i>
                        </div>
                        <p>${this.getTranslation('no_contracts_found', 'No smart contracts found')}</p>
                    </div>
                `;
            } else {
                contracts.forEach(c => container.appendChild(this.createContractCard(c)));
            }

            // Control pagination with API has_more
            const prevBtn = document.getElementById('prevContractsBtn');
            const nextBtn = document.getElementById('nextContractsBtn');
            const pageInfo = document.getElementById('contractsPageInfo');
            if (prevBtn) prevBtn.disabled = this.currentContractPage <= 1;
            if (nextBtn) nextBtn.disabled = !(data.pagination && data.pagination.has_more);
            if (pageInfo) {
                const pageText = (typeof t !== 'undefined' && t.page) ? t.page : 'Page';
                pageInfo.textContent = `${pageText} ${this.currentContractPage}`;
            }
        } catch (error) {
            console.error('Failed to load contracts:', error);
            this.showErrorInContainer('latestContracts', this.getTranslation('error_loading_contracts', 'Error loading contracts'));
        }
    }

    createContractCard(contract) {
        const card = document.createElement('div');
        card.className = 'data-card';

        const name = contract.name || 'Contract';
        const address = contract.address || '';
        const creator = contract.creator || '';
        const status = contract.status || 'active';
        const depBlock = contract.deployment_block || 0;

        const detailsText = this.getTranslation('details', 'Details');
        const creatorText = this.getTranslation('creator', 'Creator');
        const statusText = this.getTranslation('status', 'Status');
        const deployedAtText = this.getTranslation('deployed_at_block', 'Deployed at Block');
        const addressText = this.getTranslation('address', 'Address');

        card.innerHTML = `
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <div class="d-flex align-items-center mb-2">
                        <div class="stat-icon" style="width: 30px; height: 30px; font-size: 0.9rem; margin-right: 0.75rem;">
                            <i class="fas fa-file-code"></i>
                        </div>
                        <h6 class="mb-0 fw-bold">${name}</h6>
                    </div>
                    <div class="d-flex flex-column text-muted small">
                        <span><strong>${addressText}:</strong> ${this.truncateHash(address, 20)}</span>
                        <span><strong>${creatorText}:</strong> ${this.truncateHash(creator, 20)}</span>
                    </div>
                </div>
                <div class="text-end">
                    <span class="status-badge ${status === 'active' ? 'status-confirmed' : 'status-pending'}">${status}</span>
                </div>
            </div>
            <div class="d-flex justify-content-between align-items-center">
                <small class="text-muted">
                    <i class="fas fa-cube me-1"></i>
                    ${deployedAtText}: #${depBlock}
                </small>
                <button class="btn btn-outline-primary btn-sm" onclick="explorer.viewContract('${address}')">
                    <i class="fas fa-eye me-1"></i>${detailsText}
                </button>
            </div>
        `;

        return card;
    }

    async viewContract(address) {
        try {
            // Use dedicated endpoint for single contract
            const response = await fetch(`${this.apiEndpoint}/index.php?action=get_smart_contract&address=${encodeURIComponent(address)}&network=${this.currentNetwork}`);
            const data = await response.json();
            let contract = data && data.success ? (data.data || null) : null;
            if (!contract) {
                // Fallback: try getCode via wallet API
                contract = { address, name: 'Smart Contract', bytecode: '(code not available via explorer)' };
            }

            const body = document.getElementById('contractModalBody');
            const title = document.getElementById('contractModalTitle');
            if (!body || !title) return;

            title.textContent = `${this.getTranslation('contract_details','Contract Details')}: ${this.truncateHash(address, 20)}`;

            const abiPretty = contract.abi ? (typeof contract.abi === 'string' ? contract.abi : JSON.stringify(contract.abi, null, 2)) : '[]';
            const bytecode = contract.bytecode ? (contract.bytecode.length > 120 ? `${contract.bytecode.slice(0,120)}...` : contract.bytecode) : '';

            body.innerHTML = `
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="data-card">
                            <h6 class="mb-2">${this.getTranslation('address','Address')}</h6>
                            <div class="hash-display small">${address}</div>
                        </div>
                        <div class="data-card mt-3">
                            <h6 class="mb-2">${this.getTranslation('creator','Creator')}</h6>
                            <div class="hash-display small">${contract.creator || '—'}</div>
                        </div>
                        <div class="data-card mt-3">
                            <h6 class="mb-2">${this.getTranslation('status','Status')}</h6>
                            <div class="hash-display small text-capitalize">${contract.status || 'active'}</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="data-card">
                            <h6 class="mb-2">${this.getTranslation('abi','ABI')}</h6>
                            <pre class="small" style="max-height: 260px; overflow:auto;">${abiPretty}</pre>
                        </div>
                        <div class="data-card mt-3">
                            <h6 class="mb-2">${this.getTranslation('bytecode','Bytecode')}</h6>
                            <pre class="small" style="max-height: 160px; overflow:auto;">${bytecode}</pre>
                        </div>
                    </div>
                </div>
            `;

            const modal = new bootstrap.Modal(document.getElementById('contractDetailsModal'));
            modal.show();
        } catch (e) {
            console.error('Failed to view contract:', e);
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
        const txCount = block.transaction_count !== undefined ? block.transaction_count : (block.transactions ? block.transactions.length : 0);
        
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
                        <h6 class="mb-0 fw-bold">${blockText} #${block.height || block.index || '?'}</h6>
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
                    <div class="hash-display small">${this.truncateHash(tx.from_address || tx.from, 16)}</div>
                </div>
                <div class="col-6">
                    <small class="text-muted d-block">${toText}</small>
                    <div class="hash-display small">${this.truncateHash(tx.to_address || tx.to, 16)}</div>
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
                    ${blockText}: #${tx.block_height || tx.block_index || pendingText}
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
            const response = await fetch(`${this.apiEndpoint}/block?id=${hash}&network=${this.currentNetwork}`);
            if (!response.ok) throw new Error('Block not found');
            const data = await response.json();
            const block = data.data || data.block || null;
            if (!block) throw new Error('Invalid block response');
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
            const data = await response.json();
            
            if (data.success && data.data) {
                this.showTransactionDetails(data.data);
            } else {
                this.showError('Failed to load transaction details');
            }
        } catch (error) {
            console.error('Failed to load transaction details:', error);
            this.showError('Failed to load transaction details');
        } finally {
            this.showLoading(false);
        }
    }

    showBlockDetails(block) {
        // Create modal or detailed view for block
        alert(`Block #${block.height || block.index}\nHash: ${block.hash}\nTime: ${new Date(block.timestamp * 1000).toLocaleString()}`);
    }

    showTransactionDetails(tx) {
        // Use the modal view for transaction details
        this.viewTransaction(tx.hash);
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
        // Don't reset pagination pages during auto-refresh
        // this.currentBlockPage = 0;
        // this.currentTxPage = 0;
        
        await Promise.all([
            this.loadNetworkStats(),
            this.loadLatestBlocks(),
            this.loadLatestTransactions()
        ]);
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
        const formattedAmount = parseFloat(amount).toLocaleString('en-US', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 8
        });
        
        // Используем символ валюты из конфигурации или fallback
        const symbol = window.CRYPTO_SYMBOL || (typeof CRYPTO_SYMBOL !== 'undefined' ? CRYPTO_SYMBOL : 'COIN');
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

    // Show transaction details in modal
    async viewTransaction(txHash) {
        try {
            const response = await fetch(`${this.apiEndpoint}/transaction?id=${txHash}&network=${this.currentNetwork}`);
            if (!response.ok) throw new Error('Transaction not found');
            
            const data = await response.json();
            if (!data || data.error) {
                throw new Error(data.error || 'Transaction not found');
            }

            // Handle both direct transaction data and wrapped data
            const tx = data.transaction || data;
            const amount = parseFloat(tx.amount || 0);
            const fee = parseFloat(tx.fee || 0);
            const date = new Date(tx.timestamp * 1000);
            
            // Get status from transaction data, fallback to confirmations
            const confirmations = data.confirmations || 0;
            const status = tx.status || (confirmations > 0 ? 'confirmed' : 'pending');
            const blockIndex = data.block_index !== undefined ? data.block_index : null;
            const blockHash = data.block_hash || null;

            const modalTitle = document.getElementById('modalTitle');
            const modalBody = document.getElementById('modalBody');
            
            modalTitle.textContent = this.getTranslation('transaction_details', 'Transaction Details');
            
            modalBody.innerHTML = `
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="card bg-primary bg-opacity-10 border-primary">
                            <div class="card-body text-center">
                                <i class="fas fa-exchange-alt fa-3x text-primary mb-2"></i>
                                <h4 class="text-primary">${this.formatAmount(amount)}</h4>
                                <p class="mb-0">
                                    <span class="badge bg-primary">${tx.type || 'Transaction'}</span>
                                </p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card bg-light">
                            <div class="card-body">
                                <h6 class="card-title">${this.getTranslation('status', 'Status')}</h6>
                                <p class="mb-2">
                                    <span class="badge ${this.getStatusClass(status)}">${this.getStatusText(status)}</span>
                                </p>
                                <small class="text-muted">
                                    ${this.getTranslation('block', 'Block')}: #${blockIndex !== null ? blockIndex : this.getTranslation('pending', 'Pending')}
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-12">
                        <h6 class="border-bottom pb-2 mb-3">${this.getTranslation('transaction_details', 'Transaction Details')}</h6>
                    </div>
                </div>
                
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold">${this.getTranslation('transaction_hash', 'Transaction Hash')}:</label>
                        <div class="input-group">
                            <input type="text" class="form-control font-monospace" value="${tx.hash}" readonly>
                            <button class="btn btn-outline-secondary" onclick="copyToClipboard('${tx.hash}')">
                                <i class="fas fa-copy"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">${this.getTranslation('type', 'Type')}:</label>
                        <input type="text" class="form-control" value="${tx.type || 'N/A'}" readonly>
                    </div>
                </div>
                
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold">${this.getTranslation('timestamp', 'Date & Time')}:</label>
                        <input type="text" class="form-control" value="${date.toLocaleString()}" readonly>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">${this.getTranslation('block_hash', 'Block Hash')}:</label>
                        <div class="input-group">
                            <input type="text" class="form-control font-monospace" value="${blockHash || 'N/A'}" readonly>
                            ${blockHash ? `<button class="btn btn-outline-secondary" onclick="copyToClipboard('${blockHash}')"><i class="fas fa-copy"></i></button>` : ''}
                        </div>
                    </div>
                </div>
                
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold">${this.getTranslation('from_address', 'From Address')}:</label>
                        <div class="input-group">
                            <input type="text" class="form-control font-monospace" value="${tx.from}" readonly>
                            <button class="btn btn-outline-secondary" onclick="copyToClipboard('${tx.from}')">
                                <i class="fas fa-copy"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">${this.getTranslation('to_address', 'To Address')}:</label>
                        <div class="input-group">
                            <input type="text" class="form-control font-monospace" value="${tx.to}" readonly>
                            <button class="btn btn-outline-secondary" onclick="copyToClipboard('${tx.to}')">
                                <i class="fas fa-copy"></i>
                            </button>
                        </div>
                    </div>
                </div>
                
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold">${this.getTranslation('amount', 'Amount')}:</label>
                        <input type="text" class="form-control" value="${this.formatAmount(amount)}" readonly>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">${this.getTranslation('confirmations', 'Confirmations')}:</label>
                        <input type="text" class="form-control" value="${confirmations}" readonly>
                    </div>
                </div>
                
                ${tx.metadata ? `
                <div class="row mb-3">
                    <div class="col-12">
                        <label class="form-label fw-bold">${this.getTranslation('metadata', 'Metadata')}:</label>
                        <textarea class="form-control font-monospace" rows="3" readonly>${JSON.stringify(tx.metadata, null, 2)}</textarea>
                    </div>
                </div>
                ` : ''}
            `;

            const modal = new bootstrap.Modal(document.getElementById('transactionDetailsModal'));
            modal.show();

        } catch (error) {
            console.error('Error loading transaction details:', error);
            this.showError('Failed to load transaction details');
        }
    }

    // Show block details in modal
    async viewBlock(blockHash) {
        try {
            const response = await fetch(`${this.apiEndpoint}/block?id=${blockHash}&network=${this.currentNetwork}`);
            if (!response.ok) throw new Error('Block not found');
            
            const data = await response.json();
            console.log('Block API response:', data); // Debug log
            if (!data || data.error) {
                throw new Error(data.error || 'Block not found');
            }

            // API returns {data: {...}} - block is nested in data.data
            const block = data.data || data.block || data;
            const txCount = data.transaction_count || block.transaction_count || (block.transactions ? block.transactions.length : 0);
            const blockSize = data.size || block.size || JSON.stringify(block).length;
            const date = new Date((block.timestamp || 0) * 1000);

            const modalTitle = document.getElementById('blockModalTitle');
            const modalBody = document.getElementById('blockModalBody');
            
            modalTitle.textContent = `${this.getTranslation('block_details', 'Block Details')} #${block.index || block.height || '?'}`;
            
            modalBody.innerHTML = `
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="card bg-success bg-opacity-10 border-success">
                            <div class="card-body text-center">
                                <i class="fas fa-cube fa-3x text-success mb-2"></i>
                                <h4 class="text-success">#${block.index || block.height || '0'}</h4>
                                <p class="mb-0">
                                    <span class="badge bg-success">${this.getTranslation('confirmed', 'Confirmed')}</span>
                                </p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card bg-light">
                            <div class="card-body">
                                <h6 class="card-title">${this.getTranslation('tx_count', 'Transaction Count')}</h6>
                                <h4 class="text-primary">${txCount}</h4>
                                <small class="text-muted">
                                    ${this.getTranslation('transactions', 'transactions')}
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-12">
                        <h6 class="border-bottom pb-2 mb-3">${this.getTranslation('block_details', 'Block Details')}</h6>
                    </div>
                </div>
                
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold">${this.getTranslation('hash', 'Hash')}:</label>
                        <div class="input-group">
                            <input type="text" class="form-control font-monospace" value="${block.hash || 'N/A'}" readonly>
                            <button class="btn btn-outline-secondary" onclick="copyToClipboard('${block.hash || ''}')">
                                <i class="fas fa-copy"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">${this.getTranslation('timestamp', 'Date & Time')}:</label>
                        <input type="text" class="form-control" value="${date.toLocaleString()}" readonly>
                    </div>
                </div>
                
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold">${this.getTranslation('previous_hash', 'Previous Hash')}:</label>
                        <div class="input-group">
                            <input type="text" class="form-control font-monospace" value="${block.parent_hash || block.previous_hash || 'N/A'}" readonly>
                            ${(block.parent_hash || block.previous_hash) && (block.parent_hash || block.previous_hash) !== '0' ? `<button class="btn btn-outline-secondary" onclick="copyToClipboard('${block.parent_hash || block.previous_hash}')">
                                <i class="fas fa-copy"></i>
                            </button>` : ''}
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">${this.getTranslation('validator', 'Validator')}:</label>
                        <div class="input-group">
                            <input type="text" class="form-control font-monospace" value="${block.validator || 'N/A'}" readonly>
                            ${block.validator ? `<button class="btn btn-outline-secondary" onclick="copyToClipboard('${block.validator}')">
                                <i class="fas fa-copy"></i>
                            </button>` : ''}
                        </div>
                    </div>
                </div>
                
                <div class="row mb-3">
                    <div class="col-md-4">
                        <label class="form-label fw-bold">${this.getTranslation('difficulty', 'Difficulty')}:</label>
                        <input type="text" class="form-control" value="${block.difficulty || 'N/A'}" readonly>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">${this.getTranslation('nonce', 'Nonce')}:</label>
                        <input type="text" class="form-control" value="${block.nonce !== undefined ? block.nonce : 'N/A'}" readonly>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">${this.getTranslation('size', 'Size')}:</label>
                        <input type="text" class="form-control" value="${(blockSize / 1024).toFixed(2)} KB" readonly>
                    </div>
                </div>
                
                ${block.merkle_root ? `
                <div class="row mb-3">
                    <div class="col-12">
                        <label class="form-label fw-bold">${this.getTranslation('merkle_root', 'Merkle Root')}:</label>
                        <div class="input-group">
                            <input type="text" class="form-control font-monospace" value="${block.merkle_root}" readonly>
                            <button class="btn btn-outline-secondary" onclick="copyToClipboard('${block.merkle_root}')">
                                <i class="fas fa-copy"></i>
                            </button>
                        </div>
                    </div>
                </div>
                ` : ''}
                
                ${txCount > 0 && block.transactions ? `
                <div class="row">
                    <div class="col-12">
                        <h6 class="border-bottom pb-2 mb-3">${this.getTranslation('transactions', 'Transactions')} (${txCount})</h6>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>${this.getTranslation('hash', 'Hash')}</th>
                                        <th>${this.getTranslation('from', 'From')}</th>
                                        <th>${this.getTranslation('to', 'To')}</th>
                                        <th>${this.getTranslation('amount', 'Amount')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${block.transactions.map(tx => `
                                        <tr>
                                            <td class="font-monospace">${this.truncateHash(tx.hash, 12)}</td>
                                            <td class="font-monospace">${this.truncateHash(tx.from, 12)}</td>
                                            <td class="font-monospace">${this.truncateHash(tx.to, 12)}</td>
                                            <td>${this.formatAmount(parseFloat(tx.amount || 0))}</td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                ` : ''}
            `;

            const modal = new bootstrap.Modal(document.getElementById('blockDetailsModal'));
            modal.show();

        } catch (error) {
            console.error('Error loading block details:', error);
            this.showError('Failed to load block details');
        }
    }
}

// Global explorer instance
let explorer;

// Initialize explorer when page loads
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM loaded, initializing explorer...');
    console.log('Checking if required elements exist...');
    
    // Check if required elements exist
    const requiredElements = ['blockHeight', 'totalTx', 'hashRate', 'activeNodes', 'latestBlocks', 'latestTransactions'];
    const missingElements = [];
    
    requiredElements.forEach(id => {
        const element = document.getElementById(id);
        if (!element) {
            missingElements.push(id);
            console.error(`Required element with id '${id}' not found`);
        } else {
            console.log(`Element '${id}' found:`, element);
        }
    });
    
    if (missingElements.length > 0) {
        console.error('Missing required elements:', missingElements);
        return;
    }
    
    try {
        explorer = new BlockchainExplorer();
        console.log('Explorer instance created successfully');
    } catch (error) {
        console.error('Failed to create explorer instance:', error);
    }
});

// Global functions for button clicks
function performSearch() {
    explorer.performSearch();
}

function loadBlocks(direction) {
    console.log('Global loadBlocks called with direction:', direction);
    if (explorer) {
        explorer.loadBlocks(direction);
    } else {
        console.error('Explorer instance not found!');
    }
}

function loadTransactions(direction) {
    console.log('Global loadTransactions called with direction:', direction);
    if (explorer) {
        explorer.loadTransactions(direction);
    } else {
        console.error('Explorer instance not found!');
    }
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

// Copy to clipboard function
function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(function() {
        // Show success message
        const toast = document.createElement('div');
        toast.className = 'toast-notification';
        toast.textContent = t.copied || 'Copied to clipboard!';
        toast.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            background: #28a745;
            color: white;
            padding: 10px 20px;
            border-radius: 5px;
            z-index: 9999;
            animation: slideIn 0.3s ease;
        `;
        document.body.appendChild(toast);
        
        setTimeout(() => {
            toast.remove();
        }, 2000);
    }, function(err) {
        console.error('Could not copy text: ', err);
        alert('Copy failed');
    });
}
