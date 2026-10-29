<?php

mb_test('thread url without rewrite uses forum.php', function () {
    mb_assert_same('https://bbs.example.com/forum.php?mod=viewthread&tid=12', MomoBird_ThreadUrl::build('https://bbs.example.com/', 12, null), 'plain url');
    mb_assert_same('https://bbs.example.com/forum.php?mod=viewthread&tid=12', MomoBird_ThreadUrl::build('https://bbs.example.com', 12, null), 'slash appended');
    mb_assert_same('https://bbs.example.com/bbs/forum.php?mod=viewthread&tid=12', MomoBird_ThreadUrl::build(' https://bbs.example.com/bbs ', 12, null), 'subdirectory, padded');
    mb_assert_same('https://bbs.example.com/forum.php?mod=viewthread&tid=12', MomoBird_ThreadUrl::build('https://bbs.example.com/', 12, ''), 'empty rule is no rule');
    mb_assert_same('https://bbs.example.com/forum.php?mod=viewthread&tid=12', MomoBird_ThreadUrl::build('https://bbs.example.com/', 12, '  '), 'blank rule is no rule');
});

mb_test('thread url with rewrite fills the rule placeholders', function () {
    mb_assert_same('https://bbs.example.com/thread-12-1-1.html', MomoBird_ThreadUrl::build('https://bbs.example.com/', 12, 'thread-{tid}-{page}-{prevpage}.html'), 'default rule');
    mb_assert_same('https://bbs.example.com/bbs/t12/', MomoBird_ThreadUrl::build('https://bbs.example.com/bbs', 12, 't{tid}/'), 'custom rule');
    mb_assert_same('https://bbs.example.com/thread-12-1-1-.html', MomoBird_ThreadUrl::build('https://bbs.example.com/', 12, 'thread-{tid}-{page}-{prevpage}-{fid}.html'), 'unknown placeholders become empty');
    mb_assert_same('https://bbs.example.com/12-12.html', MomoBird_ThreadUrl::build('https://bbs.example.com/', 12, '{tid}-{tid}.html'), 'repeated placeholder');
    mb_assert_same('https://bbs.example.com/thread-12-1-1.html', MomoBird_ThreadUrl::build('https://bbs.example.com/', 12, '/thread-{tid}-{page}-{prevpage}.html'), 'leading slash in the rule');
});
