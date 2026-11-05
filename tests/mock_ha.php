<?php
/*
 * Minimal Home Assistant REST API mock (router script for `php -S`).
 * State lives in $MOCK_DIR/states.json; every request is logged to
 * $MOCK_DIR/requests.log as "METHOD PATH" and POST bodies to posts.log.
 */
$dir = getenv('MOCK_DIR') ?: __DIR__ . '/tmp';
$statesFile = "$dir/states.json";
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
file_put_contents("$dir/requests.log", "$method $path\n", FILE_APPEND);

if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer test-token') {
    http_response_code(401);
    exit;
}

$states = json_decode(@file_get_contents($statesFile), true) ?: [];
header('Content-Type: application/json');

if ($path === '/api/states' && $method === 'GET') {
    echo json_encode(array_values($states));
    exit;
}
if (preg_match('#^/api/states/([a-z0-9_]+\.[a-z0-9_]+)$#', $path, $m)) {
    $entity_id = $m[1];
    if ($method === 'GET') {
        if (!isset($states[$entity_id])) {
            http_response_code(404);
            echo '{"message":"Entity not found."}';
            exit;
        }
        echo json_encode($states[$entity_id]);
        exit;
    }
    if ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true);
        file_put_contents("$dir/posts.log", $entity_id . ' ' . json_encode($body) . "\n", FILE_APPEND);
        $states[$entity_id] = [
            'entity_id' => $entity_id,
            'state' => (string)$body['state'],
            'attributes' => $body['attributes'] ?? new stdClass(),
            'last_changed' => '2026-11-05T00:00:00+00:00',
        ];
        file_put_contents($statesFile, json_encode($states));
        http_response_code(200);
        echo json_encode($states[$entity_id]);
        exit;
    }
}
http_response_code(404);
