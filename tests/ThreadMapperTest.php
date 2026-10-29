<?php

function mb_map_thread(array $override = array())
{
    return array_merge(array('tid' => 12, 'subject' => '你好 &amp; 世界', 'dateline' => 1700000000), $override);
}

function mb_map_post(array $override = array())
{
    return array_merge(array('pid' => 30, 'tid' => 12, 'first' => 1, 'dateline' => 1700000100, 'message' => "第一段\n\n第二段"), $override);
}

mb_test('mapper builds the payload with exactly the push fields', function () {
    $payload = MomoBird_ThreadMapper::payload(mb_map_thread(), mb_map_post(), '汽车', 'https://bbs.example.com/forum.php?mod=viewthread&tid=12');
    mb_assert_same(array('external_id', 'title', 'content', 'url', 'modified_at', 'category'), array_keys($payload), 'payload keys');
    mb_assert_same('12', $payload['external_id'], 'external_id is the tid as a string');
    mb_assert_same('你好 & 世界', $payload['title'], 'title decoded');
    mb_assert_same("第一段\n\n第二段", $payload['content'], 'content');
    mb_assert_same('https://bbs.example.com/forum.php?mod=viewthread&tid=12', $payload['url'], 'url');
    mb_assert_same('2023-11-14T22:15:00Z', $payload['modified_at'], 'modified_at is UTC');
    mb_assert_same('汽车', $payload['category'], 'category');
});

mb_test('mapper omits the category when there is none', function () {
    $payload = MomoBird_ThreadMapper::payload(mb_map_thread(), mb_map_post(), '', 'https://x/');
    mb_assert(!array_key_exists('category', $payload), 'empty category must be omitted');
});

mb_test('mapper caps the category at 64 characters', function () {
    $payload = MomoBird_ThreadMapper::payload(mb_map_thread(), mb_map_post(), str_repeat('类', 90), 'https://x/');
    mb_assert_same(64, mb_strlen($payload['category'], 'UTF-8'), 'category length');
});

mb_test('mapper title: tags stripped, entities decoded, spaces collapsed', function () {
    $cases = array(
        '<font color="red">红字</font> 标题' => '红字 标题',
        'A &amp; B &lt;tag&gt; &quot;q&quot; &#39;s&#39;' => 'A & B <tag> "q" \'s\'',
        "  多\n 空白\t标题 " => '多 空白 标题',
        'Tom &amp;amp; Jerry' => 'Tom &amp; Jerry',
    );
    foreach ($cases as $raw => $expected) {
        $payload = MomoBird_ThreadMapper::payload(mb_map_thread(array('subject' => $raw)), mb_map_post(), '', 'https://x/');
        mb_assert_same($expected, $payload['title'], 'title for ' . $raw);
    }
});

mb_test('mapper modified_at: later of post edit (or post time) and thread time', function () {
    $at = function ($thread, $post) {
        $payload = MomoBird_ThreadMapper::payload(mb_map_thread($thread), mb_map_post($post), '', 'https://x/');
        return $payload['modified_at'];
    };
    mb_assert_same('2023-11-14T22:13:20Z', $at(array('dateline' => 1700000000), array('dateline' => 1700000000)), 'both equal');
    mb_assert_same('2023-11-14T22:13:30Z', $at(array('dateline' => 1700000000), array('dateline' => 1700000010)), 'post time later');
    mb_assert_same('2023-11-14T22:13:40Z', $at(array('dateline' => 1700000020), array('dateline' => 1700000010)), 'thread time later');
    mb_assert_same('2023-11-14T22:13:50Z', $at(array('dateline' => 1700000000), array('dateline' => 1700000010, 'lastedit' => 1700000030)), 'lastedit wins over post time');
    mb_assert_same('2023-11-14T22:13:40Z', $at(array('dateline' => 1700000020), array('dateline' => 1700000010, 'lastedit' => 1700000015)), 'thread time beats an older edit');
    mb_assert_same('2023-11-14T22:13:30Z', $at(array('dateline' => '1700000000'), array('dateline' => '1700000010', 'lastedit' => '0')), 'string columns, lastedit 0 falls back');
    mb_assert_same('2023-11-14T22:13:30Z', $at(array('dateline' => 1700000000), array('dateline' => 1700000010, 'lastedit' => null)), 'null lastedit');
});

mb_test('mapper prefers the X5 json content over the message summary', function () {
    $json = json_encode(array('type' => 'json', 'editor' => 'jsonEditor', 'content' => array('blocks' => array(
        array('type' => 'header', 'data' => array('text' => '标题块', 'level' => 2)),
        array('type' => 'paragraph', 'data' => array('text' => '正文 <b>一</b>')),
        array('type' => 'image', 'data' => array('file' => array('url' => 'http://x/y.png'))),
    ))));
    $payload = MomoBird_ThreadMapper::payload(mb_map_thread(), mb_map_post(array('content' => $json, 'message' => '摘要摘要')), '', 'https://x/');
    mb_assert_same("## 标题块\n\n正文 一", $payload['content'], 'json blocks');
    mb_assert(strpos($payload['content'], '摘要') === false, 'summary leaked');
});

