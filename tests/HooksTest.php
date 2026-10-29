<?php

function mb_hk_message(string $id, array $values = array('fid' => 2, 'tid' => 42, 'pid' => 420)): array
{
    return array('param' => array($id, 'forum.php?mod=viewthread&tid=42', $values, array(), 0));
}

mb_test('hooks: post messages that change a thread enqueue its tid', function () {
    foreach (array('post_newthread_succeed', 'post_newthread_mod_succeed', 'post_edit_succeed', 'edit_newthread_mod_succeed', 'post_edit_delete_succeed') as $id) {
        mb_assert_same(array(42), MomoBird_Hooks::messageTids(mb_hk_message($id)), $id);
    }
    foreach (array('post_reply_succeed', 'post_reply_mod_succeed', 'edit_reply_mod_succeed', 'comment_add_succeed', 'post_newthread_failed', '') as $id) {
        mb_assert_same(array(), MomoBird_Hooks::messageTids(mb_hk_message($id)), $id);
    }
    mb_assert_same(array(), MomoBird_Hooks::messageTids(mb_hk_message('post_newthread_succeed', array('tid' => 'x'))), 'bad tid');
    mb_assert_same(array(), MomoBird_Hooks::messageTids(mb_hk_message('post_newthread_succeed', array())), 'no tid');
    mb_assert_same(array(), MomoBird_Hooks::messageTids(array()), 'no param');
    mb_assert_same(array(), MomoBird_Hooks::messageTids(array('param' => 'post_newthread_succeed')), 'param not a list');
});

mb_test('hooks: thread deletion enqueues on the delete step only', function () {
    mb_assert_same(array(3, 4), MomoBird_Hooks::deleteThreadTids(array('step' => 'delete', 'param' => array(array(3, '4', 3), true, true, true))), 'delete step');
    mb_assert_same(array(), MomoBird_Hooks::deleteThreadTids(array('step' => 'check', 'param' => array(array(3), true, true))), 'check step');
    mb_assert_same(array(7), MomoBird_Hooks::deleteThreadTids(array('step' => 'delete', 'param' => array(7))), 'single id');
});

mb_test('hooks: post deletion enqueues only thread ids', function () {
    mb_assert_same(array(5), MomoBird_Hooks::deletePostTids(array('step' => 'delete', 'param' => array(array(5), 'tid', true, 0, false))), 'by tid');
    mb_assert_same(array(), MomoBird_Hooks::deletePostTids(array('step' => 'delete', 'param' => array(array(50), 'pid', true))), 'by pid');
    mb_assert_same(array(), MomoBird_Hooks::deletePostTids(array('step' => 'delete', 'param' => array(array(9), 'authorid', true))), 'by author');
    mb_assert_same(array(), MomoBird_Hooks::deletePostTids(array('step' => 'check', 'param' => array(array(5), 'tid', true))), 'check step');
});

mb_test('hooks: restored threads are enqueued', function () {
    mb_assert_same(array(8, 9), MomoBird_Hooks::undeleteTids(array('param' => array(array(8, 9)))), 'restore');
    mb_assert_same(array(), MomoBird_Hooks::undeleteTids(array('param' => array())), 'nothing');
});

/** moderationTids for a moderator after a success message. */
function mb_hk_mod(string $module, array $get, int $tid = 0, string $message = ''): array
{
    if ($message === '') {
        $message = $module === 'modcp' ? 'modcp_mod_succeed' : 'admin_succeed';
    }
    return MomoBird_Hooks::moderationTids($module, array('param' => array($message, '', array())), $get, $tid, true);
}

mb_test('hooks: front-end moderation enqueues the moderated threads', function () {
    $move = array('action' => 'moderate', 'moderate' => array('2', '3'), 'operations' => array('move'), 'moveto' => '4');
    mb_assert_same(array(2, 3), mb_hk_mod('topicadmin', $move), 'topicadmin move');
    mb_assert_same(array(6), mb_hk_mod('topicadmin', array('action' => 'banpost', 'topiclist' => array('60')), 6), 'banpost: current thread');
    mb_assert_same(array(2, 3, 6), mb_hk_mod('topicadmin', $move + array('tid' => 6), 6), 'moderate plus current thread');
    mb_assert_same(array(6), mb_hk_mod('topicadmin', array('action' => 'repair'), 6, 'admin_repair_succeed'), 'repair');

    mb_assert_same(array(7, 8), mb_hk_mod('modcp', array('action' => 'moderate', 'op' => 'threads', 'moderate' => array('7', '8'), 'modact' => 'validate')), 'modcp threads');
    mb_assert_same(array(7), mb_hk_mod('modcp', array('action' => 'moderate', 'moderate' => array('7'))), 'modcp default op');
    mb_assert_same(array(), mb_hk_mod('modcp', array('action' => 'moderate', 'op' => 'replies', 'moderate' => array('70'))), 'modcp replies are post ids');
    mb_assert_same(array(), mb_hk_mod('modcp', array('action' => 'moderate', 'op' => 'members', 'moderate' => array('5'))), 'modcp members are user ids');
    mb_assert_same(array(9), mb_hk_mod('modcp', array('action' => 'recyclebin', 'op' => 'restore', 'moderate' => array('9')), 0, 'modcp_recyclebin_restore_succeed'), 'modcp recycle bin restore');
    mb_assert_same(array(9), mb_hk_mod('modcp', array('action' => 'recyclebin', 'op' => 'delete', 'moderate' => array('9')), 0, 'modcp_recyclebin_delete_succeed'), 'modcp recycle bin delete');
    mb_assert_same(array(), mb_hk_mod('modcp', array('action' => 'member', 'moderate' => array('9'))), 'other modcp pages');
    mb_assert_same(array(), mb_hk_mod('topicadmin', array('moderate' => 'x')), 'not a list');
});

