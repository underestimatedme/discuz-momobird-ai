<?php
/**
 * MomoBird AI upgrade: make sure both tables exist. Later versions add their
 * column changes here (updatetable() is idempotent).
 */

if(!defined('IN_DISCUZ') || !defined('IN_ADMINCP')) {
	exit('Access Denied');
}

require_once __DIR__ . '/lib/Schema.php';

runquery(MomoBird_Schema::createSql());

require_once libfile('function/cache');
updatecache(array('plugin', 'setting'));

$finish = TRUE;
