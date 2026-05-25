<?php

require_once __DIR__ . '/_bootstrap.php';

try {
    $admin = require_api_admin();
    $adminId = is_array($admin) ? (int) $admin['id'] : (int) $admin;

    $db = db();

    $stmt = $db->prepare("
        SELECT 
            id,
            group_name,
            currency,
            monthly_amount,
            member_count,
            duration_months,
            bid_step_percent,
            start_date,
            status
        FROM chit_groups
        WHERE created_by = ?
        ORDER BY id DESC
    ");

    $stmt->execute([$adminId]);

    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

    api_success([
        'groups' => $groups,
    ]);

} catch (Throwable $e) {
    api_error($e->getMessage(), [], 500);
}