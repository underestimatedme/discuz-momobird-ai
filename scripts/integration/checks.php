<?php
/**
 * The integration checks, run by scripts/integration.sh after Discuz! and the plugin are
 * installed:  php scripts/integration/checks.php <x35|x5> <container prefix> <site url> <setup.json>
 *
 * Every check drives the forum over HTTP like a browser (guest or the admin account) and
 * looks at what the mock connect service received. Exit status 1 when any check fails.
 */

require __DIR__ . '/lib.php';

list(, $suite, $prefix, $site, $setupFile) = $argv;
$setup = json_decode((string) file_get_contents($setupFile), true);
$tmp = sys_get_temp_dir() . '/mbit-' . $suite . '-' . getmypid();
@mkdir($tmp);
$it = new It($suite, $prefix, $site, is_array($setup) ? $setup : array(), $tmp);
$key = (string) getenv('IT_KEY');
$mockUrl = 'http://' . $prefix . '-mock:8080';
$pluginid = (int) ($setup['pluginid'] ?? 0);
$adminPage = 'admin.php?action=plugins&operation=config&do=' . $pluginid . '&identifier=momobird_ai&pmod=admincp';
$pages = array(); // every page fetched for the "no key in HTML" check

// --- forum actions --------------------------------------------------------------------------

function formhash(It_Http $http): string
{
    $page = $http->get('forum.php?mod=post&action=newthread&fid=2');
    if (!preg_match('/name="formhash" value="([0-9a-f]+)"/', $page, $m) && !preg_match('/formhash=([0-9a-f]{8})/', $page, $m)) {
        throw new RuntimeException('no formhash on the post page');
    }
    return $m[1];
}

function login(It $it, It_Http $http, string $user, string $password): void
{
    $page = $http->get('member.php?mod=logging&action=login&infloat=yes&handlekey=login&inajax=1&ajaxtarget=fwin_content_login');
    preg_match('/loginhash=(\w+)/', $page, $l);
    preg_match('/name="formhash" value="([0-9a-f]+)"/', $page, $f);
    $http->post('member.php?mod=logging&action=login&loginsubmit=yes&handlekey=login&loginhash=' . ($l[1] ?? '') . '&inajax=1', array(
        'formhash' => $f[1] ?? '', 'referer' => '', 'loginfield' => 'username', 'username' => $user,
        'password' => $password, 'questionid' => 0, 'answer' => '',
    ));
    it_assert(preg_match('/_auth\t/', $http->cookies()) === 1, 'login failed for ' . $user);
}

/**
 * Open the admin page; when admincp asks for the password again (X5 always, X3.5 depending on
 * the admincp session), fill its login form the way a browser does, then open the page again.
 */
function adminOpen(It $it, string $page): string
{
    $html = $it->admin->get($page);
    if (strpos($html, 'name="admin_password"') === false) {
        return $html;
    }
    preg_match('/<form[^>]*name="login"[^>]*action="([^"]*)"/', $html, $action);
    $fields = array();
    if (preg_match_all('/<input type="hidden" name="(\w+)" value="([^"]*)"/', $html, $hidden, PREG_SET_ORDER)) {
        foreach ($hidden as $field) {
            $fields[$field[1]] = html_entity_decode($field[2]);
        }
    }
    if (preg_match('/<select name="admin_type"[^>]*>\s*<option value="(\d+)"/', $html, $type)) {
        $fields['admin_type'] = $type[1];
    }
    $fields += array('admin_username' => getenv('IT_ADMIN_USER'), 'admin_password' => getenv('IT_ADMIN_PASSWORD'),
        'admin_questionid' => 0, 'admin_answer' => '', 'submit' => 'yes');
    $target = html_entity_decode($action[1] ?? 'admin.php');
    $target = $target === '' || $target[0] === '?' ? 'admin.php' . $target : $target;
    $it->admin->post($target, $fields);
    $html = $it->admin->get($page);
    it_assert(strpos($html, 'name="admin_password"') === false || strpos($html, 'momobird') !== false, 'admincp login failed');
    return $html;
}

function tidBySubject(It $it, string $subject): int
{
    return (int) $it->one('SELECT tid FROM pre_forum_thread WHERE subject=' . It::q($subject) . ' ORDER BY tid DESC LIMIT 1');
}

function newThread(It $it, int $fid, string $subject, string $message, array $extra = array(), ?It_Http $as = null, string $formhash = ''): int
{
    $http = $as !== null ? $as : $it->admin;
    $http->post('forum.php?mod=post&action=newthread&fid=' . $fid . '&extra=&topicsubmit=yes', array_merge(array(
        'formhash' => $formhash !== '' ? $formhash : $it->formhash, 'posttime' => time(), 'wysiwyg' => 0, 'subject' => $subject, 'message' => $message,
        'usesig' => 1, 'allownoticeauthor' => 1,
    ), $extra));
    $tid = tidBySubject($it, $subject);
    it_assert($tid > 0, "posting \"$subject\" created no thread (HTTP {$http->lastStatus})");
    return $tid;
}

