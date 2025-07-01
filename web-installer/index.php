<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Prevent caching -->
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>Modern Blockchain Platform - Installation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="css/installer.css?v=<?php echo time(); ?>" rel="stylesheet">
</head>
<body class="gradient-bg">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="install-card p-5">
                    <div class="text-center mb-4">
                        <h1 class="text-white mb-3">
                            <i class="fas fa-coins text-warning"></i>
                            Modern Blockchain Platform
                        </h1>
                        <p class="text-white-50">Installation and blockchain platform setup</p>
                    </div>

                    <!-- Step indicator -->
                    <div class="step-indicator">
                        <div class="step active" id="step1">1</div>
                        <div class="step pending" id="step2">2</div>
                        <div class="step pending" id="step3">3</div>
                        <div class="step pending" id="step4">4</div>
                        <div class="step pending" id="step5">5</div>
                    </div>

            <div class="installer-body">

                <!-- Installation form -->
                <form id="installForm">
                        <!-- Step 1: System Requirements -->
                        <div class="install-step" id="step-1">
                            <h3 class="text-white mb-4">
                                <i class="fas fa-server"></i>
                                System Check
                            </h3>
                            
                            <!-- Overall system status -->
                            <div class="system-status alert alert-info mb-4" role="alert">
                                <i class="fas fa-spinner fa-spin"></i> 
                                Checking system requirements...
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="requirement-check mb-3">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="text-white">
                                                <strong>PHP version (>=8.1)</strong>
                                                <small class="d-block text-white-50">Required</small>
                                            </span>
                                            <span class="badge status-checking" id="php-version">Checking...</span>
                                        </div>
                                    </div>
                                    <div class="requirement-check mb-3">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="text-white">
                                                <strong>MySQL availability</strong>
                                                <small class="d-block text-white-50">Required - database is mandatory</small>
                                            </span>
                                            <span class="badge status-checking" id="mysql-check">Checking...</span>
                                        </div>
                                    </div>
                                    <div class="requirement-check mb-3">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="text-white">
                                                <strong>OpenSSL extension</strong>
                                                <small class="d-block text-white-50">Required for cryptography</small>
                                            </span>
                                            <span class="badge status-checking" id="openssl-check">Checking...</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="requirement-check mb-3">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="text-white">
                                                <strong>cURL extension</strong>
                                                <small class="d-block text-white-50">Required for networking</small>
                                            </span>
                                            <span class="badge status-checking" id="curl-check">Checking...</span>
                                        </div>
                                    </div>
                                    <div class="requirement-check mb-3">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="text-white">
                                                <strong>Write permissions</strong>
                                                <small class="d-block text-white-50">Required for data storage</small>
                                            </span>
                                            <span class="badge status-checking" id="write-check">Checking...</span>
                                        </div>
                                    </div>
                                    <div class="requirement-check mb-3">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="text-white">
                                                <strong>Node.js (>=16.0)</strong>
                                                <small class="d-block text-white-50">Optional - for advanced features</small>
                                            </span>
                                            <span class="badge status-checking" id="node-check">Checking...</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mt-4 p-3" style="background: rgba(255,255,255,0.1); border-radius: 8px;">
                                <h6 class="text-white mb-2">
                                    <i class="fas fa-info-circle"></i> Installation Requirements
                                </h6>
                                <ul class="text-white-50 mb-0" style="font-size: 0.9rem;">
                                    <li><strong>Required:</strong> PHP 8.1+, MySQL, OpenSSL, cURL, Write permissions</li>
                                    <li><strong>Optional:</strong> Node.js (for advanced features)</li>
                                    <li><strong>Note:</strong> The blockchain uses binary file storage with MySQL for fast queries</li>
                                </ul>
                            </div>
                        </div>

                        <!-- Step 2: Node Type & Database -->
                        <div class="install-step d-none" id="step-2">
                            <h3 class="text-white mb-4">
                                <i class="fas fa-network-wired"></i>
                                Node Type & Database Configuration
                            </h3>
                            
                            <!-- Node Type Selection -->
                            <div class="mb-4 p-3" style="background: rgba(255,255,255,0.1); border-radius: 8px;">
                                <h6 class="text-white mb-3">
                                    <i class="fas fa-server"></i> Node Type
                                </h6>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="node_type" id="primary_node" value="primary" checked>
                                            <label class="form-check-label text-white" for="primary_node">
                                                <strong>Primary Node (Genesis)</strong>
                                                <small class="d-block text-white-50">Create new blockchain network with initial token supply</small>
                                            </label>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="node_type" id="regular_node" value="regular">
                                            <label class="form-check-label text-white" for="regular_node">
                                                <strong>Regular Node</strong>
                                                <small class="d-block text-white-50">Connect to existing blockchain network</small>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <h5 class="text-white mb-3">
                                <i class="fas fa-database"></i>
                                Database Settings
                            </h5>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label text-white">Database Host</label>
                                        <input type="text" class="form-control" name="db_host" value="localhost" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label text-white">Port</label>
                                        <input type="number" class="form-control" name="db_port" value="3306" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label text-white">Username</label>
                                        <input type="text" class="form-control" name="db_username" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label text-white">Password</label>
                                        <input type="password" class="form-control" name="db_password">
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div class="mb-3">
                                        <label class="form-label text-white">Database Name</label>
                                        <input type="text" class="form-control" name="db_name" value="blockchain_modern" required>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Step 3: Blockchain Configuration -->
                        <div class="install-step d-none" id="step-3">
                            <h3 class="text-white mb-4">
                                <i class="fas fa-cubes"></i>
                                Blockchain Setup
                            </h3>
                            
                            <!-- Primary Node Configuration -->
                            <div id="primary-node-config">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label text-white">Network Name</label>
                                            <input type="text" class="form-control" name="network_name" value="My Blockchain Network" required>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label text-white">Token Symbol</label>
                                            <input type="text" class="form-control" name="token_symbol" value="MBC" required>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label text-white">Initial Supply</label>
                                            <input type="number" class="form-control" name="initial_supply" value="1000000" required>
                                            <small class="form-text text-white-50">Total token supply in the network</small>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label text-white">Primary Wallet Amount</label>
                                            <input type="number" class="form-control" name="primary_wallet_amount" value="100000" required>
                                            <small class="form-text text-white-50">Amount for primary node wallet</small>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label text-white">Consensus Algorithm</label>
                                            <input type="text" class="form-control" name="consensus_algorithm" value="Proof of Stake" readonly>
                                            <small class="form-text text-white-50">Only Proof of Stake is supported</small>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label text-white">Block Time (sec)</label>
                                            <input type="number" class="form-control" name="block_time" value="10" required>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label text-white">Block Reward</label>
                                            <input type="number" step="0.01" class="form-control" name="block_reward" value="10" required>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label text-white">Minimum Staking Amount</label>
                                            <input type="number" class="form-control" name="min_stake_amount" value="1000" required>
                                            <small class="form-text text-white-50">Minimum amount for staking</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Regular Node Configuration -->
                            <div id="regular-node-config" class="d-none">
                                <div class="alert alert-info mb-4">
                                    <i class="fas fa-info-circle"></i>
                                    <strong>Connecting to existing network</strong><br>
                                    <small>Specify node addresses to connect to the blockchain network</small>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label text-white">Node Wallet Amount</label>
                                            <input type="number" class="form-control" name="node_wallet_amount" value="5000">
                                            <small class="form-text text-white-50">Amount for node wallet (optional)</small>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label text-white">Staking Amount</label>
                                            <input type="number" class="form-control" name="staking_amount" value="1000">
                                            <small class="form-text text-white-50">Amount for staking (minimum 1000)</small>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label text-white">Known Nodes List</label>
                                            <textarea class="form-control" name="known_nodes" rows="4" placeholder="http://node1.example.com&#10;http://node2.example.com&#10;https://node3.example.com"></textarea>
                                            <small class="form-text text-white-50">List of known nodes (one URL per line)</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mt-4 p-3" style="background: rgba(255,255,255,0.1); border-radius: 8px;">
                                <h6 class="text-white mb-2">
                                    <i class="fas fa-database"></i> Binary Blockchain Storage
                                </h6>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label text-white">Enable Binary Storage</label>
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" name="enable_binary_storage" id="enable_binary_storage" checked>
                                                <label class="form-check-label text-white" for="enable_binary_storage">
                                                    Use binary blockchain file (recommended)
                                                </label>
                                            </div>
                                            <small class="form-text text-white-50">Binary storage provides better performance and integrity</small>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label text-white">Enable Encryption</label>
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" name="enable_encryption" id="enable_encryption" checked>
                                                <label class="form-check-label text-white" for="enable_encryption">
                                                    Encrypt blockchain data
                                                </label>
                                            </div>
                                            <small class="form-text text-white-50">Adds an extra layer of security</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-12">
                                        <div class="mb-3">
                                            <label class="form-label text-white">Blockchain Data Directory</label>
                                            <input type="text" class="form-control" name="blockchain_data_dir" value="storage/blockchain" required>
                                            <small class="form-text text-white-50">Directory where blockchain files will be stored</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Step 4: HTTP Network Setup -->
                        <div class="install-step d-none" id="step-4">
                            <h3 class="text-white mb-4">
                                <i class="fas fa-globe"></i>
                                HTTP Network Setup
                            </h3>
                            
                            <div class="alert alert-info mb-4">
                                <i class="fas fa-globe"></i>
                                <strong>HTTP Only Network</strong><br>
                                <small>This blockchain platform is optimized to work only via HTTP/HTTPS (ports 80/443) for compatibility with shared hosting environments.</small>
                            </div>
                            
                            <div class="row">
                                <div class="col-12">
                                    <div class="mb-3">
                                        <label class="form-label text-white">
                                            <i class="fas fa-server"></i> Domain/IP Address
                                        </label>
                                        <div class="input-group">
                                            <input type="text" class="form-control" name="node_domain" placeholder="your-domain.com" required>
                                            <span class="input-group-text auto-fill-indicator d-none bg-success text-white" title="Auto-filled from current URL">
                                                <i class="fas fa-magic"></i>
                                            </span>
                                        </div>
                                        <small class="form-text text-white-50">Domain or IP address of your hosting server</small>
                                        <div class="domain-preview mt-2 p-3 rounded" id="domainPreview" style="background: rgba(0,0,0,0.3); border: 1px solid rgba(255,255,255,0.2); display: none;">
                                            <div class="d-flex align-items-center mb-2">
                                                <i class="fas fa-globe text-success me-2"></i>
                                                <span class="text-white">Current domain detected:</span>
                                                <strong class="text-warning ms-1" id="detectedDomain"></strong>
                                            </div>
                                            <div class="d-flex align-items-center">
                                                <i class="fas fa-link text-info me-2"></i>
                                                <span class="text-white-50">Full URL will be:</span>
                                                <code class="ms-2 text-info" id="fullUrl"></code>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label text-white">
                                            <i class="fas fa-shield-alt"></i> Protocol
                                        </label>
                                        <select class="form-control" name="protocol" required>
                                            <option value="">Select Protocol</option>
                                            <option value="http">HTTP (Port 80)</option>
                                            <option value="https">HTTPS (Port 443) - Recommended</option>
                                        </select>
                                        <small class="form-text text-white-50">Choose HTTPS for better security when available</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label text-white">
                                            <i class="fas fa-users"></i> Max Connections
                                        </label>
                                        <input type="number" class="form-control" name="max_peers" value="10" required>
                                        <small class="form-text text-white-50">Maximum number of connections</small>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Step 5: Administrator Settings -->
                        <div class="install-step d-none" id="step-5">
                            <h3 class="text-white mb-4">
                                <i class="fas fa-user-shield"></i>
                                Administrator
                            </h3>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label text-white">Administrator Email</label>
                                        <input type="email" class="form-control" name="admin_email" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label text-white">Password</label>
                                        <input type="password" class="form-control" name="admin_password" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label text-white">Confirm Password</label>
                                        <input type="password" class="form-control" name="admin_password_confirm" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label text-white">API Key</label>
                                        <div class="input-group">
                                            <input type="text" class="form-control" name="api_key" id="api-key" required>
                                            <button type="button" class="btn btn-outline-light" onclick="generateApiKey()">
                                                <i class="fas fa-refresh"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Navigation -->
                        <div class="d-flex justify-content-between mt-4">
                            <button type="button" class="btn btn-outline-light" id="prevBtn" onclick="previousStep()" style="display: none;">
                                <i class="fas fa-arrow-left"></i> Back
                            </button>
                            <button type="button" class="btn btn-primary" id="nextBtn" onclick="nextStep()">
                                Next <i class="fas fa-arrow-right"></i>
                            </button>
                            <button type="submit" class="btn btn-success d-none" id="installBtn">
                                <i class="fas fa-download"></i> Install
                            </button>
                        </div>
                    </form>

                    <!-- Installation Progress -->
                    <div class="d-none" id="installProgress">
                        <h3 class="text-white mb-4">
                            <i class="fas fa-cog fa-spin"></i>
                            Installation...
                        </h3>
                        <div class="progress mb-3">
                            <div class="progress-bar progress-bar-striped progress-bar-animated" 
                                 role="progressbar" style="width: 0%" id="progressBar"></div>
                        </div>
                        <p class="text-white-50" id="progressText">Initializing...</p>
                    </div>

                    <!-- Installation Error -->
                    <div class="d-none" id="installError">
                        <div class="text-center">
                            <i class="fas fa-exclamation-triangle text-warning" style="font-size: 4rem;"></i>
                            <h3 class="text-white mt-3">Installation Error</h3>
                            <p class="text-white-50 mb-4" id="errorMessage">An error occurred during installation</p>
                            <button type="button" class="btn btn-primary me-2" onclick="retryInstallation()">
                                <i class="fas fa-redo"></i> Retry Installation
                            </button>
                            <button type="button" class="btn btn-outline-light" onclick="backToForm()">
                                <i class="fas fa-arrow-left"></i> Back to Form
                            </button>
                        </div>
                    </div>

                    <!-- Wallet Creation Result -->
                    <div class="d-none" id="walletResult">
                        <div class="text-center mb-4">
                            <i class="fas fa-wallet text-success" style="font-size: 4rem;"></i>
                            <h3 class="text-white mt-3">Wallet Created!</h3>
                            <p class="text-white-50 mb-4">Save this data in a secure place - it will not be shown again</p>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <div class="card bg-dark text-white">
                                    <div class="card-header">
                                        <i class="fas fa-fingerprint"></i> Wallet Address
                                    </div>
                                    <div class="card-body">
                                        <code id="walletAddress" style="word-break: break-all; color: #28a745;"></code>
                                        <button class="btn btn-sm btn-outline-light mt-2" onclick="copyToClipboard('walletAddress')">
                                            <i class="fas fa-copy"></i> Copy
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-12 mb-3">
                                <div class="card bg-dark text-white">
                                    <div class="card-header">
                                        <i class="fas fa-eye"></i> Public Key
                                    </div>
                                    <div class="card-body">
                                        <code id="walletPublicKey" style="word-break: break-all; color: #17a2b8;"></code>
                                        <button class="btn btn-sm btn-outline-light mt-2" onclick="copyToClipboard('walletPublicKey')">
                                            <i class="fas fa-copy"></i> Copy
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-12 mb-3">
                                <div class="card bg-dark text-white">
                                    <div class="card-header">
                                        <i class="fas fa-key"></i> Private Key
                                    </div>
                                    <div class="card-body">
                                        <code id="walletPrivateKey" style="word-break: break-all; color: #ffc107;"></code>
                                        <button class="btn btn-sm btn-outline-light mt-2" onclick="copyToClipboard('walletPrivateKey')">
                                            <i class="fas fa-copy"></i> Copy
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-12 mb-3">
                                <div class="card bg-dark text-white">
                                    <div class="card-header">
                                        <i class="fas fa-seedling"></i> Mnemonic Phrase (Seed)
                                    </div>
                                    <div class="card-body">
                                        <code id="walletMnemonic" style="word-break: break-all; color: #42c1a2;"></code>
                                        <button class="btn btn-sm btn-outline-light mt-2" onclick="copyToClipboard('walletMnemonic')">
                                            <i class="fas fa-copy"></i> Copy
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            <strong>Important!</strong> Save this data in a secure place. Without it, you will not be able to restore access to your wallet. 
                            Never share your private key and mnemonic phrase with anyone.
                        </div>
                        
                        <div class="text-center">
                            <button type="button" class="btn btn-success" onclick="continueToResult()">
                                <i class="fas fa-check"></i> I have saved the wallet data
                            </button>
                        </div>
                    </div>

                    <!-- Installation Result -->
                    <div class="d-none" id="installResult">
                        <div class="text-center">
                            <i class="fas fa-check-circle text-success" style="font-size: 4rem;"></i>
                            <h3 class="text-white mt-3">Installation Complete!</h3>
                            <p class="text-white-50 mb-4">Your blockchain platform is ready to use</p>
                            <div class="row">
                                <div class="col-md-4">
                                    <a href="../wallet/" class="btn btn-primary w-100 mb-2">
                                        <i class="fas fa-wallet"></i> Wallet
                                    </a>
                                </div>
                                <div class="col-md-4">
                                    <a href="../explorer/" class="btn btn-info w-100 mb-2">
                                        <i class="fas fa-search"></i> Explorer
                                    </a>
                                </div>
                                <div class="col-md-4">
                                    <a href="../admin/" class="btn btn-warning w-100 mb-2">
                                        <i class="fas fa-cog"></i> Admin Panel
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="install.js?v=<?php echo time(); ?>"></script>
</body>
</html>
