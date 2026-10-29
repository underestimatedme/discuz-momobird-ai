<?php

require_once __DIR__ . '/FakeTransport.php';

function mb_cc_ok(array $data): array
{
    return array('status' => 200, 'body' => json_encode(array('code' => 'OK', 'data' => $data)));
}

function mb_cc_client(MomoBird_FakeTransport $transport, array $settings = array()): MomoBird_ConnectClient
{
    return new MomoBird_ConnectClient(mb_settings($settings), $transport);
}

function mb_cc_post($id, $content = 'body', $category = '默认分类'): array
{
    return array(
        'external_id' => (string) $id,
        'title' => 'Thread ' . $id,
        'url' => 'https://bbs.example.com/forum.php?mod=viewthread&tid=' . $id,
        'content' => $content,
        'category' => $category,
        'modified_at' => '2026-10-29T08:00:00Z',
    );
}

function mb_cc_created(array $ids): array
{
    $results = array();
    foreach ($ids as $id) {
        $results[] = array('external_id' => (string) $id, 'document_id' => 'd' . $id, 'status' => 'created', 'error_code' => null);
    }
    return mb_cc_ok(array('results' => $results));
}

/** Responder for upsert/delete that answers every posted id. */
function mb_cc_echo_results(): callable
{
    return function ($method, $url, $headers, $body) {
        $decoded = json_decode($body, true);
        $ids = isset($decoded['posts']) ? array_column($decoded['posts'], 'external_id') : $decoded['external_ids'];
        $results = array();
        foreach ($ids as $id) {
            $results[] = isset($decoded['posts'])
                ? array('external_id' => $id, 'document_id' => 'd' . $id, 'status' => 'created', 'error_code' => null)
                : array('external_id' => $id, 'status' => 'deleted');
        }
        return mb_cc_ok(array('results' => $results));
    };
}

function mb_cc_error(MomoBird_FakeTransport $transport, callable $call): MomoBird_ConnectError
{
    $client = mb_cc_client($transport);
    return mb_assert_throws(function () use ($client, $call) {
        $call($client);
    }, 'MomoBird_ConnectError');
}

function mb_cc_sizes(MomoBird_FakeTransport $transport, string $field = 'posts'): array
{
    $sizes = array();
    foreach (array_keys($transport->requests) as $i) {
        $sizes[] = count($transport->json($i)[$field]);
    }
    return $sizes;
}

mb_test('status sends the bearer key to the connect endpoint', function () {
    $transport = (new MomoBird_FakeTransport())->push(mb_cc_ok(array('project' => array('id' => 'p1'))));
    $data = mb_cc_client($transport, array('timeout' => 7))->status();

    $request = $transport->requests[0];
    mb_assert_same('GET', $request['method'], 'method');
    mb_assert_same('https://api.valley.atlaspaces.com/momobird/api/public/connect/v1/status', $request['url'], 'url');
    mb_assert(in_array('Authorization: Bearer ' . MB_TEST_KEY, $request['headers'], true), 'missing bearer header');
    mb_assert(in_array('Accept: application/json', $request['headers'], true), 'missing accept header');
    mb_assert_same(null, $request['body'], 'GET has no body');
    mb_assert_same(7, $request['timeout'], 'timeout comes from the settings');
    mb_assert_same('p1', $data['project']['id'], 'data envelope not unwrapped');
});

mb_test('upsert sends posts as given, category included, and returns the results', function () {
    $transport = (new MomoBird_FakeTransport())->push(mb_cc_created(array(1, 2)));
    $results = mb_cc_client($transport)->upsert(array(mb_cc_post(1, '你好 / 世界', '分区 / 版块'), mb_cc_post(2)));

    $request = $transport->requests[0];
    mb_assert_same('PUT', $request['method'], 'method');
    mb_assert_same('https://api.valley.atlaspaces.com/momobird/api/public/connect/v1/posts', $request['url'], 'url');
    mb_assert(in_array('Content-Type: application/json', $request['headers'], true), 'content type');
    mb_assert(strpos($request['body'], '你好 / 世界') !== false, 'unicode and slashes must not be escaped');
    $sent = $transport->json(0);
    mb_assert_same(array('posts'), array_keys($sent), 'body is wrapped in posts');
    mb_assert_same('分区 / 版块', $sent['posts'][0]['category'], 'category lost');
    mb_assert_same(array('1', '2'), array_column($results, 'external_id'), 'results');
    mb_assert_same('created', $results[0]['status'], 'result status');
});

