<?php

// Must stay first: the guard test needs the real situation where Discuz!'s
// `filesock` class does not exist. The stub is loaded by the tests after it.
mb_test('filesock transport reports an error instead of failing when filesock is missing', function () {
    mb_assert(!class_exists('filesock', false), 'filesock must not be loaded yet');
    $result = (new MomoBird_FilesockTransport(false, true))->send('GET', 'https://api.example.com/x', array(), null, 5);
    mb_assert_same(0, $result['status'], 'status');
    mb_assert_same('', $result['body'], 'body');
    mb_assert(is_string($result['error']) && preg_match('/\p{Han}/u', $result['error']) === 1, 'Chinese error expected');
});

function mb_fs_send(array $arguments = array(), ?callable $factory = null, array $constructor = array(false, true)): array
{
    require_once __DIR__ . '/FilesockStub.php';
    filesock::$opened = array();
    filesock::$factory = $factory;
    $arguments = $arguments + array('PUT', 'https://api.valley.atlaspaces.com/momobird/api/public/connect/v1/posts', array('Accept: application/json', 'Authorization: Bearer ' . MB_TEST_KEY, 'Content-Type: application/json'), '{"posts":[]}', 8);
    $transport = (new ReflectionClass('MomoBird_FilesockTransport'))->newInstanceArgs($constructor);
    return call_user_func_array(array($transport, 'send'), $arguments);
}

mb_test('filesock transport sends explicit timeouts, verified TLS and no failonerror', function () {
    mb_fs_send();
    mb_assert_same(1, count(filesock::$opened), 'filesock::open called once');
    $param = filesock::$opened[0];
    mb_assert_same('https://api.valley.atlaspaces.com/momobird/api/public/connect/v1/posts', $param['url'], 'url');
    mb_assert_same('PUT', $param['method'], 'method');
    mb_assert_same(8, $param['timeout'], 'total timeout');
    mb_assert_same(5, $param['conntimeout'], 'connect timeout is capped at 5');
    mb_assert_same(false, $param['failonerror'], 'failonerror must be false so 4xx bodies come back');
    mb_assert_same(true, $param['verifypeer'], 'TLS verification must be on');
    mb_assert_same(true, $param['returnbody'], 'returnbody');
    mb_assert_same('{"posts":[]}', $param['rawdata'], 'raw body');
    mb_assert_same('application/json', $param['encodetype'], 'encode type');
    mb_assert_same(5 * 1024 * 1024 + 1, $param['limit'], 'response limit is one byte over 5 MB');
    mb_assert(!empty($param['useragent']) && strpos($param['useragent'], 'MomoBird') !== false, 'explicit user agent (no $_SERVER dependency)');
    mb_assert(empty($param['unsafe']), 'the Discuz! SSRF guard stays on by default');
    mb_assert(empty($param['ip']) && empty($param['cookie']), 'no ip or cookie override');
});

mb_test('filesock transport connect timeout follows a short total timeout', function () {
    mb_fs_send(array('GET', 'https://api.example.com/x', array(), null, 2));
    mb_assert_same(2, filesock::$opened[0]['conntimeout'], 'conntimeout <= timeout');
});

mb_test('filesock transport turns header lines into a header map', function () {
    mb_fs_send();
    mb_assert_same(
        array('Accept' => 'application/json', 'Authorization' => 'Bearer ' . MB_TEST_KEY, 'Content-Type' => 'application/json'),
        filesock::$opened[0]['header'],
        'header map'
    );
    mb_fs_send(array('GET', 'https://api.example.com/x', array('Broken header line', 'X-A:  spaced  ', ': nameless'), null, 5));
    mb_assert_same(array('X-A' => 'spaced'), filesock::$opened[0]['header'], 'malformed header lines are skipped, values trimmed');
});

mb_test('filesock transport sends no body parameters for a GET', function () {
    mb_fs_send(array('GET', 'https://api.example.com/x', array(), null, 5));
    $param = filesock::$opened[0];
    mb_assert_same('GET', $param['method'], 'method');
    mb_assert(!isset($param['rawdata']) || $param['rawdata'] === '', 'no raw data for GET');
    mb_assert(!isset($param['post']), 'no form post data');
});

