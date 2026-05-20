<?php

require_once __DIR__ . '/_bootstrap.php';

try {
    $adminId = require_api_admin();

    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        api_error('Invalid request body', [], 400);
    }

    $groupId = intval($input['group_id'] ?? 0);
    $fullName = trim($input['full_name'] ?? '');
    $phone = trim($input['phone'] ?? '');
    $email = trim($input['email'] ?? '');
    $address = trim($input['address'] ?? '');
    $memberCode = trim($input['member_code'] ?? '');

    if ($groupId <= 0) {
        api_error('Group ID is required', [], 422);
    }

    if ($fullName === '') {
        api_error('Member name is required', [], 422);
    }

    $db = db();

    // Check group
    $stmt = $db->prepare("SELECT id, member_count FROM chit_groups WHERE id = ?");
    $stmt->execute([$groupId]);
    $group = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$group) {
        api_error('Group not found', [], 404);
    }

    // Count members already added to this group
    $stmt = $db->prepare("SELECT COUNT(*) FROM group_members WHERE group_id = ?");
    $stmt->execute([$groupId]);
    $currentMembers = intval($stmt->fetchColumn());

    if ($currentMembers >= intval($group['member_count'])) {
        api_error('This group already has maximum members', [], 422);
    }

    // Auto generate member code if empty
    if ($memberCode === '') {
        $prefix = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $fullName), 0, 3));
        if ($prefix === '') {
            $prefix = 'MEM';
        }

        $memberCode = $prefix . str_pad($currentMembers + 1, 7, '0', STR_PAD_LEFT);
    }

    $db->beginTransaction();

    // Insert into members table
    $stmt = $db->prepare("
        INSERT INTO members
            (full_name, phone, email, address, member_code, status, created_at, updated_at)
        VALUES
            (?, ?, ?, ?, ?, 'active', NOW(), NOW())
    ");

    $stmt->execute([
        $fullName,
        $phone,
        $email,
        $address,
        $memberCode
    ]);

    $memberId = $db->lastInsertId();

    // Connect member with group
    $stmt = $db->prepare("
        INSERT INTO group_members
            (group_id, member_id, created_at, updated_at)
        VALUES
            (?, ?, NOW(), NOW())
    ");

    $stmt->execute([
        $groupId,
        $memberId
    ]);

    $db->commit();

    api_success([
        'member_id' => $memberId,
        'member_code' => $memberCode
    ], 'Member added successfully');

} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }

    api_error($e->getMessage(), [], 500);
}