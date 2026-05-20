<?php

require_once __DIR__ . '/_bootstrap.php';

try {
    require_api_admin();

    $input = api_input();
    $groupId = (int) ($input['group_id'] ?? 0);

    if ($groupId <= 0) {
        api_error('Group ID is required.');
    }

    $db = db();

    $stmt = $db->prepare("SELECT * FROM chit_groups WHERE id = ? LIMIT 1");
    $stmt->execute([$groupId]);
    $group = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$group) {
        api_error('Group not found.');
    }

    $stmt = $db->prepare("SELECT COUNT(*) FROM rounds WHERE group_id = ?");
    $stmt->execute([$groupId]);
    $existingRounds = (int) $stmt->fetchColumn();

    if ($existingRounds > 0) {
        api_error('Rounds already created for this group.');
    }

    $fundAmount = (float) $group['monthly_amount'];
    $totalRounds = (int) $group['duration_months'];
    $bidStepPercent = (float) $group['bid_step_percent'];
    $memberCount = (int) $group['member_count'];

    if ($memberCount <= 0) {
        api_error('Member count is invalid.');
    }

    $db->beginTransaction();

    for ($roundNo = 1; $roundNo <= $totalRounds; $roundNo++) {
        if ($roundNo === 1) {
            $maxBidAmount = 0;
            $memberPayAmount = $fundAmount / $memberCount;
        } else {
            $discountPercent = ($totalRounds - $roundNo) * $bidStepPercent;
            $maxBidAmount = $fundAmount - ($fundAmount * $discountPercent / 100);
            $memberPayAmount = 0;
        }

        $status = $roundNo === 1 ? 'open' : 'pending';

        $stmt = $db->prepare("
            INSERT INTO rounds
            (group_id, round_no, total_pool, max_bid_amount, member_pay_amount, payout_to, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, 'MEMBER', ?, NOW(), NOW())
        ");

        $stmt->execute([
            $groupId,
            $roundNo,
            $fundAmount,
            $maxBidAmount,
            $memberPayAmount,
            $status,
        ]);

        $roundId = (int) $db->lastInsertId();

        $stmtMembers = $db->prepare("
            SELECT id
            FROM group_members
            WHERE group_id = ?
            AND status = 'active'
        ");
        $stmtMembers->execute([$groupId]);
        $groupMembers = $stmtMembers->fetchAll(PDO::FETCH_ASSOC);

        foreach ($groupMembers as $gm) {
            $stmtContribution = $db->prepare("
                INSERT INTO contributions
                (round_id, group_member_id, amount, status, created_at, updated_at)
                VALUES (?, ?, ?, 'due', NOW(), NOW())
            ");

            $stmtContribution->execute([
                $roundId,
                $gm['id'],
                $memberPayAmount,
            ]);
        }
    }

    $db->commit();

    api_success([], 'Rounds created successfully');

} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }

    api_error($e->getMessage(), [], 500);
}