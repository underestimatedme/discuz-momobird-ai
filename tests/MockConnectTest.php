<?php

require_once __DIR__ . '/StreamTransport.php';

/** Start dev/mock-connect.php on a free port; returns [process, baseUrl, storePath, logPath]. */
function mb_mock_start(array $env = array()): array
{
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
    fclose($probe);

    $dir = sys_get_temp_dir() . '/momobird-discuz-mock-' . getmypid() . '-' . $port;
    @mkdir($dir);
    $store = $dir . '/store.json';
    $log = $dir . '/requests.log';
    $environment = $env + array('MOCK_STORE' => $store, 'MOCK_LOG' => $log, 'MOCK_SITE_URL' => 'https://bbs.example.com', 'PATH' => getenv('PATH'));
    $process = proc_open(
        array(PHP_BINARY, '-S', '127.0.0.1:' . $port, dirname(__DIR__) . '/dev/mock-connect.php'),
        array(0 => array('file', '/dev/null', 'r'), 1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')),
        $pipes,
        null,
        $environment
    );
    mb_assert(is_resource($process), 'could not start php -S');
    for ($i = 0; $i < 100; $i++) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($socket) {
            fclose($socket);
            return array($process, 'http://127.0.0.1:' . $port, $environment['MOCK_STORE'], $environment['MOCK_LOG']);
        }
        usleep(50000);
    }
    proc_terminate($process);
    throw new RuntimeException('mock server did not start');
}

function mb_mock_stop($process, string $store, string $log): void
{
    proc_terminate($process);
    proc_close($process);
    @unlink($store);
    @unlink($log);
    @rmdir(dirname($store));
}

function mb_mock_run(callable $test, array $env = array(), string $key = MB_TEST_KEY): void
{
    list($process, $base, $store, $log) = mb_mock_start($env);
    try {
        $client = new MomoBird_ConnectClient(mb_settings(array('baseUrl' => $base, 'key' => $key)), new MomoBird_StreamTransport());
        $test($client, $store, $log, $base);
    } finally {
        mb_mock_stop($process, $store, $log);
    }
}

function mb_mock_post($id, $category = '技术 / 综合讨论'): array
{
    return array(
        'external_id' => (string) $id,
        'title' => '主题 ' . $id,
        'url' => 'https://bbs.example.com/forum.php?mod=viewthread&tid=' . $id,
        'content' => '正文 ' . $id,
        'category' => $category,
        'modified_at' => '2026-10-29T08:00:00Z',
    );
}

mb_test('mock connect keeps each post category and serves the client end to end', function () {
    mb_mock_run(function (MomoBird_ConnectClient $client, string $store) {
        $status = $client->status();
        mb_assert_same('discuz', $status['source']['kind'], 'source kind');
        mb_assert_same('https://bbs.example.com', $status['source']['site_url'], 'site url');

        $results = $client->upsert(array(mb_mock_post(11, '技术 / 综合讨论'), mb_mock_post(12, '灌水')));
        mb_assert_same(array('created', 'created'), array_column($results, 'status'), 'created');
        $again = $client->upsert(array(mb_mock_post(11, '技术 / 综合讨论'), mb_mock_post(12, '改名后的版块')));
        mb_assert_same(array('unchanged', 'updated'), array_column($again, 'status'), 'unchanged then updated');

        $saved = json_decode(file_get_contents($store), true);
        mb_assert_same('技术 / 综合讨论', $saved['posts']['11']['category'], 'category 11 stored verbatim');
        mb_assert_same('改名后的版块', $saved['posts']['12']['category'], 'category 12 follows the update');

        $outside = mb_mock_post(13);
        $outside['url'] = 'https://elsewhere.example.com/thread-13.html';
        $rejected = $client->upsert(array($outside));
        mb_assert_same('rejected', $rejected[0]['status'], 'url outside the forum is rejected');

        $list = $client->listPosts(null);
        mb_assert_same(array('11', '12'), array_column($list['items'], 'external_id'), 'list');
        mb_assert_same(null, $list['next_cursor'], 'single page');

        $deleted = $client->delete(array(11, 99));
        mb_assert_same(array('deleted', 'not_found'), array_column($deleted, 'status'), 'delete');

        $widget = $client->enableWidget();
        mb_assert_same(true, $widget['available'], 'widget enabled');
        mb_assert_same(true, $client->status()['widget']['available'], 'widget state persists');
    });
});

