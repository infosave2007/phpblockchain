let currentStep = 1;
const totalSteps = 5;
let systemChecks = {};
let savedFormData = {};

// Initialization on page load
document.addEventListener('DOMContentLoaded', function() {
    // HTTPS check
    enforceHTTPS();
    checkSystemRequirements();
    generateApiKey();
    // Add CSRF protection
    addCSRFProtection();
    // Restore saved form data
    restoreFormData();
    // Setup node type handlers
    setupNodeTypeHandlers();
    // Auto-fill domain and protocol
    setTimeout(autoFillDomain, 1000);
    // Initialize domain display update
    setTimeout(updateDomainDisplay, 1500);
    // Setup domain/protocol change listeners
    setupDomainListeners();
});

// Force HTTPS
function enforceHTTPS() {
    if (location.protocol !== 'https:' && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
        location.replace('https:' + window.location.href.substring(window.location.protocol.length));
    }
}

// Add CSRF protection
async function addCSRFProtection() {
    try {
        const response = await fetch('get_csrf_token.php?v=' + Date.now(), {
            headers: {
                'Cache-Control': 'no-cache',
                'Pragma': 'no-cache'
            }
        });
        const data = await response.json();
        
        // Add CSRF token to all forms
        const csrfInput = document.createElement('input');
        csrfInput.type = 'hidden';
        csrfInput.name = 'csrf_token';
        csrfInput.value = data.token;
        
        document.getElementById('installForm').appendChild(csrfInput);
    } catch (error) {
        console.warn('CSRF token generation failed:', error);
    }
}

// System requirements check
async function checkSystemRequirements() {
    const checks = [
        { id: 'php-version', endpoint: 'check_php.php', required: true },
        { id: 'mysql-check', endpoint: 'check_mysql.php', required: true },
        { id: 'openssl-check', endpoint: 'check_openssl.php', required: true },
        { id: 'curl-check', endpoint: 'check_curl.php', required: true },
        { id: 'write-check', endpoint: 'check_permissions.php', required: true },
        { id: 'node-check', endpoint: 'check_node.php', required: false }
    ];

    for (const check of checks) {
        try {
            const response = await fetch(check.endpoint + '?v=' + Date.now(), {
                headers: {
                    'Cache-Control': 'no-cache',
                    'Pragma': 'no-cache'
                }
            });
            const result = await response.json();
            
            const element = document.getElementById(check.id);
            
            // Reset badge classes and apply only custom status
            if (result.status === 'available') {
                element.setAttribute('class', 'badge status-available');
                element.innerHTML = '<i class="fas fa-check"></i> ' + (result.message || 'Available');
                systemChecks[check.id] = true;
            } else if (result.status === 'optional') {
                element.setAttribute('class', 'badge status-optional');
                element.innerHTML = '<i class="fas fa-info-circle"></i> ' + (result.message || 'Optional');
                systemChecks[check.id] = true; // Optional checks pass
            } else if (result.status === 'partial') {
                element.setAttribute('class', 'badge status-partial');
                element.innerHTML = '<i class="fas fa-exclamation-triangle"></i> ' + (result.message || 'Partial');
                systemChecks[check.id] = !check.required; // Pass if not required
            } else {
                element.setAttribute('class', 'badge status-unavailable');
                element.innerHTML = '<i class="fas fa-times"></i> ' + (result.message || 'Unavailable');
                systemChecks[check.id] = !check.required; // Pass if not required
            }
            
            // Add tooltip with details
            if (result.details || result.message) {
                element.title = result.details || result.message;
                if (result.solution) {
                    element.title += '\nSolution: ' + result.solution;
                }
                if (result.note) {
                    element.title += '\nNote: ' + result.note;
                }
            }
            
        } catch (error) {
            const element = document.getElementById(check.id);
            // On error, reset and mark as checking
            element.setAttribute('class', 'badge status-checking');
            element.innerHTML = '<i class="fas fa-question-circle"></i> Check Failed';
            systemChecks[check.id] = !check.required;
            element.title = 'Failed to check: ' + error.message;
        }
    }
    
    // Update overall status
    updateSystemCheckStatus();
}

// Update overall system check status
function updateSystemCheckStatus() {
    const requiredChecks = ['php-version', 'mysql-check', 'openssl-check', 'curl-check', 'write-check'];
    const optionalChecks = ['node-check'];
    
    const requiredPassed = requiredChecks.every(check => systemChecks[check] === true);
    const totalPassed = Object.values(systemChecks).filter(passed => passed === true).length;
    const totalChecks = Object.keys(systemChecks).length;
    
    const statusElement = document.querySelector('.system-status');
    if (statusElement) {
        if (requiredPassed) {
            statusElement.className = 'system-status alert alert-success mb-4';
            statusElement.innerHTML = `
                <i class="fas fa-check-circle"></i> 
                System Ready (${totalPassed}/${totalChecks} checks passed)
                <br><small>All required components are available</small>
            `;
        } else {
            statusElement.className = 'system-status alert alert-warning mb-4';
            statusElement.innerHTML = `
                <i class="fas fa-exclamation-triangle"></i> 
                System Issues (${totalPassed}/${totalChecks} checks passed)
                <br><small>Some required components are missing</small>
            `;
        }
    }
}

