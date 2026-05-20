<?php

require_once __DIR__ . '/_bootstrap.php';

try {
    require_api_admin();

    $db = db();

    $stmt = $db->query("
        SELECT 
            id,
            full_name,
            phone,
            email,
            member_code,
            created_at
        FROM members
        ORDER BY id DESC
    ");

    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

    api_success([
        'members' => $members,
    ]);

} catch (Throwable $e) {
    api_error($e->getMessage(), [], 500);
}