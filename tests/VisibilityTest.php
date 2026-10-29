<?php

function mb_vis_guest(array $override = array())
{
    return array_merge(array('groupid' => 7, 'readaccess' => 1, 'allowvisit' => 1), $override);
}

function mb_vis_forum(array $override = array())
{
    return array_merge(array('fid' => 2, 'fup' => 1, 'type' => 'forum', 'name' => '综合', 'status' => 1), $override);
}

function mb_vis_field(array $override = array())
{
    return array_merge(array('fid' => 2, 'viewperm' => '', 'password' => '', 'formulaperm' => '', 'price' => 0, 'redirect' => ''), $override);
}

function mb_vis_thread(array $override = array())
{
    return array_merge(array(
        'tid' => 12, 'fid' => 2, 'displayorder' => 0, 'closed' => 0, 'readperm' => 0,
        'price' => 0, 'isgroup' => 0, 'digest' => 0, 'special' => 0, 'dateline' => 1700000000,
    ), $override);
}

function mb_vis_post(array $override = array())
{
    return array_merge(array('pid' => 30, 'tid' => 12, 'first' => 1, 'invisible' => 0, 'status' => 0, 'message' => 'hello',
        'authorid' => 5, 'username' => 'alice', 'groupid' => 10, 'memberstatus' => 0), $override);
}

function mb_vis_setting(array $override = array())
{
    return array_merge(array('bbclosed' => 0, 'bannedmessages' => 1), $override);
}

function mb_vis_check(array $thread, array $post, array $guest, $setting = null)
{
    return MomoBird_Visibility::thread($thread, $post, $guest, $setting === null ? mb_vis_setting() : $setting);
}

mb_test('visibility site: open site passes, closed or guest-denied does not', function () {
    mb_assert_same(null, MomoBird_Visibility::site(mb_vis_guest(), array('bbclosed' => 0)), 'open site rejected');
    mb_assert_same(null, MomoBird_Visibility::site(mb_vis_guest(), array('bbclosed' => '0')), 'string 0 means open');
    mb_assert_same('site_closed', MomoBird_Visibility::site(mb_vis_guest(), array('bbclosed' => 1)), 'closed site passed');
    mb_assert_same('site_closed', MomoBird_Visibility::site(mb_vis_guest(), array('bbclosed' => '1')), 'closed site (string) passed');
    mb_assert_same('site_closed', MomoBird_Visibility::site(mb_vis_guest(), array()), 'unknown closed state must fail closed');
    mb_assert_same('guest_denied', MomoBird_Visibility::site(mb_vis_guest(array('allowvisit' => 0)), array('bbclosed' => 0)), 'guest allowvisit=0 passed');
    mb_assert_same('guest_denied', MomoBird_Visibility::site(mb_vis_guest(array('allowvisit' => '0')), array('bbclosed' => 0)), 'guest allowvisit="0" passed');
    mb_assert_same('guest_denied', MomoBird_Visibility::site(array('groupid' => 7, 'readaccess' => 1), array('bbclosed' => 0)), 'missing allowvisit must fail closed');
});

mb_test('visibility forum: ordinary forum passes, selection is honoured', function () {
    mb_assert_same(null, MomoBird_Visibility::forum(mb_vis_forum(), mb_vis_field(), mb_vis_guest(), null), 'plain forum rejected');
    mb_assert_same(null, MomoBird_Visibility::forum(mb_vis_forum(array('type' => 'sub')), mb_vis_field(), mb_vis_guest(), null), 'plain sub-forum rejected');
    mb_assert_same(null, MomoBird_Visibility::forum(mb_vis_forum(array('fid' => '2')), mb_vis_field(), mb_vis_guest(), array(2, 5)), 'selected forum rejected');
    mb_assert_same('not_selected', MomoBird_Visibility::forum(mb_vis_forum(), mb_vis_field(), mb_vis_guest(), array(5)), 'unselected forum passed');
    mb_assert_same('not_selected', MomoBird_Visibility::forum(mb_vis_forum(), mb_vis_field(), mb_vis_guest(), array()), 'empty selection must select nothing');
});

