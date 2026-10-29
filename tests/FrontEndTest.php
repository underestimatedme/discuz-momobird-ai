<?php

require_once __DIR__ . '/FakeStore.php';
require_once __DIR__ . '/FakeForumSource.php';

const MB_FE_KEY = 'mbk_DISCUZLOCALTESTKEYDISCUZLOCALTESTKEYDISC';
const MB_FE_SOURCE = '0f8a1c2e-3b4d-4e5f-8a9b-0c1d2e3f4a5b';
const MB_FE_NOW = 1800000000;

/**
 * A forum (group 1; forums 2 汽车 with sub-forum 5 新能源, 3 a private forum, 4 a forum whose
 * name needs escaping), a cached connect status with a published assistant, and the front end.
 */
function mb_fe(array $settings = array(), ?array $status = null): stdClass
{
    $w = new stdClass();
    $w->store = new MomoBird_FakeStore();
    $w->forum = new MomoBird_FakeForumSource();
    $w->forum->addGroup(1, '分区')
        ->addForum(2, '汽车')
        ->addForum(3, '内部', 1, 'forum', array('viewperm' => "1\t2\t3"))
        ->addForum(4, 'Q&amp;A "问答" <b>区</b>')
        ->addForum(5, '新能源', 2, 'sub');
    $w->forum->addThread(10, 2)->addThread(11, 3)->addThread(12, 5)
        ->addThread(13, 2, null, array('displayorder' => -1));
    $w->store->set('connect_status', $status !== null ? $status : mb_fe_status());
    $w->settings = MomoBird_Settings::fromArray($settings + array(
        'baseUrl' => 'https://api.valley.atlaspaces.com',
        'key' => MB_FE_KEY,
        'forumUrl' => 'https://bbs.example.com/',
        'widgetEnabled' => true,
    ));
    $w->front = new MomoBird_FrontEnd($w->settings, new MomoBird_WidgetState($w->store), $w->forum);
    return $w;
}

function mb_fe_status(array $widget = array(), array $extra = array()): array
{
    return array_replace(array(
        'ok' => true,
        'checked_at' => MB_FE_NOW - 600,
        'project' => array('id' => 'p1', 'name' => '论坛项目'),
        'source' => array('id' => MB_FE_SOURCE, 'kind' => 'discuz', 'name' => '论坛', 'site_url' => 'https://bbs.example.com'),
        'entitlement' => array('status' => 'pro', 'active' => true),
        'widget' => array_replace(array('public_id' => 'pub_test', 'available' => true, 'reason' => 'ok'), $widget),
    ), $extra);
}

function mb_fe_loader(string $attributes): string
{
    return '<script defer src="https://momobird.atlaspaces.com/widget/loader.v1.js" data-project="pub_test"' . $attributes . '></script>';
}

mb_test('front end: the assistant loader on every page when switched on', function () {
    $w = mb_fe();
    mb_assert_same(mb_fe_loader(''), $w->front->footer('forum', 'index', 0, MB_FE_NOW), 'index page');
    mb_assert_same(mb_fe_loader(''), $w->front->footer('portal', 'index', 0, MB_FE_NOW), 'portal page');

    $left = mb_fe(array('widgetPosition' => 'left'));
    mb_assert_same(mb_fe_loader(' data-position="left"'), $left->front->footer('forum', 'index', 0, MB_FE_NOW), 'left position');
});

mb_test('front end: nothing when the assistant is switched off', function () {
    $w = mb_fe(array('widgetEnabled' => false));
    mb_assert_same('', $w->front->footer('forum', 'forumdisplay', 2, MB_FE_NOW), 'widget off');
});

mb_test('front end: nothing when the cached status says the assistant is unavailable', function () {
    $cases = array(
        'no cache' => array(),
        'not published' => mb_fe_status(array('available' => false, 'reason' => 'not_published')),
        'switched off in the console' => mb_fe_status(array('available' => false, 'reason' => 'disabled')),
        'plan expired' => mb_fe_status(array(), array('entitlement' => array('status' => 'expired', 'active' => false))),
        'stale cache' => mb_fe_status(array(), array('checked_at' => MB_FE_NOW - 3 * 86400)),
        'never checked' => mb_fe_status(array(), array('checked_at' => null)),
        'bad public id' => mb_fe_status(array('public_id' => 'pub"><script>')),
        'missing public id' => mb_fe_status(array('public_id' => '')),
        'no widget part' => mb_fe_status(array(), array('widget' => null)),
    );
    foreach ($cases as $name => $status) {
        $w = mb_fe(array(), $status);
        if ($name === 'no cache') {
            $w->store->set('connect_status', null);
        }
        mb_assert_same('', $w->front->footer('forum', 'forumdisplay', 2, MB_FE_NOW), $name);
    }
});

mb_test('front end: a failed status refresh keeps the last known assistant', function () {
    // The engine keeps the widget part on network errors and clears `available` itself on 401/402/409.
    $w = mb_fe(array(), mb_fe_status(array(), array('ok' => false, 'error_kind' => 'NETWORK')));
    mb_assert_same(mb_fe_loader(''), $w->front->footer('forum', 'index', 0, MB_FE_NOW), 'network blip');
});

mb_test('front end: forum and thread pages carry the escaped category', function () {
    $w = mb_fe();
    mb_assert_same(mb_fe_loader(' data-category="汽车"'), $w->front->footer('forum', 'forumdisplay', 2, MB_FE_NOW), 'forum page');
    mb_assert_same(mb_fe_loader(' data-category="汽车"'), $w->front->footer('forum', 'viewthread', 5, MB_FE_NOW), 'sub-forum takes its parent');
    $html = $w->front->footer('forum', 'forumdisplay', 4, MB_FE_NOW);
    mb_assert_same(mb_fe_loader(' data-category="Q&amp;A &quot;问答&quot; 区"'), $html, 'escaped category');
    $expected = MomoBird_Categories::build($w->forum->forums());
    mb_assert_same('Q&A "问答" 区', $expected[4], 'same function as the push');
});

