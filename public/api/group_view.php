<?php

require_once __DIR__ . '/_bootstrap.php';

try {
    require_api_admin();

    $groupId = (int) ($_GET['id'] ?? 0);

    if ($groupId <= 0) {
        api_error('Group ID is required.');
    }

    $db = db();

    $stmt = $db->prepare("SELECT * FROM chit_groups WHERE id = ? LIMIT 1");
    $stmt->execute([$groupId]);
    $group = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$group) {
        api_error('Group not found.', [], 404);
    }

    $stmt = $db->prepare("
        SELECT 
            gm.id AS group_member_id,
            m.id,
            m.full_name,
            m.phone,
            m.email,
            m.member_code,
            gm.status
        FROM group_members gm
        INNER JOIN members m ON m.id = gm.member_id
        WHERE gm.group_id = ?
        ORDER BY gm.id ASC
    ");
    $stmt->execute([$groupId]);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->prepare("
        SELECT *
        FROM rounds
        WHERE group_id = ?
        ORDER BY round_no ASC
    ");
    $stmt->execute([$groupId]);
    $rounds = $stmt->fetchAll(PDO::FETCH_ASSOC);

    api_success([
        'group' => $group,
        'members' => $members,
        'rounds' => $rounds,
    ]);

} catch (Throwable $e) {
    api_error($e->getMessage(), [], 500);
}