function firstPid(It $it, int $tid): int
{
    return (int) $it->one('SELECT pid FROM pre_forum_post WHERE tid=' . $tid . ' AND first=1');
}

function editFirst(It $it, int $tid, int $fid, string $subject, string $message, array $extra = array()): void
{
    $it->admin->post('forum.php?mod=post&action=edit&extra=&editsubmit=yes', array_merge(array(
        'formhash' => $it->formhash, 'fid' => $fid, 'tid' => $tid, 'pid' => firstPid($it, $tid), 'page' => 1,
        'posttime' => time(), 'wysiwyg' => 0, 'subject' => $subject, 'message' => $message, 'usesig' => 1,
    ), $extra));
}

function reply(It $it, int $tid, int $fid, string $message): void
{
    $it->admin->post('forum.php?mod=post&action=reply&fid=' . $fid . '&tid=' . $tid . '&extra=&replysubmit=yes&infloat=yes&handlekey=fastpost&inajax=1', array(
        'formhash' => $it->formhash, 'posttime' => time(), 'wysiwyg' => 0, 'subject' => '', 'message' => $message, 'usesig' => 1,
    ));
}

function moderate(It $it, It_Http $http, int $fid, array $tids, string $operation, array $extra = array(), string $formhash = ''): string
{
    $groups = array('close' => 4, 'open' => 4, 'move' => 2, 'delete' => 3);
    return $http->post('forum.php?mod=topicadmin&action=moderate&optgroup=' . ($groups[$operation] ?? 0) . '&modsubmit=yes&infloat=yes&modclick=yes&inajax=1', array_merge(array(
        'formhash' => $formhash !== '' ? $formhash : $it->formhash, 'fid' => $fid, 'moderate' => $tids, 'operations' => array($operation),
        'reason' => '', 'sendreasonpm' => 0, 'handlekey' => 'mods',
    ), $extra));
}

function modcpLogin(It $it): void
{
    $it->admin->post('forum.php?mod=modcp&action=login', array(
        'formhash' => $it->formhash, 'login_panel' => 'yes', 'cppwd' => getenv('IT_ADMIN_PASSWORD'), 'submit' => 'yes',
    ));
}

function threadRow(It $it, int $tid): ?array
{
    $rows = $it->sql('SELECT fid, displayorder, closed FROM pre_forum_thread WHERE tid=' . $tid);
    return $rows ? array('fid' => (int) $rows[0][0], 'displayorder' => (int) $rows[0][1], 'closed' => (int) $rows[0][2]) : null;
}

/** On mod_php the hook only queues: the row is waiting and MomoBird has heard nothing yet. */
function queuedWithoutNetwork(It $it, int $tid, int $mark): string
{
    if ($it->fpm) {
        return 'FPM: pushed after the response';
    }
    $row = $it->row($tid);
    it_assert($row !== null && $row['queued'], "tid $tid not queued by the hook: " . it_short($row));
    it_assert(count($it->since($mark)) === 0, 'the hook reached MomoBird: ' . it_short($it->since($mark)));
    return 'queued by the hook, no request until the cron';
}

function expectPush(It $it, int $mark, int $tid, ?string $category = null): array
{
    $post = $it->pushed($mark, $tid);
    it_assert($post !== null, "tid $tid was not pushed; requests: " . it_short($it->since($mark)));
    if ($category !== null) {
        it_assert(($post['category'] ?? null) === $category, "tid $tid category " . json_encode($post['category'] ?? null, JSON_UNESCAPED_UNICODE) . ", expected $category");
    }
    return $post;
}

function adminAjax(It $it, string $action, array $fields = array()): array
{
    global $adminPage;
    $body = $it->admin->post($adminPage, array_merge(array('mb_action' => $action, 'formhash' => $it->adminFormhash, 'inajax' => 1), $fields));
    $data = json_decode($body, true);
    it_assert(is_array($data), "admin action $action answered no JSON: " . it_short($body));
    return $data;
}

function noKey(string $what, string $html): void
{
    global $key;
    it_assert(strpos($html, $key) === false, "$what contains the connection key");
    it_assert(strpos($html, 'DISCUZLOCALTESTKEY') === false, "$what contains part of the key");
}

function fetch(It $it, It_Http $http, string $path): string
{
    global $pages;
    $html = $http->get($path);
    $pages[$path . ($http === $it->admin ? ' (admin)' : '')] = $html;
    return $html;
}

// --- run ---------------------------------------------------------------------------------------

echo "== Discuz! {$suite}, " . ($it->fpm ? 'PHP-FPM (after-response path)' : 'mod_php (cron path)') . "\n";
$it->sql("UPDATE pre_common_cron SET available=0 WHERE type='plugin'");
$it->dz('cache');

