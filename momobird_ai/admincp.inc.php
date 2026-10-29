<?php
/**
 * 应用 → 插件 → MomoBird AI (module type 3, pmod=admincp). Discuz! includes this file from
 * admincp_plugins.php after its own admin checks; the page itself lives in
 * MomoBird_AdminPage. AJAX actions (mb_action=...) answer JSON: the admincp header that
 * Discuz! already wrote into its output buffer is thrown away first.
 */

if (!defined('IN_DISCUZ') || !defined('IN_ADMINCP')) {
    exit('Access Denied');
}

require_once __DIR__ . '/lib/Bootstrap.php';

$momobirdPageUrl = ADMINSCRIPT . '?action=plugins&operation=config&do=' . intval($plugin['pluginid']) . '&identifier=momobird_ai&pmod=admincp';
$momobirdPage = MomoBird_Bootstrap::adminPage($momobirdPageUrl, (string) FORMHASH, function ($timestamp) {
    return dgmdate($timestamp, 'Y-m-d H:i:s');
});
$momobirdOut = $momobirdPage->handle(isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET', is_array($_GET) ? $_GET : array());

if ($momobirdOut['type'] === 'json') {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    @header('Content-Type: application/json; charset=utf-8');
    @header('Cache-Control: no-store');
    @header('X-Content-Type-Options: nosniff');
    echo $momobirdOut['body'];
    exit();
}

echo $momobirdOut['body'];
