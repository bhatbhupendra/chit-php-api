<?php

require_once __DIR__ . '/_bootstrap.php';

try {
    require_api_admin();

    $input = api_input();
    $roundId = (int) ($input['round_id'] ?? 0);

    if ($roundId <= 0) {
        api_error('Round ID is required.');
    }

    $db = db();

    $db->beginTransaction();

    $stmt = $db->prepare("
        SELECT r.*, g.monthly_amount, g.member_count, g.duration_months
        FROM rounds r
        INNER JOIN chit_groups g ON g.id = r.group_id
        WHERE r.id = ?
        LIMIT 1
    ");
    $stmt->execute([$roundId]);
    $round = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$round) {
        api_error('Round not found.');
    }

    if ($round['status'] !== 'open') {
        api_error('Only open round can be closed.');
    }

    $fundAmount = (float) $round['monthly_amount'];
    $memberCount = (int) $round['member_count'];
    $roundNo = (int) $round['round_no'];
    $groupId = (int) $round['group_id'];

    if ($memberCount <= 0) {
        api_error('Member count is invalid.');
    }

    if ($roundNo === 1) {
        $memberPayAmount = $fundAmount / $memberCount;

        $stmt = $db->prepare("
            UPDATE rounds
            SET 
                winning_bid = NULL,
                member_pay_amount = ?,
                payout_to = 'ADMIN',
                status = 'closed',
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$memberPayAmount, $roundId]);

        $stmt = $db->prepare("
            UPDATE contributions
            SET amount = ?, updated_at = NOW()
            WHERE round_id = ?
        ");
        $stmt->execute([$memberPayAmount, $roundId]);

        $stmt = $db->prepare("
            INSERT INTO payouts
            (round_id, winner_group_member_id, payout_amount, payout_type, created_at, updated_at)
            VALUES (?, NULL, ?, 'ADMIN_FIRST_ROUND', NOW(), NOW())
        ");
        $stmt->execute([$roundId, $fundAmount]);
    } else {
        $stmt = $db->prepare("
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
            $stmt = $db->prepare("
                SELECT *
                FROM bids
                WHERE round_id = ?
                ORDER BY bid_amount ASC, id ASC
                LIMIT 1
            ");
            $stmt->execute([$roundId]);
            $winnerBid = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$winnerBid) {
                api_error('No bids found for this round.');
            }

            $winnerGroupMemberId = (int) $winnerBid['group_member_id'];
            $winningBid = (float) $winnerBid['bid_amount'];
            $payoutType = 'MEMBER_BID';
        }

        $memberPayAmount = $winningBid / $memberCount;

        $stmt = $db->prepare("
            UPDATE rounds
            SET 
                winning_bid = ?,
                member_pay_amount = ?,
                payout_to = 'MEMBER',
                status = 'closed',
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$winningBid, $memberPayAmount, $roundId]);

        $stmt = $db->prepare("
            UPDATE contributions
            SET amount = ?, updated_at = NOW()
            WHERE round_id = ?
        ");
        $stmt->execute([$memberPayAmount, $roundId]);

        $stmt = $db->prepare("
            INSERT INTO payouts
            (round_id, winner_group_member_id, payout_amount, payout_type, created_at, updated_at)
            VALUES (?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([
            $roundId,
            $winnerGroupMemberId,
            $winningBid,
            $payoutType,
        ]);
    }

    $stmt = $db->prepare("
        SELECT id
        FROM rounds
        WHERE group_id = ?
        AND status = 'pending'
        ORDER BY round_no ASC
        LIMIT 1
    ");
    $stmt->execute([$groupId]);
    $nextRound = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($nextRound) {
        $stmt = $db->prepare("
            UPDATE rounds
            SET status = 'open', updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$nextRound['id']]);
    }

    $db->commit();

    api_success([], 'Round closed successfully');

} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }

    api_error($e->getMessage(), [], 500);
}