$it->check('A1', 'plugin installed: tables, cron job, hooks', function () use ($it) {
    $tables = array_column($it->sql("SHOW TABLES LIKE 'pre_momobird_ai_%'"), 0);
    sort($tables);
    it_assert($tables === array('pre_momobird_ai_kv', 'pre_momobird_ai_post'), 'tables: ' . implode(',', $tables));
    $cron = $it->sql("SELECT filename, minute FROM pre_common_cron WHERE type='plugin'");
    it_assert($cron && $cron[0][0] === 'momobird_ai:cron_momobird_ai.php', 'cron row: ' . it_short($cron));
    $plugin = $it->sql("SELECT available, version FROM pre_common_plugin WHERE identifier='momobird_ai'");
    it_assert($plugin && $plugin[0][0] === '1' && $plugin[0][1] === '0.1.0', 'plugin row: ' . it_short($plugin));
    return 'manifest ' . $it->setup['manifest'];
});

login($it, $it->admin, getenv('IT_ADMIN_USER'), getenv('IT_ADMIN_PASSWORD'));
$it->formhash = formhash($it->admin);

$it->check('A2', 'settings saved through the real admincp; the key is never shown', function () use ($it, $adminPage, $key, $mockUrl, $site) {
    $page = adminOpen($it, $adminPage);
    $GLOBALS['pages'][$adminPage . ' (admin)'] = $page;
    it_assert(preg_match('/name="momobird_settings".*?name="formhash" value="([0-9a-f]+)"/s', $page, $m) === 1, 'no admin page form: ' . it_short(it_text($page)));
    $it->adminFormhash = $m[1];
    $saved = $it->admin->post($adminPage, array(
        'formhash' => $it->adminFormhash, 'momobird_save' => '保存设置',
        'mb' => array('admin_password' => $key, 'baseUrl' => $mockUrl, 'timeout' => 10, 'forumUrl' => $site . '/',
            'forumScope' => 'all', 'widgetEnabled' => 1, 'widgetPosition' => 'right', 'podcastEnabled' => 1, 'podcastTranscript' => 0),
    ));
    $GLOBALS['pages']['admin save'] = $saved;
    it_assert(strpos($saved, '设置已保存') !== false, 'no 设置已保存 (HTTP ' . $it->admin->lastStatus . '): ' . it_short(it_text($saved)));
    it_assert(strpos($saved, '连接正常') !== false, 'the check after saving did not pass: ' . it_short(it_text($saved)));
    it_assert(strpos($saved, '已保存：mbk_DISC') !== false, 'no key prefix shown');
    noKey('the admin page after saving', $saved);
    $secret = $it->kv('secret');
    it_assert(($secret['key'] ?? null) === $key, 'kv secret does not hold the key');
    $settings = $it->one("SELECT v FROM pre_momobird_ai_kv WHERE k='settings'");
    it_assert(strpos((string) $settings, 'mbk_') === false, 'kv settings holds the key');
    return 'kv secret holds it, page shows 已保存：mbk_DISC…';
});

/** The admin log: X3.5 writes data/log/*_cplog.php, X5 the common_log table (type cp). */
function adminLogCheck(It $it): string
{
    $hits = trim($it->web('grep -rl "mbk_\|DISCUZLOCALTESTKEY" /var/www/html/data/log || true'));
    it_assert($hits === '', 'key found in ' . $hits);
    $files = (int) trim($it->web('grep -rl "momobird_ai" /var/www/html/data/log 2>/dev/null | wc -l'));
    $rows = 0;
    if ($it->sql("SHOW TABLES LIKE 'pre_common_log'")) {
        $leak = (int) $it->one("SELECT COUNT(*) FROM pre_common_log WHERE data LIKE '%mbk\\_%' OR data LIKE '%DISCUZLOCALTESTKEY%'");
        it_assert($leak === 0, "key found in $leak common_log rows");
        $rows = (int) $it->one("SELECT COUNT(*) FROM pre_common_log WHERE type='cp' AND data LIKE '%momobird_ai%'");
    }
    it_assert($files + $rows > 0, 'the admin log has no entry for the plugin page, so the check proves nothing');
    return $rows > 0 ? "$rows common_log cp rows for the plugin, none with the key" : "$files data/log file(s) with the plugin's admin actions, none with the key";
}

$it->check('A3', 'no mbk_ in the admin log after saving the key through admincp', function () use ($it) {
    return adminLogCheck($it);
});

// T1: post, edit, reply, close, recycle, restore, move, delete -------------------------------------
$t1Subject = 'MB-T1 第一个测试主题';
$t1 = 0;