mb_test('hooks: moderation enqueues nothing after an error message or for a non-moderator', function () {
    $get = array('action' => 'moderate', 'moderate' => array('4294967295', '4294967294'), 'operations' => array('close'));
    foreach (array('admin_nopermission', 'thread_nonexistence', 'undefined_action', 'admin_move_have_mod', 'no_privilege_closethread', '') as $id) {
        mb_assert_same(array(), MomoBird_Hooks::moderationTids('topicadmin', array('param' => array($id, '', array())), $get, 5, true), 'topicadmin ' . $id);
    }
    foreach (array('modcp_noaction', 'modcp_moduser_invalid', 'forum_status_off', 'modcp_logout_succeed', 'admin_succeed') as $id) {
        mb_assert_same(array(), MomoBird_Hooks::moderationTids('modcp', array('param' => array($id, '', array())), $get, 0, true), 'modcp ' . $id);
    }
    mb_assert_same(array(), MomoBird_Hooks::moderationTids('modcp', array('param' => array('admin_succeed', '', array())), $get, 0, true), 'topicadmin id on modcp');
    mb_assert_same(array(), MomoBird_Hooks::moderationTids('topicadmin', array('param' => array('admin_succeed', '', array())), $get, 5, false), 'not a moderator');
    mb_assert_same(array(), MomoBird_Hooks::moderationTids('topicadmin', array(), $get, 5, true), 'no message');
});

mb_test('hooks: only ids taken from the request are capped', function () {
    $many = range(1, 2000);
    $tids = mb_hk_mod('topicadmin', array('moderate' => array_merge(array('-1', '0', 'abc', '1e3', ' 12 ', '99999999999'), $many, $many)));
    mb_assert_same(MomoBird_Hooks::MAX_REQUEST_TIDS, count($tids), 'request ids capped');
    mb_assert(!in_array(0, $tids, true) && !in_array(-1, $tids, true), 'no zero or negative ids');
    mb_assert_same(count($tids), count(array_unique($tids)), 'unique');

    mb_assert_same(2000, count(MomoBird_Hooks::deleteThreadTids(array('step' => 'delete', 'param' => array($many, true, true, true)))), 'deletethread keeps every tid');
    mb_assert_same(2000, count(MomoBird_Hooks::deletePostTids(array('step' => 'delete', 'param' => array($many, 'tid', true)))), 'deletepost keeps every tid');
    mb_assert_same(2000, count(MomoBird_Hooks::undeleteTids(array('param' => array($many)))), 'undeletethreads keeps every tid');
});

mb_test('hooks: draft publishing and appended text enqueue the thread', function () {
    mb_assert_same(array(31), MomoBird_Hooks::publishTids(array('step' => 'save', 'param' => array(31, false))), 'threadpubsave save step');
    mb_assert_same(array(), MomoBird_Hooks::publishTids(array('step' => 'check', 'param' => array(31, false))), 'threadpubsave check step');
    mb_assert_same(array(32), MomoBird_Hooks::miscTids(mb_hk_message('postappend_add_succeed', array('tid' => 32, 'pid' => 320))), 'postappend');
    mb_assert_same(array(), MomoBird_Hooks::miscTids(mb_hk_message('postappend_only_yourself', array('tid' => 32))), 'postappend refused');
});

mb_test('hooks: request ids that are not threads never create queue rows', function () {
    require_once __DIR__ . '/FakeStore.php';
    require_once __DIR__ . '/FakeForumSource.php';
    require_once __DIR__ . '/FakeTransport.php';
    $store = new MomoBird_FakeStore();
    $forum = new MomoBird_FakeForumSource();
    $forum->addGroup(1, 'G')->addForum(2, 'F')->addThread(10, 2);
    $store->savePost(array('tid' => 11, 'state' => 'synced', 'remote' => 1));
    $settings = MomoBird_Settings::fromArray(array('key' => 'mbk_DISCUZLOCALTESTKEYDISCUZLOCALTESTKEYDISC', 'forumUrl' => 'https://bbs.example.com/'));
    $engine = new MomoBird_SyncEngine($settings, new MomoBird_ConnectClient($settings, new MomoBird_FakeTransport()), $store, $forum, function () {
        return 1800000000;
    });

    MomoBird_Hooks::enqueueRequested($engine, $forum, array(10, 11, 4294967295), 'topicadmin');
    $rows = array_keys($store->rows);
    sort($rows);
    mb_assert_same(array(10, 11), $rows, 'no row for the made-up id');
    mb_assert(isset($store->rows[10]['queued_at']) && isset($store->rows[11]['queued_at']), 'existing thread and known row are queued');
});
