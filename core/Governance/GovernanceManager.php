<?php
declare(strict_types=1);

namespace Blockchain\Core\Governance;

use Blockchain\Core\Contracts\BlockchainInterface;
use Blockchain\Core\Contracts\TransactionInterface;
use Blockchain\Core\Consensus\ProofOfStake;
use PDO;
use Exception;

/**
 * Blockchain network change management system
 */
class GovernanceManager
{
    private PDO $database;
    private BlockchainInterface $blockchain;
    private ProofOfStake $consensus;
    private array $votingThresholds;
    private AutoUpdater $autoUpdater;

    // Proposal types
    const PROPOSAL_PARAMETER = 'parameter';
    const PROPOSAL_CONSENSUS = 'consensus';
    const PROPOSAL_ECONOMIC = 'economic';
    const PROPOSAL_UPGRADE = 'upgrade';
    const PROPOSAL_EMERGENCY = 'emergency';

    // Proposal statuses
    const STATUS_DRAFT = 'draft';
    const STATUS_ACTIVE = 'active';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';
    const STATUS_IMPLEMENTED = 'implemented';
    const STATUS_CANCELLED = 'cancelled';

    // Voting thresholds
    const THRESHOLD_SIMPLE = 51;
    const THRESHOLD_QUALIFIED = 67;
    const THRESHOLD_SUPER = 75;
    const THRESHOLD_UNANIMOUS = 95;

    public function __construct(
        PDO $database,
        BlockchainInterface $blockchain,
        ProofOfStake $consensus
    ) {
        $this->database = $database;
        $this->blockchain = $blockchain;
        $this->consensus = $consensus;
        $this->autoUpdater = new AutoUpdater($this);

        $this->votingThresholds = [
            self::PROPOSAL_PARAMETER => self::THRESHOLD_SIMPLE,
            self::PROPOSAL_CONSENSUS => self::THRESHOLD_QUALIFIED,
            self::PROPOSAL_ECONOMIC => self::THRESHOLD_QUALIFIED,
            self::PROPOSAL_UPGRADE => self::THRESHOLD_SUPER,
            self::PROPOSAL_EMERGENCY => self::THRESHOLD_UNANIMOUS
        ];
    }


    /**
     * Create new proposal
     */
    public function createProposal(
        string $title,
        string $description,
        string $type,
        string $proposerAddress,
        float $proposerStake,
        array $changes,
        bool $emergency = false
    ): int {
        // Check proposal creation rights
        if (!$this->canCreateProposal($proposerAddress, $proposerStake, $type)) {
            throw new Exception("Insufficient rights or stake to create proposal");
        }

        // Check limits on active proposals
        if ($this->getActiveProposalCount($proposerAddress) >= 3) {
            throw new Exception("Maximum active proposals limit reached");
        }

        $sql = "INSERT INTO governance_proposals 
                (title, description, type, proposer_address, proposer_stake, changes, status, voting_start, voting_end) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $votingStart = new \DateTime();
        $votingEnd = clone $votingStart;
        
        if ($emergency) {
            $votingEnd->add(new \DateInterval('PT24H')); // 24 hours for emergency
        } else {
            $votingEnd->add(new \DateInterval('P7D')); // 7 days for regular
        }

        $stmt = $this->database->prepare($sql);
        $stmt->execute([
            $title,
            $description,
            $type,
            $proposerAddress,
            $proposerStake,
            json_encode($changes),
            self::STATUS_ACTIVE,
            $votingStart->format('Y-m-d H:i:s'),
            $votingEnd->format('Y-m-d H:i:s')
        ]);

        $proposalId = (int)$this->database->lastInsertId();

        // Record proposal creation in blockchain
        $this->recordProposalTransaction($proposalId, 'created', $proposerAddress);

        return $proposalId;
    }

