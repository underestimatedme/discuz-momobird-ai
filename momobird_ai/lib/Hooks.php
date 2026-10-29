<?php
/**
 * Which threads a Discuz! hook call touched. Pure functions over the hook
 * parameters (research §3); the hook classes pass the result to
 * MomoBird_Bootstrap::enqueue(), which only writes the queue.
 *
 * Enqueueing too much is harmless (the engine re-reads every thread and sends
 * nothing when nothing changed); missing an event costs up to a day (the scan).
 */

if (!defined('IN_DISCUZ')) {
    exit('Access Denied');
}

final class MomoBird_Hooks
{
    /**
     * At most this many tids taken from the request ($_GET) per call. Function hooks
     * (deletethread, deletepost, undeletethreads, threadpubsave) pass every tid they get:
     * an admin batch can touch thousands, and each one must be withdrawn.
     */
    const MAX_REQUEST_TIDS = 500;

    /** Success messages after which a topicadmin / modcp action has changed threads. */
    const MODERATION_MESSAGES = array(
        'topicadmin' => array('admin_succeed', 'admin_repair_succeed'),
        'modcp' => array('modcp_mod_succeed', 'modcp_recyclebin_delete_succeed', 'modcp_recyclebin_restore_succeed'),
    );

    /** misc message hook: text appended to a post (forum_misc.php action=postappend). */
    const MISC_MESSAGES = array('postappend_add_succeed');

    /** showmessage() ids after which the thread (or its first post) may have changed. */
    const POST_MESSAGES = array(
        'post_newthread_succeed',
        'post_newthread_mod_succeed',
        'post_edit_succeed',
        'edit_newthread_mod_succeed',
        'post_edit_delete_succeed',
    );

    /**
     * post_*_message: $param['param'] = array($messageId, $urlForward, $values, $extra, $custom),
     * $values holds fid, tid, pid.
     */
    public static function messageTids(array $param): array
    {
        return self::messageTid($param, self::POST_MESSAGES);
    }

    /** misc_*_message: postappend_add_succeed carries tid and pid in $values. */
    public static function miscTids(array $param): array
    {
        return self::messageTid($param, self::MISC_MESSAGES);
    }

    /**
     * threadpubsave (a draft published from forum.php?mod=misc&action=pubsave): step 'check'
     * before, 'save' after; $param['param'] = array($tid, $passapproval).
     */
    public static function publishTids(array $param): array
    {
        if (($param['step'] ?? '') !== 'save') {
            return array();
        }
        return self::ids(self::first($param));
    }

    /**
     * deletethread: $param['step'] is 'check' (before) then 'delete' (after, or just before
     * the recycle-bin update); $param['param'] = array($tids, $membercount, $credit, $ponly).
     */
    public static function deleteThreadTids(array $param): array
    {
        if (($param['step'] ?? '') !== 'delete') {
            return array();
        }
        return self::ids(self::first($param));
    }

    /** deletepost: $param['param'] = array($ids, $idtype, ...); only thread ids matter. */
    public static function deletePostTids(array $param): array
    {
        if (($param['step'] ?? '') !== 'delete') {
            return array();
        }
        $args = isset($param['param']) && is_array($param['param']) ? $param['param'] : array();
        if (($args[1] ?? '') !== 'tid') {
            return array();
        }
        return self::ids(self::first($param));
    }

    /** undeletethreads: $param['param'] = array($tids); fires before the restore. */
    public static function undeleteTids(array $param): array
    {
        return self::ids(self::first($param));
    }

