<?php
/**
 * The plugin's own two tables. The Discuz! adapter implements this over
 * pre_momobird_ai_post and pre_momobird_ai_kv; tests use an in-memory fake.
 *
 * pre_momobird_ai_post (one row per thread the engine knows about, never the body):
 *   tid                 INT UNSIGNED PRIMARY KEY
 *   state               VARCHAR(16)  pending|synced|failed|rejected|held|orphan
 *   content_hash        CHAR(64)     hash of the last payload sent or attempted ('' = none)
 *   body_hash           CHAR(64)     title+text digest, for content_changed_at ('' = none)
 *   category            VARCHAR(255) category of the last payload ('' = none)
 *   title               VARCHAR(255) thread title, for the failure list
 *   attempts            INT          failed attempts since the last success
 *   last_error          VARCHAR(255) error code or redacted message ('' = none)
 *   remote              TINYINT      1 = MomoBird may hold this thread
 *   content_changed_at  INT          when the title or text last changed (0 = never seen changing)
 *   updated_at          INT          last time the engine wrote the row
 *   queued_at           INT NULL     set while the thread waits to be processed
 *   priority            TINYINT      1 = queued by a forum settings change: processed first
 *   KEY (priority, queued_at), KEY (state)
 *
 * pre_momobird_ai_kv:
 *   k VARCHAR(64) PRIMARY KEY, v MEDIUMTEXT (JSON)
 *
 * States:
 *   pending   enqueued, never processed
 *   synced    MomoBird holds the payload whose hash is content_hash
 *   failed    network/5xx failure (push or withdraw); retried hourly, at most 5 times
 *   rejected  MomoBird refused this payload (last_error = its code); retried by hand or on change
 *   held      eligible but pushing is blocked (plan expired, address mismatch); requeued when it opens
 *   orphan    MomoBird holds it but the forum should not (MomoBird-only id, or a thread hidden
 *             only by the plugin's forum selection): waits for the cleanup guard
 *
 * Locks: lock()/unlock() give one engine run at a time (the adapter keeps them as kv rows; see MomoBird_DiscuzStore).
 */

if (!defined('IN_DISCUZ')) {
    exit('Access Denied');
}

interface MomoBird_Store
{
    /** @return array<int, array> tid => row (every column, priority and queued_at included), for the tids that have a row */
    public function posts(array $tids): array;

    /** Insert or update a row. Writes every column except queued_at (a new row gets NULL). */
    public function savePost(array $row): void;

    /**
     * Delete rows. A row that was queued again meanwhile (queued_at set) is kept instead and
     * reset to state=pending, remote=0, content_hash='', so the hook's event is not lost.
     */
    public function deletePosts(array $tids): void;

    /**
     * Mark threads as waiting. queued_at is set only where it is NULL (the oldest wait wins).
     * Missing rows are inserted as state=pending, remote=0, unless $existingOnly.
     * $priority sets priority=1 (also on rows already queued).
     */
    public function enqueue(array $tids, int $now, bool $existingOnly, bool $priority = false): void;

    /**
     * Clear queued_at and priority (called before a thread is read, so a hook firing meanwhile
     * requeues it).
     */
    public function dequeue(array $tids): void;

    /** @return int[] queued tids, priority rows first, then highest tid first */
    public function queued(int $limit): array;

    public function countQueued(): int;

    /** Enqueue every row in a state; returns how many rows. */
    public function enqueueState(string $state, int $now): int;

    /**
     * @return int[] tids with state=failed, attempts <= $maxAttempts, updated_at <= $failedBefore,
     *               not queued; highest first
     */
    public function dueRetries(int $failedBefore, int $maxAttempts, int $limit): array;

    /**
     * MomoBird listed these tids: existing rows get remote=1, missing ones are inserted as
     * state=orphan, remote=1.
     */
    public function markRemote(array $tids, int $now): void;

    /** Delete orphan rows that are not queued (a new listing marks them again). */
    public function clearOrphans(): void;

    /** @return int[] tids in a state, highest first */
    public function stateTids(string $state, int $limit): array;

    public function countState(string $state): int;

    /** Rows with remote=1. */
    public function countRemote(): int;

    /** @return array[] failed and rejected rows, most recently updated first */
    public function failures(int $limit): array;

    /** JSON value of a kv key, or null. */
    public function get(string $key): ?array;

    /** Store a kv value; null deletes the key. */
    public function set(string $key, ?array $value): void;

    /**
     * Take a named lock without waiting. False when another process holds it and it has not
     * expired; a lock not released within $ttl seconds expires (a crashed run).
     */
    public function lock(string $name, int $ttl): bool;

    public function unlock(string $name): void;
}
