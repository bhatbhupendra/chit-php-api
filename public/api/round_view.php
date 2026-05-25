<?php

require_once __DIR__ . '/_bootstrap.php';

try {
    require_api_admin();

    $roundId = (int) ($_GET['id'] ?? 0);

    if ($roundId <= 0) {
        api_error('Round ID is required.');
    }

    $db = db();

    $stmt = $db->prepare("
        SELECT r.*, g.group_name, g.monthly_amount AS fund_amount, g.currency
        FROM rounds r
        INNER JOIN chit_groups g ON g.id = r.group_id
        WHERE r.id = ?
        LIMIT 1
    ");
    $stmt->execute([$roundId]);
    $round = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$round) {
        api_error('Round not found.', [], 404);
    }

    $billStmt = $db->prepare("
        SELECT * FROM round_bills 
        WHERE round_id = ? 
        LIMIT 1
    ");
    $billStmt->execute([$roundId]);
    $bill = $billStmt->fetch(PDO::FETCH_ASSOC);

    $billCharges = [];

    if ($bill) {
        $chargeStmt = $db->prepare("
            SELECT * FROM round_bill_charges
            WHERE bill_id = ?
            ORDER BY id ASC
        ");
        $chargeStmt->execute([$bill['id']]);
        $billCharges = $chargeStmt->fetchAll(PDO::FETCH_ASSOC);

        $bill['charges'] = $billCharges;
    }

    $round['bill'] = $bill ?: null;
    ///rounds ends

    $stmt = $db->prepare("
        SELECT 
            c.*,
            m.full_name,
            m.member_code
        FROM contributions c
        INNER JOIN group_members gm ON gm.id = c.group_member_id
        INNER JOIN members m ON m.id = gm.member_id
        WHERE c.round_id = ?
        ORDER BY c.id ASC
    ");
    $stmt->execute([$roundId]);
    $contributions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->prepare("
        SELECT 
            b.*,
            m.full_name,
            m.member_code
        FROM bids b
        INNER JOIN group_members gm ON gm.id = b.group_member_id
        INNER JOIN members m ON m.id = gm.member_id
        WHERE b.round_id = ?
        ORDER BY b.bid_amount ASC, b.id ASC
    ");
    $stmt->execute([$roundId]);
    $bids = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->prepare("
        SELECT 
            gm.id AS group_member_id,
            m.full_name,
            m.member_code
        FROM group_members gm
        INNER JOIN members m ON m.id = gm.member_id
        WHERE gm.group_id = ?
        AND gm.status = 'active'
        AND gm.id NOT IN (
            SELECT winner_group_member_id
            FROM payouts
            WHERE winner_group_member_id IS NOT NULL
        )
        ORDER BY m.full_name ASC
    ");
    $stmt->execute([$round['group_id']]);
    $eligibleMembers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    api_success([
        'debug_round_id' => $roundId,
        'debug_bill_found' => $bill ? 'yes' : 'no',
        'debug_bill' => $bill,
        'round' => $round,
        'contributions' => $contributions,
        'bids' => $bids,
        'eligible_members' => $eligibleMembers,
    ]);

} catch (Throwable $e) {
    api_error($e->getMessage(), [], 500);
}