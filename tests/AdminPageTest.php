<?php

require_once __DIR__ . '/FakeStore.php';
require_once __DIR__ . '/FakeForumSource.php';
require_once __DIR__ . '/FakeConnect.php';

const MB_AP_KEY = 'mbk_DISCUZLOCALTESTKEYDISCUZLOCALTESTKEYDISC';
const MB_AP_NEW_KEY = 'mbk_ANOTHERLOCALTESTKEYANOTHERLOCALTESTKEY01';
const MB_AP_HASH = 'a1b2c3d4';

/**
 * An admin page over a fake forum (group 1 分区 with 汽车 2 and its sub 新能源 4, 数码 3),
 * a fake MomoBird and saved settings (with the key unless $withKey is false).
 */
function mb_ap(array $env = array(), bool $withKey = true): stdClass
{
    $w = new stdClass();
    $w->now = 1800000000;
    $w->store = new MomoBird_FakeStore();
    $w->forum = new MomoBird_FakeForumSource();
    $w->forum->addGroup(1, '分区')->addForum(2, '汽车')->addForum(3, '数码')->addForum(4, '新能源', 2, 'sub');
    $w->server = new MomoBird_FakeConnect();
    $w->saved = 0;
    $w->engineBuilt = 0;
    $w->store->set('settings', array(
        'baseUrl' => 'https://api.valley.atlaspaces.com',
        'timeout' => 10,
        'forumUrl' => 'https://bbs.example.com/',
        'forumIds' => null,
        'widgetEnabled' => false,
        'widgetPosition' => 'right',
        'podcastEnabled' => true,
        'podcastTranscript' => false,
    ));
    if ($withKey) {
        $w->store->set('secret', array('key' => MB_AP_KEY));
    }
    $w->env = $env + array(
        'supported' => true,
        'charset' => 'utf-8',
        'formhash' => MB_AP_HASH,
        'pageUrl' => 'admin.php?action=plugins&operation=config&do=9&identifier=momobird_ai&pmod=admincp',
        'scriptUrl' => 'source/plugin/momobird_ai/static/admin.js?v=0.1.0',
        'siteUrl' => 'https://bbs.example.com/',
        'httpHosts' => array(),
    );
    mb_ap_build($w);
    return $w;
}

function mb_ap_build(stdClass $w): void
{
    $clock = function () use ($w) {
        return $w->now;
    };
    $engine = function (MomoBird_Settings $settings) use ($w, $clock) {
        $w->engineBuilt++;
        return new MomoBird_SyncEngine($settings, new MomoBird_ConnectClient($settings, $w->server->transport), $w->store, $w->forum, $clock);
    };
    // What MomoBird_Bootstrap::saveSettings does (Bootstrap.php is not loaded here: the
    // adapter test checks the order of autoloaders it registers).
    $save = function (array $values, ?string $key) use ($w) {
        $w->saved++;
        $w->store->set(MomoBird_Settings::KV_SETTINGS, MomoBird_Settings::fromArray($values)->toArray());
        if ($key !== null) {
            $w->store->set(MomoBird_Settings::KV_SECRET, array('key' => $key));
        }
    };
    $w->page = new MomoBird_AdminPage($w->store, $w->forum, $engine, $save, $w->env);
}

function mb_ap_get(stdClass $w): string
{
    $out = $w->page->handle('GET', array('action' => 'plugins'));
    mb_assert_same('html', $out['type'], 'a page view answers html');
    return $out['body'];
}

/**
 * POST the settings form; $fields are merged over a complete valid form. 'key' stands for the
 * key input, which is named admin_password (see the admin log test).
 */
function mb_ap_save(stdClass $w, array $fields = array(), array $extra = array()): string
{
    if (array_key_exists('key', $fields)) {
        $fields['admin_password'] = $fields['key'];
        unset($fields['key']);
    }
    $form = $fields + array(
        'baseUrl' => 'https://api.valley.atlaspaces.com',
        'admin_password' => '',
        'timeout' => '10',
        'forumUrl' => 'https://bbs.example.com/',
        'forumScope' => 'all',
        'widgetEnabled' => '0',
        'widgetPosition' => 'right',
        'podcastEnabled' => '1',
        'podcastTranscript' => '0',
    );
    $request = $extra + array('formhash' => MB_AP_HASH, 'momobird_save' => '1', 'mb' => $form);
    $out = $w->page->handle('POST', $request);
    mb_assert_same('html', $out['type'], 'saving answers html');
    return $out['body'];
}

function mb_ap_ajax(stdClass $w, string $action, array $params = array(), string $method = 'POST'): array
{
    $out = $w->page->handle($method, $params + array('mb_action' => $action, 'formhash' => MB_AP_HASH, 'inajax' => '1'));
    mb_assert_same('json', $out['type'], $action . ' answers json');
    $data = json_decode($out['body'], true);
    mb_assert(is_array($data), $action . ' answer is not JSON: ' . $out['body']);
    $data['_raw'] = $out['body'];
    return $data;
}

