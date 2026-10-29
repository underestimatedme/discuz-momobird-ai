<?php
/**
 * MomoBird_Store over the plugin's tables pre_momobird_ai_post and
 * pre_momobird_ai_kv (MomoBird_Schema), through Discuz!'s DB class with %t
 * placeholders. Semantics match tests/FakeStore.php.
 *
 * Every statement passes Discuz!'s SQL safety check (querysafe): no
 * sub-selects, no IF()/CHAR() calls, no comments.
 *
 * Locks are rows in the kv table ("lock:<name>"), not discuz_process: without a
 * memory cache discuz_process checks and then REPLACEs common_process, so two
 * requests can both take the lock. Here the row is taken with INSERT IGNORE on
 * the primary key, an expired one with a compare-and-swap UPDATE, and released
 * only by its owner.
 */

if (!defined('IN_DISCUZ')) {
    exit('Access Denied');
}

final class MomoBird_DiscuzStore implements MomoBird_Store
{
    const POST = 'momobird_ai_post';
    const KV = 'momobird_ai_kv';
    const CHUNK = 500;
    const LOCK_PREFIX = 'lock:';

    const INT_COLUMNS = array('tid', 'attempts', 'remote', 'content_changed_at', 'updated_at', 'priority', 'listed');
    const TEXT_COLUMNS = array('state' => 16, 'content_hash' => 64, 'body_hash' => 64, 'category' => 255, 'title' => 255, 'last_error' => 255);

    /** @var array<string, string> lock name => the kv value this process wrote */
    private $locks = array();

    public function posts(array $tids): array
    {
        $out = array();
        foreach (array_chunk(self::ids($tids), self::CHUNK) as $chunk) {
            foreach (DB::fetch_all('SELECT * FROM %t WHERE tid IN (%n)', array(self::POST, $chunk)) as $row) {
                $row = self::row($row);
                $out[$row['tid']] = $row;
            }
        }
        return $out;
    }

