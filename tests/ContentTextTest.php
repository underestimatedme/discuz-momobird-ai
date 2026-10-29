<?php

function mb_bb($message)
{
    return MomoBird_ContentText::fromBbcode($message);
}

mb_test('hide tags are removed in every form', function () {
    foreach (array('[hide]回复可见[/hide]', '[hide=10]积分可见[/hide]', '[hide=d30]天数可见[/hide]', '[hide=d30,50]天数积分可见[/hide]', '[HIDE]大写[/HIDE]') as $hidden) {
        mb_assert_same('前后', mb_bb('前' . $hidden . '后'), 'hide not removed: ' . $hidden);
    }
});

mb_test('nested and unclosed hide never leak', function () {
    mb_assert_same('A', mb_bb('A[hide]x[hide]y[/hide]z[/hide]'), 'nested hide leaked the tail');
    mb_assert_same('A', mb_bb('A[hide]机密，没有结束标签'), 'unclosed hide leaked');
    mb_assert_same('A', mb_bb("A[hide=5]a\n\nb[/hide]"), 'multi-line hide leaked');
    mb_assert_same('A', mb_bb('A[hide][quote]q[/quote]p[/hide]'), 'hide with quote inside leaked');
});

mb_test('quote is removed whole, including nested quotes', function () {
    mb_assert_same('回复', mb_bb('[quote][size=2][color=#999999]张三 发表于 2026-10-01[/color][/size]引用的话[/quote]回复'), 'quote not removed');
    mb_assert_same('回复', mb_bb('[quote]a[quote]b[/quote]c[/quote]回复'), 'nested quote leaked');
});

mb_test('attachments, media and payto are removed whole', function () {
    foreach (array('attach' => '[attach]123[/attach]', 'attachimg' => '[attachimg]456[/attachimg]', 'img' => '[img=100,50]http://a/b.png[/img]', 'media' => '[media=x,400,300]http://v/1.mp4[/media]', 'audio' => '[audio]http://a/1.mp3[/audio]', 'flash' => '[flash=200,100]http://f/1.swf[/flash]', 'swf' => '[swf]http://f/2.swf[/swf]', 'payto' => '[payto=1]pay@example.com[/payto]') as $tag => $markup) {
        mb_assert_same('前后', mb_bb('前' . $markup . '后'), $tag . ' not removed');
    }
});

mb_test('url keeps its label, bare url keeps the address', function () {
    mb_assert_same('看这里 now', mb_bb('[url=https://example.com/a]看这里[/url] now'), 'url label lost');
    mb_assert_same('见 https://example.com/b', mb_bb('见 [url]https://example.com/b[/url]'), 'bare url lost');
});

mb_test('code keeps its text', function () {
    mb_assert_same("a\necho 1;\nb", mb_bb("a\n[code]echo 1;[/code]\nb"), 'code text lost');
    mb_assert_same('x = 1', mb_bb('[code=php]x = 1[/code]'), 'code with language lost');
});

mb_test('table cells are space separated and rows are lines', function () {
    $out = mb_bb('[table=100%][tr][td]姓名[/td][td]年龄[/td][/tr][tr][td=2,1]张三[/td][td]20[/td][/tr][/table]');
    mb_assert_same("姓名 年龄\n张三 20", $out, 'table layout wrong');
});

mb_test('lists become dash lines', function () {
    mb_assert_same("清单：\n- 一\n- 二\n- 三", mb_bb("清单：\n[list]\n[*]一\n[*]二\n[*]三\n[/list]"), 'unordered list wrong');
    mb_assert_same("- 甲\n- 乙", mb_bb('[list=1][*]甲[*]乙[/list]'), 'ordered list wrong');
});

mb_test('formatting tags keep only their text', function () {
    $in = '[b]粗[/b][i]斜[/i][u]下[/u][s]删[/s][color=red]红[/color][backcolor=#ff0]底[/backcolor][size=5]大[/size][font=宋体]字[/font][align=center]中[/align][indent]缩[/indent][float=left]浮[/float][p=30, 2, left]段[/p]';
    mb_assert_same('粗斜下删红底大字中缩浮段', mb_bb($in), 'formatting tags leaked');
});

mb_test('special thread data after three NULs is cut', function () {
    mb_assert_same('投票主题', mb_bb("投票主题" . chr(0) . chr(0) . chr(0) . "a:2:{s:3:\"x\";}选项一"), 'special data leaked');
});

mb_test('edit notes are removed', function () {
    mb_assert_same('正文', mb_bb("[i=s] 本帖最后由 张三 于 2026-10-01 编辑 [/i]\n正文"), 'edit note leaked');
});