function mb_ap_no_key(string $text, string $where): void
{
    foreach (array(MB_AP_KEY, MB_AP_NEW_KEY) as $key) {
        mb_assert(strpos($text, $key) === false, $where . ' contains a key');
        // Nothing beyond the 8-character prefix may show either.
        mb_assert(strpos($text, substr($key, 0, 9)) === false, $where . ' shows more than the key prefix');
        mb_assert(strpos($text, substr($key, 8)) === false, $where . ' contains the key body');
    }
}

// ---------------------------------------------------------------- the key

mb_test('admin page: the html never contains the key, only its prefix', function () {
    $w = mb_ap();
    $html = mb_ap_get($w);
    mb_ap_no_key($html, 'page');
    mb_assert(strpos($html, '已保存：mbk_DISC…') !== false, 'saved prefix shown');
    mb_assert(preg_match('/<input[^>]*name="mb\[admin_password\]"[^>]*>/', $html, $m) === 1, 'key input present');
    mb_assert(strpos($m[0], 'value=""') !== false, 'key input is always empty: ' . $m[0]);
    mb_assert(strpos($m[0], 'type="password"') !== false, 'key input is a password field');

    // After a sync and a status check the page still holds no key.
    mb_ap_ajax($w, 'test');
    $html = mb_ap_get($w);
    mb_ap_no_key($html, 'page after test');

    // An invalid save that included a new key does not echo it back.
    $html = mb_ap_save($w, array('key' => MB_AP_NEW_KEY, 'timeout' => '99'));
    mb_ap_no_key($html, 'page after a rejected save');
    // A valid save with a new key neither.
    $html = mb_ap_save($w, array('key' => MB_AP_NEW_KEY));
    mb_ap_no_key($html, 'page after a save');
    mb_assert(strpos($html, '已保存：mbk_ANOT…') !== false, 'new prefix shown');
});

mb_test('admin page: no saved key shows 未设置', function () {
    $w = mb_ap(array(), false);
    $html = mb_ap_get($w);
    mb_assert(strpos($html, '未设置') !== false, '未设置 shown');
    mb_assert(strpos($html, '已保存：') === false, 'no saved prefix');
});

mb_test('admin page: a blank key on save keeps the stored key', function () {
    $w = mb_ap();
    $html = mb_ap_save($w, array('key' => '   ', 'timeout' => '15'));
    mb_assert(strpos($html, '设置已保存') !== false, 'saved message');
    mb_assert_same(array('key' => MB_AP_KEY), $w->store->get('secret'), 'key kept');
    mb_assert_same(15, $w->store->get('settings')['timeout'], 'other settings saved');
    mb_assert(!isset($w->store->get('settings')['key']), 'settings row has no key');
});

mb_test('admin page: a new key is saved and the connection checked at once', function () {
    $w = mb_ap();
    $w->store->set('halt', array('kind' => 'UNAUTHORIZED', 'message' => '连接密钥无效', 'at' => 1));
    mb_ap_save($w, array('key' => ' ' . MB_AP_NEW_KEY . ' '));
    mb_assert_same(array('key' => MB_AP_NEW_KEY), $w->store->get('secret'), 'new key stored');
    mb_assert_same(1, $w->server->count('GET /status'), 'status refreshed after the new key');
    mb_assert_same(null, $w->store->get('halt'), 'halt cleared by the refresh');
    $sent = $w->server->transport->requests[0]['headers'];
    mb_assert(in_array('Authorization: Bearer ' . MB_AP_NEW_KEY, $sent, true) || (isset($sent['Authorization']) && $sent['Authorization'] === 'Bearer ' . MB_AP_NEW_KEY), 'the check used the new key');

    // A save without a new key does not go to the network.
    mb_ap_save($w, array('timeout' => '12'));
    mb_assert_same(1, $w->server->count('GET /status'), 'no status call without a new key');
});

