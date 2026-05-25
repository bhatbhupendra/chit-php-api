<?php

require_once __DIR__ . '/_bootstrap.php';

try {
    $admin = require_api_admin();
    $adminId = is_array($admin) ? (int) $admin['id'] : (int) $admin;

    $db = db();

    $stmt = $db->prepare("SELECT COUNT(*) FROM chit_groups WHERE created_by = ?");
    $stmt->execute([$adminId]);
    $totalGroups = (int) $stmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT COUNT(DISTINCT gm.member_id)
        FROM group_members gm
        INNER JOIN chit_groups cg ON cg.id = gm.group_id
        WHERE cg.created_by = ?
    ");
    $stmt->execute([$adminId]);
    $totalMembers = (int) $stmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM rounds r
        INNER JOIN chit_groups cg ON cg.id = r.group_id
        WHERE r.status = 'open'
        AND cg.created_by = ?
    ");
    $stmt->execute([$adminId]);
    $openRounds = (int) $stmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM rounds r
        INNER JOIN chit_groups cg ON cg.id = r.group_id
        WHERE r.status = 'closed'
        AND cg.created_by = ?
    ");
    $stmt->execute([$adminId]);
    $completedRounds = (int) $stmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM contributions c
        INNER JOIN rounds r ON r.id = c.round_id
        INNER JOIN chit_groups cg ON cg.id = r.group_id
        WHERE c.status = 'due'
        AND cg.created_by = ?
    ");
    $stmt->execute([$adminId]);
    $pendingPayments = (int) $stmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM payouts p
        INNER JOIN rounds r ON r.id = p.round_id
        INNER JOIN chit_groups cg ON cg.id = r.group_id
        WHERE cg.created_by = ?
    ");
    $stmt->execute([$adminId]);
    $totalPayouts = (int) $stmt->fetchColumn();

    api_success([
    'admin_id' => $adminId,
    'total_groups' => $totalGroups,
    'total_members' => $totalMembers,
    'open_rounds' => $openRounds,
    'completed_rounds' => $completedRounds,
    'pending_payments' => $pendingPayments,
    'total_payouts' => $totalPayouts,
]);
    

} catch (Throwable $e) {
    api_error($e->getMessage(), [], 500);
}