mb_test('mapper adds text appended to an X5 json post after the blocks, as viewthread does', function () {
    $json = json_encode(array('type' => 'json', 'editor' => 'jsonEditor', 'content' => json_encode(array('blocks' => array(
        array('type' => 'paragraph', 'data' => array('text' => '正文块')),
    )))));
    $message = "摘要;\n\n[postappend][b]补充内容 (2026-10-29 22:56):[/b]\n追加 [hide]秘密[/hide]文字[/postappend]";
    $payload = MomoBird_ThreadMapper::payload(mb_map_thread(), mb_map_post(array('content' => $json, 'message' => $message)), '', 'https://x/');
    mb_assert_same("正文块\n\n补充内容 (2026-10-29 22:56):\n追加 文字", $payload['content'], 'json text then the append');
    mb_assert(strpos($payload['content'], '摘要') === false && strpos($payload['content'], '秘密') === false, 'summary or hidden text: ' . $payload['content']);
});

mb_test('mapper falls back to bbcode when the json is missing or unusable', function () {
    foreach (array(null, '', 'not json', '{"content":{"blocks":[]}}', '[]') as $content) {
        $payload = MomoBird_ThreadMapper::payload(mb_map_thread(), mb_map_post(array('content' => $content, 'message' => '[b]粗体[/b] 文字')), '', 'https://x/');
        mb_assert_same('粗体 文字', $payload['content'], 'fallback for ' . var_export($content, true));
    }
});

mb_test('mapper never emits hidden text from either source', function () {
    $payload = MomoBird_ThreadMapper::payload(mb_map_thread(), mb_map_post(array('message' => "公开[hide]秘密一[/hide]后面[hide=10]秘密二[/hide][quote]引用[/quote]\0\0\0投票数据")), '', 'https://x/');
    mb_assert_same('公开后面', $payload['content'], 'hidden text in bbcode');
    $json = json_encode(array('content' => array('blocks' => array(array('type' => 'paragraph', 'data' => array('text' => '公开[hide]秘密[/hide]尾巴'))))));
    $payload = MomoBird_ThreadMapper::payload(mb_map_thread(), mb_map_post(array('content' => $json)), '', 'https://x/');
    mb_assert_same('公开尾巴', $payload['content'], 'hidden text in json');
});

mb_test('mapper skips empty and password posts, and does not fall back from json to the summary', function () {
    mb_assert_same(null, MomoBird_ThreadMapper::payload(mb_map_thread(), mb_map_post(array('message' => '')), '', 'https://x/'), 'empty message');
    mb_assert_same(null, MomoBird_ThreadMapper::payload(mb_map_thread(), mb_map_post(array('message' => '[hide]x[/hide] [img]a.png[/img]')), '', 'https://x/'), 'only hidden or media');
    mb_assert_same(null, MomoBird_ThreadMapper::payload(mb_map_thread(), mb_map_post(array('message' => '[password]a[/password]有字')), '', 'https://x/'), 'password post');
    $imageOnly = json_encode(array('content' => array('blocks' => array(array('type' => 'image', 'data' => array('file' => array('url' => 'http://x/y.png')))))));
    mb_assert_same(null, MomoBird_ThreadMapper::payload(mb_map_thread(), mb_map_post(array('content' => $imageOnly, 'message' => '摘要')), '', 'https://x/'), 'json with only an image must not fall back to the summary');
});

mb_test('mapper hash changes with title, url, content and category only', function () {
    $base = MomoBird_ThreadMapper::payload(mb_map_thread(), mb_map_post(), '汽车', 'https://x/a');
    $hash = MomoBird_ThreadMapper::hash($base);
    mb_assert(preg_match('/^[0-9a-f]{64}$/', $hash) === 1, 'sha256 hex');
    mb_assert_same($hash, MomoBird_ThreadMapper::hash(MomoBird_ThreadMapper::payload(mb_map_thread(), mb_map_post(), '汽车', 'https://x/a')), 'stable');
    mb_assert($hash !== MomoBird_ThreadMapper::hash(MomoBird_ThreadMapper::payload(mb_map_thread(), mb_map_post(), '摄影', 'https://x/a')), 'category must change the hash');
    mb_assert($hash !== MomoBird_ThreadMapper::hash(MomoBird_ThreadMapper::payload(mb_map_thread(), mb_map_post(), '', 'https://x/a')), 'removing the category must change the hash');
    mb_assert($hash !== MomoBird_ThreadMapper::hash(MomoBird_ThreadMapper::payload(mb_map_thread(), mb_map_post(), '汽车', 'https://x/b')), 'url must change the hash');
    mb_assert($hash !== MomoBird_ThreadMapper::hash(MomoBird_ThreadMapper::payload(mb_map_thread(array('subject' => '别的')), mb_map_post(), '汽车', 'https://x/a')), 'title must change the hash');
    mb_assert($hash !== MomoBird_ThreadMapper::hash(MomoBird_ThreadMapper::payload(mb_map_thread(), mb_map_post(array('message' => '别的')), '汽车', 'https://x/a')), 'content must change the hash');
    $later = MomoBird_ThreadMapper::payload(mb_map_thread(), mb_map_post(array('lastedit' => 1800000000)), '汽车', 'https://x/a');
    mb_assert_same($hash, MomoBird_ThreadMapper::hash($later), 'an edit time alone must not change the hash');
    // Field boundaries matter: moving text from one field to the next is a different document.
    $a = array('title' => 'ab', 'url' => 'c', 'content' => 'd', 'category' => 'e');
    $b = array('title' => 'a', 'url' => 'bc', 'content' => 'd', 'category' => 'e');
    mb_assert(MomoBird_ThreadMapper::hash($a) !== MomoBird_ThreadMapper::hash($b), 'field boundaries are ambiguous');
});
