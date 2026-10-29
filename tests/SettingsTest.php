<?php

const MB_TEST_KEY = 'mbk_DISCUZLOCALTESTKEYDISCUZLOCALTESTKEYDISC';

function mb_settings(array $over = array(), array $extraHttpHosts = array()): MomoBird_Settings
{
    return MomoBird_Settings::fromArray($over + array(
        'baseUrl' => 'https://api.valley.atlaspaces.com',
        'key' => MB_TEST_KEY,
        'forumUrl' => 'https://bbs.example.com/',
    ), $extraHttpHosts);
}

mb_test('settings defaults', function () {
    $s = MomoBird_Settings::fromArray(array());
    mb_assert_same('https://api.valley.atlaspaces.com', $s->baseUrl(), 'default base url');
    mb_assert_same('', $s->key(), 'default key');
    mb_assert_same(10, $s->timeout(), 'default timeout');
    mb_assert_same('', $s->forumUrl(), 'default forum url');
    mb_assert_same(null, $s->forumIds(), 'default forum ids = all forums');
    mb_assert_same(false, $s->widgetEnabled(), 'widget off by default');
    mb_assert_same('right', $s->widgetPosition(), 'default position');
    mb_assert_same(true, $s->podcastEnabled(), 'podcast on by default');
    mb_assert_same(false, $s->podcastTranscript(), 'transcript off by default');
});

mb_test('a complete valid configuration has no errors', function () {
    mb_assert_same(array(), mb_settings()->validate(), 'valid settings reported errors');
});

mb_test('settings are trimmed and normalised', function () {
    $s = mb_settings(array('baseUrl' => '  https://API.Valley.example.com/ ', 'key' => ' ' . MB_TEST_KEY . "\n", 'forumUrl' => ' https://bbs.example.com/ '));
    mb_assert_same('https://API.Valley.example.com', $s->baseUrl(), 'base url should be trimmed without trailing slash');
    mb_assert_same(MB_TEST_KEY, $s->key(), 'key should be trimmed');
    mb_assert_same('https://bbs.example.com/', $s->forumUrl(), 'forum url should be trimmed');
    mb_assert_same(array(), $s->validate(), 'trimmed settings should be valid');
});

mb_test('base url must be https', function () {
    foreach (array('http://api.example.com', 'ftp://api.example.com', 'api.example.com', '//api.example.com') as $bad) {
        $errors = mb_settings(array('baseUrl' => $bad))->validate();
        mb_assert_same(1, count($errors), 'expected one error for ' . $bad);
        mb_assert(strpos($errors[0], 'Base URL') !== false, 'error should name Base URL for ' . $bad);
    }
});

mb_test('http is allowed only for local hosts', function () {
    foreach (array('http://localhost', 'http://localhost:8080', 'http://127.0.0.1:9000', 'http://[::1]:9000', 'http://host.docker.internal:8099', 'HTTP://LOCALHOST') as $ok) {
        mb_assert_same(array(), mb_settings(array('baseUrl' => $ok))->validate(), 'should allow ' . $ok);
    }
    mb_assert(count(mb_settings(array('baseUrl' => 'http://mock-connect:8099'))->validate()) === 1, 'unknown docker host must be rejected by default');
    mb_assert_same(array(), mb_settings(array('baseUrl' => 'http://mock-connect:8099'), array('mock-connect'))->validate(), 'extra http host should be allowed when passed in');
    mb_assert(count(mb_settings(array('baseUrl' => 'http://evil.example.com'), array('mock-connect'))->validate()) === 1, 'extra host list must not open other hosts');
    mb_assert(count(mb_settings(array('baseUrl' => 'http://localhost.evil.com'))->validate()) === 1, 'prefix match must not count as local');
});

mb_test('base url rejects credentials, query, fragment and paths', function () {
    foreach (array('https://user:pw@api.example.com', 'https://user@api.example.com', 'https://api.example.com?a=1', 'https://api.example.com#x', 'https://api.example.com/momobird', 'https://') as $bad) {
        mb_assert(count(mb_settings(array('baseUrl' => $bad))->validate()) === 1, 'should reject ' . $bad);
    }
    mb_assert(count(mb_settings(array('baseUrl' => ''))->validate()) === 1, 'empty base url should be rejected');
});

