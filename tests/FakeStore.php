<?php

/** In-memory MomoBird_Store with the same semantics the Discuz! adapter must have. */
class MomoBird_FakeStore implements MomoBird_Store
{
    /** @var array<int, array> */
    public $rows = array();
    /** @var array<string, array> */
    public $kv = array();

    const COLUMNS = array(
        'tid' => 0, 'state' => 'pending', 'content_hash' => '', 'body_hash' => '', 'category' => '', 'title' => '',
        'attempts' => 0, 'last_error' => '', 'remote' => 0, 'content_changed_at' => 0, 'updated_at' => 0, 'queued_at' => null,
    );

    public function posts(array $tids): array
    {
        $out = array();
        foreach ($tids as $tid) {
            if (isset($this->rows[(int) $tid])) {
                $out[(int) $tid] = $this->rows[(int) $tid];
            }
        }
        return $out;
    }

    public function savePost(array $row): void
    {
        $tid = (int) $row['tid'];
        $queued = isset($this->rows[$tid]) ? $this->rows[$tid]['queued_at'] : null;
        $clean = array();
        foreach (self::COLUMNS as $column => $default) {
            $clean[$column] = array_key_exists($column, $row) ? $row[$column] : $default;
        }
        $clean['tid'] = $tid;
        $clean['queued_at'] = $queued;
        $this->rows[$tid] = $clean;
    }

    public function deletePosts(array $tids): void
    {
        foreach ($tids as $tid) {
            unset($this->rows[(int) $tid]);
        }
    }

    public function enqueue(array $tids, int $now, bool $existingOnly): void
    {
        foreach ($tids as $tid) {
            $tid = (int) $tid;
            if (!isset($this->rows[$tid])) {
                if ($existingOnly) {
                    continue;
                }
                $this->rows[$tid] = array('tid' => $tid, 'updated_at' => $now) + self::COLUMNS;
            }
            if ($this->rows[$tid]['queued_at'] === null) {
                $this->rows[$tid]['queued_at'] = $now;
            }
        }
    }

    public function dequeue(array $tids): void
    {
        foreach ($tids as $tid) {
            if (isset($this->rows[(int) $tid])) {
                $this->rows[(int) $tid]['queued_at'] = null;
            }
        }
    }

    public function queued(int $limit): array
    {
        $tids = array();
        foreach ($this->rows as $tid => $row) {
            if ($row['queued_at'] !== null) {
                $tids[] = $tid;
            }
        }
        rsort($tids);
        return array_slice($tids, 0, $limit);
    }

    public function countQueued(): int
    {
        return count($this->queued(PHP_INT_MAX));
    }

    public function enqueueState(string $state, int $now): int
    {
        $n = 0;
        foreach ($this->rows as $tid => $row) {
            if ($row['state'] === $state) {
                if ($row['queued_at'] === null) {
                    $this->rows[$tid]['queued_at'] = $now;
                }
                $n++;
            }
        }
        return $n;
    }

    public function dueRetries(int $failedBefore, int $maxAttempts, int $limit): array
    {
        $tids = array();
        foreach ($this->rows as $tid => $row) {
            if ($row['state'] === 'failed' && $row['attempts'] <= $maxAttempts && $row['updated_at'] <= $failedBefore && $row['queued_at'] === null) {
                $tids[] = $tid;
            }
        }
        rsort($tids);
        return array_slice($tids, 0, $limit);
    }

    public function markRemote(array $tids, int $now): void
    {
        foreach ($tids as $tid) {
            $tid = (int) $tid;
            if (isset($this->rows[$tid])) {
                $this->rows[$tid]['remote'] = 1;
            } else {
                $this->rows[$tid] = array('tid' => $tid, 'state' => 'orphan', 'remote' => 1, 'updated_at' => $now) + self::COLUMNS;
            }
        }
    }

    public function clearOrphans(): void
    {
        foreach ($this->rows as $tid => $row) {
            if ($row['state'] === 'orphan' && $row['queued_at'] === null) {
                unset($this->rows[$tid]);
            }
        }
    }

    public function stateTids(string $state, int $limit): array
    {
        $tids = array();
        foreach ($this->rows as $tid => $row) {
            if ($row['state'] === $state) {
                $tids[] = $tid;
            }
        }
        rsort($tids);
        return array_slice($tids, 0, $limit);
    }

    public function countState(string $state): int
    {
        return count($this->stateTids($state, PHP_INT_MAX));
    }

    public function countRemote(): int
    {
        $n = 0;
        foreach ($this->rows as $row) {
            $n += (int) $row['remote'] === 1 ? 1 : 0;
        }
        return $n;
    }

    public function failures(int $limit): array
    {
        $rows = array();
        foreach ($this->rows as $row) {
            if ($row['state'] === 'failed' || $row['state'] === 'rejected') {
                $rows[] = $row;
            }
        }
        usort($rows, function ($a, $b) {
            return $b['updated_at'] <=> $a['updated_at'] ?: $b['tid'] <=> $a['tid'];
        });
        return array_slice($rows, 0, $limit);
    }

    public function get(string $key): ?array
    {
        return isset($this->kv[$key]) ? $this->kv[$key] : null;
    }

    public function set(string $key, ?array $value): void
    {
        if ($value === null) {
            unset($this->kv[$key]);
        } else {
            // Round-trip through JSON like the real table does.
            $this->kv[$key] = json_decode(json_encode($value), true);
        }
    }

    /** Test helper: state of one thread, or null when it has no row. */
    public function state(int $tid): ?string
    {
        return isset($this->rows[$tid]) ? $this->rows[$tid]['state'] : null;
    }
}
