<?php
/**
 * Integration helper run inside the Discuz! web container (as www-data):
 *
 *   php /opt/it/dz.php setup <site-url>     settings for scripted posting, test forums, plugin install
 *   php /opt/it/dz.php cache                updatecache() for forums and settings
 *   php /opt/it/dz.php cron-arm             make the plugin cron due on the next page view
 *   php /opt/it/dz.php enqueue <tid>...     queue threads the way a hook does (no network)
 *   php /opt/it/dz.php drain                one engine drain, prints the result as JSON
 *
 * Discuz! clears non-superglobal globals in init(), so arguments are read from
 * $_SERVER['argv']. The cron is not started here (init_cron = false).
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$it_root = '/var/www/html';
chdir($it_root);
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_HOST'] = getenv('IT_HTTP_HOST') ?: 'localhost';
$_SERVER['HTTP_USER_AGENT'] = 'momobird-integration';
$_SERVER['REQUEST_URI'] = '/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $it_root . '/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';

require $it_root . '/source/class/class_core.php';
$it_app = C::app();
$it_app->init_cron = false;
$it_app->init_session = false;
$it_app->init_mobile = false;
$it_app->init();

require_once libfile('function/cache');

// After init(): Discuz! has unset this script's globals.
$it_command = isset($_SERVER['argv'][1]) ? $_SERVER['argv'][1] : '';
$it_rest = array_slice($_SERVER['argv'], 2);

function it_out($data)
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
}

function it_setting(array $values)
{
    foreach ($values as $key => $value) {
        DB::query('REPLACE INTO %t (skey, svalue) VALUES (%s, %s)', array('common_setting', $key, (string) $value));
    }
}

/** Copy the default forum (fid 2) and its forumfield row with overrides; returns the new fid. */
function it_forum(array $forum, array $field = array())
{
    $base = DB::fetch_first('SELECT * FROM %t WHERE fid=2', array('forum_forum'));
    $baseField = DB::fetch_first('SELECT * FROM %t WHERE fid=2', array('forum_forumfield'));
    unset($base['fid']);
    $row = array_merge($base, array('threads' => 0, 'posts' => 0, 'todayposts' => 0, 'yesterdayposts' => 0, 'lastpost' => '', 'recyclebin' => 0, 'modnewposts' => 0), $forum);
    $fid = (int) DB::insert('forum_forum', $row, true);
    $baseField['fid'] = $fid;
    DB::insert('forum_forumfield', array_merge($baseField, array('moderators' => '', 'description' => ''), $field));
    return $fid;
}

switch ($it_command) {
    case 'setup':
        $siteUrl = isset($it_rest[0]) ? rtrim($it_rest[0], '/') . '/' : 'http://localhost/';
        updatecache();
        // Scripted posting: no captcha, no flood interval, appending to posts allowed.
        it_setting(array('seccodestatus' => 0, 'floodctrl' => 0, 'newbiespan' => 0, 'postappend' => 1, 'siteurl' => $siteUrl, 'need_avatar' => 0, 'need_email' => 0));

        // A regular member (group 10) whose threads go through moderation where it is on.
        loaducenter();
        $memberName = getenv('IT_MEMBER_USER');
        $memberUid = (int) uc_user_register($memberName, getenv('IT_MEMBER_PASSWORD'), $memberName . '@example.test', '', '', '127.0.0.1');
        if ($memberUid < 1) {
            fwrite(STDERR, "member registration failed: $memberUid\n");
            exit(1);
        }
        // X5's profile table has a JSON column `fields` without a default.
        $profile = DB::fetch_first("SHOW COLUMNS FROM %t LIKE 'fields'", array('common_member_profile')) ? array('fields' => '{}') : array();
        C::t('common_member')->insert_user($memberUid, $memberName, md5(random(10)), $memberName . '@example.test', '127.0.0.1', 10, array('profile' => $profile));

        DB::query('UPDATE %t SET recyclebin=1 WHERE fid=2', array('forum_forum'));
        $tech = it_forum(array('fup' => 0, 'type' => 'group', 'name' => '技术区', 'displayorder' => 2));
        $life = it_forum(array('fup' => 0, 'type' => 'group', 'name' => '生活区', 'displayorder' => 3));
        $fids = array(
            'default' => 2,
            'water' => it_forum(array('fup' => 1, 'type' => 'forum', 'name' => '灌水区', 'displayorder' => 2)),
            'review' => it_forum(array('fup' => 1, 'type' => 'forum', 'name' => '审核区', 'displayorder' => 3, 'modnewposts' => 1)),
            'inner' => it_forum(array('fup' => 1, 'type' => 'forum', 'name' => '内部区', 'displayorder' => 4)),
            'techTalk' => it_forum(array('fup' => $tech, 'type' => 'forum', 'name' => '综合讨论', 'displayorder' => 1)),
            'lifeTalk' => it_forum(array('fup' => $life, 'type' => 'forum', 'name' => '综合讨论', 'displayorder' => 1)),
        );
        $fids['sub'] = it_forum(array('fup' => $fids['water'], 'type' => 'sub', 'name' => '新能源', 'displayorder' => 1));
        updatecache(array('forums', 'setting'));

        // Install the plugin the way admincp does: the manifest this version prefers, then install.php.
        define('IN_ADMINCP', true);
        require_once libfile('function/plugin');
        require_once libfile('function/admincp');
        $dir = 'momobird_ai';
        // X5 prefers the .json manifest (getimportfilename); X3.5 reads the .xml.
        $base = DISCUZ_ROOT . 'source/plugin/' . $dir . '/discuz_plugin_' . $dir;
        $manifest = function_exists('getimportfilename') ? getimportfilename($base) : $base . '.xml';
        $GLOBALS['importtxt'] = file_get_contents($manifest);
        $pluginarray = getimportdata('Discuz! Plugin');
        $pluginid = plugininstall($pluginarray, '', 1);
        $finish = false;
        include DISCUZ_ROOT . 'source/plugin/' . $dir . '/' . $pluginarray['installfile'];
        updatecache(array('plugin', 'setting', 'styles'));
        it_out(array('fids' => $fids, 'member' => $memberUid, 'pluginid' => (int) $pluginid, 'manifest' => basename($manifest), 'installed' => $finish === true));
        break;

    case 'cache':
        updatecache(array('forums', 'setting', 'plugin'));
        it_out(array('ok' => true));
        break;

    case 'cron-arm':
        DB::query("UPDATE %t SET available=1, nextrun=0 WHERE type='plugin'", array('common_cron'));
        savecache('cronnextrun', 0);
        it_out(array('ok' => true));
        break;

    case 'enqueue':
        require_once DISCUZ_ROOT . 'source/plugin/momobird_ai/lib/Bootstrap.php';
        MomoBird_Bootstrap::engine()->enqueue($it_rest, 'integration');
        it_out(array('queued' => MomoBird_Bootstrap::store()->countQueued()));
        break;

    case 'drain':
        require_once DISCUZ_ROOT . 'source/plugin/momobird_ai/lib/Bootstrap.php';
        $result = MomoBird_Bootstrap::engine()->drain(time() + 20);
        it_out($result->toArray() + array('queued_after' => MomoBird_Bootstrap::store()->countQueued()));
        break;

    default:
        fwrite(STDERR, "unknown command\n");
        exit(2);
}
