<?php

header('Content-Type: application/json; charset=utf-8');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

session_start();

require_once __DIR__ . '/../../src/config.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/helpers.php';
require_once __DIR__ . '/../../src/ChitService.php';
require_once __DIR__ . '/_response.php';

function db()
{
    global $pdo, $conn, $mysqli;

    if (isset($pdo) && $pdo instanceof PDO) {
        return $pdo;
    }

    if (isset($conn) && $conn instanceof PDO) {
        return $conn;
    }

    if (isset($mysqli)) {
        return $mysqli;
    }

    throw new Exception('Database connection not found. Please check src/db.php');
}

function bearer_token()
{
    $headers = function_exists('getallheaders') ? getallheaders() : [];

    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

    if (!$authHeader && isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
    }

    if (!$authHeader && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }

    if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        return trim($matches[1]);
    }

    return null;
}

function current_admin()
{
    $db = db();

    // 1. Browser/session login support
    if (!empty($_SESSION['admin_id'])) {
        $stmt = $db->prepare("
            SELECT id, name, email
            FROM admins
            WHERE id = ?
            AND status = 'active'
            LIMIT 1
        ");
        $stmt->execute([$_SESSION['admin_id']]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($admin) {
            return $admin;
        }
    }

    // 2. React Native/API Bearer token support
    $token = bearer_token();

    if ($token) {
        $stmt = $db->prepare("
            SELECT 
                t.id AS token_id,
                t.admin_id,
                t.token,
                t.expires_at,
                a.id AS admin_id_from_admins,
                a.name,
                a.email,
                a.status
            FROM api_tokens t
            LEFT JOIN admins a ON a.id = t.admin_id
            WHERE t.token = ?
            LIMIT 1
        ");
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            api_error('Token received but not found in api_tokens table.', [
                'received_token' => $token,
            ], 401);
        }

        if (!$row['admin_id_from_admins']) {
            api_error('Token found but admin not found.', [
                'token_row' => $row,
            ], 401);
        }

        if ($row['status'] !== 'active') {
            api_error('Admin found but status is not active.', [
                'token_row' => $row,
            ], 401);
        }

        if (!empty($row['expires_at']) && strtotime($row['expires_at']) <= time()) {
            api_error('Token expired.', [
                'token_row' => $row,
                'server_time' => date('Y-m-d H:i:s'),
            ], 401);
        }

        return [
            'id' => $row['admin_id_from_admins'],
            'name' => $row['name'],
            'email' => $row['email'],
        ];
}

    return null;
}

function require_api_admin()
{
    $admin = current_admin();

    if (!$admin) {
        api_error('Unauthorized. Please login first.', [], 401);
    }

    return $admin;
}

function service()
{
    return new ChitService(db());
}