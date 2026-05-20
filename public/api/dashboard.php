<?php

require_once __DIR__ . '/_bootstrap.php';

try {
    require_api_admin();

    $db = db();

    $totalGroups = (int) $db->query("SELECT COUNT(*) FROM chit_groups")->fetchColumn();
    $totalMembers = (int) $db->query("SELECT COUNT(*) FROM members")->fetchColumn();
    $openRounds = (int) $db->query("SELECT COUNT(*) FROM rounds WHERE status = 'open'")->fetchColumn();
    $completedRounds = (int) $db->query("SELECT COUNT(*) FROM rounds WHERE status = 'closed'")->fetchColumn();
    $pendingPayments = (int) $db->query("SELECT COUNT(*) FROM contributions WHERE status = 'due'")->fetchColumn();
    $totalPayouts = (int) $db->query("SELECT COUNT(*) FROM payouts")->fetchColumn();

    api_success([
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