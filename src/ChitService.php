<?php

class ChitService
{
    private PDO $db;

    public function __construct(PDO $pdo)
    {
        $this->db = $pdo;
    }

    public function createGroup(array $data): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO chit_groups
            (group_name, currency, member_count, duration_months, monthly_amount, bid_step_percent, start_date, status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?)
        ");

        $stmt->execute([
            $data['group_name'],
            $data['currency'] ?? 'NPR',
            (int) $data['member_count'],
            (int) $data['duration_months'],
            (float) $data['monthly_amount'],
            (float) ($data['bid_step_percent'] ?? 0),
            $data['start_date'] ?? date('Y-m-d'),
            $data['created_by'] ?? null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function createMember(array $data): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO members
            (full_name, phone, email, address, member_code, status)
            VALUES (?, ?, ?, ?, 'TEMP', 'active')
        ");

        $stmt->execute([
            $data['full_name'],
            $data['phone'] ?? null,
            $data['email'] ?? null,
            $data['address'] ?? null,
        ]);

        $memberId = (int) $this->db->lastInsertId();
        $memberCode = $this->generateUniqueMemberCode($data['full_name'], $memberId);

        $stmt = $this->db->prepare("UPDATE members SET member_code = ? WHERE id = ?");
        $stmt->execute([$memberCode, $memberId]);

        return $memberId;
    }

    public function generateUniqueMemberCode(string $fullName, int $memberId): string
    {
        $name = strtoupper(preg_replace('/\s+/', '', $fullName));
        $prefix = substr($name, 0, 3);

        if (strlen($prefix) < 3) {
            $prefix = str_pad($prefix, 3, 'X');
        }

        $code = $prefix . str_pad((string) $memberId, 8, '0', STR_PAD_LEFT);

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM members WHERE member_code = ? AND id != ?");
        $stmt->execute([$code, $memberId]);

        if ((int) $stmt->fetchColumn() === 0) {
            return $code;
        }

        return $prefix . random_int(10000000, 99999999);
    }

    public function addMemberToGroup(int $groupId, int $memberId): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO group_members
            (group_id, member_id, joined_at, status)
            VALUES (?, ?, CURDATE(), 'active')
        ");

        $stmt->execute([$groupId, $memberId]);
    }

    public function calculateMaxBidAmount(float $fundAmount, int $totalRounds, int $roundNo, float $bidStepPercent): float
    {
        if ($roundNo === 1) {
            return 0.00;
        }

        $discountPercent = ($totalRounds - $roundNo) * $bidStepPercent;
        return round($fundAmount - ($fundAmount * $discountPercent / 100), 2);
    }

    public function autoCreateRounds(int $groupId): void
    {
        $stmt = $this->db->prepare("SELECT * FROM chit_groups WHERE id = ? LIMIT 1");
        $stmt->execute([$groupId]);
        $group = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$group) {
            throw new Exception('Group not found.');
        }

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM rounds WHERE group_id = ?");
        $stmt->execute([$groupId]);

        if ((int) $stmt->fetchColumn() > 0) {
            throw new Exception('Rounds already created for this group.');
        }

        $fundAmount = (float) $group['monthly_amount'];
        $totalRounds = (int) $group['duration_months'];
        $memberCount = (int) $group['member_count'];
        $bidStepPercent = (float) $group['bid_step_percent'];

        if ($memberCount <= 0) {
            throw new Exception('Invalid member count.');
        }

