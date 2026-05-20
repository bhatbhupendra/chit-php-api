<?php

require_once __DIR__ . '/_bootstrap.php';

try {
    $input = api_input();

    $email = trim($input['email'] ?? '');
    $password = trim($input['password'] ?? '');

    if ($email === '' || $password === '') {
        api_error('Email and password are required.', [
            'debug_input' => $input
        ], 401);
    }

    $db = db();

    $stmt = $db->prepare("
        SELECT *
        FROM admins
        WHERE email = ?
        AND status = 'active'
        LIMIT 1
    ");

    $stmt->execute([$email]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$admin) {
        api_error('Admin email not found.', [
            'email_received' => $email
        ], 401);
    }

    if (!password_verify($password, $admin['password'])) {
        api_error('Password does not match.', [
            'email_received' => $email,
            'password_received' => $password,
            'db_hash' => $admin['password']
        ], 401);
    }

    $_SESSION['admin_id'] = $admin['id'];

    $token = bin2hex(random_bytes(32));

    $stmt = $db->prepare("
        INSERT INTO api_tokens
        (admin_id, token, expires_at)
        VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))
    ");

    $stmt->execute([
        $admin['id'],
        $token,
    ]);

    api_success([
        'token' => $token,
        'user' => [
            'id' => (int) $admin['id'],
            'name' => $admin['name'],
            'email' => $admin['email'],
        ],
    ], 'Login successful');

} catch (Throwable $e) {
    api_error($e->getMessage(), [], 500);
}