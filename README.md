# 🚀 PHP Blockchain Platform

[![PHP Version](https://img.shields.io/badge/PHP-8.0%2B-blue.svg)](https://php.net)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![Version](https://img.shields.io/badge/Version-2.0.0-orange.svg)](https://github.com/infosave2007/phpblockchain)

A professional blockchain platform built with PHP 8+, featuring Proof of Stake consensus, smart contracts, advanced synchronization systems, and enterprise security.

## ✨ Key Features

### 🔗 Core Blockchain
- **Proof of Stake Consensus** - Energy-efficient consensus mechanism
- **Smart Contracts** - EVM-compatible virtual machine
- **Advanced Cryptography** - secp256k1, ECDSA, Keccak-256
- **High Performance** - 1000+ transactions per second

### ⚡ Advanced Synchronization
- **Fast Sync** - 10x faster synchronization with state snapshots
- **Light Client** - 92% storage reduction with SPV verification
- **Checkpoint Sync** - Instant bootstrapping from trusted checkpoints
- **Mobile Optimized** - Efficient sync for resource-constrained devices

### 🛡️ Enterprise Security
- **Hardware Security Module** - Professional key management
- **Multi-signature Support** - Enhanced transaction security
- **Rate Limiting** - DDoS protection
- **Secure Storage** - AES-256 encryption

### 🔧 Developer Tools
- **RESTful API** - Complete blockchain interaction API
- **CLI Tools** - Command-line interface for operations
- **Web Explorer** - Built-in blockchain explorer
- **Comprehensive Tests** - Full test coverage

## 🚀 Quick Start

### Requirements

#### System Requirements
- **PHP 8.0 or higher** with the following extensions:
  - OpenSSL (for cryptographic operations)
  - cURL (for network communications)
  - JSON (for data serialization)
  - mbstring (for string manipulation)
  - MySQLi or PDO (for database operations)
- **Composer** (dependency management)
- **MySQL 8.0+** or **MariaDB 10.4+** (optional, for persistent storage)
- **Redis** (optional, for caching and session storage)

#### Development Requirements
- **Git** (for version control)
- **Docker & Docker Compose** (optional, for containerized deployment)
- **Node.js 16+** (optional, for frontend tools)

#### Production Requirements
- **Web server** (Apache/Nginx)
- **SSL certificate** (for HTTPS)
- **Firewall configuration** (ports 8545, 8546)
- **Backup solution** (for blockchain data)

### Installation

```bash
# Clone the repository
git clone https://github.com/infosave2007/phpblockchain.git
cd phpblockchain

# Install dependencies (simplified - works without database extensions)
composer install --ignore-platform-req=ext-mysqli --ignore-platform-req=ext-pdo_mysql --ignore-platform-req=ext-gmp --no-dev

# Alternative: Install with development tools
# composer install --ignore-platform-req=ext-mysqli --ignore-platform-req=ext-pdo_mysql --ignore-platform-req=ext-gmp

# Copy environment configuration
cp .env.example .env

# Set proper permissions
chmod +x server.php cli.php crypto-cli.php check.php
chmod -R 755 storage/ logs/

# Create required directories
mkdir -p storage/blocks storage/state storage/cache
mkdir -p logs/blockchain logs/transactions

# Run system check
php check.php

# Initialize configuration (optional - use web installer instead)
php cli.php init --network="My Network" --symbol="MBC"
```

### Configuration

#### Environment Variables (.env)
```bash
# Blockchain Configuration
BLOCKCHAIN_NETWORK=mainnet
BLOCKCHAIN_SYMBOL=MBC
CONSENSUS_ALGORITHM=pos
BLOCK_TIME=10
INITIAL_SUPPLY=1000000

# Database Configuration
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=blockchain
DB_USERNAME=blockchain_user
DB_PASSWORD=your_secure_password

# Network Configuration
P2P_PORT=8545
RPC_PORT=8546
MAX_PEERS=25

# Security
API_KEY=your_secure_api_key_32_chars_min
ADMIN_EMAIL=admin@yourdomain.com
RATE_LIMIT_ENABLED=true
```

#### Web-based Installation
For easier setup, use the web installer:
```bash
# Start development server
php server.php

# Open browser and navigate to:
http://localhost:8080/web-installer/

# Follow the installation wizard
```

#### Manual Database Setup
```sql
CREATE DATABASE blockchain;
CREATE USER 'blockchain_user'@'localhost' IDENTIFIED BY 'your_secure_password';
GRANT ALL PRIVILEGES ON blockchain.* TO 'blockchain_user'@'localhost';
FLUSH PRIVILEGES;
```

### Quick Verification
```bash
# Run tests
php simple_test.php

# Start the demo
php simple_demo.php

# Test blockchain functions
php crypto-demo.php

# Check system status
php cli.php status
```

### Basic Usage

```php
<?php
require_once 'vendor/autoload.php';

use Blockchain\Core\Cryptography\KeyPair;
use Blockchain\Core\Transaction\Transaction;
use Blockchain\Core\Blockchain\Block;

// Generate keypairs
$alice = KeyPair::generate();
$bob = KeyPair::generate();

// Create transaction
$transaction = new Transaction(
    $alice->getPublicKey(),
    $bob->getPublicKey(),
    100.0,
    0.1
);

// Create block
$block = new Block(1, [$transaction], 'previous_hash', time());

echo "Block hash: " . $block->getHash() . "\n";
```

## 📊 Performance Benchmarks

| Feature | Performance |
|---------|-------------|
| Transaction Processing | 1,000+ tx/sec |
| Block Validation | 10+ blocks/sec |
| Sync Speed (Fast) | 10x improvement |
| Storage (Light Client) | 92% reduction |
| Memory Usage | < 10MB |

## 🏗️ Architecture

```
├── core/
│   ├── Blockchain/         # Blockchain core logic
│   ├── Cryptography/       # Cryptographic functions
│   ├── Consensus/          # Proof of Stake implementation
│   ├── Transaction/        # Transaction processing
│   ├── SmartContract/      # Smart contract VM
│   ├── Sync/              # Advanced synchronization
│   └── Security/          # Security features
├── api/                   # REST API
├── wallet/                # Wallet management
├── tests/                 # Test suite
└── examples/              # Usage examples
```

## 🔐 Security Features

- **secp256k1 Elliptic Curve Cryptography**
- **ECDSA Digital Signatures**
- **Keccak-256 Cryptographic Hashing**
- **Secure Key Generation and Storage**
- **Multi-signature Transaction Support**
- **Hardware Security Module Integration**

## ⚡ Synchronization Strategies

### Fast Sync
- Downloads state snapshots
- 10x faster than full sync
- Validates recent blocks only

### Light Client
- Downloads block headers only
- SPV verification with Merkle proofs
- 92% storage reduction

### Checkpoint Sync
- Bootstraps from trusted checkpoints
- Instant network joining
- Suitable for new nodes

## 🧪 Testing

```bash
# Run all tests
./vendor/bin/phpunit

# Run simple tests
php simple_test.php

# Run performance demo
php simple_demo.php

# Test synchronization
php sync_demo.php
```

## 🔧 Troubleshooting

### Common Issues

#### Permission Errors
```bash
# Fix file permissions
chmod -R 755 storage/ logs/
chown -R www-data:www-data storage/ logs/  # Linux/Ubuntu
chown -R _www:_www storage/ logs/          # macOS
```

#### OpenSSL Extension Missing
```bash
# Ubuntu/Debian
sudo apt-get install php-openssl

# CentOS/RHEL
sudo yum install php-openssl

# macOS
brew install openssl
```

#### Missing Database Extensions
```bash
# Ubuntu/Debian
sudo apt-get install php-mysqli php-pdo-mysql php-gmp

# CentOS/RHEL
sudo yum install php-mysqli php-pdo php-gmp

# macOS
brew install php-gmp

# Alternative: Install without database extensions
composer install --ignore-platform-req=ext-mysqli --ignore-platform-req=ext-pdo_mysql --ignore-platform-req=ext-gmp
```

#### Composer Issues
```bash
# Update Composer
composer self-update

# Clear cache
composer clear-cache

# Install with increased memory
php -d memory_limit=2G composer install
```

#### Database Connection Issues
```bash
# Test database connection
php cli.php db:test

# Create database manually
mysql -u root -p -e "CREATE DATABASE blockchain;"
```

#### GitHub Authentication Issues
```bash
# If you get GitHub authentication errors during installation
composer clear-cache
rm -rf vendor/ composer.lock

# Use the simplified installation
composer install --ignore-platform-req=ext-mysqli --ignore-platform-req=ext-pdo_mysql --ignore-platform-req=ext-gmp --no-dev
```

## 📚 API Documentation

### Create Transaction
```php
POST /api/transaction
{
    "from": "public_key",
    "to": "public_key", 
    "amount": 100.0,
    "fee": 0.1
}
```

### Get Block
```php
GET /api/block/{hash}
```

### Get Balance
```php
GET /api/balance/{address}
```

## 🤝 Contributing

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## 📄 License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## 🚀 Deployment

### Docker
```bash
docker-compose up -d
```

### Traditional Server
```bash
# Configure web server to point to index.php
# Set proper permissions
chmod +x server.php
php server.php
```

## 📈 Roadmap

- [ ] Layer 2 scaling solutions
- [ ] Cross-chain interoperability  
- [ ] Advanced governance features
- [ ] Mobile wallet applications
- [ ] Enterprise integration tools

## 💬 Support

- GitHub Issues: [Report bugs](https://github.com/infosave2007/phpblockchain/issues)
- Documentation: [Wiki](https://github.com/infosave2007/phpblockchain/wiki)

---

**Built with ❤️ using PHP 8+ and modern blockchain technologies**