    /**
     * Vote on proposal
     */
    public function vote(
        int $proposalId,
        string $voterAddress,
        string $vote,
        string $reason = ''
    ): bool {
        $proposal = $this->getProposal($proposalId);
        if (!$proposal) {
            throw new Exception("Proposal not found");
        }

        if ($proposal['status'] !== self::STATUS_ACTIVE) {
            throw new Exception("Proposal is not active for voting");
        }

        if (new \DateTime() > new \DateTime($proposal['voting_end'])) {
            throw new Exception("Voting period has ended");
        }

        // Check voting rights
        if (!$this->canVote($voterAddress, $proposal['type'])) {
            throw new Exception("Insufficient rights to vote on this proposal type");
        }

        // Calculate vote weight
        $voteWeight = $this->calculateVoteWeight($voterAddress);

        // Record vote
        $sql = "INSERT INTO governance_votes 
                (proposal_id, voter_address, vote, weight, reason) 
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                vote = VALUES(vote), weight = VALUES(weight), reason = VALUES(reason)";

        $stmt = $this->database->prepare($sql);
        $success = $stmt->execute([$proposalId, $voterAddress, $vote, $voteWeight, $reason]);

        if ($success) {
            // Update счетчики голосов
            $this->updateVoteCounts($proposalId);
            
            // Record голосование в blockchain
            $this->recordVoteTransaction($proposalId, $voterAddress, $vote, $voteWeight);
            
            // Check, достигнут ли порог для автоматического принятия
            $this->checkProposalThreshold($proposalId);
        }

        return $success;
    }

    /**
     * Делегирование голосов
     */
    public function delegateVotes(
        string $delegatorAddress,
        string $delegateAddress,
        float $weight,
        ?\DateTime $expiresAt = null
    ): bool {
        // Check, что делегатор имеет достаточный стейк
        $availableStake = $this->getAvailableStake($delegatorAddress);
        if ($availableStake < $weight) {
            throw new Exception("Insufficient stake to delegate");
        }

        $sql = "INSERT INTO governance_delegations 
                (delegator_address, delegate_address, weight, expires_at) 
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                weight = VALUES(weight), expires_at = VALUES(expires_at)";

        $stmt = $this->database->prepare($sql);
        return $stmt->execute([
            $delegatorAddress,
            $delegateAddress,
            $weight,
            $expiresAt ? $expiresAt->format('Y-m-d H:i:s') : null
        ]);
    }

    /**
     * Check принятого proposal
     */
    public function implementProposal(int $proposalId): bool
    {
        $proposal = $this->getProposal($proposalId);
        if (!$proposal || $proposal['status'] !== self::STATUS_APPROVED) {
            throw new Exception("Proposal is not approved for implementation");
        }

        try {
            // Create backup текущего state
            $rollbackData = $this->createStateBackup($proposal);

            // Apply changes
            $success = $this->applyChanges($proposal['changes']);

            if ($success) {
                // Update статус proposal
                $this->updateProposalStatus($proposalId, self::STATUS_IMPLEMENTED);
                
                // Record успешную implementation
                $this->recordImplementation($proposalId, true, null, $rollbackData);
                
                return true;
            } else {
                throw new Exception("Failed to apply changes");
            }

        } catch (Exception $e) {
            // Record неудачную implementation
            $this->recordImplementation($proposalId, false, $e->getMessage(), $rollbackData ?? []);
            
            throw $e;
        }
    }