// Go to next step
async function nextStep() {
    if (currentStep === 1) {
        // Check required system requirements
        const requiredChecks = ['php-version', 'mysql-check', 'openssl-check', 'curl-check', 'write-check'];
        const requiredPassed = requiredChecks.every(check => systemChecks[check] === true);
        
        if (!requiredPassed) {
            alert('Required system components are missing. Please install them before continuing.\n\nRequired: PHP 8.1+, MySQL, OpenSSL, cURL, Write permissions');
            return;
        }
    }

    if (currentStep === 2) {
        // Check database connection
        const isValid = await validateDatabaseConnection();
        if (!isValid) {
            return;
        }
    }

    if (currentStep === 3) {
        // Validate blockchain configuration based on node type
        const nodeType = document.querySelector('input[name="node_type"]:checked').value;
        
        if (nodeType === 'primary') {
            // Validate primary node fields
            const initialSupply = parseInt(document.querySelector('[name="initial_supply"]').value);
            const primaryWalletAmount = parseInt(document.querySelector('[name="primary_wallet_amount"]').value);
            const minStakeAmount = parseInt(document.querySelector('[name="min_stake_amount"]').value);
            
            if (primaryWalletAmount > initialSupply) {
                alert('Primary wallet amount cannot exceed total supply');
                return;
            }
            
            if (minStakeAmount > primaryWalletAmount) {
                alert('Minimum staking amount cannot exceed primary wallet amount');
                return;
            }
        } else {
            // Validate regular node fields
            const networkNodes = document.querySelector('[name="network_nodes"]').value.trim();
            if (!networkNodes) {
                alert('For regular node, you must specify a list of network nodes');
                return;
            }
            
            // Validate that network nodes list contains at least one valid URL
            const nodeUrls = networkNodes.split('\n').filter(url => url.trim()).map(url => url.trim());
            if (nodeUrls.length === 0) {
                alert('Please provide at least one network node URL');
                return;
            }
            
            // Basic URL validation
            const invalidUrls = nodeUrls.filter(url => {
                try {
                    new URL(url);
                    return false;
                } catch {
                    return true;
                }
            });
            
            if (invalidUrls.length > 0) {
                alert('Invalid URLs found in network nodes list: ' + invalidUrls.join(', '));
                return;
            }
            
            // Staking amount will be automatically determined from network configuration
        }
    }
    
    if (currentStep === 5) {
        // Check passwords
        const password = document.querySelector('[name="admin_password"]').value;
        const confirm = document.querySelector('[name="admin_password_confirm"]').value;
        
        if (password !== confirm) {
            alert('Passwords do not match!');
            return;
        }
        
        if (password.length < 8) {
            alert('Password must be at least 8 characters!');
            return;
        }
    }

    if (currentStep < totalSteps) {
        document.getElementById(`step-${currentStep}`).classList.add('d-none');
        currentStep++;
        document.getElementById(`step-${currentStep}`).classList.remove('d-none');
        updateStepIndicator();
        updateNavigation();
        
        // Auto-fill domain and protocol when reaching step 4
        if (currentStep === 4) {
            setTimeout(async () => {
                await autoFillDomain();
                updateDomainDisplay();
            }, 100);
        }
    }
}

// Go to previous step
function previousStep() {
    if (currentStep > 1) {
        document.getElementById(`step-${currentStep}`).classList.add('d-none');
        currentStep--;
        document.getElementById(`step-${currentStep}`).classList.remove('d-none');
        updateStepIndicator();
        updateNavigation();
    }
}

// Update step indicator
function updateStepIndicator() {
    for (let i = 1; i <= totalSteps; i++) {
        const step = document.getElementById(`step${i}`);
        if (i < currentStep) {
            step.className = 'step completed';
        } else if (i === currentStep) {
            step.className = 'step active';
        } else {
            step.className = 'step pending';
        }
    }
}

// Update navigation
function updateNavigation() {
    const prevBtn = document.getElementById('prevBtn');
    const nextBtn = document.getElementById('nextBtn');
    const installBtn = document.getElementById('installBtn');

    if (currentStep === 1) {
        prevBtn.style.display = 'none';
    } else {
        prevBtn.style.display = 'inline-block';
    }

    if (currentStep === totalSteps) {
        nextBtn.classList.add('d-none');
        installBtn.classList.remove('d-none');
    } else {
        nextBtn.classList.remove('d-none');
        installBtn.classList.add('d-none');
    }
}

