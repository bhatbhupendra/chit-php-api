<?php

require_once __DIR__ . '/_bootstrap.php';

try {
    require_api_admin();

    $input = api_input();

    $contributionId = (int) ($input['contribution_id'] ?? 0);
    $status = trim($input['status'] ?? '');

    if ($contributionId <= 0) {
        api_error('Contribution ID is required.');
    }

    if (!in_array($status, ['paid', 'due'], true)) {
        api_error('Invalid payment status.');
    }

    $db = db();

    if ($status === 'paid') {
        $stmt = $db->prepare("
            UPDATE contributions
            SET status = 'paid', paid_at = NOW(), updated_at = NOW()
            WHERE id = ?
        ");
    } else {
        $stmt = $db->prepare("
            UPDATE contributions
            SET status = 'due', paid_at = NULL, updated_at = NOW()
            WHERE id = ?
        ");
    }

    $stmt->execute([$contributionId]);

    api_success([], 'Payment updated successfully');

} catch (Throwable $e) {
    api_error($e->getMessage(), [], 500);
}