mb_test('mock connect rejects posts the backend would reject', function () {
    mb_mock_run(function (MomoBird_ConnectClient $client, string $store) {
        $noTime = mb_mock_post(1);
        unset($noTime['modified_at']);
        $oldName = mb_mock_post(2);
        unset($oldName['modified_at']);
        $oldName['updated_at'] = '2026-10-29T08:00:00Z';
        $badTime = array();
        foreach (array('2026-10-29 08:00:00', '2026-10-29T08:00:00+08:00', '2026-13-40T08:00:00Z', '2026-10-29T25:00:00Z', '') as $i => $value) {
            $post = mb_mock_post(10 + $i);
            $post['modified_at'] = $value;
            $badTime[] = $post;
        }
        $longCategory = mb_mock_post(20, str_repeat('类', 65));
        $maxCategory = mb_mock_post(21, str_repeat('类', 64));
        $noCategory = mb_mock_post(22);
        unset($noCategory['category']);

        $results = $client->upsert(array_merge(array($noTime, $oldName), $badTime, array($longCategory, $maxCategory, $noCategory)));
        $statuses = array_column($results, 'status');
        mb_assert_same(array_fill(0, 7, 'rejected'), array_slice($statuses, 0, 7), 'missing or invalid modified_at is rejected');
        mb_assert_same('rejected', $statuses[7], 'a 65 character category is rejected');
        mb_assert_same('VALIDATION_FAILED', $results[7]['error_code'], 'error code');
        mb_assert_same(array('created', 'created'), array_slice($statuses, 8), '64 characters and no category are fine');
        $saved = json_decode(file_get_contents($store), true);
        mb_assert_same(array('21', '22'), array_map('strval', array_keys($saved['posts'])), 'only valid posts are stored');
    });
});

mb_test('mock connect paginates the post list', function () {
    mb_mock_run(function (MomoBird_ConnectClient $client, string $store, string $log, string $base) {
        $posts = array();
        for ($i = 1; $i <= 5; $i++) {
            $posts[] = mb_mock_post($i);
        }
        $client->upsert($posts);

        // The client always asks for 500; ask the endpoint for pages of 2 directly.
        $transport = new MomoBird_StreamTransport();
        $headers = array('Authorization: Bearer ' . MB_TEST_KEY);
        $url = $base . '/momobird/api/public/connect/v1/posts?limit=2';
        $seen = array();
        $pages = 0;
        do {
            $response = $transport->send('GET', $url, $headers, null, 5);
            $data = json_decode($response['body'], true)['data'];
            foreach ($data['items'] as $item) {
                $seen[] = $item['external_id'];
            }
            $pages++;
            $cursor = $data['next_cursor'];
            $url = $base . '/momobird/api/public/connect/v1/posts?limit=2&cursor=' . rawurlencode((string) $cursor);
        } while ($cursor !== null && $pages < 10);
        mb_assert_same(3, $pages, 'five posts in pages of two');
        mb_assert_same(array('1', '2', '3', '4', '5'), $seen, 'every post exactly once');
    });
});

mb_test('mock connect rejects a wrong key and can report another source kind', function () {
    mb_mock_run(function (MomoBird_ConnectClient $client) {
        $error = mb_assert_throws(function () use ($client) {
            $client->status();
        }, 'MomoBird_ConnectError');
        mb_assert_same('UNAUTHORIZED', $error->kind(), 'wrong key');
    }, array(), 'mbk_WRONGKEYWRONGKEYWRONGKEYWRONGKEYWRONGKEY');

    mb_mock_run(function (MomoBird_ConnectClient $client) {
        mb_assert_same('wordpress', $client->status()['source']['kind'], 'MOCK_SOURCE_KIND');
    }, array('MOCK_SOURCE_KIND' => 'wordpress'));
});

mb_test('mock connect answers late while its delay file holds milliseconds', function () {
    mb_mock_run(function (MomoBird_ConnectClient $client, string $store) {
        $start = microtime(true);
        $client->status();
        mb_assert(microtime(true) - $start < 0.5, 'no delay without the file');

        file_put_contents($store . '.delay', '700');
        $start = microtime(true);
        $client->status();
        mb_assert(microtime(true) - $start >= 0.7, 'the delay file slows every request');
        unlink($store . '.delay');
    });
});
