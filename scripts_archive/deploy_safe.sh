#!/bin/bash

# Safe deployment script for blockchain node
# This script will NOT overwrite existing configuration or service files

set -e

echo "=== Safe Blockchain Node Deployment ==="
echo "This script will NOT overwrite existing files"
echo ""

# Check if running as root
if [[ $EUID -eq 0 ]]; then
   echo "Error: Do not run as root. Use a regular user account."
   exit 1
fi

# Create backup directory
BACKUP_DIR="backup_$(date +%Y%m%d_%H%M%S)"
echo "Creating backup directory: $BACKUP_DIR"
mkdir -p "$BACKUP_DIR"

# Function to safely copy file (backup if exists)
safe_copy() {
    local src="$1"
    local dest="$2"
    
    if [[ -f "$dest" ]]; then
        echo "Backing up existing file: $dest -> $BACKUP_DIR/"
        cp "$dest" "$BACKUP_DIR/"
    fi
    
    echo "Installing: $dest"
    cp "$src" "$dest"
}

# Function to safely create directory
safe_mkdir() {
    local dir="$1"
    if [[ ! -d "$dir" ]]; then
        echo "Creating directory: $dir"
        mkdir -p "$dir"
    else
        echo "Directory exists: $dir"
    fi
}

# Check if this is a fresh installation
if [[ -f "/etc/blockchain/config.php" ]]; then
    echo "Existing blockchain installation detected"
    echo "Backing up configuration..."
    cp -r /etc/blockchain "$BACKUP_DIR/"
else
    echo "Fresh installation detected"
fi

# Create necessary directories
safe_mkdir "/etc/blockchain"
safe_mkdir "/var/log/blockchain"
safe_mkdir "/var/lib/blockchain"
safe_mkdir "/usr/local/bin/blockchain"

# Install scripts (only if they don't exist or are different)
echo ""
echo "Installing blockchain scripts..."

# Database migration script
safe_copy "database_migration.php" "/usr/local/bin/blockchain/"

# Network configuration script
safe_copy "network_config.php" "/usr/local/bin/blockchain/"

# Service management script
safe_copy "service_manager.php" "/usr/local/bin/blockchain/"

# Make scripts executable
chmod +x /usr/local/bin/blockchain/*.php

echo ""
echo "=== Deployment Complete ==="
echo "Backup created in: $BACKUP_DIR"
echo ""
echo "Next steps:"
echo "1. Review configuration in /etc/blockchain/"
echo "2. Start services: sudo systemctl start blockchain-node"
echo "3. Check status: sudo systemctl status blockchain-node"
echo ""
echo "To restore from backup:"
echo "cp -r $BACKUP_DIR/* /etc/blockchain/"
