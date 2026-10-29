<?php
/**
 * Which threads a Discuz! hook call touched. Pure functions over the hook
 * parameters (research §3); the hook classes pass the result to
 * MomoBird_Bootstrap::enqueue(), which only writes the queue.
 *
 * Enqueueing too much is harmless (the engine re-reads every thread and sends
 * nothing when nothing changed); missing an event costs up to a day (the scan).
 */
final class MomoBird_Hooks
{
    /** At most this many tids per request (a moderation form posts a page of them). */
    const MAX_TIDS = 500;

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
        $message = isset($param['param']) && is_array($param['param']) ? $param['param'] : array();
        $id = isset($message[0]) && is_string($message[0]) ? $message[0] : '';
        if (!in_array($id, self::POST_MESSAGES, true)) {
            return array();
        }
        $values = isset($message[2]) && is_array($message[2]) ? $message[2] : array();
        return self::ids(isset($values['tid']) ? array($values['tid']) : array());
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
     * topicadmin_*_message / modcp_*_message.
     * topicadmin: $_GET['moderate'] holds the tids of action=moderate (move, close, open, delete,
     * stick, ...); other actions (delpost, banpost, split, merge, ...) work on the current thread.
     * modcp: thread moderation (validate, ignore, delete) and the recycle bin post tids in
     * $_GET['moderate']; reply moderation posts pids and member moderation uids, so those are skipped.
     *
     * @param array $get        $_GET
     * @param int   $currentTid $_G['tid']
     */
    public static function moderationTids(string $module, array $get, int $currentTid): array
    {
        $moderate = isset($get['moderate']) && is_array($get['moderate']) ? array_values($get['moderate']) : array();
        $action = isset($get['action']) && is_string($get['action']) ? $get['action'] : '';

        if ($module === 'topicadmin') {
            if ($currentTid > 0) {
                $moderate[] = $currentTid;
            }
            return self::ids($moderate);
        }
        if ($module === 'modcp') {
            $op = isset($get['op']) && is_string($get['op']) ? $get['op'] : '';
            if (($action === 'moderate' && $op !== 'replies' && $op !== 'members') || $action === 'recyclebin') {
                return self::ids($moderate);
            }
        }
        return array();
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

    /** Positive integer ids, unique, in order, at most MAX_TIDS. */
    private static function ids(array $values): array
    {
        $ids = array();
        foreach ($values as $value) {
            if (is_int($value)) {
                $id = $value;
            } elseif (is_string($value) && ctype_digit(trim($value)) && strlen(trim($value)) <= 10) {
                $id = (int) trim($value);
            } else {
                continue;
            }
            if ($id > 0 && $id <= 4294967295 && !isset($ids[$id])) {
                $ids[$id] = $id;
                if (count($ids) >= self::MAX_TIDS) {
                    break;
                }
            }
        }
        return array_values($ids);
    }
}
