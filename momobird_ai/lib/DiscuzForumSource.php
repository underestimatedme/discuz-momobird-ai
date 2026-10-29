<?php
/**
 * MomoBird_ForumSource over Discuz!'s own tables.
 *
 * Threads and posts go through C::t('forum_thread') / C::t('forum_post'), which
 * know the split tables: archived threads live in forum_thread_<n> (ids from
 * fetch_thread_table_ids()), and a thread's posts in the post table its
 * posttableid names. Keyset scans (tid below a cursor, newest first) have no
 * table-class method, so they run one plain query per thread table on the table
 * name C::t('forum_thread') gives, and merge the results; nothing joins across
 * split tables. forum_forum and forum_forumfield are not split; they are read
 * with the columns the checks need only (forumfield rows also hold long texts).
 *
 * Author columns of the first post come from C::t('common_member') (archived
 * members included): username, groupid, and status as memberstatus, the way
 * forum_viewthread.php builds them. A missing member gives NULLs, which
 * MomoBird_Visibility treats as "hidden".
 */

if (!defined('IN_DISCUZ')) {
    exit('Access Denied');
}

final class MomoBird_DiscuzForumSource implements MomoBird_ForumSource
{
    const GUEST_GROUP = 7;

    /** @var int[]|null */
    private $threadTables;

    public function setting(): array
    {
        $rows = C::t('common_setting')->fetch_all_setting(MomoBird_Visibility::SETTING_KEYS);
        $rows = is_array($rows) ? $rows : array();
        // A fresh install stores bbclosed as '' and Discuz! closes the forum on any non-empty
        // value (discuz_application: if($this->var['setting']['bbclosed'])). Visibility wants 0/1.
        if (array_key_exists('bbclosed', $rows)) {
            $rows['bbclosed'] = empty($rows['bbclosed']) ? 0 : 1;
        }
        return $rows;
    }

    public function guestGroup(): array
    {
        $group = C::t('common_usergroup')->fetch(self::GUEST_GROUP);
        $field = C::t('common_usergroup_field')->fetch(self::GUEST_GROUP);
        if (!is_array($group) || !$group || !is_array($field) || !$field) {
            return array();
        }
        return array(
            'groupid' => (int) $group['groupid'],
            'readaccess' => isset($field['readaccess']) ? $field['readaccess'] : null,
            'allowvisit' => isset($group['allowvisit']) ? $group['allowvisit'] : null,
        );
    }

    public function forums(): array
    {
        return array_values(DB::fetch_all('SELECT fid, fup, type, name, status, displayorder FROM %t', array('forum_forum')));
    }

    public function forumFields(): array
    {
        $out = array();
        foreach (DB::fetch_all('SELECT fid, viewperm, password, formulaperm, price, redirect FROM %t', array('forum_forumfield')) as $row) {
            $out[(int) $row['fid']] = $row;
        }
        return $out;
    }

    public function rewriteRule(): ?string
    {
        global $_G;
        $status = isset($_G['setting']['rewritestatus']) ? $_G['setting']['rewritestatus'] : null;
        if (!is_array($status) || !in_array('forum_viewthread', $status, true)) {
            return null;
        }
        $rule = isset($_G['setting']['rewriterule']['forum_viewthread']) ? $_G['setting']['rewriterule']['forum_viewthread'] : null;
        return is_string($rule) && trim($rule) !== '' ? $rule : null;
    }

    public function threads(array $tids): array
    {
        $missing = self::ids($tids);
        $out = array();
        foreach ($this->threadTables() as $tableid) {
            if (!$missing) {
                break;
            }
            foreach (C::t('forum_thread')->fetch_all_by_tid($missing, 0, 0, $tableid) as $row) {
                $out[(int) $row['tid']] = $row;
            }
            $missing = array_values(array_diff($missing, array_keys($out)));
        }
        return $out;
    }

