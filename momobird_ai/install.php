<?php
/**
 * MomoBird AI install: create the plugin's two tables and rebuild the plugin
 * and hook caches (hooks are registered from the setting cache).
 */

if(!defined('IN_DISCUZ') || !defined('IN_ADMINCP')) {
	exit('Access Denied');
}

require_once __DIR__ . '/lib/Schema.php';

runquery(MomoBird_Schema::createSql());

require_once libfile('function/cache');
updatecache(array('plugin', 'setting'));

$finish = TRUE;
