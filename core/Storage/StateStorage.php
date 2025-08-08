<?php
declare(strict_types=1);

namespace Blockchain\Core\Storage;

use PDO;
use PDOException;

/**
 * Minimal smart contract state storage backed by the `smart_contracts` table.
 *
 * Responsibilities:
 * - Persist contract state (bytecode + storage + metadata) on deploy/update
 * - Retrieve contract state for execution and read APIs
 * - Retrieve contract events (currently no separate events table; returns empty array)
 */
class StateStorage
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Save or update contract state.
     *
     * Expected $state shape:
     * - code: string (hex bytecode)
     * - storage: array
     * - deployer: string (0x...)
     * - deployed_at: int (unix)
     * - constructor_args: array (optional)
     */
    public function saveContractState(string $address, array $state): void
    {
    $bytecode = (string)($state['code'] ?? '');
    $storage = json_encode($state['storage'] ?? [], JSON_UNESCAPED_SLASHES);
    $creator = (string)($state['deployer'] ?? '');
    $createdAt = (int)($state['deployed_at'] ?? time());
    $name = (string)($state['name'] ?? 'Contract');
    $abi = json_encode($state['abi'] ?? [], JSON_UNESCAPED_SLASHES);
    $source = $state['source_code'] ?? null;

        // Ensure table exists before upsert (best-effort)
        try {
            $this->pdo->query("SHOW TABLES LIKE 'smart_contracts'");
        } catch (PDOException $e) {
            // If no DB or table available, just return; caller may be operating in a mockless but non-DB context
            return;
        }

        // Upsert into smart_contracts
        $sql = "INSERT INTO smart_contracts 
                    (address, creator, name, version, bytecode, abi, source_code, deployment_tx, deployment_block, gas_used, status, storage, metadata, created_at, updated_at)
                VALUES
                    (:address, :creator, :name, :version, :bytecode, :abi, :source_code, :deployment_tx, :deployment_block, :gas_used, :status, :storage, :metadata, FROM_UNIXTIME(:created_at), NOW())
                ON DUPLICATE KEY UPDATE
                    bytecode = VALUES(bytecode),
                    abi = VALUES(abi),
                    storage = VALUES(storage),
                    metadata = VALUES(metadata),
                    updated_at = NOW()";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':address' => $address,
            ':creator' => $creator ?: '0x0000000000000000000000000000000000000000',
            ':name' => $name,
            ':version' => '1.0.0',
            ':bytecode' => $bytecode,
            ':abi' => $abi,
            ':source_code' => $source,
            ':deployment_tx' => $state['deployment_tx'] ?? hash('sha256', $address . $createdAt),
            ':deployment_block' => (int)($state['deployment_block'] ?? 0),
            ':gas_used' => (int)($state['gas_used'] ?? 0),
            ':status' => 'active',
            ':storage' => $storage,
            ':metadata' => json_encode($state['metadata'] ?? [], JSON_UNESCAPED_SLASHES),
            ':created_at' => $createdAt,
        ]);
    }

    /**
     * Load contract state by address; returns associative array compatible with SmartContractManager expectations.
     */
    public function getContractState(string $address): ?array
    {
        try {
            $stmt = $this->pdo->prepare("SELECT address, creator, bytecode, storage, created_at, metadata FROM smart_contracts WHERE address = ? LIMIT 1");
            $stmt->execute([$address]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) return null;

            $storage = [];
            if (!empty($row['storage'])) {
                $decoded = json_decode($row['storage'], true);
                if (is_array($decoded)) $storage = $decoded;
            }

            return [
                'code' => (string)($row['bytecode'] ?? ''),
                'storage' => $storage,
                'balance' => 0,
                'deployer' => (string)($row['creator'] ?? ''),
                'deployed_at' => $row['created_at'] ? strtotime((string)$row['created_at']) : time(),
            ];
        } catch (PDOException $e) {
            return null;
        }
    }

    /**
     * Return contract events; currently not persisted separately, so return an empty list.
     */
    public function getContractEvents(string $address, int $fromBlock = 0, int $toBlock = -1): array
    {
        return [];
    }
}