$it->check('C1', 'new thread is pushed; category is the forum name; [hide] text is not sent', function () use ($it, &$t1, $t1Subject, $site) {
    $mark = $it->mark();
    $t1 = newThread($it, 2, $t1Subject, "公开的第一段 MBVISIBLE1。\n\n[hide]回复可见 MBHIDDEN1[/hide]\n\n[hide=10]积分可见 MBHIDDEN2[/hide]\n\n[quote]引用 MBQUOTE1[/quote]\n\n公开结尾 MBVISIBLE2");
    $note = queuedWithoutNetwork($it, $t1, $mark);
    $it->settle();
    $post = expectPush($it, $mark, $t1, '默认版块');
    it_assert($post['title'] === $t1Subject, 'title ' . $post['title']);
    it_assert($post['url'] === $site . '/forum.php?mod=viewthread&tid=' . $t1, 'url ' . $post['url']);
    it_assert(preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $post['modified_at']) === 1, 'modified_at ' . $post['modified_at']);
    it_assert(strpos($post['content'], 'MBVISIBLE1') !== false && strpos($post['content'], 'MBVISIBLE2') !== false, 'visible text missing: ' . it_short($post['content']));
    foreach (array('MBHIDDEN1', 'MBHIDDEN2', 'MBQUOTE1', '[hide') as $hidden) {
        it_assert(strpos($post['content'], $hidden) === false, "content contains $hidden: " . it_short($post['content']));
    }
    $row = $it->row($t1);
    it_assert($row['state'] === 'synced' && !$row['queued'], 'row ' . it_short($row));
    return "tid $t1; $note";
});

$it->check('C2', 'editing the first post pushes again', function () use ($it, $t1, $t1Subject) {
    $mark = $it->mark();
    editFirst($it, $t1, 2, $t1Subject, "编辑后的正文 MBEDITED1。\n\n[hide=d30,50]按天数隐藏 MBHIDDEN3[/hide]");
    $note = queuedWithoutNetwork($it, $t1, $mark);
    $it->settle();
    $post = expectPush($it, $mark, $t1, '默认版块');
    it_assert(strpos($post['content'], 'MBEDITED1') !== false, 'edit not in content: ' . it_short($post['content']));
    it_assert(strpos($post['content'], 'MBHIDDEN3') === false, 'hidden text pushed');
    return $note;
});

$it->check('C3', 'a reply is not pushed', function () use ($it, $t1) {
    $mark = $it->mark();
    reply($it, $t1, 2, '这是一条回复 MBREPLY1');
    it_assert((int) $it->one('SELECT COUNT(*) FROM pre_forum_post WHERE tid=' . $t1 . ' AND first=0') === 1, 'reply not posted');
    $row = $it->row($t1);
    it_assert(!$row['queued'], 'the reply queued the thread');
    $it->settle();
    it_assert($it->pushed($mark, $t1) === null, 'thread pushed after a reply');
    foreach ($it->since($mark) as $request) {
        it_assert(strpos(json_encode($request, JSON_UNESCAPED_UNICODE), 'MBREPLY1') === false, 'reply text sent');
    }
    return 'not queued, nothing sent';
});

$it->check('E1', 'a guest topicadmin request with made-up tids queues nothing', function () use ($it, $t1) {
    $rows = $it->rowCount();
    $guestFormhash = '';
    $page = $it->guest->get('forum.php');
    if (preg_match('/formhash=([0-9a-f]{8})/', $page, $m)) {
        $guestFormhash = $m[1];
    }
    $it->guest->get('forum.php?mod=topicadmin&action=moderate&optgroup=4&operations[]=close&fid=2&moderate[]=987654&moderate[]=987655&moderate[]=' . $t1);
    moderate($it, $it->guest, 2, array(987656, 987657, $t1), 'close', array(), $guestFormhash !== '' ? $guestFormhash : 'deadbeef');
    it_assert($it->rowCount() === $rows, 'rows went from ' . $rows . ' to ' . $it->rowCount());
    it_assert($it->queuedCount() === 0, 'something was queued');
    it_assert(threadRow($it, $t1)['closed'] === 0, 'the guest closed the thread');
    return 'GET and POST as guest: no rows, nothing queued';
});

$it->check('C5', 'a moderator closing the thread queues it and it stays on MomoBird', function () use ($it, $t1) {
    $mark = $it->mark();
    moderate($it, $it->admin, 2, array($t1), 'close');
    it_assert(threadRow($it, $t1)['closed'] === 1, 'thread not closed');
    $note = queuedWithoutNetwork($it, $t1, $mark);
    $it->settle();
    it_assert(!$it->withdrawn($mark, $t1), 'closed thread withdrawn');
    it_assert(isset($it->mockPosts()[(string) $t1]), 'closed thread missing on the mock');
    return $note;
});

$it->check('C6', 'moving the thread to the recycle bin withdraws it', function () use ($it, $t1) {
    $mark = $it->mark();
    moderate($it, $it->admin, 2, array($t1), 'delete');
    it_assert(threadRow($it, $t1)['displayorder'] === -1, 'thread not in the recycle bin: ' . it_short(threadRow($it, $t1)));
    $it->settle();
    it_assert($it->withdrawn($mark, $t1), 'not withdrawn; requests: ' . it_short($it->since($mark)));
    it_assert(!isset($it->mockPosts()[(string) $t1]), 'still on the mock');
    return 'displayorder -1, POST /posts/delete';
});

$it->check('C7', 'restoring it from the recycle bin (modcp) pushes it again', function () use ($it, $t1) {
    modcpLogin($it);
    $mark = $it->mark();
    $it->admin->post('forum.php?mod=modcp&action=recyclebin&op=restore&fid=2', array(
        'formhash' => $it->formhash, 'dosubmit' => 'yes', 'moderate' => array($t1), 'oldop' => 'list',
    ));
    it_assert(threadRow($it, $t1)['displayorder'] === 0, 'not restored: ' . it_short(threadRow($it, $t1)));
    $note = queuedWithoutNetwork($it, $t1, $mark);
    $it->settle();
    expectPush($it, $mark, $t1, '默认版块');
    return $note;
});