mb_test('html entities are decoded', function () {
    mb_assert_same('A & B < C "d" \'e\' 你好', mb_bb('A &amp; B &lt; C &quot;d&quot; &#039;e&#039; &#x4f60;&#22909;'), 'entities not decoded');
});

mb_test('entities cannot resurrect hidden tags', function () {
    $out = mb_bb('x &#91;hide&#93;visible&#91;/hide&#93;');
    mb_assert_same('x [hide]visible[/hide]', $out, 'entity decode should happen last and leave literal text');
});

mb_test('whitespace is collapsed and paragraphs keep one blank line', function () {
    mb_assert_same("第一段\n\n第二段\n第二段续", mb_bb("  第一段  \r\n\r\n\r\n\r\n第二段   \n第二段续\n\n"), 'whitespace not normalized');
    mb_assert_same('a b', mb_bb("a \t  b"), 'inner spaces not collapsed');
});

mb_test('plain brackets that are not tags survive', function () {
    mb_assert_same('参考 [1] 与 [待定]', mb_bb('参考 [1] 与 [待定]'), 'non-tag brackets removed');
});

mb_test('invalid utf-8 does not blank the post', function () {
    mb_assert_same('ab', mb_bb("a\xFFb"), 'invalid byte should be dropped, not blank the whole text');
});

mb_test('content never contains hidden text', function () {
    $nul = chr(0) . chr(0) . chr(0);
    $message = implode("\n", array(
        '可见开头',
        '[hide]SECRET_HIDE_PLAIN[/hide]',
        '[hide=10]SECRET_HIDE_CREDITS[/hide]',
        '[hide=d30]SECRET_HIDE_DAYS[/hide]',
        '[hide=d30,50]SECRET_HIDE_DAYS_CREDITS[/hide]',
        '[HIDE]SECRET_HIDE_UPPER[/HIDE]',
        '[hide][b]SECRET_HIDE_NESTED_B[/b][hide]SECRET_HIDE_INNER[/hide]SECRET_HIDE_AFTER_INNER[/hide]',
        '[quote]SECRET_QUOTE[/quote]',
        '[attach]SECRET_ATTACH[/attach]',
        '[attachimg]SECRET_ATTACHIMG[/attachimg]',
        '[img]SECRET_IMG[/img]',
        '[media=x,1,1]SECRET_MEDIA[/media]',
        '[audio]SECRET_AUDIO[/audio]',
        '[flash]SECRET_FLASH[/flash]',
        '[swf]SECRET_SWF[/swf]',
        '[payto=1]SECRET_PAYTO[/payto]',
        '[i=s] SECRET_EDITNOTE [/i]',
        '[hide=d30, 50]SECRET_HIDE_SPACE[/hide]',
        '[quote][hide]SECRET_HIDE_IN_QUOTE[/hide][/quote]',
        'A[quote]x[hide]SECRET_CROSS1[/quote]more_SECRET_CROSS2[/hide]B',
        'A[i=s]x[hide]SECRET_CROSS3[/i]SECRET_CROSS4[/hide]B',
        'A[hide]x[quote]SECRET_CROSS5[/hide]SECRET_CROSS6[/quote]B',
        '[groupid=1]SECRET_GROUP[/groupid]',
        '[postappend]补充 [hide]SECRET_IN_APPEND[/hide]可见补充[/postappend]',
        '可见结尾',
        '[hide]SECRET_UNCLOSED',
    )) . $nul . 'SECRET_SPECIAL_DATA';
    $out = mb_bb($message);
    mb_assert(strpos($out, '可见开头') !== false && strpos($out, '可见结尾') !== false && strpos($out, '可见补充') !== false, 'visible text was lost: ' . $out);
    mb_assert(strpos($out, 'SECRET') === false, 'hidden text leaked: ' . $out);
    mb_assert(!preg_match('/\[\/?(hide|quote|attach|attachimg|img|media|audio|flash|swf|payto|password|groupid|postappend|i)\b/i', $out), 'hidden tag markup left behind: ' . $out);
});

mb_test('crossed guarded tags never leak', function () {
    foreach (array(
        'A[quote]x[hide]SECRET1[/quote]more_SECRET2[/hide]B',
        'A[i=s]x[hide]SECRET[/i]LEAK[/hide]B',
        'A[hide]x[quote]SECRET1[/hide]SECRET2[/quote]B',
        'A[hide]x[groupid=1]SECRET1[/hide]SECRET2[/groupid]B',
    ) as $input) {
        $out = mb_bb($input);
        mb_assert(strpos($out, 'SECRET') === false && strpos($out, 'LEAK') === false, 'crossed tags leaked: ' . $input . ' => ' . $out);
        mb_assert(strpos($out, 'A') === 0, 'text before the tags was lost: ' . $out);
    }
});