    public function firstPosts(array $threads): array
    {
        $byTable = array();
        foreach ($threads as $tid => $thread) {
            $table = is_array($thread) && isset($thread['posttableid']) ? (int) $thread['posttableid'] : 0;
            $byTable[$table][] = (int) $tid;
        }

        $posts = array();
        foreach ($byTable as $table => $tids) {
            foreach (array_chunk($tids, 500) as $chunk) {
                foreach (C::t('forum_post')->fetch_all_by_tid($table, $chunk, true, '', 0, 0, 1) as $post) {
                    $tid = (int) $post['tid'];
                    // One first post per thread; should there be more, the oldest wins.
                    if (!isset($posts[$tid]) || (int) $post['pid'] < (int) $posts[$tid]['pid']) {
                        $posts[$tid] = $post;
                    }
                }
            }
        }

        $uids = array();
        foreach ($posts as $post) {
            if ((int) $post['authorid'] > 0) {
                $uids[(int) $post['authorid']] = (int) $post['authorid'];
            }
        }
        $members = $uids ? C::t('common_member')->fetch_all(array_values($uids)) : array();

        foreach ($posts as $tid => $post) {
            $uid = (int) $post['authorid'];
            $member = $uid > 0 && isset($members[$uid]) && is_array($members[$uid]) ? $members[$uid] : null;
            $posts[$tid]['username'] = $member !== null ? $member['username'] : null;
            $posts[$tid]['groupid'] = $member !== null ? $member['groupid'] : null;
            $posts[$tid]['memberstatus'] = $member !== null ? $member['status'] : null;
        }
        return $posts;
    }

    public function threadsBelow(?int $belowTid, int $limit): array
    {
        if ($limit < 1 || ($belowTid !== null && $belowTid <= 1)) {
            return array();
        }
        $rows = array();
        foreach ($this->threadTables() as $tableid) {
            $table = C::t('forum_thread')->get_table_name($tableid);
            $found = $belowTid === null
                ? DB::fetch_all('SELECT * FROM %t ORDER BY tid DESC LIMIT %d', array($table, $limit))
                : DB::fetch_all('SELECT * FROM %t WHERE tid<%d ORDER BY tid DESC LIMIT %d', array($table, $belowTid, $limit));
            foreach ($found as $row) {
                $rows[(int) $row['tid']] = $row;
            }
        }
        krsort($rows);
        return array_values(array_slice($rows, 0, $limit, true));
    }

    public function threadIdsInForums(array $fids, ?int $belowTid, int $limit): array
    {
        $fids = self::ids($fids);
        if (!$fids || $limit < 1 || ($belowTid !== null && $belowTid <= 1)) {
            return array();
        }
        $tids = array();
        foreach ($this->threadTables() as $tableid) {
            $table = C::t('forum_thread')->get_table_name($tableid);
            $found = $belowTid === null
                ? DB::fetch_all('SELECT tid FROM %t WHERE fid IN (%n) ORDER BY tid DESC LIMIT %d', array($table, $fids, $limit))
                : DB::fetch_all('SELECT tid FROM %t WHERE fid IN (%n) AND tid<%d ORDER BY tid DESC LIMIT %d', array($table, $fids, $belowTid, $limit));
            foreach ($found as $row) {
                $tids[(int) $row['tid']] = (int) $row['tid'];
            }
        }
        krsort($tids);
        return array_values(array_slice($tids, 0, $limit, true));
    }

    public function countCandidates(array $fids, int $readaccess): int
    {
        $fids = self::ids($fids);
        if (!$fids) {
            return 0;
        }
        $count = 0;
        foreach ($this->threadTables() as $tableid) {
            $count += (int) DB::result_first(
                'SELECT COUNT(*) FROM %t WHERE fid IN (%n) AND displayorder>=0 AND closed<=1 AND readperm<=%d AND price=0 AND isgroup=0',
                array(C::t('forum_thread')->get_table_name($tableid), $fids, $readaccess)
            );
        }
        return $count;
    }

    /** @return int[] 0 (forum_thread) first, then the archive tables. */
    private function threadTables(): array
    {
        if ($this->threadTables === null) {
            $ids = array(0);
            foreach ((array) C::t('forum_thread')->fetch_thread_table_ids() as $id) {
                if ((int) $id > 0) {
                    $ids[] = (int) $id;
                }
            }
            $this->threadTables = array_values(array_unique($ids));
        }
        return $this->threadTables;
    }

    /** @return int[] */
    private static function ids(array $values): array
    {
        $ids = array();
        foreach ($values as $value) {
            $id = (int) $value;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        return array_values($ids);
    }
}