mb_test('filesock transport maps status and body, including error statuses', function () {
    $ok = mb_fs_send(array(), function ($instance) {
        $instance->curlstatus = array('http_code' => 200);
        $instance->body = '{"code":"OK"}';
    });
    mb_assert_same(array('status' => 200, 'body' => '{"code":"OK"}', 'error' => null), $ok, '200');

    $denied = mb_fs_send(array(), function ($instance) {
        $instance->curlstatus = array('http_code' => 402);
        $instance->body = '{"code":"PLAN_REQUIRED"}';
    });
    mb_assert_same(array('status' => 402, 'body' => '{"code":"PLAN_REQUIRED"}', 'error' => null), $denied, '402 body is kept');

    $redirect = mb_fs_send(array(), function ($instance) {
        $instance->curlstatus = array('http_code' => 301);
        $instance->body = '';
    });
    mb_assert_same(301, $redirect['status'], 'redirect status is passed on, not followed');
});

mb_test('filesock transport maps failures to error results', function () {
    $net = mb_fs_send(array(), function ($instance) {
        $instance->errno = 28;
        $instance->errstr = 'Operation timed out after 8001 milliseconds';
        $instance->curlstatus = array('http_code' => 0);
    });
    mb_assert_same(0, $net['status'], 'network status');
    mb_assert(strpos($net['error'], 'Operation timed out') !== false && strpos($net['error'], '28') !== false, 'curl error and code in the message: ' . $net['error']);

    $blocked = mb_fs_send(array(), function ($instance) {
        $instance->safequery = false;
        $instance->curlstatus = null;
    });
    mb_assert_same(0, $blocked['status'], 'blocked status');
    mb_assert(strpos($blocked['error'], 'fsockopensafe') !== false, 'the SSRF guard should be named: ' . $blocked['error']);

    $silent = mb_fs_send(array(), function ($instance) {
        $instance->curlstatus = null;
    });
    mb_assert_same(0, $silent['status'], 'no response status');
    mb_assert(is_string($silent['error']) && $silent['error'] !== '', 'no response is an error');

    $thrown = mb_fs_send(array(), function ($instance) {
        $instance->throw = new RuntimeException('boom');
    });
    mb_assert_same(0, $thrown['status'], 'exception status');
    mb_assert(strpos($thrown['error'], 'boom') !== false, 'exception message kept');
});

mb_test('filesock transport can switch the Discuz! SSRF guard off for local mock servers', function () {
    mb_fs_send(array(), null, array(true, true));
    mb_assert_same(true, filesock::$opened[0]['unsafe'], 'unsafe passed to filesock');
});

mb_test('filesock transport refuses to send without cURL', function () {
    mb_fs_send(array(), null, array(false, true)); // warm: loads the stub
    filesock::$opened = array();
    $result = mb_fs_send(array(), null, array(false, false));
    mb_assert_same(0, count(filesock::$opened), 'no request without cURL: the stream fallback would follow redirects with the key');
    mb_assert_same(0, $result['status'], 'status');
    mb_assert(strpos($result['error'], 'cURL') !== false, 'error names cURL: ' . $result['error']);
});

mb_test('filesock transport works through the client and keeps the key out of errors', function () {
    require_once __DIR__ . '/FilesockStub.php';
    filesock::$factory = function ($instance) {
        $instance->errno = 6;
        $instance->errstr = 'Could not resolve host; sent Authorization: Bearer ' . MB_TEST_KEY;
    };
    $client = new MomoBird_ConnectClient(mb_settings(), new MomoBird_FilesockTransport(false, true));
    $error = mb_assert_throws(function () use ($client) {
        $client->status();
    }, 'MomoBird_ConnectError');
    mb_assert_same('NETWORK', $error->kind(), 'kind');
    mb_assert(stripos($error->getMessage(), 'mbk_') === false, 'key leaked through the transport error');
});
