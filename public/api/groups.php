<?php

require_once __DIR__ . '/_bootstrap.php';

try {
    require_api_admin();

    $db = db();

    $stmt = $db->query("
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
        ORDER BY id DESC
    ");

    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

    api_success([
        'groups' => $groups,
    ]);

} catch (Throwable $e) {
    api_error($e->getMessage(), [], 500);
}