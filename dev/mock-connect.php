<?php
// Stand-in for MomoBird's connect API (php -S router script) for local and
// integration checks. Keeps posts as sent (category included) in
// MOCK_STORE and logs every request to MOCK_LOG. Not shipped in the plugin zip.
//
//   php -S 127.0.0.1:8099 dev/mock-connect.php
//
// Environment (all optional):
//   MOCK_KEY          accepted connection key   (default: the local test key)
//   MOCK_SITE_URL     forum address; pushed urls must start with it (default http://localhost:8088)
//   MOCK_SOURCE_KIND  source kind reported by /status (default discuz)
//   MOCK_STORE        JSON state file           (default <tmp>/momobird-discuz-mock.json)
//   MOCK_LOG          request log, one JSON per line (default <tmp>/momobird-discuz-mock.log)
//
// While the file MOCK_STORE.delay exists, every request waits that many
// milliseconds first (a slow MomoBird, for the integration tests).

const PREFIX = '/momobird/api/public/connect/v1';

function mock_env($name, $default)
{
    $value = getenv($name);
    return $value === false || $value === '' ? $default : $value;
}

$key = mock_env('MOCK_KEY', 'mbk_DISCUZLOCALTESTKEYDISCUZLOCALTESTKEYDISC');
$site = rtrim(mock_env('MOCK_SITE_URL', 'http://localhost:8088'), '/');
$kind = mock_env('MOCK_SOURCE_KIND', 'discuz');
$store = mock_env('MOCK_STORE', sys_get_temp_dir() . '/momobird-discuz-mock.json');
$log = mock_env('MOCK_LOG', sys_get_temp_dir() . '/momobird-discuz-mock.log');

function out($status, $body)
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ok($data)
{
    out(200, array('code' => 'OK', 'data' => $data));
}

$delay = $store . '.delay';
if (is_file($delay)) {
    usleep(max(0, min(60000, (int) trim((string) file_get_contents($delay)))) * 1000);
}

$state = is_file($store) ? json_decode(file_get_contents($store), true) : null;
if (!is_array($state)) {
    $state = array('posts' => array(), 'widget' => array('public_id' => 'pub_local', 'available' => false, 'reason' => 'not_published'));
}
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
file_put_contents($log, json_encode(array('method' => $method, 'path' => $path, 'query' => $_GET, 'body' => $body), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);

if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer ' . $key) {
    out(401, array('code' => 'UNAUTHORIZED', 'message' => 'bad key'));
}

$save = function () use (&$state, $store) {
    file_put_contents($store, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
};

switch ($method . ' ' . substr($path, strlen(PREFIX))) {
    case 'GET /status':
        ok(array(
            'project' => array('id' => 'p-local', 'name' => '本地测试项目'),
            'source' => array('id' => '01a16017-e335-75b8-ae8c-bf4dbaba5f1d', 'kind' => $kind, 'name' => 'Local Discuz!', 'site_url' => $site,
                'document_count' => count($state['posts']), 'ready_document_count' => count($state['posts'])),
            'entitlement' => array('status' => 'trial', 'active' => true, 'trial_ends_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 6 * 86400), 'pro_ends_at' => null),
            'widget' => $state['widget'],
        ));
    case 'PUT /posts':
        if (!is_array($body) || !isset($body['posts']) || !is_array($body['posts'])) {
            out(400, array('code' => 'VALIDATION_FAILED', 'message' => 'posts required'));
        }
        $results = array();
        foreach ($body['posts'] as $post) {
            $id = isset($post['external_id']) ? (string) $post['external_id'] : '';
            $modified = $post['modified_at'] ?? null;
            $validTime = is_string($modified) && preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})Z$/', $modified, $t)
                && checkdate((int) $t[2], (int) $t[3], (int) $t[1]) && $t[4] < 24 && $t[5] < 60 && $t[6] < 60;
            $validCategory = !isset($post['category']) || (is_string($post['category']) && mb_strlen($post['category'], 'UTF-8') <= 64);
            // Same rules as the backend: modified_at (UTC, Y-m-d\TH:i:s\Z) is required, category is optional (max 64 chars).
            if ($id === '' || !$validTime || !$validCategory || !isset($post['url']) || strpos($post['url'], $site . '/') !== 0) {
                $results[] = array('external_id' => $id, 'document_id' => null, 'status' => 'rejected', 'error_code' => 'VALIDATION_FAILED');
                continue;
            }
            // The whole post is stored as sent, so `category` (and anything else) is kept verbatim.
            $status = !isset($state['posts'][$id]) ? 'created' : ($state['posts'][$id] == $post ? 'unchanged' : 'updated');
            $state['posts'][$id] = $post;
            $results[] = array('external_id' => $id, 'document_id' => 'doc-' . $id, 'status' => $status, 'error_code' => null);
        }
        $save();
        ok(array('results' => $results));
    case 'POST /posts/delete':
        if (!is_array($body) || !isset($body['external_ids']) || !is_array($body['external_ids'])) {
            out(400, array('code' => 'VALIDATION_FAILED', 'message' => 'external_ids required'));
        }
        $results = array();
        foreach ($body['external_ids'] as $id) {
            $id = (string) $id;
            $results[] = array('external_id' => $id, 'status' => isset($state['posts'][$id]) ? 'deleted' : 'not_found');
            unset($state['posts'][$id]);
        }
        $save();
        ok(array('results' => $results));
    case 'GET /posts':
        // Opaque cursor = offset into the ids in numeric order.
        $ids = array_map('strval', array_keys($state['posts']));
        sort($ids, SORT_NATURAL);
        $limit = max(1, min(500, (int) ($_GET['limit'] ?? 500)));
        $offset = isset($_GET['cursor']) && preg_match('/^c(\d+)$/', $_GET['cursor'], $m) ? (int) $m[1] : 0;
        $items = array();
        foreach (array_slice($ids, $offset, $limit) as $id) {
            $items[] = array('external_id' => $id, 'document_id' => 'doc-' . $id, 'updated_at' => $state['posts'][$id]['modified_at'] ?? null);
        }
        $next = $offset + $limit < count($ids) ? 'c' . ($offset + $limit) : null;
        ok(array('items' => $items, 'next_cursor' => $next));
    case 'POST /widget/enable':
        $state['widget'] = array('public_id' => 'pub_local', 'available' => true, 'reason' => 'ok');
        $save();
        ok($state['widget']);
}
out(404, array('code' => 'NOT_FOUND', 'message' => $path));