mb_test('admin page: invalid settings show chinese errors and save nothing', function () {
    $w = mb_ap(array(), false);
    $before = $w->store->kv;
    $html = mb_ap_save($w, array(
        'baseUrl' => 'http://api.example.com',
        'key' => 'sk-wrong',
        'timeout' => '99',
        'forumUrl' => 'https://www.discuz.vip/',
    ));
    // The save notice comes before the form (the status below may list problems too).
    $notice = substr($html, 0, (int) strpos($html, '<form'));
    foreach (array('Base URL 必须以 https:// 开头', '连接密钥格式不对', '超时时间需要在 2 到 30 秒之间', '论坛地址还是 Discuz! 的默认值') as $text) {
        mb_assert(strpos($notice, $text) !== false, 'error shown: ' . $text);
    }
    mb_assert(strpos($notice, '设置没有保存') !== false, 'says nothing was saved');
    mb_assert(strpos($html, '应以 mbk_ 开头') !== false, 'the key format hint survives redaction');
    mb_assert(strpos($html, '应以 ' . MomoBird_Redactor::PLACEHOLDER) === false, 'the hint is not blanked');
    mb_assert_same(0, $w->saved, 'save not called');
    mb_assert_same($before, $w->store->kv, 'kv unchanged');
    mb_assert(strpos($html, 'sk-wrong') === false, 'a rejected key is not echoed');
    // The other submitted values stay in the form so the admin can fix them.
    mb_assert(strpos($html, 'value="http://api.example.com"') !== false, 'base url kept in the form');
    mb_assert(strpos($html, 'value="99"') !== false, 'timeout kept in the form');
});

mb_test('admin page: settings can be saved before there is a key', function () {
    $w = mb_ap(array(), false);
    $html = mb_ap_save($w, array('timeout' => '20'));
    mb_assert(strpos($html, '设置已保存') !== false, 'saved without a key');
    mb_assert_same(null, $w->store->get('secret'), 'no key stored');
    mb_assert(strpos($html, '设置还不完整') !== false, 'the status says the key is missing');
    mb_assert_same(0, count($w->server->transport->requests), 'no network without a key');
});

mb_test('admin page: choosing selected forums needs at least one forum', function () {
    $w = mb_ap();
    $html = mb_ap_save($w, array('forumScope' => 'selected', 'forumIds' => array()));
    mb_assert(strpos($html, '请至少选择一个版块') !== false, 'error shown');
    mb_assert_same(0, $w->saved, 'nothing saved');

    $html = mb_ap_save($w, array('forumScope' => 'selected', 'forumIds' => array('3', '4', '1', '99', 'x')));
    mb_assert(strpos($html, '设置已保存') !== false, 'saved');
    mb_assert_same(array(3, 4), $w->store->get('settings')['forumIds'], 'only forum/sub ids are kept');

    mb_ap_save($w, array('forumScope' => 'all', 'forumIds' => array('3')));
    mb_assert_same(null, $w->store->get('settings')['forumIds'], 'all = null');
});

mb_test('admin page: an empty forum address follows the site url', function () {
    $w = mb_ap(array('siteUrl' => 'https://bbs.example.com/'));
    $html = mb_ap_save($w, array('forumUrl' => ''));
    mb_assert(strpos($html, '设置已保存') !== false, 'saved with the site url');
    mb_assert_same('', $w->store->get('settings')['forumUrl'], 'stored empty (follows 站点 URL)');
    mb_assert(strpos($html, 'https://bbs.example.com/') !== false, 'site url shown as the fallback');

    $w = mb_ap(array('siteUrl' => 'https://www.discuz.vip/'));
    $html = mb_ap_save($w, array('forumUrl' => ''));
    mb_assert(strpos($html, '论坛地址还是 Discuz! 的默认值') !== false, 'placeholder site url rejected');
    mb_assert_same(0, $w->saved, 'nothing saved');
});

mb_test('admin page: a failed save shows a redacted error', function () {
    $w = mb_ap();
    $w->page = new MomoBird_AdminPage($w->store, $w->forum, function () {
        throw new RuntimeException('no engine');
    }, function () {
        throw new RuntimeException('保存设置失败：INSERT ... ' . MB_AP_NEW_KEY);
    }, $w->env);
    $html = mb_ap_save($w, array('key' => MB_AP_NEW_KEY));
    mb_ap_no_key($html, 'failed save');
    mb_assert(strpos($html, '保存设置失败') !== false, 'failure shown');
});

// ---------------------------------------------------------------- formhash

mb_test('admin page: a save without the right formhash is refused', function () {
    $w = mb_ap();
    $before = $w->store->kv;
    foreach (array(array(), array('formhash' => ''), array('formhash' => 'deadbeef')) as $extra) {
        $request = array('momobird_save' => '1', 'mb' => array('timeout' => '20', 'admin_password' => MB_AP_NEW_KEY)) + $extra;
        $out = $w->page->handle('POST', $request);
        mb_assert(strpos($out['body'], '页面已过期') !== false, 'refusal shown for ' . json_encode($extra));
    }
    // A GET with the right hash is refused too: saving is POST only.
    $out = $w->page->handle('GET', array('momobird_save' => '1', 'formhash' => MB_AP_HASH, 'mb' => array('timeout' => '20')));
    mb_assert(strpos($out['body'], '页面已过期') !== false, 'GET save refused');
    mb_assert_same(0, $w->saved, 'nothing saved');
    mb_assert_same($before, $w->store->kv, 'kv unchanged');
});

