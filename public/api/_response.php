<?php

function api_success($data = [], $message = 'Success', $statusCode = 200)
{
    http_response_code($statusCode);

    echo json_encode([
        'success' => true,
        'message' => $message,
        'data' => $data,
    ]);

    exit;
}

function api_error($message = 'Something went wrong', $errors = [], $statusCode = 400)
{
    http_response_code($statusCode);

    echo json_encode([
        'success' => false,
        'message' => $message,
        'errors' => $errors,
    ]);

    exit;
}

function api_input()
{
    $input = [];

    if (!empty($_GET)) {
        $input = array_merge($input, $_GET);
    }

    if (!empty($_POST)) {
        $input = array_merge($input, $_POST);
    }

    $raw = file_get_contents('php://input');

    if ($raw !== false && trim($raw) !== '') {
        $json = json_decode($raw, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
            $input = array_merge($input, $json);
        }
    }

    return $input;
}