// Database connection check
async function validateDatabaseConnection() {
    const dbData = {
        db_host: document.querySelector('[name="db_host"]').value,
        db_port: document.querySelector('[name="db_port"]').value,
        db_username: document.querySelector('[name="db_username"]').value,
        db_password: document.querySelector('[name="db_password"]').value,
        db_name: document.querySelector('[name="db_name"]').value
    };

    try {
        const response = await fetch('check_database.php?t=' + Date.now(), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Cache-Control': 'no-cache, no-store, must-revalidate',
                'Pragma': 'no-cache',
                'Expires': '0'
            },
            body: JSON.stringify(dbData)
        });

        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }

        const result = await response.json();

        if (result.status === 'success') {
            return true;
        } else {
            alert('Database connection error: ' + result.message + '\n\nSuggestion: ' + (result.suggestion || ''));
            return false;
        }
    } catch (error) {
        console.error('Database check error:', error);
        alert('Database check error: ' + error.message);
        return false;
    }
}

// API key generation
function generateApiKey() {
    const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    let result = '';
    for (let i = 0; i < 32; i++) {
        result += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    document.getElementById('api-key').value = result;
}

// Installation form handler
document.getElementById('installForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    // Save form data before sending
    saveFormData();
    
    // IMPORTANT: Make all form fields visible before creating FormData
    // Otherwise hidden fields (d-none) won't be included
    const allSteps = document.querySelectorAll('.install-step');
    const originalClasses = [];
    
    // Temporarily show all steps to collect form data
    allSteps.forEach((step, index) => {
        originalClasses[index] = step.className;
        step.classList.remove('d-none');
    });
    
    const formData = new FormData(this);
    
    // Restore original step visibility
    allSteps.forEach((step, index) => {
        step.className = originalClasses[index];
    });
    
    // Debug: Log form data
    console.log('Form data being sent:');
    for (let [key, value] of formData.entries()) {
        console.log(key + ': ' + value);
    }
    
    // CSRF token check
    const csrfToken = document.querySelector('[name="csrf_token"]');
    if (!csrfToken || !csrfToken.value) {
        alert('CSRF token is missing. Please reload the page.');
        return;
    }
    
    // Show installation progress
    document.getElementById('installForm').classList.add('d-none');
    document.getElementById('installProgress').classList.remove('d-none');
    
    try {
        const response = await fetch('install.php', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        
        const result = await response.json();
        
        if (result.status === 'success') {
            // Clear saved data on success
            clearSavedData();
            showInstallationProgress(result.steps);
        } else {
            throw new Error(result.message);
        }
    } catch (error) {
        console.error('Installation error:', error);
        showInstallError('Installation error: ' + error.message + '\n\nForm data has been saved, you can fix the error and try again.');
    }
});

// Show installation progress
async function showInstallationProgress(steps) {
    const progressBar = document.getElementById('progressBar');
    const progressText = document.getElementById('progressText');
    
    console.log('Starting installation with steps:', steps);
    
    try {
        for (let i = 0; i < steps.length; i++) {
            const step = steps[i];
            const progress = Math.round(((i + 1) / steps.length) * 100);
            
            console.log(`Executing step ${i + 1}/${steps.length}: ${step.id} - ${step.description}`);
            
            progressText.textContent = step.description;
            progressBar.style.width = progress + '%';
            
            // Simulate step execution
            await new Promise(resolve => setTimeout(resolve, 1000));
            
            // Execute installation step
            try {
                console.log(`Sending request for step: ${step.id}`);
                
                const response = await fetch('install_step.php', {
                    method: 'POST',
                    body: JSON.stringify({ 
                        step: step.id,
                        config: JSON.parse(localStorage.getItem('blockchain_install_data') || '{}')
                    }),
                    headers: {
                        'Content-Type': 'application/json'
                    }
                });
                
                console.log(`Response status for step ${step.id}:`, response.status);
                
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }
                
                const result = await response.json();
                console.log(`Result for step ${step.id}:`, result);
                
                if (result.status !== 'success') {
                    throw new Error(result.message || 'Unknown error');
                }
                
                // Check if this is wallet creation step
                if (step.id === 'create_wallet' && result.data && result.data.wallet_data) {
                    console.log('Wallet creation step completed, showing wallet data');
                    // Show wallet data first - pass the entire result.data object
                    showWalletResult(result.data);
                    return; // Wait for user to continue
                }
                
            } catch (error) {
                console.error(`Error in step ${step.id}:`, error);
                // Show the installation error with improved message formatting
                let errorMessage = error.message;
                
                // Clean up common PHP exception prefixes for better user experience
                errorMessage = errorMessage.replace(/^Exception:\s*/, '');
                errorMessage = errorMessage.replace(/^Failed to create wallet:\s*/, '');
                errorMessage = errorMessage.replace(/^Failed to import and verify wallet:\s*/, '');
                
                showInstallError(errorMessage);
                return; // Stop execution
            }
        }
        
        // Show final result - all steps completed
        console.log('All steps completed successfully');
        document.getElementById('installProgress').classList.add('d-none');
        document.getElementById('installResult').classList.remove('d-none');
        
    } catch (error) {
        console.error('General installation error:', error);
        showInstallError(error.message);
    }
}

