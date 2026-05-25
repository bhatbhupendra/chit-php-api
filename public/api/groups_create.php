<?php

require_once __DIR__ . '/_bootstrap.php';

try {

    $input = api_input();
    $admin = require_api_admin();
    $adminId = is_array($admin) ? (int) $admin['id'] : (int) $admin;

    $groupName = trim($input['group_name'] ?? '');
    $currency = trim($input['currency'] ?? 'NPR');
    $memberCount = (int) ($input['member_count'] ?? 0);
    $durationMonths = (int) ($input['duration_months'] ?? 0);
    $monthlyAmount = (float) ($input['monthly_amount'] ?? 0);
    $bidStepPercent = (float) ($input['bid_step_percent'] ?? 0);
    $startDate = trim($input['start_date'] ?? date('Y-m-d'));

    if ($groupName === '') {
        api_error('Group name is required.');
    }

    if ($memberCount <= 0) {
        api_error('Member count must be greater than 0.');
    }

    if ($durationMonths <= 0) {
        api_error('Duration months must be greater than 0.');
    }

    if ($monthlyAmount <= 0) {
        api_error('Chitta fund amount must be greater than 0.');
    }

    $db = db();

    $stmt = $db->prepare("
        INSERT INTO chit_groups 
        (
            group_name, 
            currency, 
            member_count, 
            duration_months, 
            monthly_amount, 
            bid_step_percent, 
            start_date, 
            status, 
            created_by,
            created_at, 
            updated_at
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?, NOW(), NOW())
    ");

    $stmt->execute([
        $groupName,
        $currency,
        $memberCount,
        $durationMonths,
        $monthlyAmount,
        $bidStepPercent,
        $startDate,
        $adminId,
    ]);

    api_success([
        'group_id' => (int) $db->lastInsertId(),
    ], 'Group created successfully');

} catch (Throwable $e) {
    api_error($e->getMessage(), [], 500);
}