mb_test('admin page: ajax actions check the formhash and the method', function () {
    $w = mb_ap();
    foreach (array('test', 'sync_batch', 'retry', 'confirm_withdraw') as $action) {
        $out = $w->page->handle('POST', array('mb_action' => $action, 'inajax' => '1'));
        $data = json_decode($out['body'], true);
        mb_assert_same('json', $out['type'], $action . ' json');
        mb_assert_same(false, $data['ok'], $action . ' refused without formhash');
        mb_assert(strpos($data['message'], '页面已过期') !== false, $action . ' message');

        $data = mb_ap_ajax($w, $action, array(), 'GET');
        mb_assert_same(false, $data['ok'], $action . ' refused as GET');
    }
    mb_assert_same(0, count($w->server->transport->requests), 'no network');
    mb_assert_same(0, $w->engineBuilt, 'engine never built');
    $data = mb_ap_ajax($w, 'drop_tables');
    mb_assert_same(false, $data['ok'], 'unknown action refused');
});

// ---------------------------------------------------------------- actions

mb_test('admin page: test connection refreshes the status', function () {
    $w = mb_ap();
    $data = mb_ap_ajax($w, 'test');
    mb_assert_same(true, $data['ok'], 'ok');
    mb_assert(strpos($data['message'], '连接正常') !== false, 'message');
    mb_assert(strpos($data['status_html'], '测试项目') !== false, 'status html has the project name');
    mb_ap_no_key($data['_raw'], 'test answer');

    $w->server->fail('GET /status', 0);
    $data = mb_ap_ajax($w, 'test');
    mb_assert_same(false, $data['ok'], 'network failure reported');
    mb_assert(strpos($data['message'], '连接失败') !== false, 'failure message: ' . $data['message']);
});

mb_test('admin page: full sync runs in browser batches until done', function () {
    $w = mb_ap();
    foreach (range(1, 45) as $tid) {
        $w->forum->addThread($tid, 2);
    }
    $cursor = '';
    $pushed = 0;
    for ($i = 0; $i < 50; $i++) {
        $data = mb_ap_ajax($w, 'sync_batch', array('cursor' => $cursor));
        mb_assert_same(true, $data['ok'], 'batch ok: ' . $data['message']);
        mb_assert_same(false, $data['busy'], 'not busy');
        $pushed += $data['result']['pushed'];
        if ($data['done']) {
            break;
        }
        mb_assert(is_string($data['cursor']) && $data['cursor'] !== '', 'cursor to continue');
        $cursor = $data['cursor'];
    }
    mb_assert_same(true, $data['done'], 'finished');
    mb_assert_same(45, $pushed, 'all pushed');
    mb_assert(isset($data['status_html']) && strpos($data['status_html'], 'MomoBird 上有 45 篇') !== false, 'final status html');
    mb_assert(strpos($data['message'], '全量同步完成') !== false, 'done message: ' . $data['message']);
    mb_assert(max(array_map('count', array_map(function ($b) {
        return $b['posts'];
    }, $w->server->bodies('PUT /posts')))) <= 20, 'at most 20 per batch');
});

mb_test('admin page: a busy lock answers 正在同步 and keeps the cursor', function () {
    $w = mb_ap();
    $w->forum->addThread(5, 2);
    $w->store->locks['sync'] = true;

    $data = mb_ap_ajax($w, 'sync_batch', array('cursor' => ''));
    mb_assert_same(false, $data['done'], 'not done');
    mb_assert_same(true, $data['busy'], 'busy');
    mb_assert_same(null, $data['cursor'], 'a busy first call keeps the null cursor');
    mb_assert_same('正在同步，请稍后再试', $data['message'], 'busy message');

    $data = mb_ap_ajax($w, 'sync_batch', array('cursor' => 't:40'));
    mb_assert_same('t:40', $data['cursor'], 'a busy later call keeps its cursor');

    foreach (array('retry', 'confirm_withdraw') as $action) {
        $data = mb_ap_ajax($w, $action);
        mb_assert_same(false, $data['ok'], $action . ' not ok');
        mb_assert_same('正在同步，请稍后再试', $data['message'], $action . ' busy message');
    }
    mb_assert_same(0, count($w->server->transport->requests), 'no network while busy');
});

mb_test('admin page: an unknown cursor starts over instead of failing', function () {
    $w = mb_ap();
    $w->forum->addThread(5, 2);
    $data = mb_ap_ajax($w, 'sync_batch', array('cursor' => 'garbage<script>'));
    mb_assert_same(true, $data['ok'], 'treated as a start');
    mb_assert(strpos($data['_raw'], 'garbage') === false, 'the cursor is not echoed');
    mb_assert_same(array('5'), $w->server->pushedIds(), 'the sync started from the newest thread');
});