// Real-time form validation
document.querySelectorAll('input[required], select[required]').forEach(input => {
    input.addEventListener('blur', function() {
        if (!this.value.trim()) {
            this.classList.add('is-invalid');
        } else {
            this.classList.remove('is-invalid');
            this.classList.add('is-valid');
        }
    });
});

// Password strength check
document.querySelector('[name="admin_password"]').addEventListener('input', function() {
    const password = this.value;
    const strength = calculatePasswordStrength(password);
    
    // You can add a password strength indicator here
    console.log('Password strength:', strength);
});

function calculatePasswordStrength(password) {
    let strength = 0;
    
    if (password.length >= 8) strength += 1;
    if (/[a-z]/.test(password)) strength += 1;
    if (/[A-Z]/.test(password)) strength += 1;
    if (/[0-9]/.test(password)) strength += 1;
    if (/[^A-Za-z0-9]/.test(password)) strength += 1;
    
    return strength;
}

// Save form data to localStorage
function saveFormData() {
    const form = document.getElementById('installForm');
    const formData = new FormData(form);
    const data = {};
    
    for (let [key, value] of formData.entries()) {
        if (key !== 'csrf_token' && key !== 'admin_password' && key !== 'admin_password_confirm') {
            data[key] = value;
        }
    }
    
    // Save to localStorage
    localStorage.setItem('blockchain_install_data', JSON.stringify(data));
}

// Restore form data from localStorage
function restoreFormData() {
    try {
        const saved = localStorage.getItem('blockchain_install_data');
        if (saved) {
            const data = JSON.parse(saved);
            
            for (let [key, value] of Object.entries(data)) {
                const field = document.querySelector(`[name="${key}"]`);
                if (field) {
                    if (field.type === 'checkbox') {
                        field.checked = value === 'on' || value === true;
                    } else {
                        field.value = value;
                    }
                }
            }
        }
    } catch (error) {
        console.warn('Failed to restore form data:', error);
    }
}

// Clear saved data after successful installation
function clearSavedData() {
    localStorage.removeItem('blockchain_install_data');
}

// Auto-saving when form fields change
document.addEventListener('DOMContentLoaded', function() {
    // Add handlers for auto-saving after some time after loading
    setTimeout(function() {
        const form = document.getElementById('installForm');
        if (form) {
            // Auto-save when fields change
            form.addEventListener('input', function(e) {
                // Save data with delay to avoid doing it too frequently
                clearTimeout(window.saveTimeout);
                window.saveTimeout = setTimeout(saveFormData, 1000);
            });
            
            form.addEventListener('change', function(e) {
                saveFormData();
            });
        }
    }, 2000);
});

// Show installation error
function showInstallError(message) {
    document.getElementById('installProgress').classList.add('d-none');
    document.getElementById('installForm').classList.add('d-none');
    document.getElementById('installError').classList.remove('d-none');
    
    const errorElement = document.getElementById('errorMessage');
    const checkBalanceBtn = document.getElementById('checkBalanceBtn');
    
    // Check for specific error types and format accordingly
    if (message.includes('INSUFFICIENT FUNDS') || message.includes('staking balance')) {
        // Show check balance button for insufficient funds errors
        checkBalanceBtn.style.display = 'inline-block';
        
        // Try to extract specific amounts from error message
        const networkReqMatch = message.match(/Network requires minimum staking balance: (\d+)/);
        const availableMatch = message.match(/but wallet only has: (\d+)/);
        const neededMatch = message.match(/at least (\d+) more tokens/);
        
        const networkRequired = networkReqMatch ? networkReqMatch[1] : 'unknown';
        const available = availableMatch ? availableMatch[1] : 'unknown';
        const needed = neededMatch ? neededMatch[1] : 'unknown';
        
        errorElement.innerHTML = `
            <div class="alert alert-danger">
                <h5><i class="fas fa-exclamation-triangle"></i> Insufficient Staking Balance</h5>
                <p>${message}</p>
                <hr>
                <div class="row">
                    <div class="col-md-4">
                        <div class="card border-warning">
                            <div class="card-body text-center">
                                <h6 class="card-title">Required</h6>
                                <h4 class="text-warning">${networkRequired}</h4>
                                <small>tokens</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card border-danger">
                            <div class="card-body text-center">
                                <h6 class="card-title">Available</h6>
                                <h4 class="text-danger">${available}</h4>
                                <small>tokens</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card border-info">
                            <div class="card-body text-center">
                                <h6 class="card-title">Need to Add</h6>
                                <h4 class="text-info">${needed}</h4>
                                <small>tokens</small>
                            </div>
                        </div>
                    </div>
                </div>
                <hr>
                <p><strong>Next steps:</strong></p>
                <ol>
                    <li>Fund your wallet with at least <strong>${needed} tokens</strong></li>
                    <li>Wait for the transaction to be confirmed on the network</li>
                    <li>Click "Check Wallet Balance" to verify your balance</li>
                    <li>Click "Retry Installation" to continue once your wallet is funded</li>
                </ol>
                <div class="alert alert-info mt-3">
                    <i class="fas fa-info-circle"></i> 
                    <strong>Note:</strong> The staking requirement is determined by the network configuration.
                    Your node must meet the minimum staking amount to participate in consensus.
                </div>
            </div>
        `;
    } else if (message.includes('SECURITY') || message.includes('ownership verification')) {
        // Hide check balance button for security errors
        checkBalanceBtn.style.display = 'none';
        
        errorElement.innerHTML = `
            <div class="alert alert-warning">
                <h5><i class="fas fa-shield-alt"></i> Wallet Ownership Verification Failed</h5>
                <p>${message}</p>
                <hr>
                <p><strong>Possible causes:</strong></p>
                <ul>
                    <li>The private key doesn't match the wallet address</li>
                    <li>Network connectivity issues</li>
                    <li>The wallet might not exist on the network</li>
                </ul>
                <p>Please verify your wallet information and try again.</p>
            </div>
        `;
    } else {
        // Hide check balance button for general errors
        checkBalanceBtn.style.display = 'none';
        
        errorElement.innerHTML = `
            <div class="alert alert-danger">
                <h5><i class="fas fa-times-circle"></i> Installation Error</h5>
                <p>${message}</p>
            </div>
        `;
    }
}