$it->check('C4', 'moving the thread to another forum changes its category', function () use ($it, $t1) {
    $mark = $it->mark();
    moderate($it, $it->admin, 2, array($t1), 'move', array('moveto' => $it->fid('water'), 'type' => 'normal'));
    it_assert(threadRow($it, $t1)['fid'] === $it->fid('water'), 'not moved: ' . it_short(threadRow($it, $t1)));
    $note = queuedWithoutNetwork($it, $t1, $mark);
    $it->settle();
    expectPush($it, $mark, $t1, '灌水区');
    return $note;
});

$it->check('C9', 'deleting the thread for good withdraws it', function () use ($it, $t1) {
    $mark = $it->mark();
    moderate($it, $it->admin, $it->fid('water'), array($t1), 'delete');
    it_assert(threadRow($it, $t1) === null, 'thread still exists: ' . it_short(threadRow($it, $t1)));
    $it->settle();
    it_assert($it->withdrawn($mark, $t1), 'not withdrawn; requests: ' . it_short($it->since($mark)));
    it_assert($it->row($t1) === null, 'plugin row left: ' . it_short($it->row($t1)));
    return 'thread row gone, POST /posts/delete, plugin row removed';
});

// Moderation queue, drafts, appended text ----------------------------------------------------------
$it->check('C8', 'a thread waiting for moderation is not pushed; approving it in modcp pushes it', function () use ($it) {
    login($it, $it->member, getenv('IT_MEMBER_USER'), getenv('IT_MEMBER_PASSWORD'));
    $mark = $it->mark();
    $tid = newThread($it, $it->fid('review'), 'MB-T2 审核中的主题', '需要审核的正文 MBREVIEW1', array(), $it->member, formhash($it->member));
    it_assert(threadRow($it, $tid)['displayorder'] === -2, 'not in moderation: ' . it_short(threadRow($it, $tid)));
    $it->settle();
    it_assert($it->pushed($mark, $tid) === null, 'pushed while waiting for moderation');
    modcpLogin($it);
    $mark = $it->mark();
    $it->admin->post('forum.php?mod=modcp&action=moderate&op=threads&fid=' . $it->fid('review'), array(
        'formhash' => $it->formhash, 'modsubmit' => 'yes', 'modact' => 'validate', 'moderate' => array($tid), 'reason' => '',
    ));
    it_assert(threadRow($it, $tid)['displayorder'] === 0, 'not approved: ' . it_short(threadRow($it, $tid)));
    $note = queuedWithoutNetwork($it, $tid, $mark);
    $it->settle();
    expectPush($it, $mark, $tid, '审核区');
    return "tid $tid; $note";
});

$it->check('E2', 'publishing a draft (misc pubsave) and appending to a first post (postappend) are synced', function () use ($it) {
    $mark = $it->mark();
    $tid = newThread($it, 2, 'MB-T3 草稿主题', '草稿正文 MBDRAFT1', array('save' => 1));
    it_assert(threadRow($it, $tid)['displayorder'] === -4, 'not a draft: ' . it_short(threadRow($it, $tid)));
    $it->settle();
    it_assert($it->pushed($mark, $tid) === null, 'draft pushed');
    $mark = $it->mark();
    $it->admin->get('forum.php?mod=misc&action=pubsave&tid=' . $tid . '&formhash=' . $it->formhash);
    it_assert(threadRow($it, $tid)['displayorder'] === 0, 'draft not published: ' . it_short(threadRow($it, $tid)));
    $it->settle();
    expectPush($it, $mark, $tid, '默认版块');

    $mark = $it->mark();
    $it->admin->post('forum.php?mod=misc&action=postappend&tid=' . $tid . '&pid=' . firstPid($it, $tid) . '&extra=&postappendsubmit=yes&infloat=yes&inajax=1', array(
        'formhash' => $it->formhash, 'postappendmessage' => '补充内容 MBAPPEND1', 'handlekey' => 'postappend',
    ));
    it_assert(strpos((string) $it->one('SELECT message FROM pre_forum_post WHERE pid=' . firstPid($it, $tid)), 'MBAPPEND1') !== false, 'text not appended');
    $it->settle();
    $post = expectPush($it, $mark, $tid);
    it_assert(strpos($post['content'], 'MBAPPEND1') !== false, 'appended text not pushed');
    return "tid $tid";
});