mb_test('a password tag blanks the whole post', function () {
    mb_assert_same('', mb_bb('PUBLIC [password]pw[/password] MORE'), 'password post should be empty');
    mb_assert_same('', mb_bb('PUBLIC [PASSWORD=x]pw'), 'unclosed password tag should blank the post');
});

mb_test('groupid spans are removed; postappend keeps its text like X5 viewthread', function () {
    mb_assert_same('ab', mb_bb('a[groupid=7]SECRET[/groupid]b'), 'groupid leaked');
    // X5's misc postappend wraps the appended text in [postappend]; viewthread shows it to everyone.
    mb_assert_same("正文\n\n补充内容：追加的文字", mb_bb("正文\n\n[postappend][b]补充内容：[/b]追加的文字[/postappend]"), 'appended text');
    mb_assert_same('a补充b', mb_bb('a[postappend]补充[hide]SECRET[/hide][/postappend]b'), 'hidden text inside the append');
    mb_assert_same('a补充', mb_bb('a[postappend]补充'), 'an unclosed append keeps its text');
});

mb_test('postAppends returns the appended spans of a message', function () {
    mb_assert_same('', MomoBird_ContentText::postAppends('摘要'), 'none');
    mb_assert_same("一\n\n二", MomoBird_ContentText::postAppends("摘要[postappend]一[/postappend]中间[postappend]二[/postappend]"), 'two spans');
});

mb_test('oversized input is cut at 2 MiB on a character boundary', function () {
    $text = str_repeat('你', 800000) . '[hide]x[/hide]' . 'TAIL_MARK';
    $out = mb_bb($text);
    mb_assert(strlen($out) <= 2 * 1024 * 1024, 'output longer than the cap: ' . strlen($out));
    mb_assert(strpos($out, 'TAIL_MARK') === false, 'text beyond the cap was kept');
    mb_assert(preg_match('//u', $out) === 1, 'cut produced invalid utf-8');
    mb_assert(strpos($out, '你你') === 0, 'text before the cap was lost');
});

mb_test('document puts the title on one line before the body', function () {
    mb_assert_same("# 标题\n\n正文", MomoBird_ContentText::document('标题', '正文'), 'document shape changed');
    mb_assert_same("# A B & C\n\n正文", MomoBird_ContentText::document("  A\nB &amp; C ", '正文'), 'title not normalized');
});

mb_test('document of an empty body is empty', function () {
    mb_assert_same('', MomoBird_ContentText::document('标题', " \n "), 'empty body should produce no document');
});

function mb_x5($blocks, $wrap = true)
{
    $payload = $wrap
        ? array('type' => 'json', 'editor' => 'jsonEditor', 'content' => array('blocks' => $blocks))
        : array('blocks' => $blocks);
    return json_encode($payload, JSON_UNESCAPED_UNICODE);
}

mb_test('x5 paragraph and header levels', function () {
    $json = mb_x5(array(
        array('type' => 'header', 'data' => array('text' => '大标题', 'level' => 1)),
        array('type' => 'paragraph', 'data' => array('text' => '第一段<br>换行&nbsp;<b>加粗</b> &amp; <a href="http://x">链接</a>')),
        array('type' => 'header', 'data' => array('text' => '小标题', 'level' => 3)),
        array('type' => 'paragraph', 'data' => array('text' => '第二段')),
    ));
    mb_assert_same("# 大标题\n\n第一段\n换行 加粗 & 链接\n\n### 小标题\n\n第二段", MomoBird_ContentText::fromX5Json($json), 'x5 text wrong');
});

mb_test('x5 lists, nested lists, quote and code', function () {
    $json = mb_x5(array(
        array('type' => 'list', 'data' => array('style' => 'unordered', 'items' => array('甲', '<b>乙</b>'))),
        array('type' => 'list', 'data' => array('style' => 'ordered', 'items' => array(array('content' => '一', 'items' => array(array('content' => '一·一', 'items' => array()))), array('content' => '二', 'items' => array())))),
        array('type' => 'quote', 'data' => array('text' => '引言<br>第二行', 'caption' => '某人')),
        array('type' => 'code', 'data' => array('code' => "echo 1;\necho 2;")),
    ));
    mb_assert_same("- 甲\n- 乙\n\n1. 一\n  1. 一·一\n2. 二\n\n引言\n第二行\n\necho 1;\necho 2;", MomoBird_ContentText::fromX5Json($json), 'x5 lists/quote/code wrong');
});