// Retry installation
function retryInstallation() {
    document.getElementById('installError').classList.add('d-none');
    document.getElementById('installForm').classList.remove('d-none');
    
    // Restore form data if it was saved
    restoreFormData();
}

// Return to form
function backToForm() {
    document.getElementById('installError').classList.add('d-none');
    document.getElementById('installProgress').classList.add('d-none');
    document.getElementById('installForm').classList.remove('d-none');
    
    // Reset to first step
    if (typeof currentStep !== 'undefined' && typeof totalSteps !== 'undefined') {
        // Hide current step
        if (document.getElementById(`step-${currentStep}`)) {
            document.getElementById(`step-${currentStep}`).classList.add('d-none');
        }
        
        // Reset to step 1
        currentStep = 1;
        
        // Show first step
        if (document.getElementById(`step-${currentStep}`)) {
            document.getElementById(`step-${currentStep}`).classList.remove('d-none');
        }
        
        // Update step indicator and navigation if functions exist
        if (typeof updateStepIndicator === 'function') {
            updateStepIndicator();
        }
        if (typeof updateNavigation === 'function') {
            updateNavigation();
        }
    }
    
    // Restore form data
    restoreFormData();
}

// Setup node type handlers
function setupNodeTypeHandlers() {
    const primaryRadio = document.getElementById('primary_node');
    const regularRadio = document.getElementById('regular_node');
    
    if (primaryRadio && regularRadio) {
        primaryRadio.addEventListener('change', handleNodeTypeChange);
        regularRadio.addEventListener('change', handleNodeTypeChange);
        
        // Trigger initial state
        handleNodeTypeChange();
    }
}

// Handle node type change
function handleNodeTypeChange() {
    const primaryRadio = document.getElementById('primary_node');
    const regularRadio = document.getElementById('regular_node');
    const primaryConfig = document.getElementById('primary-node-config');
    const regularConfig = document.getElementById('regular-node-config');
    
    if (primaryRadio && primaryRadio.checked) {
        // Primary node selected (Genesis node)
        if (primaryConfig) primaryConfig.classList.remove('d-none');
        if (regularConfig) regularConfig.classList.add('d-none');
        
        // Make primary node fields required
        setFieldsRequired('[name="network_name"], [name="token_symbol"], [name="initial_supply"], [name="primary_wallet_amount"], [name="min_stake_amount"]', true);
        setFieldsRequired('[name="existing_wallet_address"], [name="existing_wallet_private_key"]', false);
        
        // For genesis node, network_nodes is NOT required (no existing nodes yet)
        setFieldsRequired('[name="network_nodes"]', false);
        
    } else if (regularRadio && regularRadio.checked) {
        // Regular node selected
        if (primaryConfig) primaryConfig.classList.add('d-none');
        if (regularConfig) regularConfig.classList.remove('d-none');
        
        // Make regular node fields required
        setFieldsRequired('[name="network_name"], [name="token_symbol"], [name="initial_supply"], [name="primary_wallet_amount"], [name="min_stake_amount"]', false);
        setFieldsRequired('[name="existing_wallet_address"], [name="existing_wallet_private_key"]', true);
        
        // For regular node, network_nodes IS required (must connect to existing network)
        setFieldsRequired('[name="network_nodes"]', true);
    }
}