mb_test('visibility forum rejects every guest-hidden forum', function () {
    $cases = array(
        'not_forum' => array(array('type' => 'group'), array()),
        'hidden' => array(array('status' => 0), array()),
        'group' => array(array('status' => 3), array()),
        'password' => array(array(), array('password' => 'secret')),
        'formula' => array(array(), array('formulaperm' => 'a:1:{i:0;s:3:"x>1";}')),
        'priced' => array(array(), array('price' => 5)),
        'redirect' => array(array(), array('redirect' => 'https://example.com/')),
        'viewperm' => array(array(), array('viewperm' => "1\t2\t3\t10")),
        'missing_forumfield' => array(array(), array('viewperm' => null, 'password' => null, 'formulaperm' => null, 'price' => null, 'redirect' => null)),
    );
    foreach ($cases as $code => $case) {
        $actual = MomoBird_Visibility::forum(mb_vis_forum($case[0]), mb_vis_field($case[1]), mb_vis_guest(), null);
        mb_assert_same($code, $actual, 'forum case ' . $code);
    }
    // Unknown types and statuses fail closed.
    mb_assert_same('not_forum', MomoBird_Visibility::forum(mb_vis_forum(array('type' => 'weird')), mb_vis_field(), mb_vis_guest(), null), 'unknown type passed');
    mb_assert_same('hidden', MomoBird_Visibility::forum(mb_vis_forum(array('status' => 2)), mb_vis_field(), mb_vis_guest(), null), 'status 2 passed');
    mb_assert_same('hidden', MomoBird_Visibility::forum(mb_vis_forum(array('status' => '0')), mb_vis_field(), mb_vis_guest(), null), 'status "0" passed');
    mb_assert_same('hidden', MomoBird_Visibility::forum(array('fid' => 2, 'type' => 'forum'), mb_vis_field(), mb_vis_guest(), null), 'missing status passed');
});

mb_test('visibility forum: password, formula and price edge values', function () {
    $guest = mb_vis_guest();
    mb_assert_same('password', MomoBird_Visibility::forum(mb_vis_forum(), mb_vis_field(array('password' => '0')), $guest, null), 'password "0" is still a password');
    mb_assert_same('password', MomoBird_Visibility::forum(mb_vis_forum(), mb_vis_field(array('password' => ' ')), $guest, null), 'blank-but-present password is still a password');
    mb_assert_same(null, MomoBird_Visibility::forum(mb_vis_forum(), mb_vis_field(array('formulaperm' => 'a:0:{}')), $guest, null), 'serialized empty array means no formula');
    mb_assert_same(null, MomoBird_Visibility::forum(mb_vis_forum(), mb_vis_field(array('formulaperm' => ' a:0:{} ')), $guest, null), 'padded empty array means no formula');
    mb_assert_same('formula', MomoBird_Visibility::forum(mb_vis_forum(), mb_vis_field(array('formulaperm' => '0')), $guest, null), 'garbage formula must fail closed');
    mb_assert_same('formula', MomoBird_Visibility::forum(mb_vis_forum(), mb_vis_field(array('formulaperm' => 'a:1:{i:0;i:1;}')), $guest, null), 'non-empty array is a formula');
    mb_assert_same(null, MomoBird_Visibility::forum(mb_vis_forum(), mb_vis_field(array('price' => '0')), $guest, null), 'price "0" means free');
    mb_assert_same(null, MomoBird_Visibility::forum(mb_vis_forum(), mb_vis_field(array('price' => '')), $guest, null), 'price "" means free');
    mb_assert_same('priced', MomoBird_Visibility::forum(mb_vis_forum(), mb_vis_field(array('price' => '12')), $guest, null), 'price "12" passed');
    mb_assert_same('priced', MomoBird_Visibility::forum(mb_vis_forum(), mb_vis_field(array('price' => 'abc')), $guest, null), 'non-numeric price must fail closed');
});