mb_test('upsert splits more than 20 posts into batches and keeps result order', function () {
    $transport = new MomoBird_FakeTransport(mb_cc_echo_results());
    $posts = array();
    for ($i = 1; $i <= 45; $i++) {
        $posts[] = mb_cc_post($i);
    }
    $results = mb_cc_client($transport)->upsert($posts);

    mb_assert_same(array(20, 20, 5), mb_cc_sizes($transport), 'batch sizes');
    mb_assert_same(45, count($results), 'one result per post');
    mb_assert_same(array_map('strval', range(1, 45)), array_column($results, 'external_id'), 'result order');
});

mb_test('upsert splits batches that would exceed 4 MiB', function () {
    $transport = new MomoBird_FakeTransport(mb_cc_echo_results());
    $big = str_repeat('a', 1536 * 1024); // 1.5 MiB: two fit in 4 MiB, three do not
    $posts = array();
    for ($i = 1; $i <= 5; $i++) {
        $posts[] = mb_cc_post($i, $big);
    }
    $results = mb_cc_client($transport)->upsert($posts);

    mb_assert_same(array(2, 2, 1), mb_cc_sizes($transport), 'expected 2+2+1 posts');
    foreach ($transport->requests as $request) {
        mb_assert(strlen($request['body']) <= 4 * 1024 * 1024, 'request body over 4 MiB: ' . strlen($request['body']));
    }
    mb_assert_same(5, count($results), 'all posts answered');
});

