<?php

require_once __DIR__ . '/_bootstrap.php';

try {
    $input = api_input();

    $memberCode = trim($input['member_code'] ?? '');

    if ($memberCode === '') {
        api_error('Member code is required.');
    }

    $db = db();

    // 1. Find member by member code
    $stmt = $db->prepare("
        SELECT 
            id,
            full_name,
            phone,
            member_code
        FROM members
        WHERE member_code = ?
        LIMIT 1
    ");
    $stmt->execute([$memberCode]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$member) {
        api_error('Invalid member code.');
    }

    // 2. Get all active chit groups of this member
    $stmt = $db->prepare("
        SELECT 
            gm.id AS group_member_id,
            gm.status AS member_status,

            g.id AS group_id,
            g.group_name,
            g.currency,
            g.member_count,
            g.duration_months,
            g.monthly_amount,
            g.status AS group_status
        FROM group_members gm
        INNER JOIN chit_groups g ON g.id = gm.group_id
        WHERE gm.member_id = ?
        AND gm.status = 'active'
        ORDER BY g.id DESC
    ");
    $stmt->execute([$member['id']]);
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. For each group, get open round and calculate eligibility
    foreach ($groups as &$group) {
        $groupId = (int) $group['group_id'];
        $groupMemberId = (int) $group['group_member_id'];

        $group['open_round'] = null;
        $group['eligible'] = false;
        $group['already_paid'] = false;
        $group['already_bid'] = false;
        $group['not_eligible_reason'] = '';

        // Find current open round
        $stmt = $db->prepare("
            SELECT 
                id AS round_id,
                group_id,
                round_no,
                status,
                max_bid_amount
            FROM rounds
            WHERE group_id = ?
            AND status = 'open'
            ORDER BY round_no ASC
            LIMIT 1
        ");
        $stmt->execute([$groupId]);
        $openRound = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$openRound) {
            $group['not_eligible_reason'] = 'No open round found.';
            continue;
        }

        $group['open_round'] = [
            'id' => (int) $openRound['round_id'],
            'round_id' => (int) $openRound['round_id'],
            'group_id' => (int) $openRound['group_id'],
            'round_no' => (int) $openRound['round_no'],
            'status' => $openRound['status'],
            'max_bid_amount' => (float) $openRound['max_bid_amount'],
        ];

        $roundId = (int) $openRound['round_id'];
        $roundNo = (int) $openRound['round_no'];

        // Round 1 is owner/admin payout round, no bidding
        if ($roundNo === 1) {
            $group['not_eligible_reason'] = 'Round 1 does not allow bidding.';
            continue;
        }

        // Check if this group member already received payout
        // IMPORTANT: winner_group_member_id should match group_members.id
        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM payouts
            WHERE winner_group_member_id = ?
        ");
        $stmt->execute([$groupMemberId]);
        $alreadyPaid = ((int) $stmt->fetchColumn()) > 0;

        $group['already_paid'] = $alreadyPaid;

        if ($alreadyPaid) {
            $group['not_eligible_reason'] = 'Member already received payout.';
            continue;
        }

        // Check if this group member already placed bid in this round
        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM bids
            WHERE round_id = ?
            AND group_member_id = ?
        ");
        $stmt->execute([
            $roundId,
            $groupMemberId
        ]);
        $alreadyBid = ((int) $stmt->fetchColumn()) > 0;

        $group['already_bid'] = $alreadyBid;

        if ($alreadyBid) {
            $group['not_eligible_reason'] = 'Member already placed a bid in this round.';
            continue;
        }

        // If all checks passed, member is eligible
        $group['eligible'] = true;
        $group['not_eligible_reason'] = '';
    }

    unset($group);

    api_success([
        'member' => $member,
        'groups' => $groups,
    ], 'Member found successfully.');

} catch (Throwable $e) {
    api_error($e->getMessage(), [], 500);
}