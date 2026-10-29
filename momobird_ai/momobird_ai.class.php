<?php
/**
 * MomoBird AI hook classes (modules type 11 and 28, both named momobird_ai).
 *
 * Discuz! registers every public method of these classes as a hook by its name
 * (source/function/cache/cache_setting.php), so they hold hook methods only and
 * do not inherit from each other. Hooks write the plugin's queue and print
 * cached markup; they never call MomoBird and never let an error reach the page.
 *
 *   plugin_momobird_ai / mobileplugin_momobird_ai (global):
 *     global_footer(_mobile)  assistant loader
 *     deletethread            thread deleted or moved to the recycle bin
 *     deletepost              posts deleted by thread id
 *     undeletethreads         threads restored from the recycle bin
 *     threadpubsave           a draft published
 *   plugin_momobird_ai_forum / mobileplugin_momobird_ai_forum (forum.php):
 *     post_momobird_ai_message        new thread, edit, delete from the edit page
 *     topicadmin_momobird_ai_message  front-end thread management
 *     modcp_momobird_ai_message       moderator panel: moderation queue, recycle bin
 *     misc_momobird_ai_message        text appended to a post
 *     viewthread_postbottom(_mobile)_output  podcast placeholder under the first post
 */

if(!defined('IN_DISCUZ')) {
	exit('Access Denied');
}

require_once __DIR__ . '/lib/Bootstrap.php';

class plugin_momobird_ai {

	public function global_footer() {
		return MomoBird_Bootstrap::footer();
	}

	public function deletethread($param) {
		MomoBird_Bootstrap::hook('deletethread', $param);
	}

	public function deletepost($param) {
		MomoBird_Bootstrap::hook('deletepost', $param);
	}

	public function undeletethreads($param) {
		MomoBird_Bootstrap::hook('undeletethreads', $param);
	}

	public function threadpubsave($param) {
		MomoBird_Bootstrap::hook('threadpubsave', $param);
	}
}

class plugin_momobird_ai_forum {

	public function post_momobird_ai_message($param) {
		MomoBird_Bootstrap::hook('post', $param);
	}

	public function misc_momobird_ai_message($param) {
		MomoBird_Bootstrap::hook('misc', $param);
	}

	public function topicadmin_momobird_ai_message($param) {
		MomoBird_Bootstrap::moderation('topicadmin', $param);
	}

	public function modcp_momobird_ai_message($param) {
		MomoBird_Bootstrap::moderation('modcp', $param);
	}

	public function viewthread_postbottom_output() {
		return MomoBird_Bootstrap::podcast();
	}
}

class mobileplugin_momobird_ai {

	public function global_footer_mobile() {
		return MomoBird_Bootstrap::footer();
	}

	public function deletethread($param) {
		MomoBird_Bootstrap::hook('deletethread', $param);
	}

	public function deletepost($param) {
		MomoBird_Bootstrap::hook('deletepost', $param);
	}

	public function undeletethreads($param) {
		MomoBird_Bootstrap::hook('undeletethreads', $param);
	}

	public function threadpubsave($param) {
		MomoBird_Bootstrap::hook('threadpubsave', $param);
	}
}

class mobileplugin_momobird_ai_forum {

	public function post_momobird_ai_message($param) {
		MomoBird_Bootstrap::hook('post', $param);
	}

	public function misc_momobird_ai_message($param) {
		MomoBird_Bootstrap::hook('misc', $param);
	}

	public function topicadmin_momobird_ai_message($param) {
		MomoBird_Bootstrap::moderation('topicadmin', $param);
	}

	public function modcp_momobird_ai_message($param) {
		MomoBird_Bootstrap::moderation('modcp', $param);
	}

	public function viewthread_postbottom_mobile_output() {
		return MomoBird_Bootstrap::podcast();
	}
}
