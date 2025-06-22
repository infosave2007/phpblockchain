-- Initialize multiple databases for blockchain nodes

-- Create databases for each node
CREATE DATABASE IF NOT EXISTS blockchain_node1 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS blockchain_node2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS blockchain_node3 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Create user and grant permissions
CREATE USER IF NOT EXISTS 'blockchain_user'@'%' IDENTIFIED BY 'blockchain_pass';

-- Grant permissions to all node databases
GRANT ALL PRIVILEGES ON blockchain_node1.* TO 'blockchain_user'@'%';
GRANT ALL PRIVILEGES ON blockchain_node2.* TO 'blockchain_user'@'%';
GRANT ALL PRIVILEGES ON blockchain_node3.* TO 'blockchain_user'@'%';

-- Flush privileges
FLUSH PRIVILEGES;

-- Show created databases
SHOW DATABASES LIKE 'blockchain_%';