mb_test('admin page: retry and confirm withdraw report what they did', function () {
    $w = mb_ap();
    $w->forum->addThread(5, 2);
    $w->server->fail('PUT /posts', 503);
    mb_ap_ajax($w, 'sync_batch', array('cursor' => ''));
    mb_assert_same('failed', $w->store->state(5), 'failed first');

    $data = mb_ap_ajax($w, 'retry');
    mb_assert_same(true, $data['ok'], 'retry ok: ' . $data['message']);
    mb_assert_same(1, $data['result']['pushed'], 'pushed on retry');
    mb_assert(strpos($data['message'], '推送 1 篇') !== false, 'summary: ' . $data['message']);
    mb_assert_same('synced', $w->store->state(5), 'synced');

    $data = mb_ap_ajax($w, 'confirm_withdraw');
    mb_assert_same(true, $data['ok'], 'confirm ok: ' . $data['message']);
    mb_assert(strpos($data['message'], '已确认撤下：撤下 0 篇') === 0, 'confirm message: ' . $data['message']);
    mb_assert(isset($data['status_html']), 'status html');
});

mb_test('admin page: ajax errors are redacted', function () {
    $w = mb_ap();
    $w->page = new MomoBird_AdminPage($w->store, $w->forum, function () {
        throw new RuntimeException('SQL failed near ' . MB_AP_KEY . ' and mbk_otherKEY123456789012345');
    }, function () {
    }, $w->env);
    foreach (array('test', 'sync_batch', 'retry', 'confirm_withdraw') as $action) {
        $data = mb_ap_ajax($w, $action);
        mb_assert_same(false, $data['ok'], $action . ' failed');
        mb_assert(strpos($data['message'], '操作失败') !== false, $action . ' message: ' . $data['message']);
        mb_ap_no_key($data['_raw'], $action . ' answer');
        mb_assert(strpos($data['_raw'], 'mbk_') === false, $action . ' has no mbk_ text');
    }

    // Engine errors that reach the result are cleaned too.
    $w = mb_ap();
    $w->forum->addThread(5, 2);
    $w->server->transport = new MomoBird_FakeTransport(function () {
        return array('status' => 500, 'body' => '{"code":"ERR","message":"bad key ' . MB_AP_KEY . '"}');
    });
    mb_ap_build($w);
    $data = mb_ap_ajax($w, 'sync_batch', array('cursor' => ''));
    mb_ap_no_key($data['_raw'], 'sync answer');
});

// ---------------------------------------------------------------- status

mb_test('admin page: an address mismatch shows both addresses and where to fix them', function () {
    $w = mb_ap();
    $w->server->siteUrl = 'https://forum.example.org';
    mb_ap_ajax($w, 'test');
    $html = mb_ap_get($w);
    mb_assert(strpos($html, 'https://bbs.example.com/') !== false, 'forum address shown');
    mb_assert(strpos($html, 'https://forum.example.org') !== false, 'source address shown');
    mb_assert(strpos($html, '插件设置里改「论坛地址」') !== false, 'plugin setting named');
    mb_assert(strpos($html, 'Discuz! 后台「全局 → 站点信息」') !== false, 'Discuz! setting named');

    $w->server->siteUrl = 'https://bbs.example.com';
    mb_ap_ajax($w, 'test');
    $html = mb_ap_get($w);
    mb_assert(strpos($html, '全局 → 站点信息」改站点 URL') === false, 'no mismatch notice when same origin');
});

mb_test('admin page: the quota hint shows only when many posts are not indexed', function () {
    $hint = '部分主题还没有索引，可能是本月额度已用完，请到控制台『用量』查看';
    $cases = array(
        array(null, null, false),
        array(0, 0, false),
        array(100, 100, false),
        array(100, 80, false),   // exactly 20 % apart
        array(100, 79, true),
        array(10, 0, true),
    );
    foreach ($cases as $case) {
        $w = mb_ap();
        $source = array('name' => '论坛', 'kind' => 'discuz', 'site_url' => 'https://bbs.example.com');
        if ($case[0] !== null) {
            $source['document_count'] = $case[0];
            $source['ready_document_count'] = $case[1];
        }
        $w->store->set('connect_status', array('ok' => true, 'checked_at' => $w->now, 'project' => array('name' => 'P'), 'source' => $source,
            'entitlement' => array('status' => 'pro', 'active' => true), 'widget' => null));
        $html = mb_ap_get($w);
        mb_assert_same($case[2], strpos($html, $hint) !== false, 'hint for ' . json_encode($case));
        if ($case[2]) {
            mb_assert(strpos($html, '同步哪些版块') !== false, 'suggests narrowing the forums');
        }
    }
});

