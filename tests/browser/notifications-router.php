<?php

// Isolated UI fixture; no application database or real mail transport.
if (PHP_SAPI !== 'cli-server' || ! in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit;
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (! str_starts_with($path, '/api/notifications')) {
    require __DIR__.'/dashboard-router.php';
    exit;
}
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');
$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET' && ! hash_equals($_SESSION['fixture_csrf'] ?? 'missing', $_SERVER['HTTP_X_XSRF_TOKEN'] ?? '')) {
    http_response_code(419);
    echo json_encode(['message' => 'TEST CSRF']);
    exit;
}
$items = &$_SESSION['notification_fixture'];
if (! is_array($items)) {
    $items = [
        ['id' => 'test-task', 'kind' => 'task', 'target_id' => '55555555-5555-4555-8555-555555555550', 'title' => 'Productteksten controleren', 'actor_name' => 'Testcollega', 'deadline' => '2026-10-02', 'created_at' => '2026-09-24T09:00:00Z', 'read_at' => null],
        ['id' => 'test-project', 'kind' => 'project', 'target_id' => '33333333-3333-4333-8333-333333333333', 'title' => 'Najaarcampagne', 'actor_name' => 'Testcollega', 'deadline' => null, 'created_at' => '2026-09-24T08:00:00Z', 'read_at' => null],
        ['id' => 'test-read', 'kind' => 'task', 'target_id' => '55555555-5555-4555-8555-555555555551', 'title' => 'Nieuwsbrief klaarzetten', 'actor_name' => 'Testcollega', 'deadline' => null, 'created_at' => '2026-09-23T08:00:00Z', 'read_at' => '2026-09-23T09:00:00Z'],
    ];
}
if ($path === '/api/notifications/preferences') {
    if ($method === 'PUT') $_SESSION['notification_preferences'] = json_decode(file_get_contents('php://input'), true);
    echo json_encode(($_SESSION['notification_preferences'] ?? ['task_email' => true, 'project_email' => true]) + ['email_active' => false]);
    exit;
}
if ($method === 'POST') {
    foreach ($items as &$item) {
        if ($path === '/api/notifications/read-all' || $path === '/api/notifications/'.$item['id'].'/read') $item['read_at'] = date('c');
    }
    unset($item);
    echo json_encode(['ok' => true]);
    exit;
}
if ($path === '/api/notifications') {
    $unread = array_filter($items, fn ($item) => ! $item['read_at']);
    echo json_encode(['items' => array_values(($_GET['unread'] ?? false) ? $unread : $items), 'unread_count' => count($unread), 'has_more' => false]);
    exit;
}
foreach ($items as $item) {
    if ($path === '/api/notifications/'.$item['id']) { echo json_encode($item); exit; }
}
http_response_code(404);
echo json_encode(['message' => 'TEST niet gevonden']);