mb_test('visibility forum: view permission rules', function () {
    // Empty viewperm falls back to the group rule: guest readaccess 0 denies.
    mb_assert_same('viewperm', MomoBird_Visibility::forum(mb_vis_forum(), mb_vis_field(), mb_vis_guest(array('readaccess' => 0)), null), 'empty viewperm with readaccess 0 passed');
    mb_assert_same('viewperm', MomoBird_Visibility::forum(mb_vis_forum(), mb_vis_field(), array('groupid' => 7, 'allowvisit' => 1), null), 'missing readaccess must fail closed');
    mb_assert_same(null, MomoBird_Visibility::forum(mb_vis_forum(), mb_vis_field(), mb_vis_guest(array('readaccess' => 1)), null), 'empty viewperm with readaccess 1 rejected');
    // Listing the guest group passes, even if the group readaccess is 0.
    mb_assert_same(null, MomoBird_Visibility::forum(mb_vis_forum(), mb_vis_field(array('viewperm' => "7\t10")), mb_vis_guest(array('readaccess' => 0)), null), 'viewperm with 7 rejected');
    mb_assert_same(null, MomoBird_Visibility::forum(mb_vis_forum(), mb_vis_field(array('viewperm' => "10\t7\t")), mb_vis_guest(), null), 'trailing tab confused the parser');
    // 17 and 70 are not 7.
    mb_assert_same('viewperm', MomoBird_Visibility::forum(mb_vis_forum(), mb_vis_field(array('viewperm' => "17\t70\t77")), mb_vis_guest(), null), '17/70 matched group 7');
    // The guest group id comes from the row, not a constant.
    mb_assert_same(null, MomoBird_Visibility::forum(mb_vis_forum(), mb_vis_field(array('viewperm' => "9")), mb_vis_guest(array('groupid' => 9)), null), 'custom guest group id ignored');
    mb_assert_same('viewperm', MomoBird_Visibility::forum(mb_vis_forum(), mb_vis_field(array('viewperm' => "7")), mb_vis_guest(array('groupid' => 9)), null), 'hard-coded 7');
});

mb_test('visibility rejects every guest-hidden thread', function () {
    $guest = mb_vis_guest();
    $cases = array(
        'recycle bin' => array('displayorder', array('displayorder' => -1), array()),
        'awaiting moderation' => array('displayorder', array('displayorder' => -2), array()),
        'moderation ignored' => array('displayorder', array('displayorder' => -3), array()),
        'draft' => array('displayorder', array('displayorder' => -4), array()),
        'string displayorder' => array('displayorder', array('displayorder' => '-2'), array()),
        'moved placeholder' => array('moved', array('closed' => 44), array()),
        'moved placeholder (string)' => array('moved', array('closed' => '5'), array()),
        'read permission' => array('readperm', array('readperm' => 5), array()),
        'read permission (string)' => array('readperm', array('readperm' => '2'), array()),
        'paid thread' => array('price', array('price' => 3), array()),
        'reward thread (negative price)' => array('price', array('price' => -10, 'special' => 3), array()),
        'paid thread (string)' => array('price', array('price' => '3'), array()),
        'group thread' => array('isgroup', array('isgroup' => 1), array()),
        'post in moderation' => array('post_invisible', array(), array('invisible' => -2)),
        'thread in recycle bin (post flag)' => array('post_invisible', array(), array('invisible' => -1)),
        'draft reply flag' => array('post_invisible', array(), array('invisible' => -3)),
        'banned post' => array('post_banned', array(), array('status' => 1)),
        'banned and warned post' => array('post_banned', array(), array('status' => 3)),
        'banned post (string)' => array('post_banned', array(), array('status' => '1')),
        'password post' => array('password_post', array(), array('message' => 'intro [password]abc[/password] text')),
        'password post (uppercase)' => array('password_post', array(), array('message' => '[PASSWORD]abc[/PASSWORD]')),
        'password post (X5 json)' => array('password_post', array(), array('content' => '{"content":{"blocks":[{"type":"paragraph","data":{"text":"[password]x[/password]"}}]}}')),
        'not the first post' => array('missing_post', array(), array('first' => 0)),
        'post of another thread' => array('missing_post', array(), array('tid' => 99)),
    );
    foreach ($cases as $label => $case) {
        $actual = mb_vis_check(mb_vis_thread($case[1]), mb_vis_post($case[2]), $guest);
        mb_assert_same($case[0], $actual, 'thread case: ' . $label);
    }
    mb_assert_same('missing_post', mb_vis_check(mb_vis_thread(), array(), $guest), 'empty first post passed');
    mb_assert_same('displayorder', mb_vis_check(array('tid' => 12, 'closed' => 0, 'readperm' => 0, 'price' => 0, 'isgroup' => 0), mb_vis_post(), $guest), 'missing displayorder must fail closed');
});

