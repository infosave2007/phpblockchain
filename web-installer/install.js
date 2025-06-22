let currentStep = 1;
const totalSteps = 5;
let systemChecks = {};

// Initialization on page load
document.addEventListener('DOMContentLoaded', function() {
    // HTTPS check
    enforceHTTPS();
    checkSystemRequirements();
    generateApiKey();
    // Add CSRF protection
    addCSRFProtection();
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
        const response = await fetch('get_csrf_token.php');
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
        { id: 'php-version', endpoint: 'check_php.php' },
        { id: 'mysql-check', endpoint: 'check_mysql.php' },
        { id: 'openssl-check', endpoint: 'check_openssl.php' },
        { id: 'curl-check', endpoint: 'check_curl.php' },
        { id: 'write-check', endpoint: 'check_permissions.php' },
        { id: 'node-check', endpoint: 'check_node.php' }
    ];

    for (const check of checks) {
        try {
            const response = await fetch(check.endpoint);
            const result = await response.json();
            
            const element = document.getElementById(check.id);
            if (result.status === 'ok') {
                element.className = 'badge bg-success';
                element.textContent = result.message || 'OK';
                systemChecks[check.id] = true;
            } else {
                element.className = 'badge bg-danger';
                element.textContent = result.message || 'Error';
                systemChecks[check.id] = false;
            }
        } catch (error) {
            const element = document.getElementById(check.id);
            element.className = 'badge bg-warning';
            element.textContent = 'Unavailable';
            systemChecks[check.id] = false;
        }
    }
}

// Go to next step
function nextStep() {
    if (currentStep === 1) {
        // Check system requirements
        const allPassed = Object.values(systemChecks).every(check => check === true);
        if (!allPassed) {
            alert('Not all system requirements are met. Please fix the errors before continuing.');
            return;
        }
    }

    if (currentStep === 2) {
        // Check database connection
        if (!validateDatabaseConnection()) {
            return;
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
    const formData = new FormData();
    formData.append('host', document.querySelector('[name="db_host"]').value);
    formData.append('port', document.querySelector('[name="db_port"]').value);
    formData.append('username', document.querySelector('[name="db_username"]').value);
    formData.append('password', document.querySelector('[name="db_password"]').value);
    formData.append('database', document.querySelector('[name="db_name"]').value);

    try {
        const response = await fetch('test_db_connection.php', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();

        if (result.status === 'success') {
            return true;
        } else {
            alert('Database connection error: ' + result.message);
            return false;
        }
    } catch (error) {
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
    
    const formData = new FormData(this);
    
    // CSRF token check
    const csrfToken = document.querySelector('[name="csrf_token"]').value;
    if (!csrfToken) {
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
            showInstallationProgress(result.steps);
        } else {
            throw new Error(result.message);
        }
    } catch (error) {
        alert('Installation error: ' + error.message);
        document.getElementById('installForm').classList.remove('d-none');
        document.getElementById('installProgress').classList.add('d-none');
    }
});

// Show installation progress
async function showInstallationProgress(steps) {
    const progressBar = document.getElementById('progressBar');
    const progressText = document.getElementById('progressText');
    
    for (let i = 0; i < steps.length; i++) {
        const step = steps[i];
        const progress = Math.round(((i + 1) / steps.length) * 100);
        
        progressText.textContent = step.description;
        progressBar.style.width = progress + '%';
        
        // Simulate step execution
        await new Promise(resolve => setTimeout(resolve, 1000));
        
        // Execute installation step
        try {
            const response = await fetch('install_step.php', {
                method: 'POST',
                body: JSON.stringify({ step: step.id }),
                headers: {
                    'Content-Type': 'application/json'
                }
            });
            
            const result = await response.json();
            if (result.status !== 'success') {
                throw new Error(result.message);
            }
        } catch (error) {
            alert('Error at step "' + step.description + '": ' + error.message);
            return;
        }
    }
    
    // Show result
    document.getElementById('installProgress').classList.add('d-none');
    document.getElementById('installResult').classList.remove('d-none');
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
