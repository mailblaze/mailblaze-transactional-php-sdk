<?php

declare(strict_types=1);

// Router for PHP's built-in web server, used by HttpClientTest. It never contacts the real API.

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = is_string($uri) ? (string) parse_url($uri, PHP_URL_PATH) : '/';
$method = is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET';

if (str_starts_with($path, '/slow/')) {
    sleep(3);
}

header('Content-Type: application/json');

if ($method === 'POST' && str_ends_with($path, '/transactional')) {
    $body = json_decode((string) file_get_contents('php://input'), true);
    $ok = is_array($body)
        && ($_SERVER['CONTENT_TYPE'] ?? '') === 'application/json'
        && ($_SERVER['HTTP_AUTHORIZATION'] ?? '') === 'mb-local-test-key'
        && isset($body['to_email'], $body['body']);
    http_response_code($ok ? 201 : 422);
    echo json_encode($ok
        ? ['status' => 'success', 'code' => '000.000', 'email_uid' => 'em_local', 'warning' => null]
        : ['status' => 'error', 'code' => '150.007', 'error' => 'The transactional email was not sent']);

    return true;
}

if ($method === 'GET' && str_ends_with($path, '/transactional')) {
    echo json_encode([
        'status' => 'success',
        'code' => '000.000',
        'data' => [
            'count' => 1,
            'total_pages' => 1,
            'current_page' => 1,
            'next_page' => null,
            'prev_page' => null,
            'records' => [[
                'email_uid' => 'em_local',
                'to_email' => 'jane@example.com',
                'subject' => 'Local',
                'status' => 'sent',
            ]],
        ],
    ]);

    return true;
}

http_response_code(404);
echo json_encode(['status' => 'error', 'code' => '150.009', 'error' => 'Not found']);

return true;