mb_test('a batch may be exactly 4 MiB but not one byte more', function () {
    $overhead = strlen(json_encode(array('posts' => array(mb_cc_post(1, ''))), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $transport = new MomoBird_FakeTransport(mb_cc_echo_results());
    mb_cc_client($transport)->upsert(array(mb_cc_post(1, str_repeat('b', 4 * 1024 * 1024 - $overhead))));
    mb_assert_same(1, count($transport->requests), 'a body of exactly 4 MiB is sent');
    mb_assert_same(4 * 1024 * 1024, strlen($transport->requests[0]['body']), 'body size');

    $transport = new MomoBird_FakeTransport(mb_cc_echo_results());
    $results = mb_cc_client($transport)->upsert(array(mb_cc_post(1, str_repeat('b', 4 * 1024 * 1024 - $overhead + 1))));
    mb_assert_same(0, count($transport->requests), 'one byte over is never sent');
    mb_assert_same('rejected', $results[0]['status'], 'oversized post is reported as rejected');
    mb_assert_same('TOO_LARGE', $results[0]['error_code'], 'oversized error code');
});

mb_test('an oversized post does not stop the others and order is kept', function () {
    $transport = new MomoBird_FakeTransport(mb_cc_echo_results());
    $results = mb_cc_client($transport)->upsert(array(mb_cc_post(1), mb_cc_post(2, str_repeat('c', 5 * 1024 * 1024)), mb_cc_post(3)));
    mb_assert_same(array('1', '2', '3'), array_column($results, 'external_id'), 'order');
    mb_assert_same(array('created', 'rejected', 'created'), array_column($results, 'status'), 'statuses');
    foreach ($transport->requests as $request) {
        mb_assert(strpos($request['body'], 'cccc') === false, 'oversized content was sent');
    }
});

mb_test('upsert and delete of nothing make no request', function () {
    $transport = new MomoBird_FakeTransport();
    $client = mb_cc_client($transport);
    mb_assert_same(array(), $client->upsert(array()), 'empty upsert');
    mb_assert_same(array(), $client->delete(array()), 'empty delete');
    mb_assert_same(0, count($transport->requests), 'no requests expected');
});

mb_test('delete sends string ids in batches of 100', function () {
    $transport = new MomoBird_FakeTransport(mb_cc_echo_results());
    $results = mb_cc_client($transport)->delete(range(1, 230));

    mb_assert_same('POST', $transport->requests[0]['method'], 'method');
    mb_assert_same('https://api.valley.atlaspaces.com/momobird/api/public/connect/v1/posts/delete', $transport->requests[0]['url'], 'url');
    mb_assert_same(array(100, 100, 30), mb_cc_sizes($transport, 'external_ids'), 'sizes');
    mb_assert_same('1', $transport->json(0)['external_ids'][0], 'ids are strings');
    mb_assert_same(230, count($results), 'results merged');
    mb_assert_same('deleted', $results[0]['status'], 'status');
});

mb_test('listPosts passes cursor and limit and returns the next cursor', function () {
    $transport = (new MomoBird_FakeTransport())
        ->push(mb_cc_ok(array('items' => array(array('external_id' => '7', 'document_id' => 'd7')), 'next_cursor' => 'abc def')))
        ->push(mb_cc_ok(array('items' => array(), 'next_cursor' => null)));
    $client = mb_cc_client($transport);
    $first = $client->listPosts(null);
    $second = $client->listPosts('abc def');

    mb_assert_same('https://api.valley.atlaspaces.com/momobird/api/public/connect/v1/posts?limit=500', $transport->requests[0]['url'], 'first url');
    mb_assert_same('https://api.valley.atlaspaces.com/momobird/api/public/connect/v1/posts?limit=500&cursor=abc%20def', $transport->requests[1]['url'], 'second url');
    mb_assert_same('abc def', $first['next_cursor'], 'next cursor');
    mb_assert_same('7', $first['items'][0]['external_id'], 'items');
    mb_assert_same(null, $second['next_cursor'], 'no more pages');
});

mb_test('listPosts rejects malformed lists', function () {
    foreach (array(array('items' => 'x'), array('items' => array('nope')), array('items' => array(array('external_id' => 5))), array('nothing' => 1)) as $data) {
        $error = mb_cc_error((new MomoBird_FakeTransport())->push(mb_cc_ok($data)), function ($client) {
            $client->listPosts(null);
        });
        mb_assert_same('HTTP', $error->kind(), 'malformed list kind');
    }
});

mb_test('enableWidget posts an empty object', function () {
    $transport = (new MomoBird_FakeTransport())->push(mb_cc_ok(array('public_id' => 'pub_1', 'available' => true)));
    $data = mb_cc_client($transport)->enableWidget();
    mb_assert_same('POST', $transport->requests[0]['method'], 'method');
    mb_assert_same('https://api.valley.atlaspaces.com/momobird/api/public/connect/v1/widget/enable', $transport->requests[0]['url'], 'url');
    mb_assert_same('{}', $transport->requests[0]['body'], 'body is an empty JSON object');
    mb_assert_same('pub_1', $data['public_id'], 'data');
});

mb_test('5xx and network errors are not retried inside the client', function () {
    $transport = new MomoBird_FakeTransport(function () {
        return array('status' => 503, 'body' => '{"code":"UNAVAILABLE","message":"busy"}');
    });
    $error = mb_cc_error($transport, function ($client) {
        $client->status();
    });
    mb_assert_same(1, count($transport->requests), '5xx was retried');
    mb_assert_same('HTTP', $error->kind(), '5xx kind');
    mb_assert_same(503, $error->status(), '5xx status');
    mb_assert_same('UNAVAILABLE', $error->code(), '5xx code');

    $transport = new MomoBird_FakeTransport(function () {
        return array('status' => 0, 'body' => '', 'error' => 'Could not resolve host');
    });
    $error = mb_cc_error($transport, function ($client) {
        $client->upsert(array(mb_cc_post(1)));
    });
    mb_assert_same(1, count($transport->requests), 'network error was retried');
    mb_assert_same('NETWORK', $error->kind(), 'network kind');
    mb_assert_same(0, $error->status(), 'network status');
    mb_assert(strpos($error->getMessage(), 'Could not resolve host') !== false, 'network detail should reach the message');
});

mb_test('error responses are classified', function () {
    $cases = array(
        array(401, 'UNAUTHORIZED', 'UNAUTHORIZED', 'UNAUTHORIZED'),
        array(402, 'PLAN_REQUIRED', 'PLAN_REQUIRED', 'PLAN_REQUIRED'),
        array(402, null, 'PLAN_REQUIRED', 'http_402'),
        array(409, 'SOURCE_DISABLED', 'DISABLED', 'SOURCE_DISABLED'),
        array(409, 'WIDGET_NOT_READY', 'WIDGET_NOT_READY', 'WIDGET_NOT_READY'),
        array(409, 'SOMETHING_ELSE', 'HTTP', 'SOMETHING_ELSE'),
        array(413, 'PAYLOAD_TOO_LARGE', 'TOO_LARGE', 'PAYLOAD_TOO_LARGE'),
        array(413, null, 'TOO_LARGE', 'http_413'),
        array(400, 'VALIDATION_FAILED', 'REJECTED', 'VALIDATION_FAILED'),
        array(422, 'VALIDATION_FAILED', 'REJECTED', 'VALIDATION_FAILED'),
        array(404, 'NOT_FOUND', 'HTTP', 'NOT_FOUND'),
        array(429, null, 'HTTP', 'http_429'),
        array(500, null, 'HTTP', 'http_500'),
        array(502, null, 'HTTP', 'http_502'),
    );
    foreach ($cases as $case) {
        list($status, $code, $kind, $expectedCode) = $case;
        $body = $code === null ? 'oops' : json_encode(array('code' => $code, 'message' => '说明 ' . $status));
        $error = mb_cc_error((new MomoBird_FakeTransport())->push(array('status' => $status, 'body' => $body)), function ($client) {
            $client->status();
        });
        mb_assert_same($kind, $error->kind(), "kind for $status/$code");
        mb_assert_same($status, $error->status(), "status for $status/$code");
        mb_assert_same($expectedCode, $error->code(), "code for $status/$code");
    }
});

mb_test('error code and message are read from a nested error object too', function () {
    $body = json_encode(array('error' => array('code' => 'SOURCE_DISABLED', 'message' => '来源已停用')));
    $error = mb_cc_error((new MomoBird_FakeTransport())->push(array('status' => 409, 'body' => $body)), function ($client) {
        $client->status();
    });
    mb_assert_same('DISABLED', $error->kind(), 'nested kind');
    mb_assert(strpos($error->getMessage(), '来源已停用') !== false, 'server message should be kept: ' . $error->getMessage());
});

mb_test('every error message is Chinese when the server gives none', function () {
    foreach (array(401, 402, 409, 413, 500, 0) as $status) {
        $response = $status === 0 ? array('status' => 0, 'error' => 'timeout') : array('status' => $status, 'body' => '');
        $error = mb_cc_error((new MomoBird_FakeTransport())->push($response), function ($client) {
            $client->status();
        });
        mb_assert(preg_match('/\p{Han}/u', $error->getMessage()) === 1, "message for $status is not Chinese: " . $error->getMessage());
    }
});

mb_test('server messages are stripped of tags and truncated', function () {
    $body = json_encode(array('code' => 'X', 'message' => '<b>坏</b>' . str_repeat('长', 500)));
    $error = mb_cc_error((new MomoBird_FakeTransport())->push(array('status' => 500, 'body' => $body)), function ($client) {
        $client->status();
    });
    mb_assert(strpos($error->getMessage(), '<b>') === false, 'tags survived');
    mb_assert(mb_strlen($error->getMessage(), 'UTF-8') < 300, 'message not truncated: ' . mb_strlen($error->getMessage(), 'UTF-8'));
    mb_assert(mb_check_encoding($error->getMessage(), 'UTF-8'), 'truncation broke UTF-8');
});

mb_test('a response over 5 MB is an error', function () {
    $limit = 5 * 1024 * 1024;
    $huge = str_repeat(' ', $limit + 1);
    foreach (array(200, 500) as $status) {
        $error = mb_cc_error((new MomoBird_FakeTransport())->push(array('status' => $status, 'body' => $huge)), function ($client) {
            $client->status();
        });
        mb_assert_same('HTTP', $error->kind(), 'oversized response kind');
        mb_assert_same('response_too_large', $error->code(), 'oversized response code');
    }
    // Exactly at the limit is still parsed.
    $json = json_encode(array('code' => 'OK', 'data' => array('pad' => '')));
    $body = substr($json, 0, -3) . str_repeat('p', $limit - strlen($json)) . substr($json, -3);
    mb_assert_same($limit, strlen($body), 'test body size');
    $data = mb_cc_client((new MomoBird_FakeTransport())->push(array('status' => 200, 'body' => $body)))->status();
    mb_assert_same($limit - strlen($json), strlen($data['pad']), 'response of exactly 5 MB should parse');
});

mb_test('redirects are errors and are never followed', function () {
    foreach (array(301, 302, 303, 307, 308) as $status) {
        $transport = (new MomoBird_FakeTransport())->push(array('status' => $status, 'body' => ''));
        $error = mb_cc_error($transport, function ($client) {
            $client->status();
        });
        mb_assert_same(1, count($transport->requests), 'redirect was followed for ' . $status);
        mb_assert_same('HTTP', $error->kind(), 'redirect kind');
        mb_assert_same($status, $error->status(), 'redirect status');
        mb_assert_same('redirect', $error->code(), 'redirect code');
    }
});

mb_test('malformed success responses are HTTP errors', function () {
    foreach (array('not json', '[]', '{"code":"OK"}', '{"code":"FAIL","data":{}}', '{"code":"OK","data":"x"}', '') as $body) {
        $error = mb_cc_error((new MomoBird_FakeTransport())->push(array('status' => 200, 'body' => $body)), function ($client) {
            $client->status();
        });
        mb_assert_same('HTTP', $error->kind(), 'kind for body ' . $body);
        mb_assert_same('malformed_response', $error->code(), 'code for body ' . $body);
    }
    foreach (array(array('results' => 'x'), array('results' => array(array('external_id' => '1', 'status' => 'bogus'))), array('results' => array('x'))) as $data) {
        $error = mb_cc_error((new MomoBird_FakeTransport())->push(mb_cc_ok($data)), function ($client) {
            $client->upsert(array(mb_cc_post(1)));
        });
        mb_assert_same('malformed_response', $error->code(), 'bad upsert results');
    }
});

mb_test('the key never appears in any error', function () {
    $leaky = array(
        array('status' => 401, 'body' => json_encode(array('code' => 'UNAUTHORIZED', 'message' => 'bad key ' . MB_TEST_KEY))),
        array('status' => 500, 'body' => json_encode(array('code' => 'E_' . MB_TEST_KEY, 'message' => 'other token mbk_SOMEOTHERTOKEN'))),
        array('status' => 0, 'error' => 'connect to https://x failed with Authorization: Bearer ' . MB_TEST_KEY),
        array('status' => 200, 'body' => 'garbage ' . MB_TEST_KEY),
    );
    foreach ($leaky as $response) {
        $error = mb_cc_error((new MomoBird_FakeTransport())->push($response), function ($client) {
            $client->status();
        });
        $text = $error->getMessage() . '|' . $error->code() . '|' . $error->kind();
        mb_assert(stripos($text, 'mbk_') === false, 'key or mbk_ token leaked: ' . $text);
        mb_assert(strpos($text, 'DISCUZLOCAL') === false, 'part of the key leaked: ' . $text);
    }
});

mb_test('a transport that throws becomes a network error', function () {
    $transport = new MomoBird_FakeTransport(function () {
        throw new RuntimeException('socket exploded ' . MB_TEST_KEY);
    });
    $error = mb_cc_error($transport, function ($client) {
        $client->status();
    });
    mb_assert_same('NETWORK', $error->kind(), 'kind');
    mb_assert(strpos($error->getMessage(), MB_TEST_KEY) === false, 'key leaked from a transport exception');
});

mb_test('a transport answering garbage becomes a network error', function () {
    $transport = new MomoBird_FakeTransport(function () {
        return array('status' => 'x', 'body' => array(), 'error' => 5);
    });
    $error = mb_cc_error($transport, function ($client) {
        $client->status();
    });
    mb_assert_same('NETWORK', $error->kind(), 'kind');
});
