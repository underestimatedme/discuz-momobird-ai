<?php

require_once __DIR__ . '/FakeStore.php';

const MB_MF_DIR = __DIR__ . '/../momobird_ai';

/** Discuz!'s <item id="..."> tree as nested arrays (what xml2array() gives the installer). */
function mb_mf_items(SimpleXMLElement $node): array
{
    $out = array();
    foreach ($node->item as $item) {
        $id = (string) $item['id'];
        $out[$id] = count($item->item) > 0 ? mb_mf_items($item) : (string) $item;
    }
    return $out;
}

function mb_mf_xml(): array
{
    $text = file_get_contents(MB_MF_DIR . '/discuz_plugin_momobird_ai.xml');
    mb_assert(is_string($text), 'xml manifest missing');
    mb_assert(strpos($text, '<?xml version="1.0" encoding="ISO-8859-1"?>') === 0, 'xml header like the bundled plugins');
    // Discuz!'s class_xml parses ISO-8859-1 in and out, so the UTF-8 bytes pass through unchanged:
    // reading the same bytes as UTF-8 gives the strings the installer stores.
    $text = str_replace('encoding="ISO-8859-1"', 'encoding="UTF-8"', $text);
    $xml = simplexml_load_string($text, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
    mb_assert($xml instanceof SimpleXMLElement, 'xml manifest does not parse');
    return mb_mf_items($xml);
}

function mb_mf_json(): array
{
    $text = file_get_contents(MB_MF_DIR . '/discuz_plugin_momobird_ai.json');
    mb_assert(is_string($text), 'json manifest missing');
    $data = json_decode($text, true);
    mb_assert(is_array($data), 'json manifest does not parse: ' . json_last_error_msg());
    return $data;
}

/** Module lists: XML keys them "0", "1", ...; JSON has a list. */
function mb_mf_normalize(array $root): array
{
    $root['Data']['plugin']['__modules'] = array_values($root['Data']['plugin']['__modules']);
    return $root;
}

function mb_mf_load_classes(): void
{
    if (!defined('IN_DISCUZ')) {
        define('IN_DISCUZ', true);
    }
    require_once MB_MF_DIR . '/momobird_ai.class.php';
}

mb_test('manifest: xml and json parse and say the same', function () {
    $xml = mb_mf_xml();
    $json = mb_mf_json();
    mb_assert_same('Discuz! Plugin', $xml['Title'], 'xml title');
    mb_assert_same('Discuz! Plugin', $json['Title'], 'json title');
    mb_assert_same(mb_mf_normalize($xml), mb_mf_normalize($json), 'xml and json differ');
});

mb_test('manifest: identifier, name, version and compatible versions', function () {
    foreach (array('xml' => mb_mf_xml(), 'json' => mb_mf_json()) as $kind => $root) {
        $plugin = $root['Data']['plugin'];
        mb_assert_same('momobird_ai', $plugin['identifier'], $kind . ' identifier');
        mb_assert_same('MomoBird AI', $plugin['name'], $kind . ' name');
        mb_assert_same('0.1.0', $plugin['version'], $kind . ' plugin version');
        mb_assert_same('momobird_ai/', $plugin['directory'], $kind . ' directory');
        mb_assert_same('X3.5,X5.0', $root['Data']['version'], $kind . ' compatible versions');
        mb_assert_same('install.php', $root['Data']['installfile'], $kind . ' installfile');
        mb_assert_same('uninstall.php', $root['Data']['uninstallfile'], $kind . ' uninstallfile');
        mb_assert_same('upgrade.php', $root['Data']['upgradefile'], $kind . ' upgradefile');
        foreach (array('installfile', 'uninstallfile', 'upgradefile') as $file) {
            mb_assert(is_file(MB_MF_DIR . '/' . $root['Data'][$file]), $kind . ' ' . $file . ' does not exist');
        }
    }
});

mb_test('manifest: admin page and hook modules, no plugin variables', function () {
    foreach (array('xml' => mb_mf_xml(), 'json' => mb_mf_json()) as $kind => $root) {
        $modules = array();
        foreach ($root['Data']['plugin']['__modules'] as $module) {
            $modules[] = $module['type'] . ':' . $module['name'];
        }
        sort($modules);
        mb_assert_same(array('11:momobird_ai', '28:momobird_ai', '3:admincp'), $modules, $kind . ' modules');
        mb_assert(!array_key_exists('var', $root['Data']), $kind . ' declares plugin variables');
        mb_assert(strpos(json_encode($root), '"var"') === false, $kind . ' mentions var');
    }
    $text = file_get_contents(MB_MF_DIR . '/discuz_plugin_momobird_ai.xml');
    mb_assert(strpos($text, 'id="var"') === false, 'xml has a var item');
});

mb_test('manifest: the cron job runs every five minutes and has a name', function () {
    $text = file_get_contents(MB_MF_DIR . '/cron/cron_momobird_ai.php');
    mb_assert(is_string($text), 'cron file missing');
    // The same regexes cron_create() uses (function_plugin.php).
    mb_assert(preg_match("/minute\:(.+?)\n/", $text, $m) === 1, 'minute line');
    mb_assert_same('0,5,10,15,20,25,30,35,40,45,50,55', trim($m[1]), 'minute list');
    mb_assert(preg_match("/cronname\:(.+?)\n/", $text, $n) === 1, 'cronname line');
    $lang = mb_mf_xml()['Data']['language']['scriptlang'];
    mb_assert(isset($lang[trim($n[1])]) && $lang[trim($n[1])] !== '', 'cron name has a scriptlang entry');
    mb_assert(strpos($text, "if(!defined('IN_DISCUZ'))") !== false, 'cron file guards direct access');
});

mb_test('manifest: hook classes expose exactly the hook methods', function () {
    mb_mf_load_classes();
    // Discuz! registers every public method by name (cache_setting.php), so nothing else may be public.
    $expected = array(
        'plugin_momobird_ai' => array('deletepost', 'deletethread', 'global_footer', 'undeletethreads'),
        'plugin_momobird_ai_forum' => array('modcp_momobird_ai_message', 'post_momobird_ai_message', 'topicadmin_momobird_ai_message', 'viewthread_postbottom_output'),
        'mobileplugin_momobird_ai' => array('deletepost', 'deletethread', 'global_footer_mobile', 'undeletethreads'),
        'mobileplugin_momobird_ai_forum' => array('modcp_momobird_ai_message', 'post_momobird_ai_message', 'topicadmin_momobird_ai_message', 'viewthread_postbottom_mobile_output'),
    );
    foreach ($expected as $class => $methods) {
        mb_assert(class_exists($class, false), $class . ' missing');
        $public = get_class_methods($class);
        sort($public);
        mb_assert_same($methods, $public, $class . ' public methods');
        mb_assert(get_parent_class($class) === false, $class . ' must not inherit hooks of another class');
    }
});

mb_test('manifest: install creates the tables the engine needs', function () {
    $sql = MomoBird_Schema::createSql();
    mb_assert(preg_match('/CREATE TABLE IF NOT EXISTS pre_momobird_ai_post \((.+?)\) ENGINE=InnoDB;/s', $sql, $post) === 1, 'post table');
    foreach (array_keys(MomoBird_FakeStore::COLUMNS) as $column) {
        mb_assert(preg_match('/^\s*' . $column . ' /m', $post[1]) === 1, 'post table lacks ' . $column);
    }
    mb_assert(strpos($post[1], 'PRIMARY KEY (tid)') !== false, 'tid primary key');
    mb_assert(strpos($post[1], 'KEY priority_queued (priority, queued_at)') !== false, 'queue index');
    mb_assert(preg_match('/CREATE TABLE IF NOT EXISTS pre_momobird_ai_kv \((.+?)\) ENGINE=InnoDB;/s', $sql, $kv) === 1, 'kv table');
    mb_assert(preg_match('/^\s*v MEDIUMTEXT/m', $kv[1]) === 1, 'kv value is MEDIUMTEXT');
    mb_assert(strpos($kv[1], 'PRIMARY KEY (k)') !== false, 'kv primary key');
    // runquery() splits on ";\n" and drops lines starting with # or --.
    mb_assert(strpos($sql, '--') === false && strpos($sql, '#') === false, 'no comments in the install SQL');
    mb_assert(preg_match('/^DROP TABLE IF EXISTS pre_momobird_ai_post;\nDROP TABLE IF EXISTS pre_momobird_ai_kv;$/', MomoBird_Schema::dropSql()) === 1, 'drop SQL');

    foreach (array('install.php' => 'createSql', 'upgrade.php' => 'createSql', 'uninstall.php' => 'dropSql') as $file => $method) {
        $text = file_get_contents(MB_MF_DIR . '/' . $file);
        mb_assert(strpos($text, 'MomoBird_Schema::' . $method . '()') !== false, $file . ' runs ' . $method);
        mb_assert(strpos($text, '$finish = TRUE;') !== false, $file . ' finishes');
        mb_assert(strpos($text, "if(!defined('IN_DISCUZ') || !defined('IN_ADMINCP'))") !== false, $file . ' runs only inside the admin panel');
    }
    mb_assert(strpos(file_get_contents(MB_MF_DIR . '/install.php'), "updatecache(array('plugin', 'setting'))") !== false, 'install rebuilds caches');
});
