<?php

mb_test('redactor removes the exact key', function () {
    $key = 'not-an-mbk-key-but-secret';
    $out = MomoBird_Redactor::clean('boom ' . $key . ' end', $key);
    mb_assert(strpos($out, $key) === false, 'key survived');
    mb_assert(strpos($out, 'boom') === 0 && substr($out, -4) === ' end', 'surrounding text was damaged: ' . $out);
});

mb_test('redactor removes every mbk_ token, even other keys', function () {
    $out = MomoBird_Redactor::clean('a mbk_ABCdef-123_xyz b MBK_other c mbk_', MB_TEST_KEY);
    mb_assert(stripos($out, 'mbk_') === false, 'an mbk_ token survived: ' . $out);
    mb_assert(strpos($out, 'a ') === 0, 'text before was damaged');
});

mb_test('redactor removes url-encoded keys and bearer values', function () {
    $key = 'secret key/with+chars';
    $out = MomoBird_Redactor::clean('x ' . rawurlencode($key) . ' ' . urlencode($key) . ' Authorization: Bearer abc.DEF-123', $key);
    mb_assert(strpos($out, rawurlencode($key)) === false && strpos($out, urlencode($key)) === false, 'encoded key survived: ' . $out);
    mb_assert(strpos($out, 'abc.DEF') === false, 'bearer value survived: ' . $out);
});

mb_test('redactor leaves clean text alone and ignores tiny keys', function () {
    mb_assert_same('连接失败，请稍后再试', MomoBird_Redactor::clean('连接失败，请稍后再试', MB_TEST_KEY), 'clean text changed');
    mb_assert_same('abc', MomoBird_Redactor::clean('abc', ''), 'empty key must not blank the text');
    mb_assert_same('abc', MomoBird_Redactor::clean('abc', 'b'), 'a one-character key must not be replaced everywhere');
});

mb_test('connect error redacts its message and code at construction', function () {
    $error = new MomoBird_ConnectError(MomoBird_ConnectError::HTTP, 'bad ' . MB_TEST_KEY . ' mbk_other', 500, 'E_' . MB_TEST_KEY, MB_TEST_KEY);
    mb_assert(strpos($error->getMessage(), MB_TEST_KEY) === false && stripos($error->getMessage(), 'mbk_') === false, 'message leaked: ' . $error->getMessage());
    mb_assert(stripos($error->code(), 'mbk_') === false, 'code leaked');
    mb_assert_same('HTTP', $error->kind(), 'kind');
    mb_assert_same(500, $error->status(), 'status');
    $bare = new MomoBird_ConnectError(MomoBird_ConnectError::NETWORK, 'x ' . MB_TEST_KEY);
    mb_assert(strpos($bare->getMessage(), MB_TEST_KEY) === false, 'keyless construction leaked');
});

mb_test('connect error kinds', function () {
    foreach (array('NETWORK', 'HTTP', 'PLAN_REQUIRED', 'UNAUTHORIZED', 'DISABLED', 'WIDGET_NOT_READY', 'TOO_LARGE', 'REJECTED') as $kind) {
        mb_assert_same($kind, constant('MomoBird_ConnectError::' . $kind), 'constant ' . $kind);
    }
});