    public function savePost(array $row): void
    {
        $tid = isset($row['tid']) ? (int) $row['tid'] : 0;
        if ($tid < 1) {
            return;
        }
        $values = array('tid' => $tid);
        foreach (self::TEXT_COLUMNS as $column => $max) {
            $values[$column] = self::cut(isset($row[$column]) ? (string) $row[$column] : ($column === 'state' ? 'pending' : ''), $max);
        }
        foreach (array('attempts', 'remote', 'content_changed_at', 'updated_at') as $column) {
            $values[$column] = isset($row[$column]) ? max(0, (int) $row[$column]) : 0;
        }

        $columns = array_keys($values);
        $quoted = array();
        $updates = array();
        foreach ($values as $column => $value) {
            $quoted[] = is_int($value) ? (string) $value : DB::quote($value);
            if ($column !== 'tid') {
                $updates[] = $column . '=VALUES(' . $column . ')';
            }
        }
        // queued_at, priority and listed are never written here: a hook may have queued the
        // thread meanwhile, and listed belongs to the running reconcile.
        DB::query('INSERT INTO ' . DB::table(self::POST) . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $quoted) . ')'
            . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates));
    }

    public function deletePosts(array $tids): void
    {
        foreach (array_chunk(self::ids($tids), self::CHUNK) as $chunk) {
            DB::query('DELETE FROM %t WHERE tid IN (%n) AND queued_at IS NULL', array(self::POST, $chunk));
            // What is left was queued again meanwhile: keep it so the hook's event is not lost.
            DB::query("UPDATE %t SET state='pending', remote=0, content_hash='' WHERE tid IN (%n) AND queued_at IS NOT NULL", array(self::POST, $chunk));
        }
    }

    public function enqueue(array $tids, int $now, bool $existingOnly, bool $priority = false): void
    {
        $now = max(0, $now);
        foreach (array_chunk(self::ids($tids), self::CHUNK) as $chunk) {
            if (!$existingOnly) {
                $rows = array();
                foreach ($chunk as $tid) {
                    $rows[] = '(' . $tid . ", 'pending', " . $now . ', ' . $now . ', ' . ($priority ? 1 : 0) . ')';
                }
                DB::query('INSERT IGNORE INTO ' . DB::table(self::POST) . ' (tid, state, updated_at, queued_at, priority) VALUES ' . implode(', ', $rows));
            }
            DB::query('UPDATE %t SET queued_at=%d WHERE tid IN (%n) AND queued_at IS NULL', array(self::POST, $now, $chunk));
            if ($priority) {
                DB::query('UPDATE %t SET priority=1 WHERE tid IN (%n)', array(self::POST, $chunk));
            }
        }
    }

    public function dequeue(array $tids): void
    {
        foreach (array_chunk(self::ids($tids), self::CHUNK) as $chunk) {
            DB::query('UPDATE %t SET queued_at=NULL, priority=0 WHERE tid IN (%n)', array(self::POST, $chunk));
        }
    }

    public function queued(int $limit): array
    {
        if ($limit < 1) {
            return array();
        }
        return self::column(DB::fetch_all('SELECT tid FROM %t WHERE queued_at IS NOT NULL ORDER BY priority DESC, tid DESC LIMIT %d', array(self::POST, $limit)));
    }

    public function countQueued(): int
    {
        return (int) DB::result_first('SELECT COUNT(*) FROM %t WHERE queued_at IS NOT NULL', array(self::POST));
    }

    public function enqueueState(string $state, int $now): int
    {
        DB::query('UPDATE %t SET queued_at=%d WHERE state=%s AND queued_at IS NULL', array(self::POST, max(0, $now), $state));
        return $this->countState($state);
    }

    public function dueRetries(int $failedBefore, int $maxAttempts, int $limit): array
    {
        if ($limit < 1) {
            return array();
        }
        return self::column(DB::fetch_all(
            "SELECT tid FROM %t WHERE state='failed' AND attempts<=%d AND updated_at<=%d AND queued_at IS NULL ORDER BY tid DESC LIMIT %d",
            array(self::POST, $maxAttempts, $failedBefore, $limit)
        ));
    }

    public function markRemote(array $tids, int $now): void
    {
        $now = max(0, $now);
        foreach (array_chunk(self::ids($tids), self::CHUNK) as $chunk) {
            $rows = array();
            foreach ($chunk as $tid) {
                $rows[] = '(' . $tid . ", 'orphan', 1, 1, " . $now . ')';
            }
            DB::query('INSERT INTO ' . DB::table(self::POST) . ' (tid, state, remote, listed, updated_at) VALUES ' . implode(', ', $rows)
                . ' ON DUPLICATE KEY UPDATE remote=1, listed=1');
        }
    }

    public function clearListed(): void
    {
        DB::query('UPDATE %t SET listed=0 WHERE listed<>0', array(self::POST));
    }

    public function requeueUnlisted(int $since, int $now): int
    {
        $where = "state='synced' AND listed=0 AND updated_at<=" . max(0, $since);
        $count = (int) DB::result_first('SELECT COUNT(*) FROM %t WHERE ' . $where, array(self::POST));
        if ($count > 0) {
            DB::query("UPDATE %t SET content_hash='' WHERE " . $where, array(self::POST));
            DB::query("UPDATE %t SET queued_at=%d WHERE state='synced' AND listed=0 AND content_hash='' AND queued_at IS NULL", array(self::POST, max(0, $now)));
        }
        return $count;
    }

    public function rowsBetween(int $low, int $high, int $limit): array
    {
        if ($limit < 1 || $high < $low) {
            return array();
        }
        $out = array();
        foreach (DB::fetch_all("SELECT tid, remote FROM %t WHERE tid>=%d AND tid<=%d AND state NOT IN ('gone', 'orphan') ORDER BY tid DESC LIMIT %d",
            array(self::POST, max(0, $low), $high, $limit)) as $row) {
            $out[(int) $row['tid']] = (int) $row['remote'];
        }
        return $out;
    }

    public function markGone(array $tids, int $now): void
    {
        $now = max(0, $now);
        foreach (array_chunk(self::ids($tids), self::CHUNK) as $chunk) {
            $rows = array();
            foreach ($chunk as $tid) {
                $rows[] = '(' . $tid . ", 'gone', 1, " . $now . ')';
            }
            DB::query('INSERT INTO ' . DB::table(self::POST) . ' (tid, state, remote, updated_at) VALUES ' . implode(', ', $rows)
                . " ON DUPLICATE KEY UPDATE state='gone', remote=1, updated_at=VALUES(updated_at)");
        }
    }

    public function resetSync(int $now): int
    {
        $now = max(0, $now);
        DB::query("DELETE FROM %t WHERE state IN ('orphan', 'gone') AND queued_at IS NULL", array(self::POST));
        DB::query("UPDATE %t SET state='pending', content_hash='', body_hash='', remote=0, attempts=0, last_error='', listed=0, updated_at=%d",
            array(self::POST, $now));
        DB::query('UPDATE %t SET queued_at=%d WHERE queued_at IS NULL', array(self::POST, $now));
        return (int) DB::result_first('SELECT COUNT(*) FROM %t', array(self::POST));
    }

    public function clearOrphans(): void
    {
        DB::query("DELETE FROM %t WHERE state='orphan' AND queued_at IS NULL", array(self::POST));
    }

    public function stateTids(string $state, int $limit): array
    {
        if ($limit < 1) {
            return array();
        }
        return self::column(DB::fetch_all('SELECT tid FROM %t WHERE state=%s ORDER BY tid DESC LIMIT %d', array(self::POST, $state, $limit)));
    }

    public function countState(string $state): int
    {
        return (int) DB::result_first('SELECT COUNT(*) FROM %t WHERE state=%s', array(self::POST, $state));
    }

    public function countRemote(): int
    {
        return (int) DB::result_first('SELECT COUNT(*) FROM %t WHERE remote=1', array(self::POST));
    }

    public function failures(int $limit): array
    {
        if ($limit < 1) {
            return array();
        }
        $rows = DB::fetch_all(
            "SELECT * FROM %t WHERE state IN ('failed', 'rejected') ORDER BY updated_at DESC, tid DESC LIMIT %d",
            array(self::POST, $limit)
        );
        return array_map(array(__CLASS__, 'row'), $rows);
    }

    public function get(string $key): ?array
    {
        $value = DB::result_first('SELECT v FROM %t WHERE k=%s', array(self::KV, $key));
        if (!is_string($value) || $value === '') {
            return null;
        }
        $data = json_decode($value, true);
        return is_array($data) ? $data : null;
    }

    public function set(string $key, ?array $value): void
    {
        if ($value === null) {
            DB::query('DELETE FROM %t WHERE k=%s', array(self::KV, $key));
            return;
        }
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($json)) {
            return;
        }
        DB::query('INSERT INTO %t (k, v) VALUES (%s, %s) ON DUPLICATE KEY UPDATE v=VALUES(v)', array(self::KV, $key, $json));
    }

    public function lock(string $name, int $ttl): bool
    {
        $key = self::LOCK_PREFIX . $name;
        $now = time();
        $mine = json_encode(array('until' => $now + max(1, $ttl), 'token' => bin2hex(random_bytes(8))));

        DB::query('INSERT IGNORE INTO %t (k, v) VALUES (%s, %s)', array(self::KV, $key, $mine));
        if (DB::affected_rows() === 1) {
            $this->locks[$name] = $mine;
            return true;
        }

        $held = DB::result_first('SELECT v FROM %t WHERE k=%s', array(self::KV, $key));
        if (!is_string($held)) {
            return false; // released between the two statements: the next run takes it
        }
        $data = json_decode($held, true);
        if (is_array($data) && isset($data['until']) && (int) $data['until'] >= $now) {
            return false;
        }
        // Expired (a crashed run): take it over only if nobody else did meanwhile.
        DB::query('UPDATE %t SET v=%s WHERE k=%s AND v=%s', array(self::KV, $mine, $key, $held));
        if (DB::affected_rows() === 1) {
            $this->locks[$name] = $mine;
            return true;
        }
        return false;
    }

    public function unlock(string $name): void
    {
        if (!isset($this->locks[$name])) {
            return;
        }
        DB::query('DELETE FROM %t WHERE k=%s AND v=%s', array(self::KV, self::LOCK_PREFIX . $name, $this->locks[$name]));
        unset($this->locks[$name]);
    }

    /** A post row with integer columns as ints and queued_at as int|null. */
    private static function row(array $row): array
    {
        foreach (self::INT_COLUMNS as $column) {
            $row[$column] = isset($row[$column]) ? (int) $row[$column] : 0;
        }
        $row['queued_at'] = isset($row['queued_at']) && $row['queued_at'] !== null ? (int) $row['queued_at'] : null;
        foreach (array_keys(self::TEXT_COLUMNS) as $column) {
            $row[$column] = isset($row[$column]) ? (string) $row[$column] : '';
        }
        return $row;
    }

    /** @return int[] */
    private static function column(array $rows): array
    {
        $out = array();
        foreach ($rows as $row) {
            $out[] = (int) $row['tid'];
        }
        return $out;
    }

    /** @return int[] positive unique ids */
    private static function ids(array $tids): array
    {
        $ids = array();
        foreach ($tids as $tid) {
            $tid = (int) $tid;
            if ($tid > 0) {
                $ids[$tid] = $tid;
            }
        }
        return array_values($ids);
    }

    /** At most $max characters (the columns count characters, utf8mb4). */
    private static function cut(string $text, int $max): string
    {
        if (preg_match('//u', $text) !== 1) {
            $text = (string) @iconv('UTF-8', 'UTF-8//IGNORE', $text);
        }
        if (preg_match('/^.{0,' . $max . '}/us', $text, $match) === 1) {
            return $match[0];
        }
        return '';
    }
}
