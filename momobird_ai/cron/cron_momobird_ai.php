<?php
/*
cronname:momobird_ai_cron
week:
day:
hour:
minute:0,5,10,15,20,25,30,35,40,45,50,55
*/

/**
 * MomoBird AI sync, every 5 minutes (Discuz! runs it from a visitor's request).
 * Settings fingerprint, queue, hourly retries and connect status, and one
 * 20-second segment of the daily scan: MomoBird_SyncEngine::cronTick(). On
 * PHP-FPM the work runs after the visitor's page has been sent.
 */

if(!defined('IN_DISCUZ')) {
	exit('Access Denied');
}

require_once __DIR__ . '/../lib/Bootstrap.php';

MomoBird_Bootstrap::cron();
