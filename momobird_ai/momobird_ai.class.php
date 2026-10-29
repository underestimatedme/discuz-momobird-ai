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
 *   plugin_momobird_ai_forum / mobileplugin_momobird_ai_forum (forum.php):
 *     post_momobird_ai_message        new thread, edit, delete from the edit page
 *     topicadmin_momobird_ai_message  front-end thread management
 *     modcp_momobird_ai_message       moderator panel: moderation queue, recycle bin
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
		MomoBird_Bootstrap::enqueue(MomoBird_Hooks::deleteThreadTids((array) $param), 'deletethread');
	}

	public function deletepost($param) {
		MomoBird_Bootstrap::enqueue(MomoBird_Hooks::deletePostTids((array) $param), 'deletepost');
	}

	public function undeletethreads($param) {
		MomoBird_Bootstrap::enqueue(MomoBird_Hooks::undeleteTids((array) $param), 'undeletethreads');
	}
}

class plugin_momobird_ai_forum {

	public function post_momobird_ai_message($param) {
		MomoBird_Bootstrap::enqueue(MomoBird_Hooks::messageTids((array) $param), 'post');
	}

	public function topicadmin_momobird_ai_message($param) {
		MomoBird_Bootstrap::moderation('topicadmin');
	}

	public function modcp_momobird_ai_message($param) {
		MomoBird_Bootstrap::moderation('modcp');
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
		MomoBird_Bootstrap::enqueue(MomoBird_Hooks::deleteThreadTids((array) $param), 'deletethread');
	}

	public function deletepost($param) {
		MomoBird_Bootstrap::enqueue(MomoBird_Hooks::deletePostTids((array) $param), 'deletepost');
	}

	public function undeletethreads($param) {
		MomoBird_Bootstrap::enqueue(MomoBird_Hooks::undeleteTids((array) $param), 'undeletethreads');
	}
}

class mobileplugin_momobird_ai_forum {

	public function post_momobird_ai_message($param) {
		MomoBird_Bootstrap::enqueue(MomoBird_Hooks::messageTids((array) $param), 'post');
	}

	public function topicadmin_momobird_ai_message($param) {
		MomoBird_Bootstrap::moderation('topicadmin');
	}

	public function modcp_momobird_ai_message($param) {
		MomoBird_Bootstrap::moderation('modcp');
	}

	public function viewthread_postbottom_mobile_output() {
		return MomoBird_Bootstrap::podcast();
	}
}