$it->check('C12', 'two top-level forums with the same name get distinct categories; a sub forum uses its parent', function () use ($it) {
    $mark = $it->mark();
    $a = newThread($it, $it->fid('techTalk'), 'MB-T4 技术区的讨论', '技术区正文 MBTECH1');
    $b = newThread($it, $it->fid('lifeTalk'), 'MB-T5 生活区的讨论', '生活区正文 MBLIFE1');
    $c = newThread($it, $it->fid('sub'), 'MB-T6 子版块的主题', '子版块正文 MBSUB1');
    $it->settle();
    expectPush($it, $mark, $a, '技术区 / 综合讨论');
    expectPush($it, $mark, $b, '生活区 / 综合讨论');
    expectPush($it, $mark, $c, '灌水区');
    return "tids $a, $b, $c";
});

$innerTid = 0;
$it->check('C11a', 'a thread in the forum whose permissions change later is pushed', function () use ($it, &$innerTid) {
    $mark = $it->mark();
    $innerTid = newThread($it, $it->fid('inner'), 'MB-T7 内部区主题', '内部区正文 MBINNER1');
    $it->settle();
    expectPush($it, $mark, $innerTid, '内部区');
    return "tid $innerTid";
});

// Full sync from the admin page, then the front end -----------------------------------------------
$it->check('A4', 'admin page full sync runs in batches and publishes the assistant', function () use ($it) {
    $cursor = '';
    $batches = 0;
    $pushed = 0;
    do {
        $data = adminAjax($it, 'sync_batch', array('cursor' => $cursor));
        it_assert(!empty($data['ok']) || !empty($data['busy']), 'sync_batch failed: ' . it_short($data));
        noKey('a sync_batch answer', json_encode($data));
        $cursor = (string) ($data['cursor'] ?? '');
        $pushed += (int) ($data['result']['pushed'] ?? 0);
        $batches++;
    } while (empty($data['done']) && $batches < 50);
    it_assert(!empty($data['done']), 'full sync did not finish');
    $status = $it->kv('connect_status');
    it_assert(!empty($status['widget']['available']), 'assistant not available after the full sync: ' . it_short($status['widget'] ?? null)
        . ' widget_enable=' . it_short($it->kv('widget_enable')) . ' full_sync=' . it_short($it->kv('full_sync')) . ' last answer=' . it_short($data['result'] ?? $data));
    return "$batches batches, $pushed pushed, assistant enabled";
});

$it->check('C13', 'front end: assistant on every page, data-category on forum pages, podcast under the first post, no key', function () use ($it) {
    // A thread that is on MomoBird and visible.
    $tid = (int) $it->one("SELECT tid FROM pre_momobird_ai_post WHERE state='synced' AND category='技术区 / 综合讨论' LIMIT 1");
    $index = fetch($it, $it->guest, 'forum.php');
    it_assert(preg_match('/<script[^>]+loader\.v1\.js[^>]*data-project="pub_local"[^>]*>/', $index, $m) === 1, 'no loader on the index');
    it_assert(strpos($m[0], 'data-category') === false, 'index has a category');
    $forum = fetch($it, $it->guest, 'forum.php?mod=forumdisplay&fid=' . $it->fid('techTalk'));
    it_assert(strpos($forum, 'data-category="技术区 / 综合讨论"') !== false, 'forumdisplay without data-category');
    $sub = fetch($it, $it->guest, 'forum.php?mod=forumdisplay&fid=' . $it->fid('sub'));
    it_assert(strpos($sub, 'data-category="灌水区"') !== false, 'sub forum page without the parent category');
    $thread = fetch($it, $it->guest, 'forum.php?mod=viewthread&tid=' . $tid);
    it_assert(strpos($thread, 'data-category="技术区 / 综合讨论"') !== false, 'viewthread without data-category');
    it_assert(substr_count($thread, 'class="momobird-podcast"') === 1, 'podcast placeholders: ' . substr_count($thread, 'class="momobird-podcast"'));
    it_assert(strpos($thread, 'data-post="' . $tid . '"') !== false, 'podcast without data-post');
    it_assert(strpos($thread, 'podcast.v1.js') !== false, 'no podcast script');
    $touch = fetch($it, $it->guest, 'forum.php?mod=viewthread&tid=' . $tid . '&mobile=2');
    it_assert(strpos($touch, 'loader.v1.js') !== false, 'touch page without the loader');
    it_assert(substr_count($touch, 'class="momobird-podcast"') === 1, 'touch podcast placeholders: ' . substr_count($touch, 'class="momobird-podcast"'));
    return "tid $tid, PC and touch";
});

$it->check('C11', 'forum view permission changed in the backend: the next cron tick withdraws its threads', function () use ($it, &$innerTid) {
    $mark = $it->mark();
    // What admincp's forum edit writes (no hook fires there): view permission for administrators only.
    $it->sql("UPDATE pre_forum_forumfield SET viewperm='\t1\t' WHERE fid=" . $it->fid('inner'));
    $it->dz('cache');
    $page = fetch($it, $it->guest, 'forum.php?mod=forumdisplay&fid=' . $it->fid('inner'));
    it_assert(strpos($page, 'data-category="内部区"') === false, 'hidden forum still has data-category');
    $it->cron();
    it_assert($it->withdrawn($mark, $innerTid), 'not withdrawn; requests: ' . it_short($it->since($mark)));
    return "tid $innerTid withdrawn by the fingerprint";
});

