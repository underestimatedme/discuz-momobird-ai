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
        'price' => 0, 'isgroup' => 0, 'special' => 0, 'dateline' => 1700000000,
    ), $override);
}

function mb_vis_post(array $override = array())
{
    return array_merge(array('pid' => 30, 'tid' => 12, 'first' => 1, 'invisible' => 0, 'status' => 0, 'message' => 'hello'), $override);
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
        $actual = MomoBird_Visibility::thread(mb_vis_thread($case[1]), mb_vis_post($case[2]), $guest);
        mb_assert_same($case[0], $actual, 'thread case: ' . $label);
    }
    mb_assert_same('missing_post', MomoBird_Visibility::thread(mb_vis_thread(), array(), $guest), 'empty first post passed');
    mb_assert_same('displayorder', MomoBird_Visibility::thread(array('tid' => 12, 'closed' => 0, 'readperm' => 0, 'price' => 0, 'isgroup' => 0), mb_vis_post(), $guest), 'missing displayorder must fail closed');
});

mb_test('visibility passes ordinary and sticky threads', function () {
    $guest = mb_vis_guest();
    mb_assert_same(null, MomoBird_Visibility::thread(mb_vis_thread(), mb_vis_post(), $guest), 'ordinary thread rejected');
    mb_assert_same(null, MomoBird_Visibility::thread(mb_vis_thread(array('displayorder' => 1)), mb_vis_post(), $guest), 'sticky thread rejected');
    mb_assert_same(null, MomoBird_Visibility::thread(mb_vis_thread(array('displayorder' => 3)), mb_vis_post(), $guest), 'global sticky rejected');
    mb_assert_same(null, MomoBird_Visibility::thread(mb_vis_thread(array('closed' => 1)), mb_vis_post(), $guest), 'closed thread is still readable');
    mb_assert_same(null, MomoBird_Visibility::thread(mb_vis_thread(array('readperm' => 1)), mb_vis_post(), $guest), 'readperm equal to readaccess rejected');
    mb_assert_same(null, MomoBird_Visibility::thread(mb_vis_thread(array('tid' => '12')), mb_vis_post(array('tid' => 12, 'first' => '1', 'invisible' => '0', 'status' => '0')), $guest), 'string columns from the database rejected');
    mb_assert_same(null, MomoBird_Visibility::thread(mb_vis_thread(), mb_vis_post(array('status' => 2)), $guest), 'warned (bit 2) post is still readable');
    mb_assert_same(null, MomoBird_Visibility::thread(mb_vis_thread(), mb_vis_post(array('message' => 'password reset tips [b]ok[/b]')), $guest), 'the word password is not a [password] tag');
});

mb_test('visibility reads the guest read access from the group row', function () {
    $thread = mb_vis_thread(array('readperm' => 5));
    mb_assert_same('readperm', MomoBird_Visibility::thread($thread, mb_vis_post(), mb_vis_guest(array('readaccess' => 1))), 'readperm 5 vs readaccess 1');
    mb_assert_same('readperm', MomoBird_Visibility::thread($thread, mb_vis_post(), mb_vis_guest(array('readaccess' => 4))), 'readperm 5 vs readaccess 4');
    mb_assert_same(null, MomoBird_Visibility::thread($thread, mb_vis_post(), mb_vis_guest(array('readaccess' => 5))), 'readperm 5 vs readaccess 5');
    mb_assert_same(null, MomoBird_Visibility::thread($thread, mb_vis_post(), mb_vis_guest(array('readaccess' => 200))), 'readperm 5 vs readaccess 200');
    mb_assert_same('readperm', MomoBird_Visibility::thread(mb_vis_thread(array('readperm' => 1)), mb_vis_post(), array('groupid' => 7)), 'missing readaccess must fail closed');
});