mb_test('admin page: before the first full sync the local count is shown', function () {
    $w = mb_ap();
    foreach (range(1, 7) as $tid) {
        $w->forum->addThread($tid, 2);
    }
    $html = mb_ap_get($w);
    mb_assert(strpos($html, '本地应同步 7 篇') !== false, 'local count');
    mb_assert(strpos($html, '立即全量同步') !== false, 'full sync button');
    mb_assert(strpos($html, '确认撤下') === false, 'no confirm button without a held guard');
});

mb_test('admin page: a held cleanup shows the confirm withdraw button', function () {
    $w = mb_ap();
    $w->store->set('guard', array('held' => true, 'reason' => 'guard', 'would_remove' => 30, 'local' => 0, 'remote' => 40, 'message' => '清理保护', 'at' => 1));
    $html = mb_ap_get($w);
    mb_assert(strpos($html, 'data-mb-action="confirm_withdraw"') !== false, 'confirm button');
    mb_assert(strpos($html, '30') !== false, 'count shown');
});

mb_test('admin page: threads missing from the forum held by the guard are explained', function () {
    $w = mb_ap();
    $w->store->set('gone_guard', array('held' => true, 'reason' => 'missing', 'would_remove' => 25, 'message' => 'x', 'at' => 1, 'confirmed' => false));
    $html = mb_ap_get($w);
    mb_assert(strpos($html, 'data-mb-action="confirm_withdraw"') !== false, 'confirm button');
    mb_assert(strpos($html, '论坛里读不到 25 篇') !== false, 'missing-thread text: ' . strip_tags($html));
});

mb_test('admin page: the failure list shows title, error code and time', function () {
    $w = mb_ap(array('formatTime' => function ($t) {
        return 'T' . $t;
    }));
    $w->store->rows[8] = array_merge(MomoBird_FakeStore::COLUMNS, array('tid' => 8, 'state' => 'rejected', 'title' => '<b>标题</b>', 'last_error' => 'VALIDATION_FAILED', 'updated_at' => 1234, 'attempts' => 1));
    mb_ap_build($w);
    $html = mb_ap_get($w);
    mb_assert(strpos($html, '&lt;b&gt;标题&lt;/b&gt;') !== false, 'title escaped');
    mb_assert(strpos($html, 'VALIDATION_FAILED') !== false, 'error code');
    mb_assert(strpos($html, 'T1234') !== false, 'time');
    mb_assert(strpos($html, 'data-mb-action="retry"') !== false, 'retry button');
});

mb_test('admin page: busy and halted states are explained', function () {
    $w = mb_ap();
    $w->store->set('halt', array('kind' => 'UNAUTHORIZED', 'message' => '连接密钥无效或已被撤销', 'at' => 1));
    $html = mb_ap_get($w);
    mb_assert(strpos($html, '同步已停止：连接密钥无效或已被撤销。') !== false, 'halt message intact');
});

// ---------------------------------------------------------------- forums

mb_test('admin page: the forum select lists forums and subs grouped, with hidden reasons', function () {
    $w = mb_ap();
    $w->forum->addGroup(10, '另一个分区');
    $w->forum->addForum(11, '密码版', 10, 'forum', array('password' => 'x'));
    $w->forum->addForum(12, '会员版', 10, 'forum', array('viewperm' => "\t1\t2\t"));
    $w->forum->addForum(13, '群组', 10, 'sub');
    $w->forum->forums[13]['status'] = 3;
    $w->forum->addForum(14, '隐藏版', 10);
    $w->forum->forums[14]['status'] = 0;
    $w->forum->addForum(15, '<i>跳转</i>', 10, 'forum', array('redirect' => 'https://x.example'));
    mb_ap_save($w, array('forumScope' => 'selected', 'forumIds' => array('3')));
    $html = mb_ap_get($w);

    mb_assert(preg_match('/<select[^>]*name="mb\[forumIds\]\[\]"[^>]*multiple[^>]*>(.*?)<\/select>/s', $html, $m) === 1, 'multi-select present');
    $select = $m[1];
    mb_assert(strpos($select, '<optgroup label="分区">') !== false, 'group 分区');
    mb_assert(strpos($select, '<optgroup label="另一个分区">') !== false, 'second group');
    mb_assert(strpos($select, 'value="1"') === false, 'the group itself is not an option');
    mb_assert(strpos($select, 'value="13"') === false, 'group (status 3) forums are not listed');
    // 汽车 (2) then its sub 新能源 (4) then 数码 (3), inside 分区.
    $p2 = strpos($select, 'value="2"');
    $p4 = strpos($select, 'value="4"');
    $p3 = strpos($select, 'value="3"');
    mb_assert($p2 !== false && $p4 !== false && $p3 !== false && $p2 < $p4 && $p4 < $p3, 'sub listed under its parent');
    mb_assert(preg_match('/<option value="3" selected[^>]*>/', $select) === 1, 'saved selection selected');
    mb_assert(preg_match('/<option value="11" disabled[^>]*>[^<]*访问密码/', $select) === 1, 'password reason');
    mb_assert(preg_match('/<option value="12" disabled[^>]*>[^<]*游客/', $select) === 1, 'viewperm reason');
    mb_assert(preg_match('/<option value="14" disabled[^>]*>[^<]*隐藏/', $select) === 1, 'hidden reason');
    mb_assert(preg_match('/<option value="15" disabled[^>]*>[^<]*跳转/', $select) === 1, 'redirect reason');
    mb_assert(strpos($select, '<i>') === false, 'names are escaped');
    mb_assert(preg_match('/name="mb\[forumScope\]" value="selected" checked/', $html) === 1, 'scope radio reflects the selection');
});