// Set fields required status
function setFieldsRequired(selector, required) {
    const fields = document.querySelectorAll(selector);
    fields.forEach(field => {
        field.required = required;
        if (!required) {
            field.classList.remove('is-invalid');
        }
    });
}

// Auto-fill domain field
async function autoFillDomain() {
    const domainField = document.querySelector('[name="node_domain"]');
    const protocolSelect = document.querySelector('[name="protocol"]');
    
    if (domainField && protocolSelect) {
        try {
            // Get domain info from server
            const response = await fetch('get_domain_info.php?v=' + Date.now(), {
                headers: {
                    'Cache-Control': 'no-cache',
                    'Pragma': 'no-cache'
                }
            });
            
            if (response.ok) {
                const result = await response.json();
                
                if (result.status === 'success') {
                    const domainInfo = result.data;
                    
                    // Auto-fill domain if empty
                    if (!domainField.value || domainField.value.trim() === '') {
                        domainField.value = domainInfo.domain;
                        domainField.classList.add('auto-filled');
                        
                        // Show auto-fill indicator
                        const indicator = domainField.parentElement.querySelector('.auto-fill-indicator');
                        if (indicator) {
                            indicator.classList.remove('d-none');
                            indicator.title = `Auto-filled from current URL (${domainInfo.full_url})`;
                        }
                        
                        console.log('Auto-filled domain:', domainInfo.domain);
                    }
                    
                    // Auto-fill protocol
                    if (!protocolSelect.value || protocolSelect.value === '') {
                        protocolSelect.value = domainInfo.protocol;
                        protocolSelect.classList.add('auto-filled');
                        
                        console.log('Auto-filled protocol:', domainInfo.protocol);
                    }
                    
                    // Update domain display after auto-fill
                    updateDomainDisplay();
                    return;
                }
            }
        } catch (error) {
            console.warn('Failed to get domain info from server:', error);
        }
        
        // Fallback to client-side detection
        const currentProtocol = window.location.protocol === 'https:' ? 'https' : 'http';
        const hostname = window.location.hostname;
        const port = window.location.port;
        
        // Auto-fill domain if empty
        if (!domainField.value || domainField.value.trim() === '') {
            let domain = hostname;
            
            // Don't include standard ports
            if (port && port !== '80' && port !== '443') {
                domain += `:${port}`;
            }
            
            domainField.value = domain;
            domainField.classList.add('auto-filled');
            
            // Show auto-fill indicator
            const indicator = domainField.parentElement.querySelector('.auto-fill-indicator');
            if (indicator) {
                indicator.classList.remove('d-none');
                indicator.title = 'Auto-filled from current URL (fallback method)';
            }
            
            console.log('Auto-filled domain (fallback):', domain);
        }
        
        // Auto-fill protocol
        if (!protocolSelect.value || protocolSelect.value === '') {
            protocolSelect.value = currentProtocol;
            protocolSelect.classList.add('auto-filled');
            
            console.log('Auto-filled protocol (fallback):', currentProtocol);
        }
        
        // Update domain display after auto-fill
        updateDomainDisplay();
    }
}