mb_test('key must be mbk_ followed by 16 to 200 url-safe characters', function () {
    $bad = array('', 'abc', 'mbk', 'MBK_xxxxxxxxxxxxxxxxxxxx', 'xmbk_abcdefghijklmnopqrstu', 'mbk_short', 'mbk_' . str_repeat('a', 15), 'mbk_' . str_repeat('a', 201),
        "mbk_abcdefghijklmnop\r\nX-Evil: 1", 'mbk_abcdefghij klmnopqrstu', 'mbk_abcdefghijklmnop!', 'mbk_密钥密钥密钥密钥密钥密钥密钥密钥');
    foreach ($bad as $key) {
        $errors = mb_settings(array('key' => $key))->validate();
        mb_assert_same(1, count($errors), 'expected one error for key ' . var_export($key, true));
        mb_assert(strpos($errors[0], 'mbk_') !== false, 'error should explain the format');
        mb_assert(strlen($key) < 8 || strpos($errors[0], $key) === false, 'error must not echo the key');
        mb_assert(preg_match('/\p{Han}/u', $errors[0]) === 1, 'error should be Chinese');
    }
    foreach (array('mbk_' . str_repeat('a', 16), 'mbk_' . str_repeat('Z_-9', 50), MB_TEST_KEY) as $key) {
        mb_assert_same(array(), mb_settings(array('key' => $key))->validate(), 'should accept ' . $key);
    }
});

mb_test('timeout is 2 to 30 seconds', function () {
    foreach (array(2, 10, 30, '5') as $ok) {
        mb_assert_same(array(), mb_settings(array('timeout' => $ok))->validate(), 'should allow ' . $ok);
    }
    foreach (array(1, 0, 31, -5, 'abc') as $bad) {
        mb_assert_same(1, count(mb_settings(array('timeout' => $bad))->validate()), 'should reject timeout ' . var_export($bad, true));
    }
    mb_assert_same(10, mb_settings(array('timeout' => null))->timeout(), 'null timeout falls back to the default');
    mb_assert_same(10, mb_settings(array('timeout' => ''))->timeout(), 'empty timeout falls back to the default');
});

mb_test('forum url must be an absolute http(s) address and not the discuz default', function () {
    foreach (array('', 'bbs.example.com', '/forum', 'ftp://bbs.example.com/', 'https://', 'javascript:alert(1)', 'https://u:p@bbs.example.com/') as $bad) {
        mb_assert_same(1, count(mb_settings(array('forumUrl' => $bad))->validate()), 'should reject forum url ' . $bad);
    }
    foreach (array('https://www.discuz.vip/', 'https://www.discuz.vip', 'HTTPS://WWW.DISCUZ.VIP/', 'http://www.discuz.vip/') as $bad) {
        $errors = mb_settings(array('forumUrl' => $bad))->validate();
        mb_assert_same(1, count($errors), 'should reject the discuz default ' . $bad);
        mb_assert(strpos($errors[0], 'discuz.vip') !== false, 'error should mention the default address');
    }
    foreach (array('http://bbs.example.com/', 'https://bbs.example.com/forum/', 'https://www.discuz.vip/forum/') as $ok) {
        mb_assert_same(array(), mb_settings(array('forumUrl' => $ok))->validate(), 'should accept ' . $ok);
    }
});

mb_test('validate reports every problem in Chinese', function () {
    $errors = MomoBird_Settings::fromArray(array('baseUrl' => 'http://x.example.com', 'key' => 'nope', 'timeout' => 99, 'forumUrl' => ''))->validate();
    mb_assert_same(4, count($errors), 'all four problems should be listed');
    foreach ($errors as $error) {
        mb_assert(preg_match('/\p{Han}/u', $error) === 1, 'error is not Chinese: ' . $error);
    }
});