    /**
     * topicadmin_*_message / modcp_*_message. These hooks run for every showmessage() of the
     * module, errors included, and the ids come from the request: only a moderator's successful
     * action counts (anyone can send moderate[]=... and get admin_nopermission).
     * topicadmin: $_GET['moderate'] holds the tids of action=moderate (move, close, open, delete,
     * stick, ...); other actions (delpost, banpost, split, merge, repair, ...) work on the current
     * thread. modcp: thread moderation (validate, ignore, delete) and the recycle bin post tids
     * in $_GET['moderate']; reply moderation posts pids and member moderation uids, so those are
     * skipped. At most MAX_REQUEST_TIDS ids.
     *
     * @param array $param      the hook's parameter (message id in $param['param'][0])
     * @param array $get        $_GET
     * @param int   $currentTid $_G['tid']
     * @param bool  $moderator  logged in, and an admin/moderator (adminid > 0) or a moderator of this forum
     */
    public static function moderationTids(string $module, array $param, array $get, int $currentTid, bool $moderator): array
    {
        $message = isset($param['param']) && is_array($param['param']) ? $param['param'] : array();
        $id = isset($message[0]) && is_string($message[0]) ? $message[0] : '';
        if (!$moderator || !isset(self::MODERATION_MESSAGES[$module]) || !in_array($id, self::MODERATION_MESSAGES[$module], true)) {
            return array();
        }

        $moderate = isset($get['moderate']) && is_array($get['moderate']) ? array_values($get['moderate']) : array();
        $action = isset($get['action']) && is_string($get['action']) ? $get['action'] : '';

        if ($module === 'topicadmin') {
            if ($currentTid > 0) {
                $moderate[] = $currentTid;
            }
            return self::ids($moderate, self::MAX_REQUEST_TIDS);
        }
        $op = isset($get['op']) && is_string($get['op']) ? $get['op'] : '';
        if (($action === 'moderate' && $op !== 'replies' && $op !== 'members') || $action === 'recyclebin') {
            return self::ids($moderate, self::MAX_REQUEST_TIDS);
        }
        return array();
    }

    /**
     * Queue ids that came from the request: threads that exist get a row as usual; any other id
     * is queued only if the plugin already has a row for it (a deleted thread MomoBird may
     * hold), so made-up ids never create rows.
     */
    public static function enqueueRequested(MomoBird_SyncEngine $engine, MomoBird_ForumSource $source, array $tids, string $reason): void
    {
        $tids = self::ids($tids, self::MAX_REQUEST_TIDS);
        if (!$tids) {
            return;
        }
        $existing = array_keys($source->threads($tids));
        $existing = array_values(array_intersect($tids, array_map('intval', $existing)));
        $others = array_values(array_diff($tids, $existing));
        if ($existing) {
            $engine->enqueue($existing, $reason);
        }
        if ($others) {
            $engine->enqueue($others, $reason, true);
        }
    }

    /** First argument of a function hook, as a list. */
    private static function first(array $param): array
    {
        $args = isset($param['param']) && is_array($param['param']) ? $param['param'] : array();
        if (!array_key_exists(0, $args)) {
            return array();
        }
        return is_array($args[0]) ? array_values($args[0]) : array($args[0]);
    }

    /** The tid in $values of a message whose id is in $ids. */
    private static function messageTid(array $param, array $ids): array
    {
        $message = isset($param['param']) && is_array($param['param']) ? $param['param'] : array();
        $id = isset($message[0]) && is_string($message[0]) ? $message[0] : '';
        if (!in_array($id, $ids, true)) {
            return array();
        }
        $values = isset($message[2]) && is_array($message[2]) ? $message[2] : array();
        return self::ids(isset($values['tid']) ? array($values['tid']) : array());
    }

    /** Positive integer ids (unsigned INT range), unique, in order; at most $cap when given. */
    private static function ids(array $values, int $cap = 0): array
    {
        $ids = array();
        foreach ($values as $value) {
            if (is_int($value)) {
                $id = $value;
            } elseif (is_string($value) && preg_match('/^\s*(\d{1,10})\s*$/D', $value, $match) === 1) {
                $id = (int) $match[1];
            } else {
                continue;
            }
            if ($id > 0 && $id <= 4294967295 && !isset($ids[$id])) {
                $ids[$id] = $id;
                if ($cap > 0 && count($ids) >= $cap) {
                    break;
                }
            }
        }
        return array_values($ids);
    }
}