// ---------------------------------------------------------------- admin log

/** Discuz!'s writecplog(): implodearray() over GET and POST with its skip list (X3.5 and X5). */
function mb_ap_cplog(array $get, array $post): string
{
    $implode = function ($array, $skip) use (&$implode) {
        $return = '';
        foreach ($array as $key => $value) {
            if (!in_array($key, $skip, true)) {
                if (is_array($value)) {
                    $return .= "$key={" . $implode($value, $skip) . '}; ';
                } elseif (!empty($value)) {
                    $return .= "$key=$value; ";
                }
            }
        }
        return $return;
    };
    return $implode(array('GET' => $get, 'POST' => $post), array('formhash', 'submit', 'addsubmit', 'admin_password', 'sid', 'action'));
}

mb_test('admin page: the key never reaches Discuz!\'s admin log', function () {
    $w = mb_ap(array(), false);
    $html = mb_ap_get($w);
    // Fill every field of the rendered form; the password field gets the key.
    preg_match_all('/<(?:input|select)[^>]*name="(mb\[[^"]+\])"[^>]*>/', $html, $m, PREG_SET_ORDER);
    $post = array('formhash' => MB_AP_HASH, 'momobird_save' => '1');
    $keyFields = 0;
    foreach ($m as $field) {
        $value = strpos($field[0], 'type="password"') !== false ? MB_AP_NEW_KEY : 'x';
        $keyFields += $value === MB_AP_NEW_KEY ? 1 : 0;
        parse_str(rawurlencode($field[1]) . '=' . rawurlencode($value), $one);
        $post = array_replace_recursive($post, $one);
    }
    mb_assert_same(1, $keyFields, 'one key field');
    $get = array('action' => 'plugins', 'operation' => 'config', 'identifier' => 'momobird_ai', 'pmod' => 'admincp') + $post;
    $log = mb_ap_cplog($get, $post);
    mb_assert(strpos($log, 'MomoBird') === false && strpos($log, 'mbk_') === false, 'key in the admin log: ' . $log);
    mb_assert(strpos($log, 'momobird_save') !== false, 'the log itself works');

    // And that field is the one the page saves.
    $out = $w->page->handle('POST', $get);
    mb_assert(strpos($out['body'], '设置已保存') !== false || strpos($out['body'], '设置没有保存') !== false, 'handled');
    $w2 = mb_ap(array(), false);
    mb_ap_save($w2, array('admin_password' => MB_AP_NEW_KEY));
    mb_assert_same(array('key' => MB_AP_NEW_KEY), $w2->store->get('secret'), 'saved from admin_password');
    // A key sent under any other name is ignored, so no logged field ever carries one.
    $w3 = mb_ap(array(), false);
    $w3->page->handle('POST', array('formhash' => MB_AP_HASH, 'momobird_save' => '1', 'mb' => array('key' => MB_AP_NEW_KEY)));
    mb_assert_same(null, $w3->store->get('secret'), 'mb[key] is not read');
});

mb_test('admin page: actions get time before the engine runs, refusals do not', function () {
    $w = mb_ap(array('beforeRun' => function () use (&$w) {
        $w->prepared[] = $w->engineBuilt;
    }));
    $w->prepared = array();
    mb_ap_build($w);
    foreach (array('test', 'sync_batch', 'retry', 'confirm_withdraw') as $action) {
        mb_ap_ajax($w, $action);
    }
    mb_assert_same(4, count($w->prepared), 'once per action');
    mb_assert_same(0, $w->prepared[0], 'before the engine is built');
    $w->page->handle('POST', array('mb_action' => 'sync_batch', 'inajax' => '1'));
    mb_assert_same(4, count($w->prepared), 'not for a refused action');
    mb_ap_save($w, array('key' => MB_AP_NEW_KEY));
    mb_assert_same(5, count($w->prepared), 'also before the status check after a new key');
});