// Update domain display based on protocol selection
function updateDomainDisplay() {
    const domainField = document.querySelector('[name="node_domain"]');
    const protocolSelect = document.querySelector('[name="protocol"]');
    const domainPreview = document.getElementById('domainPreview');
    
    if (domainField && protocolSelect && domainPreview) {
        const domain = domainField.value.trim();
        const protocol = protocolSelect.value;
        
        if (domain && protocol) {
            // Clean up domain (remove protocol if user accidentally included it)
            let cleanDomain = domain.replace(/^https?:\/\//, '');
            
            const fullUrl = `${protocol}://${cleanDomain}`;
            domainPreview.innerHTML = `
                <small class="text-success">
                    <i class="fas fa-globe"></i> 
                    Current domain detected: <strong>${cleanDomain}</strong>
                </small>
                <br>
                <small class="text-info">
                    Full URL will be: <code>${fullUrl}</code>
                </small>
            `;
            domainPreview.className = `domain-preview ${protocol}`;
        } else if (domain) {
            domainPreview.innerHTML = `
                <small class="text-warning">
                    <i class="fas fa-exclamation-triangle"></i> 
                    Please select a protocol
                </small>
            `;
            domainPreview.className = 'domain-preview warning';
        } else {
            domainPreview.innerHTML = `
                <small class="text-muted">
                    <i class="fas fa-info-circle"></i> 
                    Domain or IP address of your hosting server
                </small>
            `;
            domainPreview.className = 'domain-preview';
        }
    }
}

// Show wallet creation result
function showWalletResult(walletData) {
    console.log('showWalletResult called with:', walletData); // Debug log
    
    document.getElementById('installProgress').classList.add('d-none');
    document.getElementById('walletResult').classList.remove('d-none');
    
    // Check node type to determine what to show
    const nodeType = document.querySelector('input[name="node_type"]:checked')?.value || 'primary';
    const isPrimary = walletData.is_primary || nodeType === 'primary';
    
    console.log('Node type:', nodeType, 'Is primary from data:', walletData.is_primary, 'Final isPrimary:', isPrimary);
    
    // Show appropriate content based on node type
    const walletDataCards = document.getElementById('walletDataCards');
    const networkConnectionInfo = document.getElementById('networkConnectionInfo');
    const primaryContinueBtn = document.getElementById('primaryContinueBtn');
    const regularContinueBtn = document.getElementById('regularContinueBtn');
    
    if (isPrimary) {
        // Primary node - show wallet data
        console.log('Showing wallet data for primary node');
        
        if (walletDataCards) walletDataCards.classList.remove('d-none');
        if (networkConnectionInfo) networkConnectionInfo.classList.add('d-none');
        if (primaryContinueBtn) primaryContinueBtn.classList.remove('d-none');
        if (regularContinueBtn) regularContinueBtn.classList.add('d-none');
        
        // Access wallet data properly - handle different response structures
        let wallet;
        if (walletData.wallet_data) {
            wallet = walletData.wallet_data;
        } else if (walletData.wallet) {
            wallet = walletData.wallet;
        } else {
            wallet = walletData;
        }
        
        console.log('Extracted wallet data:', wallet);
        
        // Fill wallet data in correct order: Address, Public Key, Private Key, Mnemonic
        const walletAddressElement = document.getElementById('walletAddress');
        const walletPublicKeyElement = document.getElementById('walletPublicKey');
        const walletPrivateKeyElement = document.getElementById('walletPrivateKey');
        const walletMnemonicElement = document.getElementById('walletMnemonic');
        
        if (walletAddressElement) {
            walletAddressElement.textContent = wallet.address || 'Not available';
        }
        
        if (walletPublicKeyElement) {
            walletPublicKeyElement.textContent = wallet.public_key || 'Not available';
        }
        
        if (walletPrivateKeyElement) {
            walletPrivateKeyElement.textContent = wallet.private_key || 'Not available';
        }
        
        if (walletMnemonicElement) {
            walletMnemonicElement.textContent = wallet.mnemonic || 'Not available';
        }
        
        console.log('Wallet fields populated for primary node');
        
    } else {
        // Regular node - show network connection info
        console.log('Showing network connection info for regular node');
        
        if (walletDataCards) walletDataCards.classList.add('d-none');
        if (networkConnectionInfo) networkConnectionInfo.classList.remove('d-none');
        if (primaryContinueBtn) primaryContinueBtn.classList.add('d-none');
        if (regularContinueBtn) regularContinueBtn.classList.remove('d-none');
        
        // Show network connection status
        const connectedNetworkElement = document.getElementById('connectedNetwork');
        const connectedNodeElement = document.getElementById('connectedNode');
        const connectedWalletElement = document.getElementById('connectedWallet');
        
        if (connectedNetworkElement) {
            connectedNetworkElement.textContent = walletData.network_status || 'VitaFlow Network';
        }
        
        if (connectedNodeElement) {
            connectedNodeElement.textContent = walletData.synced_nodes || 'Synchronized with peers';
        }
        
        if (connectedWalletElement) {
            connectedWalletElement.textContent = walletData.wallet_status || 'Wallet verified';
        }
        
        console.log('Network info populated for regular node');
    }
}

// Continue to final result
async function continueToResult() {
    document.getElementById('walletResult').classList.add('d-none');
    document.getElementById('installProgress').classList.remove('d-none');
    
    // Check node type to determine steps
    const nodeType = document.querySelector('input[name="node_type"]:checked')?.value || 'primary';
    
    let allSteps = [];
    
    if (nodeType === 'primary') {
        // Primary node steps
        allSteps = [
            { id: 'generate_genesis', description: 'Generating genesis block...' },
            { id: 'initialize_binary_storage', description: 'Initializing binary blockchain storage...' },
            { id: 'create_config', description: 'Creating configuration...' },
            { id: 'setup_admin', description: 'Setting up administrator...' },
            { id: 'initialize_blockchain', description: 'Initializing blockchain...' },
            { id: 'start_services', description: 'Starting services...' },
            { id: 'finalize', description: 'Completing installation...' }
        ];
    } else {
        // Regular node steps
        allSteps = [
            { id: 'sync_blockchain', description: 'Syncing blockchain with genesis node...' },
            { id: 'initialize_binary_storage', description: 'Initializing binary blockchain storage...' },
            { id: 'create_config', description: 'Creating configuration...' },
            { id: 'setup_admin', description: 'Setting up administrator...' },
            { id: 'initialize_blockchain', description: 'Initializing blockchain...' },
            { id: 'start_services', description: 'Starting services...' },
            { id: 'finalize', description: 'Completing installation...' }
        ];
    }
    
    try {
        await showInstallationProgress(allSteps);
    } catch (error) {
        console.error('Error continuing installation:', error);
        showInstallError('Error continuing installation: ' + error.message);
    }
}

// Copy to clipboard
function copyToClipboard(elementId) {
    const element = document.getElementById(elementId);
    const text = element.textContent;
    
    navigator.clipboard.writeText(text).then(() => {
        // Show feedback
        const originalBtn = element.nextElementSibling;
        const originalText = originalBtn.innerHTML;
        originalBtn.innerHTML = '<i class="fas fa-check"></i> Copied!';
        originalBtn.classList.add('btn-success');
        
        setTimeout(() => {
            originalBtn.innerHTML = originalText;
            originalBtn.classList.remove('btn-success');
        }, 2000);
    }).catch(() => {
        // Fallback for older browsers
        const textArea = document.createElement('textarea');
        textArea.value = text;
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        document.execCommand('copy');
        document.body.removeChild(textArea);
        
        alert('Copied to clipboard!');
    });
}

// Check wallet balance function
async function checkWalletBalance() {
    const checkBalanceBtn = document.getElementById('checkBalanceBtn');
    const originalText = checkBalanceBtn.innerHTML;
    
    // Show loading state
    checkBalanceBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Checking...';
    checkBalanceBtn.disabled = true;
    
    try {
        // Get saved form data
        const savedData = JSON.parse(localStorage.getItem('blockchain_install_data') || '{}');
        const walletAddress = savedData.existing_wallet_address;
        const networkNodes = savedData.network_nodes;
        
        if (!walletAddress) {
            alert('Wallet address not found in saved data. Please go back to the form and enter your wallet address.');
            return;
        }
        
        if (!networkNodes) {
            alert('Network nodes not found in saved data. Please go back to the form and enter network nodes.');
            return;
        }
        
        // Check balance via API
        const response = await fetch(`${networkNodes}/api/explorer/index.php?action=get_wallet_balance&address=${encodeURIComponent(walletAddress)}`);
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        
        const result = await response.json();
        
        if (result.success && result.data) {
            const balance = result.data.balance || 0;
            const stake = result.data.stake || 0;
            const total = balance + stake;
            
            // Show balance result in a nice modal-like alert
            const balanceInfo = `
Current Wallet Balance:
• Available Balance: ${balance} tokens
• Staked Amount: ${stake} tokens  
• Total Balance: ${total} tokens

Address: ${walletAddress}
Last Updated: ${new Date().toLocaleString()}
            `;
            
            alert(balanceInfo);
            
            // If balance is sufficient, encourage retry
            if (total >= 1000) {
                if (confirm('Great! Your wallet now has sufficient balance. Would you like to retry the installation?')) {
                    retryInstallation();
                }
            }
        } else {
            throw new Error(result.message || 'Failed to get wallet balance');
        }
        
    } catch (error) {
        console.error('Balance check error:', error);
        alert('Failed to check wallet balance: ' + error.message);
    } finally {
        // Restore button state
        checkBalanceBtn.innerHTML = originalText;
        checkBalanceBtn.disabled = false;
    }
}

// Setup domain and protocol change listeners
function setupDomainListeners() {
    // Add event listeners for real-time domain display updates
    const domainField = document.querySelector('[name="node_domain"]');
    const protocolSelect = document.querySelector('[name="protocol"]');
    
    if (domainField) {
        domainField.addEventListener('input', function() {
            updateDomainDisplay();
            saveFormData(); // Save as user types
        });
        
        domainField.addEventListener('blur', function() {
            // Clean up domain on blur
            const cleanDomain = this.value.replace(/^https?:\/\//, '').trim();
            if (cleanDomain !== this.value) {
                this.value = cleanDomain;
                updateDomainDisplay();
            }
        });
    }
    
    if (protocolSelect) {
        protocolSelect.addEventListener('change', function() {
            updateDomainDisplay();
            saveFormData(); // Save selection
        });
    }
}

// Function to start network synchronization
function startNetworkSync() {
    // Check if sync service is available
    if (window.location.pathname.includes('web-installer')) {
        // Open sync service in new tab/window
        const syncUrl = '../sync-service/index.php';
        window.open(syncUrl, '_blank');
    } else {
        // Redirect to sync service
        window.location.href = 'sync-service/index.php';
    }
}

// Function to open advanced sync interface
function openAdvancedSync() {
    const syncUrl = window.location.pathname.includes('web-installer') ? 
        '../sync-service/sync.php' : 'sync-service/sync.php';
    window.open(syncUrl, '_blank');
}