    /**
     * Check изменений
     */
    public function rollbackProposal(int $proposalId, string $reason = ''): bool
    {
        $implementation = $this->getImplementation($proposalId);
        if (!$implementation || !$implementation['success']) {
            throw new Exception("No successful implementation found to rollback");
        }

        try {
            $rollbackData = json_decode($implementation['rollback_data'], true);
            $success = $this->applyRollback($rollbackData);

            if ($success) {
                // Create экстренное предложение для формализации отката
                $this->createRollbackProposal($proposalId, $reason);
                return true;
            }

            return false;

        } catch (Exception $e) {
            error_log("Rollback failed for proposal {$proposalId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check информации о предложении
     */
    public function getProposal(int $proposalId): ?array
    {
        $sql = "SELECT * FROM governance_proposals WHERE id = ?";
        $stmt = $this->database->prepare($sql);
        $stmt->execute([$proposalId]);
        
        $proposal = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($proposal) {
            $proposal['changes'] = json_decode($proposal['changes'], true);
        }
        
        return $proposal ?: null;
    }

    /**
     * Check списка активных предложений
     */
    public function getActiveProposals(): array
    {
        $sql = "SELECT p.*, 
                       (SELECT COUNT(*) FROM governance_votes v WHERE v.proposal_id = p.id) as vote_count
                FROM governance_proposals p 
                WHERE p.status = ? 
                ORDER BY p.created_at DESC";
        
        $stmt = $this->database->prepare($sql);
        $stmt->execute([self::STATUS_ACTIVE]);
        
        $proposals = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($proposals as &$proposal) {
            $proposal['changes'] = json_decode($proposal['changes'], true);
        }
        
        return $proposals;
    }

    /**
     * Check голосов по предложению
     */
    public function getProposalVotes(int $proposalId): array
    {
        $sql = "SELECT v.*, vr.reputation_score 
                FROM governance_votes v
                LEFT JOIN validator_reputation vr ON v.voter_address = vr.address
                WHERE v.proposal_id = ? 
                ORDER BY v.weight DESC, v.created_at ASC";
        
        $stmt = $this->database->prepare($sql);
        $stmt->execute([$proposalId]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Автоматическая проверка и применение одобренных предложений
     */
    public function processAutomaticUpdates(): array
    {
        return $this->autoUpdater->processUpdates();
    }

    // Приватные методы

    private function canCreateProposal(string $address, float $stake, string $type): bool
    {
        $minStake = $this->getMinimumStakeForProposal($type);
        $activeValidators = $this->consensus->getActiveValidators();
        return $stake >= $minStake && isset($activeValidators[$address]);
    }

    private function canVote(string $address, string $proposalType): bool
    {
        // Базовые права голоса для держателей токенов
        $balance = $this->blockchain->getBalance($address);
        if ($balance < 100) {
            return false;
        }

        // Расширенные права для валидаторов
        if (in_array($proposalType, [self::PROPOSAL_CONSENSUS, self::PROPOSAL_UPGRADE])) {
            $activeValidators = $this->consensus->getActiveValidators();
            return isset($activeValidators[$address]);
        }

        return true;
    }

    private function calculateVoteWeight(string $address): float
    {
        $activeValidators = $this->consensus->getActiveValidators();
        $baseStake = $activeValidators[$address]['stake'] ?? 0;
        $reputation = 0; // Simplified for now
        $delegatedWeight = $this->getDelegatedWeight($address);

        return ($baseStake + $delegatedWeight) * (1 + $reputation * 0.5);
    }

    private function updateVoteCounts(int $proposalId): void
    {
        $sql = "UPDATE governance_proposals SET 
                votes_for = (SELECT COALESCE(SUM(weight), 0) FROM governance_votes WHERE proposal_id = ? AND vote = 'for'),
                votes_against = (SELECT COALESCE(SUM(weight), 0) FROM governance_votes WHERE proposal_id = ? AND vote = 'against'),
                votes_abstain = (SELECT COALESCE(SUM(weight), 0) FROM governance_votes WHERE proposal_id = ? AND vote = 'abstain')
                WHERE id = ?";

        $stmt = $this->database->prepare($sql);
        $stmt->execute([$proposalId, $proposalId, $proposalId, $proposalId]);
    }

    private function checkProposalThreshold(int $proposalId): void
    {
        $proposal = $this->getProposal($proposalId);
        $threshold = $this->votingThresholds[$proposal['type']];
        
        $totalVotes = $proposal['votes_for'] + $proposal['votes_against'];
        if ($totalVotes > 0) {
            $approvalRate = ($proposal['votes_for'] / $totalVotes) * 100;
            
            if ($approvalRate >= $threshold) {
                $this->updateProposalStatus($proposalId, self::STATUS_APPROVED);
            }
        }
    }

    private function applyChanges(array $changes): bool
    {
        // Check изменений в зависимости от типа
        foreach ($changes as $key => $value) {
            switch ($key) {
                case 'block_time':
                    $this->updateConsensusParameter('block_time', $value);
                    break;
                case 'block_reward':
                    $this->updateConsensusParameter('block_reward', $value);
                    break;
                case 'minimum_stake':
                    $this->updateConsensusParameter('minimum_stake', $value);
                    break;
                // Добавить другие типы изменений
            }
        }
        
        return true;
    }

    private function updateProposalStatus(int $proposalId, string $status): void
    {
        $sql = "UPDATE governance_proposals SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?";
        $stmt = $this->database->prepare($sql);
        $stmt->execute([$status, $proposalId]);
    }

    private function recordProposalTransaction(int $proposalId, string $action, string $address): void
    {
        // Check transaction governance для записи в blockchain
        // Это будет реализовано в зависимости от структуры транзакций
    }

    private function recordVoteTransaction(int $proposalId, string $voter, string $vote, float $weight): void
    {
        // Check transaction voting для записи в blockchain
    }

    private function getMinimumStakeForProposal(string $type): float
    {
        return match($type) {
            self::PROPOSAL_PARAMETER => 1000,
            self::PROPOSAL_CONSENSUS => 5000,
            self::PROPOSAL_ECONOMIC => 3000,
            self::PROPOSAL_UPGRADE => 10000,
            self::PROPOSAL_EMERGENCY => 20000,
            default => 1000
        };
    }

    private function getActiveProposalCount(string $address): int
    {
        $sql = "SELECT COUNT(*) FROM governance_proposals WHERE proposer_address = ? AND status = ?";
        $stmt = $this->database->prepare($sql);
        $stmt->execute([$address, self::STATUS_ACTIVE]);
        
        return (int)$stmt->fetchColumn();
    }

    private function getDelegatedWeight(string $address): float
    {
        $sql = "SELECT COALESCE(SUM(weight), 0) FROM governance_delegations 
                WHERE delegate_address = ? AND (expires_at IS NULL OR expires_at > NOW())";
        
        $stmt = $this->database->prepare($sql);
        $stmt->execute([$address]);
        
        return (float)$stmt->fetchColumn();
    }

    private function getAvailableStake(string $address): float
    {
        $activeValidators = $this->consensus->getActiveValidators();
        $totalStake = $activeValidators[$address]['stake'] ?? 0;
        $delegatedStake = $this->getDelegatedStakeOut($address);
        
        return $totalStake - $delegatedStake;
    }

    private function getDelegatedStakeOut(string $address): float
    {
        $sql = "SELECT COALESCE(SUM(weight), 0) FROM governance_delegations 
                WHERE delegator_address = ? AND (expires_at IS NULL OR expires_at > NOW())";
        
        $stmt = $this->database->prepare($sql);
        $stmt->execute([$address]);
        
        return (float)$stmt->fetchColumn();
    }

    private function createStateBackup(array $proposal): array
    {
        // Check backupа текущего state для возможного отката
        return [
            'consensus_parameters' => [
                'minimum_stake' => 1000,
                'block_reward' => 10,
                'epoch_length' => 100
            ],
            'timestamp' => time(),
            'block_height' => $this->blockchain->getHeight()
        ];
    }

    private function recordImplementation(int $proposalId, bool $success, ?string $error, array $rollbackData): void
    {
        $sql = "INSERT INTO governance_implementations 
                (proposal_id, implementation_hash, block_height, success, error_message, rollback_data) 
                VALUES (?, ?, ?, ?, ?, ?)";

        $stmt = $this->database->prepare($sql);
        $stmt->execute([
            $proposalId,
            hash('sha256', serialize($rollbackData)),
            $this->blockchain->getHeight(),
            $success,
            $error,
            json_encode($rollbackData)
        ]);
    }

    private function getImplementation(int $proposalId): ?array
    {
        $sql = "SELECT * FROM governance_implementations WHERE proposal_id = ? ORDER BY created_at DESC LIMIT 1";
        $stmt = $this->database->prepare($sql);
        $stmt->execute([$proposalId]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function applyRollback(array $rollbackData): bool
    {
        // Check данных отката
        if (isset($rollbackData['consensus_parameters'])) {
            foreach ($rollbackData['consensus_parameters'] as $param => $value) {
                $this->updateConsensusParameter($param, $value);
            }
        }
        
        return true;
    }

    private function updateConsensusParameter(string $parameter, $value): void
    {
        // Логируем изменение параметра (реальная реализация будет позже)
        error_log("Consensus parameter update requested: {$parameter} = " . json_encode($value));
    }

    private function createRollbackProposal(int $originalProposalId, string $reason): int
    {
        return $this->createProposal(
            "Rollback of Proposal #$originalProposalId",
            "Emergency rollback: $reason",
            self::PROPOSAL_EMERGENCY,
            'system',
            0,
            ['rollback_proposal_id' => $originalProposalId],
            true
        );
    }
}