mb_test('forum ids normalise to unique positive integers', function () {
    mb_assert_same(null, mb_settings(array('forumIds' => null))->forumIds(), 'null means all forums');
    mb_assert_same(array(), mb_settings(array('forumIds' => array()))->forumIds(), 'an empty selection stays empty');
    mb_assert_same(array(2, 5, 9), mb_settings(array('forumIds' => array('9', 2, '5', 2, 0, -1, 'x')))->forumIds(), 'ids should be sorted, unique and positive');
    mb_assert_same(array(1, 3), mb_settings(array('forumIds' => '3, 1,3'))->forumIds(), 'comma separated ids are accepted');
});

mb_test('flags accept form values', function () {
    $s = mb_settings(array('widgetEnabled' => '1', 'podcastEnabled' => '0', 'podcastTranscript' => true, 'widgetPosition' => 'left'));
    mb_assert_same(true, $s->widgetEnabled(), 'widget on');
    mb_assert_same(false, $s->podcastEnabled(), 'podcast off');
    mb_assert_same(true, $s->podcastTranscript(), 'transcript on');
    mb_assert_same('left', $s->widgetPosition(), 'left position');
    mb_assert_same('right', mb_settings(array('widgetPosition' => 'top'))->widgetPosition(), 'unknown position falls back to right');
    mb_assert_same(false, mb_settings(array('widgetEnabled' => '0', 'podcastEnabled' => false))->podcastEnabled(), 'false disables podcast');
});

mb_test('toArray never contains the key and keyPrefix is 8 chars', function () {
    $s = mb_settings(array('forumIds' => array(3, 4), 'widgetEnabled' => true));
    $array = $s->toArray();
    mb_assert(!array_key_exists('key', $array), 'toArray exposes a key entry');
    mb_assert(strpos(json_encode($array), MB_TEST_KEY) === false, 'toArray leaked the key');
    mb_assert(strpos(print_r($s, true), MB_TEST_KEY) === false, 'print_r leaked the key');
    mb_assert(strpos(var_export($array, true), 'DISCUZLOCAL') === false, 'toArray leaked part of the key');
    mb_assert_same('mbk_DISC', $s->keyPrefix(), 'key prefix');
    mb_assert_same('', mb_settings(array('key' => ''))->keyPrefix(), 'empty key has an empty prefix');
    mb_assert_same('mbk_a', mb_settings(array('key' => 'mbk_a'))->keyPrefix(), 'short key prefix');
    mb_assert_same(array('baseUrl', 'timeout', 'forumUrl', 'forumIds', 'widgetEnabled', 'widgetPosition', 'podcastEnabled', 'podcastTranscript'), array_keys($array), 'toArray keys');
    mb_assert_same(array(3, 4), $array['forumIds'], 'forum ids in array');
    $again = MomoBird_Settings::fromArray($array + array('key' => MB_TEST_KEY));
    mb_assert_same($array, $again->toArray(), 'round trip');
});

mb_test('the key does not leak through serialize, var_export, var_dump, print_r or casts', function () {
    $s = mb_settings(array('forumIds' => array(1)));
    $dumps = array(
        'serialize' => serialize($s),
        'var_export' => var_export($s, true),
        'print_r' => print_r($s, true),
        'json_encode' => json_encode($s),
        'cast var_export' => var_export((array) $s, true),
        'cast serialize-ish' => json_encode(array_map('gettype', (array) $s)),
    );
    ob_start();
    var_dump($s);
    $dumps['var_dump'] = ob_get_clean();
    foreach ($dumps as $name => $text) {
        mb_assert(strpos($text, MB_TEST_KEY) === false, $name . ' leaked the key');
        mb_assert(strpos($text, 'DISCUZLOCAL') === false, $name . ' leaked part of the key');
    }
});

mb_test('serialized settings keep everything except the key', function () {
    $s = mb_settings(array('forumIds' => array(3, 4), 'widgetEnabled' => true, 'timeout' => 12), array('mock-connect'));
    $again = unserialize(serialize($s));
    mb_assert(get_class($again) === 'MomoBird_Settings', 'class');
    mb_assert_same($s->toArray(), $again->toArray(), 'fields survive');
    mb_assert_same('', $again->key(), 'the key does not survive');
    mb_assert_same('', $again->keyPrefix(), 'no key prefix either');
    mb_assert_same($s->key(), $s->key(), 'the original still has its key');
    mb_assert_same(MB_TEST_KEY, (clone $s)->key(), 'clones keep the key');
});