mb_test('x5 media blocks are dropped', function () {
    $json = mb_x5(array(
        array('type' => 'paragraph', 'data' => array('text' => '前')),
        array('type' => 'image', 'data' => array('file' => array('url' => 'http://i/1.png'), 'caption' => 'IMG_CAPTION', 'text' => 'IMG_TEXT')),
        array('type' => 'video', 'data' => array('url' => 'http://v', 'text' => 'VIDEO_TEXT')),
        array('type' => 'audio', 'data' => array('url' => 'http://a', 'text' => 'AUDIO_TEXT')),
        array('type' => 'attaches', 'data' => array('file' => array('url' => 'http://f'), 'title' => 'ATTACH_TITLE', 'text' => 'ATTACH_TEXT')),
        array('type' => 'paragraph', 'data' => array('text' => '后')),
    ));
    $out = MomoBird_ContentText::fromX5Json($json);
    mb_assert_same("前\n\n后", $out, 'media blocks leaked: ' . $out);
});

mb_test('x5 unknown blocks use data.text or are skipped', function () {
    $json = mb_x5(array(
        array('type' => 'warning', 'data' => array('text' => '注意事项')),
        array('type' => 'delimiter', 'data' => array()),
        array('type' => 'weird', 'data' => array('foo' => 'bar')),
        array('type' => 'weird2'),
        'not-a-block',
    ));
    mb_assert_same('注意事项', MomoBird_ContentText::fromX5Json($json), 'unknown block handling wrong');
});

mb_test('x5 accepts content wrapped or unwrapped', function () {
    $blocks = array(array('type' => 'paragraph', 'data' => array('text' => '你好')));
    mb_assert_same('你好', MomoBird_ContentText::fromX5Json(mb_x5($blocks, true)), 'wrapped content not read');
    mb_assert_same('你好', MomoBird_ContentText::fromX5Json(mb_x5($blocks, false)), 'top-level blocks not read');
    mb_assert_same('你好', MomoBird_ContentText::fromX5Json(json_encode(array('content' => json_encode(array('blocks' => $blocks))))), 'string-encoded content not read');
});

mb_test('x5 invalid json or no blocks returns null', function () {
    foreach (array('', 'not json', '{', '[]', '"str"', '123', 'null', '{"content":{}}', '{"content":{"blocks":"x"}}', '{"blocks":null}', '{"type":"json"}') as $input) {
        mb_assert_same(null, MomoBird_ContentText::fromX5Json($input), 'expected null for: ' . $input);
    }
});

mb_test('x5 blocks with no text give an empty string, not null', function () {
    $json = mb_x5(array(array('type' => 'image', 'data' => array('file' => array('url' => 'http://i/1.png')))));
    mb_assert_same('', MomoBird_ContentText::fromX5Json($json), 'image-only post should be empty text');
});

mb_test('x5 text never contains hidden bbcode', function () {
    $json = mb_x5(array(
        array('type' => 'paragraph', 'data' => array('text' => '可见[hide]SECRET_X5_HIDE[/hide]尾')),
        array('type' => 'paragraph', 'data' => array('text' => '[quote]SECRET_X5_QUOTE[/quote]正文')),
        array('type' => 'paragraph', 'data' => array('text' => '[hide=10]SECRET_X5_UNCLOSED')),
    ));
    $out = MomoBird_ContentText::fromX5Json($json);
    mb_assert(strpos($out, 'SECRET') === false, 'hidden text leaked from x5 json: ' . $out);
    mb_assert(strpos($out, '可见') !== false && strpos($out, '正文') !== false, 'visible x5 text lost: ' . $out);
});

mb_test('x5 hide opened in one block and closed in a later one drops everything between', function () {
    $json = mb_x5(array(
        array('type' => 'paragraph', 'data' => array('text' => '开头[hide]SECRET_ONE')),
        array('type' => 'header', 'data' => array('text' => 'SECRET_HEADER', 'level' => 2)),
        array('type' => 'list', 'data' => array('style' => 'unordered', 'items' => array('SECRET_ITEM'))),
        array('type' => 'code', 'data' => array('code' => 'SECRET_CODE')),
        array('type' => 'paragraph', 'data' => array('text' => 'SECRET_TWO[/hide]结尾')),
        array('type' => 'paragraph', 'data' => array('text' => '最后一段')),
    ));
    $out = MomoBird_ContentText::fromX5Json($json);
    mb_assert(strpos($out, 'SECRET') === false, 'cross-block hide leaked: ' . $out);
    mb_assert(strpos($out, '开头') === 0 && strpos($out, '最后一段') !== false, 'visible x5 text lost: ' . $out);
});

mb_test('x5 password tag blanks the post', function () {
    $json = mb_x5(array(
        array('type' => 'paragraph', 'data' => array('text' => '公开')),
        array('type' => 'paragraph', 'data' => array('text' => '[password]pw[/password]')),
    ));
    mb_assert_same('', MomoBird_ContentText::fromX5Json($json), 'x5 password post should be empty');
});
