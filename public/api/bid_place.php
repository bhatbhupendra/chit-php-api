<?php

require_once __DIR__ . '/_bootstrap.php';

try {
    require_api_admin();

    $input = api_input();

    $roundId = (int) ($input['round_id'] ?? 0);
    $groupMemberId = (int) ($input['group_member_id'] ?? 0);
    $bidAmount = (float) ($input['bid_amount'] ?? 0);

    if ($roundId <= 0 || $groupMemberId <= 0) {
        api_error('Round ID and group member ID are required.');
    }

    if ($bidAmount <= 0) {
        api_error('Bid amount must be greater than 0.');
    }

    $db = db();

    $stmt = $db->prepare("SELECT * FROM rounds WHERE id = ? LIMIT 1");
    $stmt->execute([$roundId]);
    $round = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$round) {
        api_error('Round not found.');
    }

    if ((int) $round['round_no'] === 1) {
        api_error('Round 1 does not allow bidding.');
    }

    if ($round['status'] !== 'open') {
        api_error('This round is not open for bidding.');
    }

    if ($bidAmount > (float) $round['max_bid_amount']) {
        api_error('Bid amount must be less than or equal to max bid amount.');
    }

    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM payouts
        WHERE winner_group_member_id = ?
    ");
    $stmt->execute([$groupMemberId]);

    if ((int) $stmt->fetchColumn() > 0) {
        api_error('This member already received payout and cannot bid again.');
    }

    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM bids
        WHERE round_id = ?
        AND group_member_id = ?
    ");
    $stmt->execute([$roundId, $groupMemberId]);

    if ((int) $stmt->fetchColumn() > 0) {
        api_error('This member already placed a bid in this round.');
    }

    $stmt = $db->prepare("
        INSERT INTO bids
        (round_id, group_member_id, bid_amount, created_at, updated_at)
        VALUES (?, ?, ?, NOW(), NOW())
    ");

    $stmt->execute([
        $roundId,
        $groupMemberId,
        $bidAmount,
    ]);

    api_success([], 'Bid placed successfully');

} catch (Throwable $e) {
    api_error($e->getMessage(), [], 500);
}