mb_test('visibility passes ordinary and sticky threads', function () {
    $guest = mb_vis_guest();
    mb_assert_same(null, mb_vis_check(mb_vis_thread(), mb_vis_post(), $guest), 'ordinary thread rejected');
    mb_assert_same(null, mb_vis_check(mb_vis_thread(array('displayorder' => 1)), mb_vis_post(), $guest), 'sticky thread rejected');
    mb_assert_same(null, mb_vis_check(mb_vis_thread(array('displayorder' => 3)), mb_vis_post(), $guest), 'global sticky rejected');
    mb_assert_same(null, mb_vis_check(mb_vis_thread(array('closed' => 1)), mb_vis_post(), $guest), 'closed thread is still readable');
    mb_assert_same(null, mb_vis_check(mb_vis_thread(array('readperm' => 1)), mb_vis_post(), $guest), 'readperm equal to readaccess rejected');
    mb_assert_same(null, mb_vis_check(mb_vis_thread(array('tid' => '12')), mb_vis_post(array('tid' => 12, 'first' => '1', 'invisible' => '0', 'status' => '0')), $guest), 'string columns from the database rejected');
    mb_assert_same(null, mb_vis_check(mb_vis_thread(), mb_vis_post(array('status' => 2)), $guest), 'warned (bit 2) post is still readable');
    mb_assert_same(null, mb_vis_check(mb_vis_thread(), mb_vis_post(array('message' => 'password reset tips [b]ok[/b]')), $guest), 'the word password is not a [password] tag');
});

mb_test('visibility reads the guest read access from the group row', function () {
    $thread = mb_vis_thread(array('readperm' => 5));
    mb_assert_same('readperm', mb_vis_check($thread, mb_vis_post(), mb_vis_guest(array('readaccess' => 1))), 'readperm 5 vs readaccess 1');
    mb_assert_same('readperm', mb_vis_check($thread, mb_vis_post(), mb_vis_guest(array('readaccess' => 4))), 'readperm 5 vs readaccess 4');
    mb_assert_same(null, mb_vis_check($thread, mb_vis_post(), mb_vis_guest(array('readaccess' => 5))), 'readperm 5 vs readaccess 5');
    mb_assert_same(null, mb_vis_check($thread, mb_vis_post(), mb_vis_guest(array('readaccess' => 200))), 'readperm 5 vs readaccess 200');
    mb_assert_same('readperm', mb_vis_check(mb_vis_thread(array('readperm' => 1)), mb_vis_post(), array('groupid' => 7)), 'missing readaccess must fail closed');
});