$it->check('E5', 'the plugin cron runs through Discuz! on demand and moves to the next 5-minute slot', function () use ($it) {
    $before = (int) $it->one("SELECT lastrun FROM pre_common_cron WHERE type='plugin'");
    sleep(1);
    $it->cron();
    $rows = $it->sql("SELECT lastrun, nextrun FROM pre_common_cron WHERE type='plugin'");
    list($lastrun, $nextrun) = array((int) $rows[0][0], (int) $rows[0][1]);
    it_assert($lastrun > $before, "lastrun not updated ($before -> $lastrun)");
    it_assert($nextrun > time() && $nextrun <= time() + 300 && (int) date('i', $nextrun) % 5 === 0, 'nextrun ' . date('c', $nextrun));
    return 'nextrun ' . gmdate('H:i', $nextrun) . ' UTC';
});

// Locks ----------------------------------------------------------------------------------------------------
$it->check('E3', 'DiscuzStore lock: an overlapping drain reports busy; an expired lock row is taken over', function () use ($it) {
    $tid = (int) $it->one("SELECT tid FROM pre_momobird_ai_post WHERE state='synced' ORDER BY tid DESC LIMIT 1");
    // A content change so the drain has a request to make.
    $it->sql('UPDATE pre_forum_post SET message=CONCAT(message, \' MBLOCK1\') WHERE tid=' . $tid . ' AND first=1');
    $it->mockDelay(4000);
    $it->dz('enqueue ' . $tid);
    $it->sh('docker exec -d -u www-data ' . escapeshellarg($it->prefix . '-web') . ' php /opt/it/dz.php drain');
    $held = false;
    for ($i = 0; $i < 40 && !$held; $i++) {
        $held = $it->one("SELECT COUNT(*) FROM pre_momobird_ai_kv WHERE k='lock:sync'") === '1';
        usleep(100000);
    }
    it_assert($held, 'the first drain never took the lock');
    // Meanwhile another thread is queued (a hook) and a second request drains.
    $other = (int) $it->one("SELECT tid FROM pre_momobird_ai_post WHERE state='synced' AND tid<>" . $tid . ' ORDER BY tid DESC LIMIT 1');
    $it->dz('enqueue ' . $other);
    $second = $it->dz('drain');
    it_assert(in_array('正在同步，请稍后再试', (array) ($second['notes'] ?? array()), true), 'second drain: ' . it_short($second));
    it_assert((int) $second['pushed'] === 0 && (int) $second['unchanged'] === 0, 'second drain did work');
    for ($i = 0; $i < 100 && $it->one("SELECT COUNT(*) FROM pre_momobird_ai_kv WHERE k='lock:sync'") === '1'; $i++) {
        usleep(200000);
    }
    $it->mockDelay(0);
    it_assert($it->one("SELECT COUNT(*) FROM pre_momobird_ai_kv WHERE k='lock:sync'") === '0', 'lock not released');
    it_assert(strpos((string) ($it->mockPosts()[(string) $tid]['content'] ?? ''), 'MBLOCK1') !== false, 'first drain did not push');
    $it->dz('drain'); // the thread the busy drain left in the queue
    it_assert($it->queuedCount() === 0, 'queue not empty after the lock was free again');

    // A crashed run left an expired lock row.
    $it->sql('UPDATE pre_forum_post SET message=CONCAT(message, \' MBLOCK2\') WHERE tid=' . $tid . ' AND first=1');
    $it->sql("INSERT INTO pre_momobird_ai_kv (k, v) VALUES ('lock:sync', '{\"until\":" . (time() - 30) . ",\"token\":\"crashed\"}')");
    $it->dz('enqueue ' . $tid);
    $third = $it->dz('drain');
    it_assert((int) $third['pushed'] === 1, 'drain after an expired lock: ' . it_short($third));
    it_assert($it->one("SELECT COUNT(*) FROM pre_momobird_ai_kv WHERE k='lock:sync'") === '0', 'taken-over lock not released');
    return 'second drain busy, expired row taken over and released';
});

