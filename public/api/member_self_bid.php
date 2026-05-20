<?php

require_once __DIR__ . '/_bootstrap.php';

try {
    $input = api_input();

    $memberCode = trim($input['member_code'] ?? '');
    $groupId = (int) ($input['group_id'] ?? 0);
    $bidAmount = (float) ($input['bid_amount'] ?? 0);

    if ($memberCode === '') {
        api_error('Member code is required.');
    }

    if ($groupId <= 0) {
        api_error('Group is required.');
    }

    if ($bidAmount <= 0) {
        api_error('Bid amount must be greater than 0.');
    }

    $db = db();

    // 1. Find member by member code
    $stmt = $db->prepare("
        SELECT *
        FROM members
        WHERE member_code = ?
        LIMIT 1
    ");
    $stmt->execute([$memberCode]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$member) {
        api_error('Invalid member code.');
    }

    // 2. Check member belongs to selected group
    $stmt = $db->prepare("
        SELECT *
        FROM group_members
        WHERE member_id = ?
        AND group_id = ?
        AND status = 'active'
        LIMIT 1
    ");
    $stmt->execute([
        $member['id'],
        $groupId
    ]);

    $groupMember = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$groupMember) {
        api_error('Member does not belong to this group.');
    }

    $groupMemberId = (int) $groupMember['id'];

    // 3. Find open round automatically by group_id
    $stmt = $db->prepare("
        SELECT *
        FROM rounds
        WHERE group_id = ?
        AND status = 'open'
        ORDER BY round_no ASC
        LIMIT 1
    ");
    $stmt->execute([$groupId]);
    $round = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$round) {
        api_error('No open round found for this group.');
    }

    $roundId = (int) $round['id'];
    $roundNo = (int) $round['round_no'];
    $maxBidAmount = (float) $round['max_bid_amount'];

    // 4. Round 1 should not allow bidding
    if ($roundNo === 1) {
        api_error('Round 1 does not allow bidding.');
    }

    // 5. Validate max bid
    if ($bidAmount > $maxBidAmount) {
        api_error('Bid amount must be less than or equal to max allowed bid.');
    }

    // 6. Check if member already received payout
    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM payouts
        WHERE winner_group_member_id = ?
    ");
    $stmt->execute([$groupMemberId]);

    if ((int) $stmt->fetchColumn() > 0) {
        api_error('You already received payout, so you cannot bid again.');
    }

    // 7. Check if member already bid in this open round
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

    if ((int) $stmt->fetchColumn() > 0) {
        api_error('You have already placed a bid in this round.');
    }

    // 8. Insert bid
    $stmt = $db->prepare("
        INSERT INTO bids
        (
            round_id,
            group_member_id,
            bid_amount,
            created_at,
            updated_at
        )
        VALUES (?, ?, ?, NOW(), NOW())
    ");

    $stmt->execute([
        $roundId,
        $groupMemberId,
        $bidAmount
    ]);

    api_success([
        'round_id' => $roundId,
        'round_no' => $roundNo,
        'bid_amount' => $bidAmount,
        'max_bid_amount' => $maxBidAmount,
    ], 'Bid placed successfully');

} catch (Throwable $e) {
    api_error($e->getMessage(), [], 500);
}