mb_test('visibility forum: a missing forumfield row never passes', function () {
    $guest = mb_vis_guest();
    mb_assert_same('missing_forumfield', MomoBird_Visibility::forum(mb_vis_forum(), array(), $guest, null), 'empty forumfield passed');
    mb_assert_same('missing_forumfield', MomoBird_Visibility::forum(mb_vis_forum(), array('fid' => null, 'viewperm' => null, 'password' => null, 'formulaperm' => null, 'price' => null, 'redirect' => null), $guest, null), 'LEFT JOIN without a row passed');
    foreach (array('viewperm', 'password', 'formulaperm', 'price', 'redirect') as $column) {
        $field = mb_vis_field();
        $field[$column] = null;
        mb_assert_same('missing_forumfield', MomoBird_Visibility::forum(mb_vis_forum(), $field, $guest, null), 'null ' . $column . ' passed');
        $field = mb_vis_field();
        unset($field[$column]);
        mb_assert_same('missing_forumfield', MomoBird_Visibility::forum(mb_vis_forum(), $field, $guest, null), 'absent ' . $column . ' passed');
    }
});

mb_test('visibility thread: first post tid must be present and match', function () {
    $guest = mb_vis_guest();
    $post = mb_vis_post();
    unset($post['tid']);
    mb_assert_same('missing_post', mb_vis_check(mb_vis_thread(), $post, $guest), 'post without tid passed');
    $thread = mb_vis_thread();
    unset($thread['tid']);
    mb_assert_same('missing_post', mb_vis_check($thread, mb_vis_post(), $guest), 'thread without tid passed');
    mb_assert_same('missing_post', mb_vis_check(mb_vis_thread(), mb_vis_post(array('tid' => null)), $guest), 'null post tid passed');
    mb_assert_same('missing_post', mb_vis_check(mb_vis_thread(array('tid' => 12)), mb_vis_post(array('tid' => '13')), $guest), 'mismatch passed');
});

mb_test('visibility thread: posts Discuz blanks for guests because of the author never sync', function () {
    $guest = mb_vis_guest();
    $thread = mb_vis_thread();
    $cases = array(
        'deleted author (null username)' => array('username' => null, 'groupid' => null, 'memberstatus' => null),
        'deleted author (empty username)' => array('username' => '', 'groupid' => null, 'memberstatus' => null),
        'banned user group 4' => array('groupid' => 4),
        'banned user group 5' => array('groupid' => '5'),
        'member status -1' => array('memberstatus' => -1),
        'member status "-1"' => array('memberstatus' => '-1'),
    );
    foreach ($cases as $label => $override) {
        mb_assert_same('author_banned', mb_vis_check($thread, mb_vis_post($override), $guest), $label);
        mb_assert_same('author_banned', mb_vis_check($thread, mb_vis_post($override), $guest, mb_vis_setting(array('bannedmessages' => 3))), $label . ' with bit 1 and bit 2');
        mb_assert_same(null, mb_vis_check($thread, mb_vis_post($override), $guest, mb_vis_setting(array('bannedmessages' => 0))), $label . ' with bannedmessages 0');
        mb_assert_same(null, mb_vis_check($thread, mb_vis_post($override), $guest, mb_vis_setting(array('bannedmessages' => 2))), $label . ' with only bit 2');
    }
});