// MomoBird down or slow ----------------------------------------------------------------------------
$it->check('C14', 'posting while MomoBird is down or slow takes no longer; the cron catches up afterwards', function () use ($it) {
    $times = array();
    for ($i = 1; $i <= 3; $i++) {
        newThread($it, 2, 'MB-B' . $i . ' 基准主题', '基准正文 ' . $i);
        $times[] = $it->admin->lastTime;
        $it->quiesce();
    }
    $baseline = max($times);

    $it->mockStop();
    try {
    $down = newThread($it, 2, 'MB-T8 服务停掉时发的主题', '停机正文 MBDOWN1');
    $downTime = $it->admin->lastTime;
    it_assert(threadRow($it, $down)['displayorder'] === 0, 'post failed while MomoBird was down');
    it_assert($downTime <= $baseline + 1.0, sprintf('post took %.2f s, baseline %.2f s', $downTime, $baseline));
    $cronDown = $it->cron(); // FPM: the after-response drain already failed; mod_php: this tick fails
    $row = $it->row($down);
    it_assert($row !== null && $row['state'] === 'failed', 'row while down: ' . it_short($row));

    } finally {
        $it->mockStart();
    }
    $it->mockDelay(6000);
    $slow = newThread($it, 2, 'MB-T9 服务很慢时发的主题', '慢速正文 MBSLOW1');
    $slowTime = $it->admin->lastTime;
    it_assert($slowTime <= $baseline + 1.0, sprintf('post took %.2f s with a slow MomoBird, baseline %.2f s', $slowTime, $baseline));
    $it->quiesce();
    $it->mockDelay(0);

    // An hour later (retry interval): the cron pushes what failed.
    $mark = $it->mark();
    $it->sql('UPDATE pre_momobird_ai_post SET updated_at=updated_at-3700 WHERE state=\'failed\'');
    $it->cron();
    expectPush($it, $mark, $down, '默认版块');
    if ($it->pushed($mark, $slow) === null) {
        it_assert(isset($it->mockPosts()[(string) $slow]), 'slow thread never pushed');
    }
    return sprintf('baseline %.2f s, down %.2f s, slow %.2f s, cron page while down %.2f s', $baseline, $downTime, $slowTime, $cronDown);
});

if ($suite === 'x5') {
    $it->check('C15', 'X5 JSON editor post: pushed text comes from the blocks', function () use ($it) {
        $mark = $it->mark();
        $blocks = array('time' => time() * 1000, 'blocks' => array(
            array('id' => 'a1', 'type' => 'header', 'data' => array('text' => 'JSON 小标题', 'level' => 2)),
            array('id' => 'a2', 'type' => 'paragraph', 'data' => array('text' => 'JSON 段落 <b>MBJSON1</b>')),
            array('id' => 'a3', 'type' => 'list', 'data' => array('style' => 'unordered', 'items' => array('甲项 MBJSONLIST', '乙项'))),
            array('id' => 'a4', 'type' => 'image', 'data' => array('file' => array('url' => 'https://example.test/x.png'), 'caption' => 'MBJSONIMG')),
            array('id' => 'a5', 'type' => 'paragraph', 'data' => array('text' => '结尾 MBJSON2')),
        ), 'version' => '2.28.0');
        $tid = newThread($it, 2, 'MB-T10 新编辑器主题', '', array(
            'contentType' => 'json', 'contentEditor' => 'jsonEditor', 'content' => json_encode($blocks, JSON_UNESCAPED_UNICODE),
        ));
        $stored = (string) $it->one('SELECT content FROM pre_forum_post WHERE tid=' . $tid . ' AND first=1');
        $it->settle();
        $post = expectPush($it, $mark, $tid, '默认版块');
        foreach (array('## JSON 小标题', 'MBJSON1', '- 甲项 MBJSONLIST', 'MBJSON2') as $want) {
            it_assert(strpos($post['content'], $want) !== false, "content lacks $want: " . it_short($post['content']) . ' stored: ' . it_short($stored));
        }
        it_assert(strpos($post['content'], 'MBJSONIMG') === false && strpos($post['content'], '<b>') === false, 'image caption or HTML pushed: ' . it_short($post['content']));

        // Text appended later sits in the message only; viewthread shows it after the blocks.
        $mark = $it->mark();
        $it->admin->post('forum.php?mod=misc&action=postappend&tid=' . $tid . '&pid=' . firstPid($it, $tid) . '&extra=&postappendsubmit=yes&infloat=yes&inajax=1', array(
            'formhash' => $it->formhash, 'postappendmessage' => 'JSON 补充 MBJSONAPPEND', 'handlekey' => 'postappend',
        ));
        $it->settle();
        $post = expectPush($it, $mark, $tid);
        it_assert(strpos($post['content'], 'MBJSON2') !== false && strpos($post['content'], 'MBJSONAPPEND') !== false, 'append to a JSON post: ' . it_short($post['content']));
        return "tid $tid, blocks and appended text";
    });
}

$it->check('A5', 'no page HTML (front or admin) contains the key; no PHP errors from the plugin', function () use ($it, $adminPage) {
    fetch($it, $it->admin, $adminPage);
    fetch($it, $it->guest, 'forum.php?mobile=2');
    foreach ($GLOBALS['pages'] as $what => $html) {
        noKey($what, $html);
    }
    adminLogCheck($it);
    $errors = trim($it->web('grep -h "momobird_ai" /var/log/php-errors.log /var/www/html/data/log/*.php 2>/dev/null | grep -iv "cplog\|operation=config" | head -5 || true'));
    it_assert($errors === '', 'PHP errors: ' . $errors);
    return count($GLOBALS['pages']) . ' pages checked';
});

// --- summary --------------------------------------------------------------------------------------
$failed = array_filter($it->results, function ($r) {
    return $r[2] !== 'PASS';
});
printf("\n%d checks, %d passed, %d failed\n", count($it->results), count($it->results) - count($failed), count($failed));
file_put_contents($setupFile . '.results.json', json_encode($it->results, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
exit($failed ? 1 : 0);