mb_test('front end: no category outside the synced forums', function () {
    $w = mb_fe();
    mb_assert_same(mb_fe_loader(''), $w->front->footer('forum', 'forumdisplay', 3, MB_FE_NOW), 'guests cannot read forum 3');
    mb_assert_same(mb_fe_loader(''), $w->front->footer('forum', 'forumdisplay', 99, MB_FE_NOW), 'unknown forum');
    mb_assert_same(mb_fe_loader(''), $w->front->footer('forum', 'index', 2, MB_FE_NOW), 'not a forum or thread page');
    mb_assert_same(mb_fe_loader(''), $w->front->footer('group', 'forumdisplay', 2, MB_FE_NOW), 'group script');

    $selected = mb_fe(array('forumIds' => array(4)));
    mb_assert_same(mb_fe_loader(''), $selected->front->footer('forum', 'forumdisplay', 2, MB_FE_NOW), 'forum not selected');
    mb_assert_same(mb_fe_loader(' data-category="Q&amp;A &quot;问答&quot; 区"'), $selected->front->footer('forum', 'forumdisplay', 4, MB_FE_NOW), 'selected forum');

    $closed = mb_fe();
    $closed->forum->setting['bbclosed'] = 1;
    mb_assert_same(mb_fe_loader(''), $closed->front->footer('forum', 'forumdisplay', 2, MB_FE_NOW), 'site closed');
});

mb_test('front end: a forum read failure only drops the category', function () {
    $w = mb_fe();
    $w->forum->onRead = function () {
        throw new RuntimeException('database gone');
    };
    mb_assert_same(mb_fe_loader(''), $w->front->footer('forum', 'forumdisplay', 2, MB_FE_NOW), 'read failure');
});

mb_test('front end: podcast placeholder only under the first post of a synced thread', function () {
    $w = mb_fe(array('widgetEnabled' => false));
    $player = '<div class="momobird-podcast" data-source="' . MB_FE_SOURCE . '" data-post="10" data-api="https://api.valley.atlaspaces.com"></div>'
        . '<script defer src="https://momobird.atlaspaces.com/widget/podcast.v1.js"></script>';
    $postlist = array(
        100 => array('pid' => 100, 'tid' => 10, 'first' => 1),
        101 => array('pid' => 101, 'tid' => 10, 'first' => 0),
        102 => array('pid' => 102, 'tid' => 10, 'first' => 0),
    );
    mb_assert_same(array(0 => $player), $w->front->podcastSlots($postlist), 'first post');

    unset($postlist[100]);
    mb_assert_same(array(), $w->front->podcastSlots($postlist), 'page 2 has no first post');

    // Reverse order: the first post is still found by its flag, at its own index.
    $reverse = array(
        102 => array('pid' => 102, 'tid' => 10, 'first' => 0),
        100 => array('pid' => 100, 'tid' => 10, 'first' => '1'),
    );
    mb_assert_same(array(1 => $player), $w->front->podcastSlots($reverse), 'index of the first post');
    mb_assert_same('', $w->front->podcast(array('pid' => 101, 'tid' => 10, 'first' => 0)), 'reply');
});

mb_test('front end: no podcast outside the synced threads', function () {
    $w = mb_fe();
    mb_assert_same('', $w->front->podcast(array('tid' => 11, 'first' => 1)), 'private forum');
    mb_assert_same('', $w->front->podcast(array('tid' => 13, 'first' => 1)), 'thread in the recycle bin');
    mb_assert_same('', $w->front->podcast(array('tid' => 99, 'first' => 1)), 'unknown thread');
    mb_assert('' !== $w->front->podcast(array('tid' => 12, 'first' => 1)), 'sub-forum thread is synced');

    $selected = mb_fe(array('forumIds' => array(4)));
    mb_assert_same('', $selected->front->podcast(array('tid' => 10, 'first' => 1)), 'forum not selected');

    $off = mb_fe(array('podcastEnabled' => false));
    mb_assert_same('', $off->front->podcast(array('tid' => 10, 'first' => 1)), 'podcast switched off');

    $noSource = mb_fe(array(), mb_fe_status(array(), array('source' => array('id' => 'src-1'))));
    mb_assert_same('', $noSource->front->podcast(array('tid' => 10, 'first' => 1)), 'no valid source id');

    $broken = mb_fe();
    $broken->forum->onRead = function () {
        throw new RuntimeException('database gone');
    };
    mb_assert_same('', $broken->front->podcast(array('tid' => 10, 'first' => 1)), 'read failure');
});

mb_test('front end: podcast transcript switch', function () {
    $w = mb_fe(array('podcastTranscript' => true));
    $html = $w->front->podcast(array('tid' => 10, 'first' => 1));
    mb_assert(strpos($html, ' data-transcript="1"></div>') !== false, 'transcript attribute: ' . $html);
});

mb_test('front end: output never contains the key', function () {
    $w = mb_fe(array('podcastTranscript' => true));
    $html = $w->front->footer('forum', 'forumdisplay', 2, MB_FE_NOW)
        . $w->front->footer('forum', 'viewthread', 5, MB_FE_NOW)
        . implode('', $w->front->podcastSlots(array(array('tid' => 10, 'first' => 1))));
    mb_assert($html !== '' && strpos($html, 'data-category') !== false && strpos($html, 'momobird-podcast') !== false, 'output expected');
    mb_assert(strpos($html, MB_FE_KEY) === false, 'key in output');
    mb_assert(stripos($html, 'mbk_') === false, 'key-like string in output');
});