mb_test('visibility thread: banned-author rule details', function () {
    $guest = mb_vis_guest();
    // Group 4/5 and member status are ignored for digest threads (the template says digest == 0), a deleted author is not.
    mb_assert_same(null, mb_vis_check(mb_vis_thread(array('digest' => 1)), mb_vis_post(array('groupid' => 4)), $guest), 'digest thread by banned group');
    mb_assert_same(null, mb_vis_check(mb_vis_thread(array('digest' => 2)), mb_vis_post(array('memberstatus' => -1)), $guest), 'digest thread by status -1');
    mb_assert_same('author_banned', mb_vis_check(mb_vis_thread(array('digest' => 3)), mb_vis_post(array('username' => null)), $guest), 'digest thread by deleted author');
    // A guest post (authorid 0, no member row) is fine.
    mb_assert_same(null, mb_vis_check(mb_vis_thread(), mb_vis_post(array('authorid' => 0, 'username' => null, 'groupid' => null, 'memberstatus' => null)), $guest), 'anonymous post');
    mb_assert_same(null, mb_vis_check(mb_vis_thread(), mb_vis_post(array('authorid' => '0', 'username' => '', 'groupid' => 7, 'memberstatus' => null)), $guest), 'anonymous post (strings)');
    // Other groups and member states pass.
    mb_assert_same(null, mb_vis_check(mb_vis_thread(), mb_vis_post(array('groupid' => 6, 'memberstatus' => 1)), $guest), 'ordinary member');
    mb_assert_same(null, mb_vis_check(mb_vis_thread(), mb_vis_post(array('groupid' => 40, 'memberstatus' => 0)), $guest), 'group 40 is not group 4');
    // Fail closed: unknown setting, missing author columns.
    mb_assert_same('author_banned', MomoBird_Visibility::thread($thread = mb_vis_thread(), mb_vis_post(array('groupid' => 4)), $guest, array()), 'missing bannedmessages counts as set');
    foreach (array('authorid', 'username', 'groupid', 'memberstatus') as $column) {
        $post = mb_vis_post();
        unset($post[$column]);
        mb_assert_same('author_banned', mb_vis_check(mb_vis_thread(), $post, $guest), 'absent ' . $column);
    }
    $thread = mb_vis_thread();
    unset($thread['digest']);
    mb_assert_same('author_banned', mb_vis_check($thread, mb_vis_post(array('groupid' => 4)), $guest), 'absent digest counts as 0');
    // With the setting off the author columns are not needed.
    $bare = mb_vis_post();
    unset($bare['authorid'], $bare['username'], $bare['groupid'], $bare['memberstatus']);
    mb_assert_same(null, mb_vis_check(mb_vis_thread(), $bare, $guest, mb_vis_setting(array('bannedmessages' => 0))), 'author columns not needed when the setting is off');
});

mb_test('visibility exports the columns the adapter must select, and a full row passes', function () {
    foreach (array('THREAD_COLUMNS', 'POST_COLUMNS', 'FORUM_COLUMNS', 'FORUMFIELD_COLUMNS', 'GUEST_GROUP_COLUMNS', 'SETTING_KEYS') as $name) {
        mb_assert(defined('MomoBird_Visibility::' . $name) && is_array(constant('MomoBird_Visibility::' . $name)), $name . ' missing');
    }
    $row = function (array $columns) {
        return array_fill_keys($columns, 1);
    };
    $thread = array_merge($row(MomoBird_Visibility::THREAD_COLUMNS), array('displayorder' => 0, 'closed' => 0, 'readperm' => 0, 'price' => 0, 'isgroup' => 0, 'digest' => 0, 'tid' => 12));
    $post = array_merge($row(MomoBird_Visibility::POST_COLUMNS), array('tid' => 12, 'first' => 1, 'invisible' => 0, 'status' => 0, 'message' => 'x', 'authorid' => 5, 'username' => 'a', 'groupid' => 10, 'memberstatus' => 0));
    mb_assert_same(null, MomoBird_Visibility::thread($thread, $post, mb_vis_guest(), array('bbclosed' => 0, 'bannedmessages' => 1)), 'a row with exactly the exported columns must pass');
    $forum = array_merge($row(MomoBird_Visibility::FORUM_COLUMNS), array('type' => 'forum', 'status' => 1, 'name' => 'x'));
    $field = array_merge($row(MomoBird_Visibility::FORUMFIELD_COLUMNS), array('viewperm' => '', 'password' => '', 'formulaperm' => '', 'price' => 0, 'redirect' => ''));
    mb_assert_same(null, MomoBird_Visibility::forum($forum, $field, mb_vis_guest(), null), 'forum with exactly the exported columns must pass');
    mb_assert_same(null, MomoBird_Visibility::site($row(MomoBird_Visibility::GUEST_GROUP_COLUMNS), array('bbclosed' => 0)), 'guest group with exactly the exported columns must pass');
});
