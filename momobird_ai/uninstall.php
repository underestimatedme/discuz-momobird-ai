<?php
/**
 * MomoBird AI uninstall: drop the plugin's tables (settings, key, sync state).
 * Discuz! removes the plugin row, its cron job and its hooks itself. Documents
 * already on MomoBird stay there; delete the source in the MomoBird console.
 */

if(!defined('IN_DISCUZ') || !defined('IN_ADMINCP')) {
	exit('Access Denied');
}

require_once __DIR__ . '/lib/Schema.php';

runquery(MomoBird_Schema::dropSql());

$finish = TRUE;
