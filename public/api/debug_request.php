<?php

header('Content-Type: application/json; charset=utf-8');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$headers = function_exists('getallheaders') ? getallheaders() : [];

$raw = file_get_contents('php://input');

echo json_encode([
    'success' => true,
    'message' => 'Debug request received',

    'method' => $_SERVER['REQUEST_METHOD'] ?? null,

    'headers_getallheaders' => $headers,

    'server_authorization_values' => [
        'HTTP_AUTHORIZATION' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
        'REDIRECT_HTTP_AUTHORIZATION' => $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null,
        'Authorization' => $_SERVER['Authorization'] ?? null,
    ],

    'content_type' => $_SERVER['CONTENT_TYPE'] ?? null,

    'get' => $_GET,
    'post' => $_POST,

    'raw_body' => $raw,
    'json_body' => json_decode($raw, true),

], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);