        $stmtMembers = $this->db->prepare("
            SELECT id FROM group_members
            WHERE group_id = ? AND status = 'active'
        ");
        $stmtMembers->execute([$groupId]);
        $groupMembers = $stmtMembers->fetchAll(PDO::FETCH_ASSOC);

        if (count($groupMembers) === 0) {
            throw new Exception('Please add members before creating rounds.');
        }

        $this->db->beginTransaction();

        try {
            for ($roundNo = 1; $roundNo <= $totalRounds; $roundNo++) {
                $maxBidAmount = $this->calculateMaxBidAmount($fundAmount, $totalRounds, $roundNo, $bidStepPercent);
                $memberPayAmount = $roundNo === 1 ? round($fundAmount / $memberCount, 2) : 0.00;
                $status = $roundNo === 1 ? 'open' : 'pending';
                $payoutTo = $roundNo === 1 ? 'ADMIN' : 'MEMBER';

                $stmt = $this->db->prepare("
                    INSERT INTO rounds
                    (group_id, round_no, total_pool, max_bid_amount, member_pay_amount, payout_to, status, opened_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $stmt->execute([
                    $groupId,
                    $roundNo,
                    $fundAmount,
                    $maxBidAmount,
                    $memberPayAmount,
                    $payoutTo,
                    $status,
                    $status === 'open' ? date('Y-m-d H:i:s') : null,
                ]);

                $roundId = (int) $this->db->lastInsertId();

                foreach ($groupMembers as $gm) {
                    $stmtContribution = $this->db->prepare("
                        INSERT INTO contributions
                        (round_id, group_member_id, amount, status)
                        VALUES (?, ?, ?, 'due')
                    ");
                    $stmtContribution->execute([$roundId, $gm['id'], $memberPayAmount]);
                }
            }

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function markContributionPaid(int $contributionId): void
    {
        $stmt = $this->db->prepare("
            UPDATE contributions
            SET status = 'paid', paid_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$contributionId]);
    }

    public function markContributionDue(int $contributionId): void
    {
        $stmt = $this->db->prepare("
            UPDATE contributions
            SET status = 'due', paid_at = NULL
            WHERE id = ?
        ");
        $stmt->execute([$contributionId]);
    }

    public function placeBid(int $roundId, int $groupMemberId, float $bidAmount, string $bidBy = 'MEMBER'): void
    {
        if ($bidAmount <= 0) {
            throw new Exception('Bid amount must be greater than 0.');
        }

        $stmt = $this->db->prepare("SELECT * FROM rounds WHERE id = ? LIMIT 1");
        $stmt->execute([$roundId]);
        $round = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$round) {
            throw new Exception('Round not found.');
        }

        if ((int) $round['round_no'] === 1) {
            throw new Exception('Round 1 does not allow bidding.');
        }

        if ($round['status'] !== 'open') {
            throw new Exception('This round is not open.');
        }

        if ($bidAmount > (float) $round['max_bid_amount']) {
            throw new Exception('Bid amount must be less than or equal to max allowed bid.');
        }

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM payouts WHERE winner_group_member_id = ?");
        $stmt->execute([$groupMemberId]);

        if ((int) $stmt->fetchColumn() > 0) {
            throw new Exception('This member already received payout and cannot bid again.');
        }

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM bids WHERE round_id = ? AND group_member_id = ?");
        $stmt->execute([$roundId, $groupMemberId]);

        if ((int) $stmt->fetchColumn() > 0) {
            throw new Exception('This member already placed a bid in this round.');
        }

        $stmt = $this->db->prepare("
            INSERT INTO bids
            (round_id, group_member_id, bid_amount, bid_by)
            VALUES (?, ?, ?, ?)
        ");

        $stmt->execute([$roundId, $groupMemberId, $bidAmount, $bidBy]);
    }

    public function placeBidByMemberCode(string $memberCode, int $groupId, int $roundId, float $bidAmount): void
    {
        $stmt = $this->db->prepare("
            SELECT gm.id AS group_member_id
            FROM members m
            INNER JOIN group_members gm ON gm.member_id = m.id
            WHERE m.member_code = ?
            AND gm.group_id = ?
            AND gm.status = 'active'
            LIMIT 1
        ");
        $stmt->execute([$memberCode, $groupId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new Exception('Invalid member code or member does not belong to this group.');
        }

        $this->placeBid($roundId, (int) $row['group_member_id'], $bidAmount, 'MEMBER');
    }

    public function closeRound(int $roundId): void
    {
        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare("
                SELECT r.*, g.monthly_amount, g.member_count
                FROM rounds r
                INNER JOIN chit_groups g ON g.id = r.group_id
                WHERE r.id = ?
                LIMIT 1
            ");
            $stmt->execute([$roundId]);
            $round = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$round) {
                throw new Exception('Round not found.');
            }

            if ($round['status'] !== 'open') {
                throw new Exception('Only open round can be closed.');
            }

            $fundAmount = (float) $round['monthly_amount'];
            $memberCount = (int) $round['member_count'];
            $roundNo = (int) $round['round_no'];
            $groupId = (int) $round['group_id'];

            if ($memberCount <= 0) {
                throw new Exception('Invalid member count.');
            }

            if ($roundNo === 1) {
                $memberPayAmount = round($fundAmount / $memberCount, 2);

                $stmt = $this->db->prepare("
                    UPDATE rounds
                    SET winning_bid = NULL,
                        member_pay_amount = ?,
                        payout_to = 'ADMIN',
                        status = 'closed',
                        closed_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$memberPayAmount, $roundId]);

                $stmt = $this->db->prepare("UPDATE contributions SET amount = ? WHERE round_id = ?");
                $stmt->execute([$memberPayAmount, $roundId]);

                $stmt = $this->db->prepare("
                    INSERT INTO payouts
                    (round_id, winner_group_member_id, payout_amount, payout_type)
                    VALUES (?, NULL, ?, 'ADMIN_FIRST_ROUND')
                ");
                $stmt->execute([$roundId, $fundAmount]);
            } else {
                $stmt = $this->db->prepare("
                    SELECT gm.id AS group_member_id
                    FROM group_members gm
                    WHERE gm.group_id = ?
                    AND gm.status = 'active'
                    AND gm.id NOT IN (
                        SELECT winner_group_member_id
                        FROM payouts
                        WHERE winner_group_member_id IS NOT NULL
                    )
                ");
                $stmt->execute([$groupId]);
                $eligibleMembers = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (count($eligibleMembers) === 1) {
                    $winnerGroupMemberId = (int) $eligibleMembers[0]['group_member_id'];
                    $winningBid = $fundAmount;
                    $payoutType = 'FINAL_AUTO';
                } else {
                    $stmt = $this->db->prepare("
                        SELECT *
                        FROM bids
                        WHERE round_id = ?
                        ORDER BY bid_amount ASC, id ASC
                        LIMIT 1
                    ");
                    $stmt->execute([$roundId]);
                    $winnerBid = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$winnerBid) {
                        throw new Exception('No bids found for this round.');
                    }

                    $winnerGroupMemberId = (int) $winnerBid['group_member_id'];
                    $winningBid = (float) $winnerBid['bid_amount'];
                    $payoutType = 'MEMBER_BID';
                }

                $memberPayAmount = round($winningBid / $memberCount, 2);

                $stmt = $this->db->prepare("
                    UPDATE rounds
                    SET winning_bid = ?,
                        member_pay_amount = ?,
                        payout_to = 'MEMBER',
                        status = 'closed',
                        closed_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$winningBid, $memberPayAmount, $roundId]);

                $stmt = $this->db->prepare("UPDATE contributions SET amount = ? WHERE round_id = ?");
                $stmt->execute([$memberPayAmount, $roundId]);

                $stmt = $this->db->prepare("
                    INSERT INTO payouts
                    (round_id, winner_group_member_id, payout_amount, payout_type)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$roundId, $winnerGroupMemberId, $winningBid, $payoutType]);
            }

            $stmt = $this->db->prepare("
                SELECT id FROM rounds
                WHERE group_id = ?
                AND status = 'pending'
                ORDER BY round_no ASC
                LIMIT 1
            ");
            $stmt->execute([$groupId]);
            $nextRound = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($nextRound) {
                $stmt = $this->db->prepare("
                    UPDATE rounds
                    SET status = 'open', opened_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$nextRound['id']]);
            } else {
                $stmt = $this->db->prepare("
                    UPDATE chit_groups
                    SET status = 'completed'
                    WHERE id = ?
                ");
                $stmt->execute([$groupId]);
            }

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