mb_test('admin page: a selected forum that guests cannot read stays selected', function () {
    $w = mb_ap();
    mb_ap_save($w, array('forumScope' => 'selected', 'forumIds' => array('2', '3')));
    $w->forum->fields[3]['password'] = 'secret';
    $html = mb_ap_get($w);
    mb_assert(preg_match('/<option value="3" disabled[^>]*>/', $html) === 1, 'shown disabled');
    mb_assert(strpos($html, '<input type="hidden" name="mb[forumIds][]" value="3" />') !== false, 'kept by a hidden field');
    mb_assert(strpos($html, 'name="mb[forumIds][]" value="2"') === false, 'no hidden field for a selectable forum');
    // An unrelated save that submits what the browser sends keeps forum 3.
    mb_ap_save($w, array('forumScope' => 'selected', 'forumIds' => array('2', '3'), 'timeout' => '12'));
    mb_assert_same(array(2, 3), $w->store->get('settings')['forumIds'], 'forum 3 kept');
});

mb_test('admin page: thread links in the failure list open safely', function () {
    $w = mb_ap();
    $w->store->rows[8] = array_merge(MomoBird_FakeStore::COLUMNS, array('tid' => 8, 'state' => 'failed', 'title' => 't', 'updated_at' => 5));
    mb_ap_build($w);
    mb_assert(strpos(mb_ap_get($w), 'target="_blank" rel="noopener noreferrer"') !== false, 'rel noopener');
});

// ---------------------------------------------------------------- charset

mb_test('admin page: a non-utf-8 site only sees the unsupported notice', function () {
    $w = mb_ap(array('supported' => false, 'charset' => 'gbk'));
    $out = $w->page->handle('GET', array());
    mb_assert_same('html', $out['type'], 'html');
    mb_assert(strpos($out['body'], '本插件只支持 UTF-8 编码的论坛') !== false, 'notice');
    foreach (array('<form', 'mb[admin_password]', 'data-mb-action', '连接状态', 'admin.js') as $text) {
        mb_assert(strpos($out['body'], $text) === false, 'no ' . $text);
    }
    $out = $w->page->handle('POST', array('momobird_save' => '1', 'formhash' => MB_AP_HASH, 'mb' => array('timeout' => '20')));
    mb_assert(strpos($out['body'], '本插件只支持 UTF-8 编码的论坛') !== false, 'save shows the notice');
    $data = mb_ap_ajax($w, 'test');
    mb_assert_same(false, $data['ok'], 'ajax refused');
    mb_assert(strpos($data['message'], '本插件只支持 UTF-8 编码的论坛') !== false, 'ajax notice');
    mb_assert_same(0, $w->saved, 'nothing saved');
    mb_assert_same(0, $w->engineBuilt, 'no engine');
    mb_assert_same(array(), $w->forum->reads, 'forum not read');
});

// ---------------------------------------------------------------- page wiring

mb_test('admin page: the page wires admin.js with the url and formhash', function () {
    $w = mb_ap();
    $html = mb_ap_get($w);
    mb_assert(strpos($html, 'id="momobird-admin"') !== false, 'root element');
    mb_assert(strpos($html, 'data-url="admin.php?action=plugins&amp;operation=config&amp;do=9&amp;identifier=momobird_ai&amp;pmod=admincp"') !== false, 'ajax url');
    mb_assert(strpos($html, 'data-formhash="' . MB_AP_HASH . '"') !== false, 'formhash for ajax');
    mb_assert(strpos($html, '<input type="hidden" name="formhash" value="' . MB_AP_HASH . '"') !== false, 'formhash in the form');
    mb_assert(strpos($html, 'src="source/plugin/momobird_ai/static/admin.js?v=0.1.0"') !== false, 'script');
    foreach (array('test', 'sync', 'retry') as $action) {
        mb_assert(strpos($html, 'data-mb-action="' . $action . '"') !== false, $action . ' button');
    }
    mb_assert(strpos($html, 'name="action"') === false, 'no field may shadow Discuz!\'s action parameter');
});

mb_test('admin page: admin.js and the admincp entry exist', function () {
    $dir = dirname(__DIR__) . '/momobird_ai';
    mb_assert(is_file($dir . '/static/admin.js'), 'admin.js');
    mb_assert(is_file($dir . '/static/index.htm'), 'static/index.htm');
    $entry = file_get_contents($dir . '/admincp.inc.php');
    mb_assert(strpos($entry, "defined('IN_ADMINCP')") !== false, 'admincp.inc.php checks IN_ADMINCP');
    $js = file_get_contents($dir . '/static/admin.js');
    foreach (array('sync_batch', 'confirm_withdraw', 'formhash', 'busy') as $text) {
        mb_assert(strpos($js, $text) !== false, 'admin.js mentions ' . $text);
    }
});
