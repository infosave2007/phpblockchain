# 🚀 PHP Blockchain Platform

[![PHP Version](https://img.shields.io/badge/PHP-8.0%2B-blue.svg)](https://php.net)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![Version](https://img.shields.io/badge/Version-2.0.0-orange.svg)](https://github.com/infosave2007/phpblockchain)

A professional blockchain platform built with PHP 8+, featuring Proof of Stake consensus, smart contracts, advanced synchronization systems, enterprise security, and comprehensive web dashboard.

## ✨ Key Features

### 🔗 Core Blockchain
- **Proof of Stake Consensus** - Energy-efficient consensus mechanism
- **Smart Contracts** - EVM-compatible virtual machine
- **Advanced Cryptography** - secp256k1, ECDSA, Keccak-256
- **High Performance** - 1000+ transactions per second

### 🖥️ Web Interface & Management
- **Node Dashboard** - Real-time health monitoring and statistics
- **Web Explorer** - Built-in blockchain explorer with search
- **Wallet Interface** - Complete wallet management system
- **API Documentation** - Interactive API documentation page
- **Multi-language Support** - English and Russian interface

### ⚡ Advanced Synchronization
- **Network Sync** - Automated network synchronization
- **Recovery System** - Automatic blockchain recovery tools
- **Node Management** - Multi-node network coordination
- **Health Monitoring** - Real-time node health checks

### 💱 Decentralized Exchange (DEX)
- **Uniswap V2 Protocol** - Complete decentralized exchange integration
- **AMM Calculations** - Automated market maker for token swaps
- **Liquidity Pools** - Create and manage token liquidity pairs
- **DEX API Suite** - 14 comprehensive API endpoints for DEX operations
- **Smart Contract Integration** - Factory and Router contract deployment

### 🛡️ Enterprise Security
- **Hardware Security Module** - Professional key management
- **Multi-signature Support** - Enhanced transaction security
- **Rate Limiting** - DDoS protection
- **Secure Storage** - AES-256 encryption

### 🔧 Developer Tools
- **RESTful API** - Complete blockchain interaction API
- **CLI Tools** - Command-line interface for operations
- **Web Dashboard** - Node management and monitoring
- **Network Tools** - Sync, recovery, and node management
- **Comprehensive Tests** - Full test coverage

## 🚀 Quick Start

### Requirements

#### System Requirements
- **PHP 8.0 or higher** with extensions: OpenSSL, cURL, JSON, mbstring
- **Composer** (dependency management)
- **MySQL 8.0+** or **MariaDB 10.4+** (for persistent storage)
- **Web server** (Apache/Nginx for production)

### Installation

```bash
# Clone the repository
git clone https://github.com/infosave2007/phpblockchain.git
cd phpblockchain

# Install dependencies
composer install --ignore-platform-req=ext-mysqli --ignore-platform-req=ext-pdo_mysql --ignore-platform-req=ext-gmp --no-dev

# Set proper permissions
chmod +x *.php
chmod -R 755 storage/ logs/

# Run system check
php check.php
```

### Web-based Installation

Open browser and navigate to: `http://localhost/web-installer/`

![wallet_step1](https://github.com/user-attachments/assets/8875e33f-20e5-431d-92ca-05d485859da3)
![wallet_step2](https://github.com/user-attachments/assets/ebc53876-c064-4372-a4c2-34694c3c4422)
![wallet_step3](https://github.com/user-attachments/assets/587c6ee5-1fb5-49f8-8705-26e64987ea44)
![wallet_step4](https://github.com/user-attachments/assets/73367cf1-6c2e-4c98-9a8b-af39e3faa256)
![wallet_step5](https://github.com/user-attachments/assets/7715443e-15aa-4b5b-a79b-c41174ea855c)
![wallet_step6](https://github.com/user-attachments/assets/97e0d06e-5438-4c57-bcf3-bfe25e1bd219)
![wallet_step7](https://github.com/user-attachments/assets/9fde8a23-81fa-42c5-a07f-73b467d65fc1)

Complete installation wizard:
1. System requirements check
2. Database configuration
3. Network setup
4. Genesis block creation
5. Admin account setup

### Browser Access

After installation, access your blockchain through web interface:

```bash
# Start development server
php server.php

# Open in browser (development)
http://localhost
```

**Note**: For production, use standard HTTP (80) or HTTPS (443) ports with Apache/Nginx.

**Features available in web interface:**
- 🔗 **Node Dashboard** - Real-time blockchain statistics and health monitoring
- 💰 **Wallet Interface** - Create wallets, send transactions, check balances
- 🔍 **Blockchain Explorer** - Browse blocks, transactions, and network nodes
- 🔌 **API Documentation** - Interactive API reference
- 🌐 **Multi-language** - Switch between English and Russian

## 🖥️ Web Interface Usage

### Main Dashboard
Access your blockchain node:
- **Development**: `http://localhost` (if using standard port)
- **Production**: `http://yourdomain.com` or `https://yourdomain.com`

Features:
- **Real-time Statistics** - Blocks, transactions, active nodes, hash rate
- **Network Health** - Node status, component checks, network statistics
- **Quick Actions** - Direct links to wallet, explorer, and API docs

### Wallet Management
Click "💰 Wallet" button to:
- Create new wallets with mnemonic phrases
- Check wallet balances
- Send and receive transactions
- View transaction history
- Manage multiple wallets

### Blockchain Explorer
Click "🔍 Explorer" button to:
- Browse recent blocks and transactions
- Search by block height, hash, or address
- View network nodes and validators
- Monitor mempool status

### API Documentation
Click "🔌 API" button for:
- Complete API endpoint reference
- Interactive examples with copy-to-clipboard
- Request/response formats
- Authentication and parameters guide

### Advanced Tools (CLI)
For advanced users and automation:

```bash
# Network synchronization
php network_sync.php sync

# Node management
php node_manager.php

# Recovery operations
php recovery_cli.php

# Generate crypto keys
php crypto-cli.php generate
```

## 📊 Web Interface Components

### Node Dashboard (`index.php`)
- **Health Monitoring** - Real-time node status and component checks
- **Blockchain Stats** - Blocks, transactions, active nodes, hash rate display
- **Quick Access Panel** - One-click access to wallet, explorer, API docs
- **Multi-language** - Automatic language detection and switching

### API Documentation (`api-docs.php`)
- **Interactive Docs** - Click-to-copy code examples
- **Complete Reference** - All explorer, wallet, and node endpoints
- **Live Examples** - Working curl commands for testing
- **Multi-language** - English and Russian documentation

### Additional Tools
- `network_sync.php` - Network synchronization manager
- `node_manager.php` - Multi-node coordination
- `recovery_cli.php` - Blockchain recovery tools
- `crypto-cli.php` - Cryptographic key management

### Directory Structure
```
├── core/               # Core blockchain logic
├── api/               # REST API endpoints
├── wallet/            # Wallet management
├── explorer/          # Blockchain explorer
├── web-installer/     # Web-based installation
├── sync-service/      # Synchronization services
├── storage/           # Blockchain data storage
├── config/            # Configuration files
└── tests/             # Test suite
```

## 🔐 API Endpoints

### Explorer API
- `GET /api/explorer/stats` - Blockchain statistics
- `GET /api/explorer/blocks` - Recent blocks
- `GET /api/explorer/transactions` - Recent transactions
- `GET /api/explorer/?action=get_nodes_list` - Network nodes

### Wallet API
- `POST /wallet/wallet_api.php` - Wallet operations
  - `action: create_wallet` - Create new wallet
  - `action: get_balance` - Check balance
  - `action: transfer_tokens` - Transfer tokens

### Node API
- `GET /api/health` - Node health check
- `GET /api/status` - Full node status

## 🔐 Security Features

- **secp256k1 Elliptic Curve Cryptography**
- **ECDSA Digital Signatures**
- **Keccak-256 Cryptographic Hashing**
- **Secure Key Generation and Storage**
- **Multi-signature Transaction Support**
- **Hardware Security Module Integration**

## 🧪 Testing & Deployment

### Testing via Web Interface
1. **Development**: Open `http://localhost` for dashboard
2. **Production**: Open `http://yourdomain.com` for dashboard
3. Check all components show "✅ OK" status
4. Click "💰 Wallet" and create test wallet
5. Click "🔍 Explorer" and verify blockchain data
6. Click "🔌 API" for documentation testing

### Alternative Testing
```bash
# Run basic tests
php tests/AllTests.php

# System check
php check.php
```

### Production Deployment
```bash
# Using Docker (recommended)
docker-compose up -d

# Development server (custom port if needed)
php server.php --port=8080

# Production with Apache/Nginx (standard ports 80/443)
# Configure virtual host to point to project directory
```

**Production Access:**
- Dashboard: `https://yourdomain.com`
- API Docs: `https://yourdomain.com/api-docs.php`
- Wallet: `https://yourdomain.com/wallet/`
- Explorer: `https://yourdomain.com/explorer/`

**Development Access:**
- Dashboard: `http://localhost`
- API Docs: `http://localhost/api-docs.php`
- Wallet: `http://localhost/wallet/`
- Explorer: `http://localhost/explorer/`

## 🔧 Troubleshooting

### Common Issues
- **Permission Errors**: `chmod -R 755 storage/ logs/`
- **Missing Extensions**: Install php-openssl, php-curl
- **Database Issues**: Check config/.env settings
- **Port Conflicts**: Use `php server.php --port=8081` for custom port
- **Browser Access**: Ensure web server is properly configured

### Web Interface Issues
- **Dashboard not loading**: Check web server configuration
- **Port conflicts**: Use custom port with `php server.php --port=8081`
- **Production deployment**: Use Apache/Nginx virtual hosts for standard ports (80/443)
- **API docs not working**: Verify `api-docs.php` file exists
- **Wallet errors**: Check database connection and permissions
- **Language issues**: Clear browser cache and refresh

## 🚀 Deployment

### Docker
```bash
docker-compose up -d
```

### Development Server
```bash
# Start development server
php server.php

# Custom port
php server.php --port=8080

# Background mode
nohup php server.php > server.log 2>&1 &
```

### Production Server
For production, use Apache or Nginx with virtual hosts pointing to project directory:
- Standard HTTP port: 80
- Standard HTTPS port: 443
- SSL certificate recommended for HTTPS

## 📈 Roadmap

- [ ] Enhanced mobile wallet
- [ ] Cross-chain bridges
- [ ] Advanced governance
- [ ] Layer 2 scaling
- [ ] Enterprise tools

## 💬 Support

- **Issues**: [GitHub Issues](https://github.com/infosave2007/phpblockchain/issues)
- **Documentation**: Available in `/api-docs.php`
- **Development Dashboard**: `http://localhost`
- **Production Dashboard**: `http://yourdomain.com`

---

**Built with ❤️ using PHP 8+ and modern blockchain technologies**
