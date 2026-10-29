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

mb_test('hooks: front-end moderation enqueues the moderated threads', function () {
    $move = array('action' => 'moderate', 'moderate' => array('2', '3'), 'operations' => array('move'), 'moveto' => '4');
    mb_assert_same(array(2, 3), MomoBird_Hooks::moderationTids('topicadmin', $move, 0), 'topicadmin move');
    mb_assert_same(array(6), MomoBird_Hooks::moderationTids('topicadmin', array('action' => 'banpost', 'topiclist' => array('60')), 6), 'banpost: current thread');
    mb_assert_same(array(2, 3, 6), MomoBird_Hooks::moderationTids('topicadmin', $move + array('tid' => 6), 6), 'moderate plus current thread');

    mb_assert_same(array(7, 8), MomoBird_Hooks::moderationTids('modcp', array('action' => 'moderate', 'op' => 'threads', 'moderate' => array('7', '8'), 'modact' => 'validate'), 0), 'modcp threads');
    mb_assert_same(array(7), MomoBird_Hooks::moderationTids('modcp', array('action' => 'moderate', 'moderate' => array('7')), 0), 'modcp default op');
    mb_assert_same(array(), MomoBird_Hooks::moderationTids('modcp', array('action' => 'moderate', 'op' => 'replies', 'moderate' => array('70')), 0), 'modcp replies are post ids');
    mb_assert_same(array(), MomoBird_Hooks::moderationTids('modcp', array('action' => 'moderate', 'op' => 'members', 'moderate' => array('5')), 0), 'modcp members are user ids');
    mb_assert_same(array(9), MomoBird_Hooks::moderationTids('modcp', array('action' => 'recyclebin', 'moderate' => array('9')), 0), 'modcp recycle bin');
    mb_assert_same(array(), MomoBird_Hooks::moderationTids('modcp', array('action' => 'member', 'moderate' => array('9')), 0), 'other modcp pages');
    mb_assert_same(array(), MomoBird_Hooks::moderationTids('topicadmin', array('moderate' => 'x'), 0), 'not a list');
});

mb_test('hooks: tids are positive, unique and capped', function () {
    $many = range(1, 2000);
    $tids = MomoBird_Hooks::moderationTids('topicadmin', array('moderate' => array_merge(array('-1', '0', 'abc', '1e3', ' 12 '), $many, $many)), 0);
    mb_assert_same(MomoBird_Hooks::MAX_TIDS, count($tids), 'capped');
    mb_assert(!in_array(0, $tids, true) && !in_array(-1, $tids, true), 'no zero or negative ids');
    mb_assert_same(count($tids), count(array_unique($tids)), 'unique');
});
