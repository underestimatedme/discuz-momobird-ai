<?php
/**
 * Keeps MomoBird in step with the threads a guest can read.
 *
 * - Hooks call enqueue(): one database write, never a network request.
 * - drain() processes the queue (after the response, or from cron): each thread
 *   is read again and pushed, withdrawn or left alone.
 * - cronTick() (every 5 minutes, inside a visitor's request) refreshes the
 *   connect status hourly, compares the settings fingerprint, drains, retries
 *   failures hourly, scans one segment of the daily tid round and, when a round
 *   ends, takes down what MomoBird holds that the forum should not, behind the
 *   cleanup guard.
 * - fullSyncBatch() is the admin page's browser-driven full sync.
 *
 * Every step respects $deadline: the time is checked before each network call
 * and before each read of up to 1000 rows, and the work left over is kept
 * (queue, cursors) for the next call.
 *
 * Product rules shared with the Halo plugin: 402 stops pushing but not
 * withdrawals; 401 and 409 SOURCE_DISABLED stop everything; a source that is not
 * `discuz` is pushed to but never cleaned up automatically; the cleanup guard
 * holds removals when nothing local should sync, or when they are more than 10
 * and more than half of what MomoBird holds. Threads of forums taken out of the
 * plugin's forum selection go through the same guard.
 */
final class MomoBird_SyncEngine
{
    const SOURCE_KIND = 'discuz';
    const PUSH_BATCH = 20;
    const DELETE_BATCH = 100;
    const READ_ROWS = 1000;
    const SCAN_CHUNK = 100;
    const FULL_READ_ROWS = 100;
    const RETRY_INTERVAL = 3600;
    const MAX_RETRIES = 5;
    const STATUS_TTL = 3600;
    const ROUND_INTERVAL = 86400;
    const GUARD_MIN_REMOVALS = 10;
    /** The scan stops adding work while this many threads already wait in the queue. */
    const SCAN_QUEUE_LIMIT = 1000;
    const FAILURE_LIST = 50;
    const MAX_LIST_PAGES = 10000;
    const FULL_BATCH_SECONDS = 20;

    const PLAN_MESSAGE = '套餐已到期，已停止推送，撤下照常；续费后点「立即全量同步」或等下一次对账补齐';
    const BUSY_MESSAGE = '正在同步，请稍后再试';
    const READ_MESSAGE = '读取论坛数据失败';
    const LOCK = 'sync';
    /** Queued threads read per drain step: withdrawals go up to 100 per request. */
    const DRAIN_BATCH = 100;
    const SITE_MESSAGE = '论坛已关闭或游客不能访问论坛，暂停同步（不推送也不撤下）';

    private $settings;
    private $client;
    private $store;
    private $source;
    private $clock;

    /** Per call: null (not checked yet), 'open', or why pushing is blocked. */
    private $gate;
    /** Per call: a network or server failure ended network use for this call. */
    private $networkDown = false;

    public function __construct(MomoBird_Settings $settings, MomoBird_ConnectClient $client, MomoBird_Store $store, MomoBird_ForumSource $source, callable $clock)
    {
        $this->settings = $settings;
        $this->client = $client;
        $this->store = $store;
        $this->source = $source;
        $this->clock = $clock;
    }

    // ================================================================ entry points

    /** For hooks: only marks the threads as waiting. No reads, no network. */
    public function enqueue(array $tids, string $reason): void
    {
        $ids = self::tids($tids);
        if ($ids) {
            $this->store->enqueue($ids, $this->now(), false);
        }
    }

    /**
     * Process queued threads (forum-settings requeues first, then newest first) until the
     * queue is empty or the deadline. Returns at once when another run holds the lock: the
     * queue stays for the cron job.
     */
    public function drain(int $deadline): MomoBird_SyncResult
    {
        $run = $this->begin('drain');
        if ($this->store->countQueued() === 0) {
            return $run;
        }
        $locked = $this->guarded($run, $this->lockTtl($deadline), function () use ($run, $deadline) {
            if (!$this->ready($run)) {
                return;
            }
            $ctx = $this->siteContext($run);
            if ($ctx === null) {
                return;
            }
            $this->drainQueue($run, $ctx, $deadline);
            // While a reconcile is listing, the orphan set is incomplete: the guard waits for it.
            if ($this->store->get('reconcile') === null) {
                $this->settleOrphans($run, $deadline, false, null, null);
            }
        });
        if (!$locked) {
            $run->note(self::BUSY_MESSAGE);
        }
        return $run;
    }

    /** The 5-minute cron job. Skips the tick when another run holds the lock. */
    public function cronTick(int $deadline): MomoBird_SyncResult
    {
        $run = $this->begin('cron');
        $locked = $this->guarded($run, $this->lockTtl($deadline), function () use ($run, $deadline) {
            $this->tick($run, $deadline);
        });
        if (!$locked) {
            $run->note(self::BUSY_MESSAGE);
        }
        return $run;
    }

    private function tick(MomoBird_SyncResult $run, int $deadline): void
    {
        $problems = $this->settings->validate();
        if ($problems) {
            $run->fail('设置不完整，没有同步：' . implode(' ', $problems));
            return;
        }

        // First, so a renewed plan, a new key or a fixed address takes effect in this tick.
        $refreshed = false;
        if ($this->statusDue() && $this->hasTime($deadline)) {
            $this->refreshStatusCache($run);
            $refreshed = true;
        }
        if (!$this->ready($run)) {
            return;
        }
        $ctx = $this->siteContext($run);
        if ($ctx === null) {
            return;
        }

        $this->fingerprint($run, $ctx);
        $this->runRequeue($run, $deadline);
        if ($refreshed && $this->gateOpenFromCache() && $this->store->countState('held') > 0) {
            $this->store->enqueueState('held', $this->now());
        }
        $this->drainQueue($run, $ctx, $deadline);

        $due = $this->store->dueRetries($this->now() - self::RETRY_INTERVAL, self::MAX_RETRIES, self::READ_ROWS);
        if ($due) {
            $this->store->enqueue($due, $this->now(), true);
            $this->drainQueue($run, $ctx, $deadline);
        }

        $this->scanSegment($run, $ctx, $deadline);
        $this->drainQueue($run, $ctx, $deadline);
        $this->reconcileStep($run, $deadline);
        if ($this->store->get('reconcile') === null) {
            $this->settleOrphans($run, $deadline, false, null, null);
        }
        $this->maybeEnableWidget($run, $deadline);
    }

    /**
     * One step of the admin page's full sync. Pass null to start, then the returned cursor
     * until done. Each step pushes at most 20 threads (newest first); the last steps list
     * MomoBird's posts and take down what the forum no longer has, behind the cleanup guard.
     * When another run holds the lock: done=false, busy=true, the same cursor, and the
     * result's error 「正在同步，请稍后再试」.
     *
     * @return array{done: bool, cursor: ?string, result: MomoBird_SyncResult, busy?: bool}
     */
    public function fullSyncBatch(?string $cursor): array
    {
        $run = $this->begin('full');
        $deadline = $this->now() + self::FULL_BATCH_SECONDS;
        $out = null;
        $locked = $this->guarded($run, $this->lockTtl($deadline), function () use ($run, $cursor, $deadline, &$out) {
            $out = $this->fullSyncStep($run, $cursor, $deadline);
        });
        if (!$locked) {
            $run->fail(self::BUSY_MESSAGE);
            return array('done' => false, 'cursor' => $cursor, 'busy' => true, 'result' => $run);
        }
        if ($out === null) {
            // The forum could not be read: stop the page; nothing was changed.
            $out = array('done' => true, 'cursor' => null);
        }
        $out['result'] = $run;
        return $out;
    }

    /** @return array{done: bool, cursor: ?string} */
    private function fullSyncStep(MomoBird_SyncResult $run, ?string $cursor, int $deadline): array
    {
        $stop = array('done' => true, 'cursor' => null);
        if (!$this->ready($run)) {
            return $stop;
        }
        $ctx = $this->siteContext($run);
        if ($ctx === null) {
            $run->fail(self::SITE_MESSAGE);
            return $stop;
        }

        if ($cursor === null) {
            if (!$this->refreshStatusCache($run)) {
                $run->fail($run->error !== null ? $run->error : '无法读取 MomoBird 的连接状态，没有开始同步');
                return $stop;
            }
            $this->gate = null;
            if ($this->pushGate($run, $deadline) === 'ORIGIN_MISMATCH') {
                $run->fail($this->originMessage());
                return $stop;
            }
            $previous = $this->store->get('full_sync');
            $this->store->set('full_sync', array(
                'started_at' => $this->now(),
                'eligible' => 0,
                'finished_at' => isset($previous['finished_at']) ? $previous['finished_at'] : null,
            ));
            $cursor = 't:';
        }

        if (strpos($cursor, 't:') === 0) {
            $below = substr($cursor, 2) === '' ? null : (int) substr($cursor, 2);
            $next = $this->fullThreads($run, $ctx, $below, $deadline);
            if ($this->stopped($run)) {
                return $stop;
            }
            if ($next !== null) {
                return array('done' => false, 'cursor' => 't:' . $next);
            }
            $full = $this->store->get('full_sync');
            $this->finishRound((int) ($full['eligible'] ?? 0), (int) ($full['started_at'] ?? $this->now()));
            $cursor = 'r';
        }

        if ($cursor !== 'r') {
            $run->fail('同步进度无法识别，请重新点「立即全量同步」');
            return $stop;
        }

        $this->reconcileStep($run, $deadline);
        if ($this->store->get('reconcile') !== null) {
            if ($run->error !== null || $this->stopped($run)) {
                // The cron job picks the listing up again; the page stops here.
                return $stop;
            }
            return array('done' => false, 'cursor' => 'r');
        }

        if ($run->error === null && $run->halted === null) {
            $full = $this->store->get('full_sync');
            $full['finished_at'] = $this->now();
            $this->store->set('full_sync', $full);
            $this->refreshStatusCache($run);
            $this->maybeEnableWidget($run, $deadline);
        }
        return $stop;
    }

    /** The admin confirmed the removals the cleanup guard held back. */
    public function confirmWithdraw(): MomoBird_SyncResult
    {
        $run = $this->begin('confirm');
        $deadline = $this->now() + self::FULL_BATCH_SECONDS;
        $locked = $this->guarded($run, $this->lockTtl($deadline), function () use ($run, $deadline) {
            if ($this->ready($run) && $this->siteContext($run) !== null) {
                $guard = $this->store->get('guard');
                $guard = is_array($guard) ? $guard : array();
                $guard['confirmed'] = true;
                $this->store->set('guard', $guard);
                $this->settleOrphans($run, $deadline, true, null, null);
            }
        });
        if (!$locked) {
            $run->fail(self::BUSY_MESSAGE);
        }
        return $run;
    }

    /** The admin's 「重试失败项」: every failed, rejected and held thread is tried again now. */
    public function retryFailed(): MomoBird_SyncResult
    {
        $run = $this->begin('retry');
        $deadline = $this->now() + self::FULL_BATCH_SECONDS;
        $locked = $this->guarded($run, $this->lockTtl($deadline), function () use ($run, $deadline) {
            if (!$this->ready($run)) {
                return;
            }
            $ctx = $this->siteContext($run);
            if ($ctx !== null) {
                foreach (array('failed', 'rejected', 'held') as $state) {
                    $this->store->enqueueState($state, $this->now());
                }
                $this->drainQueue($run, $ctx, $deadline);
            }
        });
        if (!$locked) {
            $run->fail(self::BUSY_MESSAGE);
        }
        return $run;
    }

    /** The admin's 「测试连接」: fetch the connect status now and cache it. */
    public function refreshStatus(): array
    {
        $run = $this->begin('status');
        $this->refreshStatusCache($run);
        $cached = $this->store->get('connect_status');
        return is_array($cached) ? $cached : array();
    }

    /** Everything the admin page shows. Reads the plugin tables and the forum tree, no network. */
    public function status(): array
    {
        $cached = $this->store->get('connect_status');
        $source = isset($cached['source']) && is_array($cached['source']) ? $cached['source'] : array();
        $scan = $this->store->get('scan') ?: array();
        $full = $this->store->get('full_sync') ?: array();
        $guard = $this->store->get('guard');
        $halt = $this->store->get('halt');
        $plan = $this->store->get('plan');
        $reconcile = $this->store->get('last_reconcile');

        $readError = $this->store->get('read_error');
        try {
            $ctx = $this->context();
        } catch (MomoBird_ForumReadError $e) {
            $ctx = null;
            if ($readError === null) {
                $readError = array('at' => $this->now(), 'message' => $e->getMessage());
            }
        }
        if ($ctx === null) {
            $local = array('count' => null, 'exact' => false);
        } elseif (!empty($scan['round_finished_at'])) {
            $local = array('count' => (int) ($scan['eligible'] ?? 0), 'exact' => true);
        } else {
            $fids = array();
            foreach ($ctx['reasons'] as $fid => $reason) {
                if ($reason === null) {
                    $fids[] = $fid;
                }
            }
            $readaccess = isset($ctx['guest']['readaccess']) && is_numeric($ctx['guest']['readaccess']) ? (int) $ctx['guest']['readaccess'] : 0;
            try {
                $count = $fids ? (int) $this->src('countCandidates', array($fids, $readaccess)) : 0;
            } catch (MomoBird_ForumReadError $e) {
                $count = null;
            }
            $local = array('count' => $count, 'exact' => false);
        }

        $kind = isset($source['kind']) && is_string($source['kind']) ? $source['kind'] : null;
        $siteUrl = isset($source['site_url']) && is_string($source['site_url']) ? $source['site_url'] : null;
        $wrongKind = $kind !== null && $kind !== self::SOURCE_KIND;

        $failures = array();
        foreach ($this->store->failures(self::FAILURE_LIST) as $row) {
            $failures[] = array(
                'tid' => (int) $row['tid'],
                'title' => (string) $row['title'],
                'state' => (string) $row['state'],
                'error' => (string) $row['last_error'],
                'attempts' => (int) $row['attempts'],
                'at' => (int) $row['updated_at'],
            );
        }

        $planActive = isset($cached['entitlement']['active']) ? $cached['entitlement']['active'] : null;
        return array(
            'settings_problems' => $this->settings->validate(),
            'site' => $ctx === null ? null : $ctx['site'],
            'read_error' => $readError,
            'connect' => is_array($cached) ? $cached : null,
            'source' => array(
                'kind' => $kind,
                'name' => isset($source['name']) && is_string($source['name']) ? $source['name'] : null,
                'wrong_kind' => $wrongKind,
                'notice' => $wrongKind ? $this->kindNotice($kind) : null,
            ),
            'origin' => array(
                'ok' => $siteUrl !== null && MomoBird_Origin::same($this->settings->forumUrl(), $siteUrl),
                'checked' => $siteUrl !== null,
                'forum_url' => $this->settings->forumUrl(),
                'source_url' => $siteUrl,
            ),
            'plan' => $plan !== null || $planActive === false
                ? array('message' => self::PLAN_MESSAGE, 'at' => isset($plan['at']) ? (int) $plan['at'] : null)
                : null,
            'halted' => $halt,
            'local' => $local,
            'remote' => array(
                'documents' => isset($source['document_count']) && is_numeric($source['document_count']) ? (int) $source['document_count'] : null,
                'ready' => isset($source['ready_document_count']) && is_numeric($source['ready_document_count']) ? (int) $source['ready_document_count'] : null,
            ),
            'last_sync' => $this->store->get('last_sync'),
            'last_full_sync_at' => isset($full['finished_at']) ? $full['finished_at'] : null,
            'last_reconcile_at' => isset($reconcile['at']) ? $reconcile['at'] : null,
            'scan' => array(
                'active' => !empty($scan['active']),
                'cursor' => isset($scan['cursor']) ? (int) $scan['cursor'] : null,
                'round_started_at' => isset($scan['round_started_at']) ? $scan['round_started_at'] : null,
                'round_finished_at' => isset($scan['round_finished_at']) ? $scan['round_finished_at'] : null,
                'eligible' => (int) ($scan['eligible'] ?? 0),
            ),
            'guard' => is_array($guard) && !empty($guard['held']) ? $guard : null,
            'failures' => $failures,
            'queue' => $this->store->countQueued(),
            'held' => $this->store->countState('held'),
        );
    }

    // ================================================================ run plumbing

    /**
     * Run $body holding the engine lock, record the run, release the lock. A forum read
     * failure inside ends the run with nothing changed. Returns false when the lock is held.
     */
    private function guarded(MomoBird_SyncResult $run, int $ttl, callable $body): bool
    {
        if (!$this->store->lock(self::LOCK, $ttl)) {
            return false;
        }
        try {
            try {
                $body();
            } catch (MomoBird_ForumReadError $e) {
                $this->readFailed($run, $e);
            }
            $this->finish($run);
        } finally {
            $this->store->unlock(self::LOCK);
        }
        return true;
    }

    /** Long enough for the work up to the deadline plus one request that starts just before it. */
    private function lockTtl(int $deadline): int
    {
        return max(1, $deadline - $this->now()) + $this->settings->timeout() + 10;
    }

    private function readFailed(MomoBird_SyncResult $run, MomoBird_ForumReadError $e): void
    {
        $this->store->set('read_error', array('at' => $this->now(), 'message' => $e->getMessage()));
        $run->fail($e->getMessage());
    }

    /** Call the forum source; any failure becomes a MomoBird_ForumReadError. */
    private function src(string $method, array $args = array())
    {
        try {
            return call_user_func_array(array($this->source, $method), $args);
        } catch (MomoBird_ForumReadError $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new MomoBird_ForumReadError(self::READ_MESSAGE . '：' . self::truncate(MomoBird_Redactor::clean($e->getMessage(), $this->settings->key()), 200));
        }
    }

    private function begin(string $mode): MomoBird_SyncResult
    {
        $this->gate = null;
        $this->networkDown = false;
        return new MomoBird_SyncResult($mode, $this->now());
    }

    private function finish(MomoBird_SyncResult $run): MomoBird_SyncResult
    {
        $run->finishedAt = $this->now();
        $run->queued = $this->store->countQueued();
        if ($run->didWork() || in_array($run->mode, array('full', 'retry', 'confirm'), true)) {
            $this->store->set('last_sync', $run->toArray());
        }
        return $run;
    }

    /** Settings usable and not halted by a 401 / 409 SOURCE_DISABLED. */
    private function ready(MomoBird_SyncResult $run): bool
    {
        $problems = $this->settings->validate();
        if ($problems) {
            $run->fail('设置不完整，没有同步：' . implode(' ', $problems));
            return false;
        }
        $halt = $this->store->get('halt');
        if (is_array($halt)) {
            $run->halted = (string) ($halt['kind'] ?? 'UNAUTHORIZED');
            $run->fail((string) ($halt['message'] ?? '同步已停止'));
            return false;
        }
        return true;
    }

    private function stopped(MomoBird_SyncResult $run): bool
    {
        return $run->halted !== null || $this->networkDown;
    }

    private function hasTime(int $deadline): bool
    {
        return $this->now() < $deadline;
    }

    private function now(): int
    {
        return (int) call_user_func($this->clock);
    }

    /** The forum context, or null (recorded on the run) when the site is closed to guests. */
    private function siteContext(MomoBird_SyncResult $run): ?array
    {
        $ctx = $this->context();
        if ($this->store->get('read_error') !== null) {
            $this->store->set('read_error', null);
        }
        if ($ctx['site'] !== null) {
            $run->siteClosed = true;
            $run->note(self::SITE_MESSAGE);
            return null;
        }
        return $ctx;
    }

    /**
     * The forum tree, guest group and settings. A read that cannot be right (exception, no
     * forum at all, no guest group, no settings) throws MomoBird_ForumReadError.
     */
    private function context(): array
    {
        $setting = $this->src('setting');
        $guest = $this->src('guestGroup');
        if (!is_array($setting) || !$setting) {
            throw new MomoBird_ForumReadError(self::READ_MESSAGE . '：没有读到论坛设置');
        }
        if (!is_array($guest) || !$guest) {
            throw new MomoBird_ForumReadError(self::READ_MESSAGE . '：没有读到游客用户组');
        }
        $forums = array();
        $postable = 0;
        foreach ((array) $this->src('forums') as $row) {
            if (is_array($row) && isset($row['fid'])) {
                $forums[(int) $row['fid']] = $row;
                $type = isset($row['type']) ? (string) $row['type'] : '';
                $postable += $type === 'forum' || $type === 'sub' ? 1 : 0;
            }
        }
        if ($postable === 0) {
            throw new MomoBird_ForumReadError(self::READ_MESSAGE . '：没有读到任何版块');
        }
        $fields = (array) $this->src('forumFields');
        $selected = $this->settings->forumIds();
        $reasons = array();
        $open = array();
        foreach ($forums as $fid => $row) {
            $field = isset($fields[$fid]) && is_array($fields[$fid]) ? $fields[$fid] : array();
            $reasons[$fid] = MomoBird_Visibility::forum($row, $field, $guest, $selected);
            // The same check with the plugin's forum selection ignored.
            $open[$fid] = MomoBird_Visibility::forum($row, $field, $guest, null);
        }
        return array(
            'site' => MomoBird_Visibility::site($guest, $setting),
            'setting' => $setting,
            'guest' => $guest,
            'forums' => $forums,
            'reasons' => $reasons,
            'open' => $open,
            'categories' => MomoBird_Categories::build(array_values($forums)),
            'rewrite' => $this->src('rewriteRule'),
        );
    }

    // ================================================================ deciding

    /**
     * Thread- and forum-level checks only (no post): null when the thread may sync,
     * otherwise the reason. Every guest-visibility reason comes before `not_selected`, so a
     * deselected forum never hides another reason to withdraw.
     */
    private function precheck(array $ctx, array $thread): ?string
    {
        $fid = isset($thread['fid']) ? (int) $thread['fid'] : 0;
        $reason = array_key_exists($fid, $ctx['open']) ? $ctx['open'][$fid] : 'missing_forum';
        if ($reason !== null) {
            return $reason;
        }
        $reason = MomoBird_Visibility::thread($thread, array(), $ctx['guest'], $ctx['setting']);
        // Every thread-level check passed when the only complaint is the absent post.
        if ($reason !== 'missing_post') {
            return $reason;
        }
        // `??` would turn the null ("selected") into the default, hence array_key_exists.
        return array_key_exists($fid, $ctx['reasons']) && $ctx['reasons'][$fid] === null ? null : 'not_selected';
    }

    /**
     * `not_selected` means: a guest could read it and it has text, only the plugin's forum
     * selection leaves it out (those removals go through the cleanup guard).
     *
     * @return array{tid:int, eligible:bool, reason:?string, title:string, ...}
     */
    private function decide(array $ctx, int $tid, ?array $thread, ?array $post): array
    {
        if ($thread === null) {
            return array('tid' => $tid, 'eligible' => false, 'reason' => 'missing', 'title' => '');
        }
        $title = isset($thread['subject']) ? self::plainTitle((string) $thread['subject']) : '';
        $reason = $this->precheck($ctx, $thread);
        $deselected = $reason === 'not_selected';
        if ($reason === null || $deselected) {
            $reason = MomoBird_Visibility::thread($thread, $post === null ? array() : $post, $ctx['guest'], $ctx['setting']);
        }
        if ($reason !== null) {
            return array('tid' => $tid, 'eligible' => false, 'reason' => $reason, 'title' => $title);
        }
        $fid = (int) $thread['fid'];
        $category = isset($ctx['categories'][$fid]) ? (string) $ctx['categories'][$fid] : '';
        $url = MomoBird_ThreadUrl::build($this->settings->forumUrl(), $tid, $ctx['rewrite']);
        $payload = MomoBird_ThreadMapper::payload($thread, $post, $category, $url);
        if ($payload === null) {
            return array('tid' => $tid, 'eligible' => false, 'reason' => 'empty', 'title' => $title);
        }
        if ($deselected) {
            return array('tid' => $tid, 'eligible' => false, 'reason' => 'not_selected', 'title' => $payload['title']);
        }
        return array('tid' => $tid, 'eligible' => true, 'reason' => null, 'title' => $payload['title'],
            'payload' => $payload, 'thread' => $thread, 'post' => $post, 'category' => $category, 'url' => $url);
    }

    /**
     * The push item for an eligible decision. When the title or text differs from what was
     * last seen, content_changed_at moves to now and becomes the post's edit time
     * (Discuz! keeps no reliable edit time of its own).
     */
    private function prepare(array $d, ?array $row): array
    {
        $payload = $d['payload'];
        $body = self::bodyHash($payload);
        $changedAt = $row !== null ? (int) $row['content_changed_at'] : 0;
        if ($row !== null && (string) $row['body_hash'] !== '' && (string) $row['body_hash'] !== $body) {
            $changedAt = $this->now();
        }
        if ($changedAt > 0) {
            $post = $d['post'];
            $post['lastedit'] = $changedAt;
            $withTime = MomoBird_ThreadMapper::payload($d['thread'], $post, $d['category'], $d['url']);
            if ($withTime !== null) {
                $payload = $withTime;
            }
        }
        return array(
            'tid' => $d['tid'],
            'payload' => $payload,
            'hash' => MomoBird_ThreadMapper::hash($payload),
            'body' => $body,
            'changed_at' => $changedAt,
            'title' => (string) $payload['title'],
            'category' => isset($payload['category']) ? (string) $payload['category'] : '',
            'row' => $row,
        );
    }

    /**
     * Sorts one decision into the push list, the withdraw list, the cleanup guard
     * (orphan) or nothing.
     */
    private function route(MomoBird_SyncResult $run, array $d, ?array $row, array &$push, array &$withdraw): void
    {
        if ($d['eligible']) {
            $item = $this->prepare($d, $row);
            if ($row !== null && $row['state'] === 'synced' && (string) $row['content_hash'] === $item['hash']) {
                $run->unchanged++;
                return;
            }
            $push[] = $item;
            return;
        }

        if ($d['reason'] === 'empty') {
            $run->skipped++;
        }
        if ($row === null) {
            return;
        }
        if ((int) $row['remote'] === 1) {
            if ($d['reason'] === 'not_selected') {
                // Taken out of the forum selection: removals go through the cleanup guard.
                $row['state'] = 'orphan';
                $row['updated_at'] = $this->now();
                $this->store->savePost($row);
                return;
            }
            $withdraw[] = array('tid' => $d['tid'], 'title' => $d['title'] !== '' ? $d['title'] : (string) $row['title'], 'row' => $row);
            return;
        }
        $this->store->deletePosts(array($d['tid']));
    }

    // ================================================================ queue

    private function drainQueue(MomoBird_SyncResult $run, array $ctx, int $deadline): void
    {
        while (!$this->stopped($run)) {
            if (!$this->hasTime($deadline)) {
                $run->deadlineReached = true;
                return;
            }
            $tids = $this->store->queued(self::DRAIN_BATCH);
            if (!$tids) {
                return;
            }
            $priority = array();
            foreach ($this->store->posts($tids) as $tid => $row) {
                if (!empty($row['priority'])) {
                    $priority[] = $tid;
                }
            }
            $this->store->dequeue($tids);
            try {
                $this->processTids($run, $ctx, $tids, $deadline);
            } catch (MomoBird_ForumReadError $e) {
                // Read before anything was decided: put them back untouched, priority included.
                $this->store->enqueue($tids, $this->now(), false);
                if ($priority) {
                    $this->store->enqueue($priority, $this->now(), true, true);
                }
                throw $e;
            }
        }
    }

    private function processTids(MomoBird_SyncResult $run, array $ctx, array $tids, int $deadline): void
    {
        $threads = (array) $this->src('threads', array($tids));
        $posts = $this->postsFor($ctx, $threads);
        $rows = $this->store->posts($tids);
        $push = array();
        $withdraw = array();
        foreach ($tids as $tid) {
            $d = $this->decide($ctx, $tid, $threads[$tid] ?? null, $posts[$tid] ?? null);
            $this->route($run, $d, $rows[$tid] ?? null, $push, $withdraw);
        }
        // Withdrawals first: keeping hidden content out matters more than pushing.
        $this->sendWithdrawals($run, $withdraw, $deadline, false);
        $this->sendPushes($run, $push, $deadline);
    }

    /**
     * First posts of the threads that could sync on thread level (precheck null or only
     * not_selected). Rejected threads are decided without a post: redirect stubs left by
     * "move with redirect" (closed > 1) have no posts at all and must not look like a failed read.
     */
    private function postsFor(array $ctx, array $threads): array
    {
        $need = array();
        foreach ($threads as $tid => $thread) {
            $reason = $this->precheck($ctx, $thread);
            if ($reason === null || $reason === 'not_selected') {
                $need[$tid] = $thread;
            }
        }
        return $need ? $this->firstPostsOf($need) : array();
    }

    /**
     * First posts of these threads. Every thread has one, so none at all for several
     * threads is a read failure (e.g. a post table the adapter cannot reach), not deletions.
     */
    private function firstPostsOf(array $threads): array
    {
        $posts = (array) $this->src('firstPosts', array($threads));
        if (!$posts && count($threads) >= 2) {
            throw new MomoBird_ForumReadError(self::READ_MESSAGE . '：' . count($threads) . ' 个主题都没有读到主帖');
        }
        return $posts;
    }

    private function requeue(array $items): void
    {
        if ($items) {
            $this->store->enqueue(array_column($items, 'tid'), $this->now(), false);
        }
    }

    // ================================================================ pushing

    /** null when pushing may go on, else PLAN_REQUIRED | ORIGIN_MISMATCH | UNAVAILABLE. */
    private function pushGate(MomoBird_SyncResult $run, int $deadline): ?string
    {
        if ($this->gate === null) {
            $this->gate = $this->computeGate($run, $deadline);
        }
        return $this->gate === 'open' ? null : $this->gate;
    }

    private function computeGate(MomoBird_SyncResult $run, int $deadline): string
    {
        if ($this->store->get('plan') !== null) {
            $run->planRequired = true;
            $run->note(self::PLAN_MESSAGE);
            return 'PLAN_REQUIRED';
        }
        $cached = $this->store->get('connect_status');
        if ($this->statusDue() || empty($cached['ok'])) {
            if (!$this->hasTime($deadline) || !$this->refreshStatusCache($run)) {
                return 'UNAVAILABLE';
            }
            $cached = $this->store->get('connect_status');
            if ($this->store->get('plan') !== null) {
                $run->planRequired = true;
                $run->note(self::PLAN_MESSAGE);
                return 'PLAN_REQUIRED';
            }
        }
        $kind = $cached['source']['kind'] ?? null;
        if ($kind !== self::SOURCE_KIND) {
            $run->wrongKind = true;
            $run->note($this->kindNotice(is_string($kind) ? $kind : null));
        }
        $siteUrl = $cached['source']['site_url'] ?? null;
        if (!is_string($siteUrl) || !MomoBird_Origin::same($this->settings->forumUrl(), $siteUrl)) {
            $run->originBlocked = true;
            $run->note($this->originMessage());
            return 'ORIGIN_MISMATCH';
        }
        return 'open';
    }

    /** From the cache only: no plan block and the addresses match. */
    private function gateOpenFromCache(): bool
    {
        $cached = $this->store->get('connect_status');
        $siteUrl = $cached['source']['site_url'] ?? null;
        return $this->store->get('plan') === null && !empty($cached['ok'])
            && is_string($siteUrl) && MomoBird_Origin::same($this->settings->forumUrl(), $siteUrl);
    }

    private function sendPushes(MomoBird_SyncResult $run, array $items, int $deadline): void
    {
        if (!$items) {
            return;
        }
        if ($this->stopped($run)) {
            $this->requeue($items);
            return;
        }
        $gate = $this->pushGate($run, $deadline);
        if ($gate === 'UNAVAILABLE') {
            $this->networkDown = true;
            $this->requeue($items);
            return;
        }
        if ($gate !== null) {
            $this->hold($run, $items, $gate);
            return;
        }
        foreach (array_chunk($items, self::PUSH_BATCH) as $batch) {
            $this->upsertBatch($run, $batch, $deadline);
        }
    }

    private function upsertBatch(MomoBird_SyncResult $run, array $items, int $deadline): void
    {
        if (!$items) {
            return;
        }
        if ($this->stopped($run)) {
            $this->requeue($items);
            return;
        }
        if ($run->planRequired) {
            $this->hold($run, $items, 'PLAN_REQUIRED');
            return;
        }
        if (!$this->hasTime($deadline)) {
            $run->deadlineReached = true;
            $this->requeue($items);
            return;
        }

        try {
            $results = $this->client->upsert(array_column($items, 'payload'));
        } catch (MomoBird_ConnectError $e) {
            $completed = $e->getCompletedResults();
            $this->applyUpserts($run, $items, $completed);
            $done = array_flip(array_map('strval', array_column($completed, 'external_id')));
            $failedIds = array_flip($e->getFailedIds());
            $failed = array();
            $rest = array();
            foreach ($items as $item) {
                $id = (string) $item['tid'];
                if (isset($failedIds[$id])) {
                    $failed[] = $item;
                } elseif (!isset($done[$id])) {
                    $rest[] = $item;
                }
            }
            $this->upsertFailed($run, $e, $failed, $rest, $deadline);
            return;
        }
        $this->applyUpserts($run, $items, $results);
    }

    /**
     * A push request failed. $failed were in the failing request; $rest were never sent.
     * One bad post must not block the rest: content errors re-push the batch one post at a time.
     */
    private function upsertFailed(MomoBird_SyncResult $run, MomoBird_ConnectError $e, array $failed, array $rest, int $deadline): void
    {
        $kind = $e->kind();
        if ($kind === MomoBird_ConnectError::PLAN_REQUIRED) {
            $this->planRequired($run);
            $this->hold($run, array_merge($failed, $rest), 'PLAN_REQUIRED');
            return;
        }
        if ($kind === MomoBird_ConnectError::UNAUTHORIZED || $kind === MomoBird_ConnectError::DISABLED) {
            $this->halt($run, $e);
            $this->requeue(array_merge($failed, $rest));
            return;
        }
        if (self::contentError($e)) {
            if (count($failed) > 1) {
                foreach ($failed as $item) {
                    $this->upsertBatch($run, array($item), $deadline);
                }
            } elseif ($failed) {
                $this->reject($run, $failed[0], self::code($e->code(), $kind));
            }
            $this->upsertBatch($run, $rest, $deadline);
            return;
        }
        // Network, 5xx, rate limits, malformed answers: try again within the hour.
        $this->networkDown = true;
        $run->fail($e->getMessage());
        foreach ($failed as $item) {
            $this->fail($run, $item, $e->getMessage(), true);
        }
        $this->requeue($rest);
    }

    /** Errors about the content of the batch (as opposed to the connection or the account). */
    private static function contentError(MomoBird_ConnectError $e): bool
    {
        $kind = $e->kind();
        if ($kind === MomoBird_ConnectError::TOO_LARGE || $kind === MomoBird_ConnectError::REJECTED) {
            return true;
        }
        $status = $e->status();
        return $kind === MomoBird_ConnectError::HTTP && $status >= 400 && $status < 500
            && !in_array($status, array(401, 402, 408, 409, 429), true);
    }

    private function applyUpserts(MomoBird_SyncResult $run, array $items, array $results): void
    {
        $byId = array();
        foreach ($results as $result) {
            if (is_array($result) && isset($result['external_id'])) {
                $byId[(string) $result['external_id']] = $result;
            }
        }
        foreach ($items as $item) {
            $id = (string) $item['tid'];
            if (!isset($byId[$id])) {
                continue;
            }
            $status = $byId[$id]['status'] ?? null;
            if ($status === 'created' || $status === 'updated' || $status === 'unchanged') {
                if ($status === 'unchanged') {
                    $run->unchanged++;
                } else {
                    $run->pushed++;
                }
                $this->save($item, array('state' => 'synced', 'attempts' => 0, 'last_error' => '', 'remote' => 1));
            } else {
                $this->reject($run, $item, self::code($byId[$id]['error_code'] ?? null, 'REJECTED'));
            }
        }
    }

    private function reject(MomoBird_SyncResult $run, array $item, string $code): void
    {
        $run->rejected++;
        $this->save($item, array('state' => 'rejected', 'last_error' => $code));
    }

    private function hold(MomoBird_SyncResult $run, array $items, string $code): void
    {
        foreach ($items as $item) {
            $run->held++;
            $this->save($item, array('state' => 'held', 'last_error' => $code));
        }
    }

    /** A failed push ($isPush) or withdrawal: counted towards the hourly retries. */
    private function fail(MomoBird_SyncResult $run, array $item, string $message, bool $isPush): void
    {
        $run->failed++;
        $row = isset($item['row']) && is_array($item['row']) ? $item['row'] : null;
        $attempts = ($row !== null ? (int) $row['attempts'] : 0) + 1;
        $message = self::truncate($message, 200);
        if ($isPush) {
            // The request's outcome is unknown: MomoBird may have stored it, so a later hide must send a delete.
            $this->save($item, array('state' => 'failed', 'attempts' => $attempts, 'last_error' => $message, 'remote' => 1));
            return;
        }
        $row['state'] = 'failed';
        $row['attempts'] = $attempts;
        $row['last_error'] = $message;
        $row['title'] = self::truncate((string) $item['title'], 255);
        $row['updated_at'] = $this->now();
        $this->store->savePost($row);
    }

    /** Write the row for a push item with the given outcome. */
    private function save(array $item, array $outcome): void
    {
        $row = isset($item['row']) && is_array($item['row']) ? $item['row'] : array();
        $this->store->savePost(array_merge(array(
            'tid' => $item['tid'],
            'attempts' => isset($row['attempts']) ? (int) $row['attempts'] : 0,
            'remote' => isset($row['remote']) ? (int) $row['remote'] : 0,
        ), $outcome, array(
            'content_hash' => $item['hash'],
            'body_hash' => $item['body'],
            'category' => self::truncate($item['category'], 255),
            'title' => self::truncate($item['title'], 255),
            'content_changed_at' => $item['changed_at'],
            'updated_at' => $this->now(),
        )));
    }

    private function planRequired(MomoBird_SyncResult $run): void
    {
        $run->planRequired = true;
        $run->note(self::PLAN_MESSAGE);
        $this->gate = 'PLAN_REQUIRED';
        $this->store->set('plan', array('at' => $this->now()));
    }

    private function halt(MomoBird_SyncResult $run, MomoBird_ConnectError $e): void
    {
        $this->store->set('halt', array('kind' => $e->kind(), 'message' => $e->getMessage(), 'at' => $this->now()));
        $run->halted = $e->kind();
        $run->fail($e->getMessage());
    }

    // ================================================================ withdrawing

    /**
     * Take threads down, 100 per request. Confirmed ids (deleted / not_found) lose their row.
     * On failure a queued thread is requeued or marked failed; an orphan simply stays an orphan.
     *
     * @return bool false when something stopped the withdrawals
     */
    private function sendWithdrawals(MomoBird_SyncResult $run, array $items, int $deadline, bool $orphans): bool
    {
        foreach (array_chunk($items, self::DELETE_BATCH) as $batch) {
            if ($this->stopped($run) || !$this->hasTime($deadline)) {
                if (!$this->stopped($run)) {
                    $run->deadlineReached = true;
                }
                if (!$orphans) {
                    $this->requeue($batch);
                }
                continue;
            }
            $ids = array_map('strval', array_column($batch, 'tid'));
            try {
                $this->applyDeletes($run, $this->client->delete($ids));
            } catch (MomoBird_ConnectError $e) {
                $done = $this->applyDeletes($run, $e->getCompletedResults());
                $left = array();
                foreach ($batch as $item) {
                    if (!isset($done[(string) $item['tid']])) {
                        $left[] = $item;
                    }
                }
                if ($e->kind() === MomoBird_ConnectError::UNAUTHORIZED || $e->kind() === MomoBird_ConnectError::DISABLED) {
                    $this->halt($run, $e);
                    if (!$orphans) {
                        $this->requeue($left);
                    }
                } else {
                    $this->networkDown = true;
                    $run->fail($e->getMessage());
                    if (!$orphans) {
                        foreach ($left as $item) {
                            $this->fail($run, $item, $e->getMessage(), false);
                        }
                    }
                }
            }
        }
        return !$this->stopped($run);
    }

    /** @return array<string, true> ids MomoBird confirmed gone */
    private function applyDeletes(MomoBird_SyncResult $run, array $results): array
    {
        $gone = array();
        foreach ($results as $result) {
            $status = $result['status'] ?? null;
            if ($status === 'deleted' || $status === 'not_found') {
                $gone[(string) $result['external_id']] = true;
                if ($status === 'deleted') {
                    $run->removed++;
                }
            }
        }
        if ($gone) {
            $this->store->deletePosts(array_map('intval', array_keys($gone)));
        }
        return $gone;
    }

    // ================================================================ cleanup guard

    /**
     * Withdraw orphan rows (what MomoBird holds that the forum should not), unless the
     * cleanup guard holds them: a source that is not discuz, nothing local, or more than
     * 10 removals that are more than half of MomoBird's posts. Held removals wait for
     * confirmWithdraw(); a new reconcile round decides again.
     *
     * @return bool true when settled (nothing left, or held), false when interrupted
     */
    private function settleOrphans(MomoBird_SyncResult $run, int $deadline, bool $confirmed, ?int $local, ?int $remote): bool
    {
        $count = $this->store->countState('orphan');
        $guard = $this->store->get('guard');
        if ($count === 0) {
            if ($guard !== null) {
                $this->store->set('guard', null);
            }
            return true;
        }
        $confirmed = $confirmed || !empty($guard['confirmed']);
        if (!$confirmed && !empty($guard['held'])) {
            $run->guardHeld = true;
            return true;
        }

        if (!$confirmed) {
            $cached = $this->store->get('connect_status');
            if (empty($cached['ok']) || $this->statusDue()) {
                if (!$this->hasTime($deadline) || !$this->refreshStatusCache($run)) {
                    return false;
                }
                $cached = $this->store->get('connect_status');
            }
            $kind = $cached['source']['kind'] ?? null;
            $local = $local !== null ? $local : $this->store->countState('synced');
            if ($remote === null) {
                $documents = $cached['source']['document_count'] ?? 0;
                $remote = max(is_numeric($documents) ? (int) $documents : 0, $this->store->countRemote());
            }
            $reason = null;
            if ($kind !== self::SOURCE_KIND) {
                $reason = 'kind';
                $run->wrongKind = true;
                $message = 'MomoBird 上有 ' . $count . ' 篇帖子论坛里已不该同步，因为知识源不是 discuz 类型，没有撤下。确认要撤下，请在 MomoBird 页面点「确认撤下」';
            } elseif ($local === 0 || ($count > self::GUARD_MIN_REMOVALS && $count * 2 > $remote)) {
                $reason = 'guard';
                $message = '清理保护：论坛应同步 ' . $local . ' 篇，MomoBird 上有 ' . $remote . ' 篇，其中 ' . $count
                    . ' 篇论坛里已不该同步，已跳过全部撤下。确认要撤下，请在 MomoBird 页面点「确认撤下」';
            }
            if ($reason !== null) {
                $this->store->set('guard', array(
                    'held' => true, 'reason' => $reason, 'would_remove' => $count, 'local' => $local, 'remote' => $remote,
                    'kind' => is_string($kind) ? $kind : null, 'message' => $message, 'at' => $this->now(), 'confirmed' => false,
                ));
                $run->guardHeld = true;
                $run->note($message);
                return true;
            }
        }

        while (true) {
            if ($this->stopped($run)) {
                return false;
            }
            if (!$this->hasTime($deadline)) {
                $run->deadlineReached = true;
                return false;
            }
            $tids = $this->store->stateTids('orphan', self::DELETE_BATCH);
            if (!$tids) {
                break;
            }
            $rows = $this->store->posts($tids);
            $items = array();
            foreach ($tids as $tid) {
                $items[] = array('tid' => $tid, 'title' => isset($rows[$tid]) ? (string) $rows[$tid]['title'] : '', 'row' => $rows[$tid] ?? null);
            }
            if (!$this->sendWithdrawals($run, $items, $deadline, true)) {
                return false;
            }
        }
        $this->store->set('guard', null);
        return true;
    }

    // ================================================================ fingerprint

    /**
     * Compare what decides "may a guest read it, under which category" with the last
     * tick, and queue the threads of every forum whose answer changed.
     */
    private function fingerprint(MomoBird_SyncResult $run, array $ctx): void
    {
        $forums = array();
        foreach ($ctx['forums'] as $fid => $row) {
            $type = isset($row['type']) ? (string) $row['type'] : '';
            if ($type !== 'forum' && $type !== 'sub') {
                continue;
            }
            $forums[(string) $fid] = $ctx['reasons'][$fid] === null ? (string) ($ctx['categories'][$fid] ?? '') : false;
        }
        ksort($forums);
        $global = hash('sha256', (string) json_encode(array(
            $ctx['guest']['groupid'] ?? null,
            $ctx['guest']['readaccess'] ?? null,
            $ctx['setting']['bannedmessages'] ?? null,
            $this->settings->forumUrl(),
            $ctx['rewrite'],
        )));
        $now = array('global' => $global, 'forums' => $forums);
        $old = $this->store->get('fingerprint');
        if ($old === $now) {
            return;
        }
        if ($old === null) {
            $this->store->set('fingerprint', $now);
            return;
        }

        $oldForums = isset($old['forums']) && is_array($old['forums']) ? $old['forums'] : array();
        $globalChanged = ($old['global'] ?? null) !== $global;
        $affected = array();
        foreach (array_unique(array_merge(array_keys($oldForums), array_keys($forums))) as $fid) {
            $before = array_key_exists($fid, $oldForums) ? $oldForums[$fid] : false;
            $after = array_key_exists($fid, $forums) ? $forums[$fid] : false;
            if ($before !== $after || ($globalChanged && ($before !== false || $after !== false))) {
                $affected[(int) $fid] = $after !== false;
            }
        }
        if ($affected) {
            $requeue = $this->store->get('requeue');
            $jobs = isset($requeue['jobs']) && is_array($requeue['jobs']) ? $requeue['jobs'] : array();
            foreach (array(true, false) as $syncing) {
                $fids = array_keys(array_filter($affected, function ($s) use ($syncing) {
                    return $s === $syncing;
                }));
                if ($fids) {
                    // Forums that no longer sync only need the threads MomoBird may hold.
                    $jobs[] = array('fids' => $fids, 'existing' => !$syncing, 'below' => null);
                }
            }
            // Jobs first: a crash between the two writes repeats the requeue instead of losing it.
            $this->store->set('requeue', array('jobs' => $jobs));
            $run->note('版块设置有变化，已把 ' . count($affected) . ' 个版块的主题优先排进同步队列');
        }
        $this->store->set('fingerprint', $now);
    }

    private function runRequeue(MomoBird_SyncResult $run, int $deadline): void
    {
        $requeue = $this->store->get('requeue');
        $jobs = isset($requeue['jobs']) && is_array($requeue['jobs']) ? $requeue['jobs'] : array();
        while ($jobs) {
            if (!$this->hasTime($deadline)) {
                $run->deadlineReached = true;
                break;
            }
            $job = $jobs[0];
            $below = isset($job['below']) ? (int) $job['below'] : null;
            $tids = self::tids((array) $this->src('threadIdsInForums', array(array_map('intval', (array) $job['fids']), $below, self::READ_ROWS)));
            if ($tids) {
                $this->store->enqueue($tids, $this->now(), !empty($job['existing']), true);
                $jobs[0]['below'] = min($tids);
            }
            if (count($tids) < self::READ_ROWS) {
                array_shift($jobs);
            }
            $this->store->set('requeue', $jobs ? array('jobs' => $jobs) : null);
        }
    }

    // ================================================================ daily scan

    private function scanSegment(MomoBird_SyncResult $run, array $ctx, int $deadline): void
    {
        if ($this->stopped($run)) {
            return;
        }
        $scan = $this->store->get('scan') ?: array();
        if (empty($scan['active'])) {
            $due = empty($scan['round_started_at']) || $this->now() - (int) $scan['round_started_at'] >= self::ROUND_INTERVAL;
            if (!$due || $this->store->get('reconcile') !== null) {
                return;
            }
            $scan = array(
                'active' => true, 'cursor' => null, 'round_started_at' => $this->now(), 'eligible' => 0,
                'round_finished_at' => $scan['round_finished_at'] ?? null, 'last_eligible' => $scan['eligible'] ?? null,
            );
            $this->store->set('scan', $scan);
        }

        while (true) {
            if ($this->store->countQueued() >= self::SCAN_QUEUE_LIMIT) {
                $run->note('同步队列较长，扫描暂停，等队列处理完再继续');
                return;
            }
            if (!$this->hasTime($deadline)) {
                $run->deadlineReached = true;
                return;
            }
            $cursor = isset($scan['cursor']) ? (int) $scan['cursor'] : null;
            $rows = (array) $this->src('threadsBelow', array($cursor, self::READ_ROWS));
            foreach (array_chunk($rows, self::SCAN_CHUNK) as $chunk) {
                $scan['eligible'] = (int) $scan['eligible'] + $this->scanChunk($ctx, $chunk);
                $scan['cursor'] = min(array_map('intval', array_column($chunk, 'tid')));
                $this->store->set('scan', $scan);
            }
            if (count($rows) < self::READ_ROWS) {
                $this->finishRound((int) $scan['eligible'], (int) $scan['round_started_at']);
                return;
            }
        }
    }

    /**
     * Queue the threads of one chunk that need a request: new, changed (text or category),
     * or to be taken down. Returns how many may sync.
     */
    private function scanChunk(array $ctx, array $threads): int
    {
        $byTid = array();
        foreach ($threads as $thread) {
            if (is_array($thread) && isset($thread['tid']) && (int) $thread['tid'] > 0) {
                $byTid[(int) $thread['tid']] = $thread;
            }
        }
        $rows = $this->store->posts(array_keys($byTid));
        $queue = array();
        $needPost = array();
        $eligible = 0;
        foreach ($byTid as $tid => $thread) {
            $row = $rows[$tid] ?? null;
            $reason = $this->precheck($ctx, $thread);
            if ($reason !== null && $reason !== 'not_selected') {
                if ($row !== null) {
                    $queue[] = $tid;
                }
                continue;
            }
            if ($row === null) {
                if ($reason === null) {
                    // New to the plugin: the drain reads the post and decides.
                    $eligible++;
                    $queue[] = $tid;
                }
                continue;
            }
            // Deselected threads are read too: one hidden for another reason is withdrawn at once.
            $needPost[$tid] = $thread;
        }
        if ($needPost) {
            $posts = $this->firstPostsOf($needPost);
            foreach ($needPost as $tid => $thread) {
                $d = $this->decide($ctx, $tid, $thread, $posts[$tid] ?? null);
                if (!$d['eligible']) {
                    if (!($d['reason'] === 'not_selected' && $rows[$tid]['state'] === 'orphan')) {
                        $queue[] = $tid;
                    }
                    continue;
                }
                $eligible++;
                $row = $rows[$tid];
                $item = $this->prepare($d, $row);
                $settled = in_array($row['state'], array('synced', 'rejected', 'failed', 'held'), true);
                if (!$settled || (string) $row['content_hash'] !== $item['hash']) {
                    $queue[] = $tid;
                }
            }
        }
        if ($queue) {
            $this->store->enqueue($queue, $this->now(), false);
        }
        return $eligible;
    }

    /** A round (cron scan or full sync) read every thread: record it and start the reconcile. */
    private function finishRound(int $eligible, int $startedAt): void
    {
        $this->store->set('scan', array(
            'active' => false, 'cursor' => null, 'round_started_at' => $startedAt,
            'round_finished_at' => $this->now(), 'eligible' => $eligible,
        ));
        $this->store->clearOrphans();
        $this->store->set('guard', null);
        $this->store->set('reconcile', array('phase' => 'listing', 'cursor' => null, 'pages' => 0, 'remote' => 0, 'local' => $eligible));
    }

    // ================================================================ reconcile

    /** List MomoBird's posts (resumable), then settle the orphans it found. */
    private function reconcileStep(MomoBird_SyncResult $run, int $deadline): void
    {
        $rec = $this->store->get('reconcile');
        if (!is_array($rec) || $this->stopped($run)) {
            return;
        }
        while (($rec['phase'] ?? '') === 'listing') {
            if ($this->stopped($run)) {
                return;
            }
            if (!$this->hasTime($deadline)) {
                $run->deadlineReached = true;
                return;
            }
            $cursor = isset($rec['cursor']) && is_string($rec['cursor']) ? $rec['cursor'] : null;
            try {
                $page = $this->client->listPosts($cursor);
            } catch (MomoBird_ConnectError $e) {
                if ($e->kind() === MomoBird_ConnectError::UNAUTHORIZED || $e->kind() === MomoBird_ConnectError::DISABLED) {
                    $this->halt($run, $e);
                } else {
                    $this->networkDown = true;
                    $run->fail('读取 MomoBird 上的帖子列表失败：' . $e->getMessage());
                }
                return;
            }
            $ids = array();
            foreach ($page['items'] as $item) {
                $id = (string) $item['external_id'];
                if (ctype_digit($id) && (int) $id > 0 && strlen($id) <= 10) {
                    $ids[] = (int) $id;
                }
            }
            if ($ids) {
                $this->store->markRemote($ids, $this->now());
            }
            $rec['remote'] = (int) $rec['remote'] + count($page['items']);
            $rec['pages'] = (int) $rec['pages'] + 1;
            $next = $page['next_cursor'];
            if ($next === null) {
                $rec['phase'] = 'settle';
            } elseif ($next === $cursor || $rec['pages'] >= self::MAX_LIST_PAGES) {
                $this->store->clearOrphans();
                $this->store->set('reconcile', null);
                $run->fail('MomoBird 的帖子列表分页游标重复，无法读完列表，本轮没有撤下任何帖子');
                return;
            } else {
                $rec['cursor'] = $next;
            }
            $this->store->set('reconcile', $rec);
        }

        if (($rec['phase'] ?? '') !== 'settle') {
            $this->store->set('reconcile', null);
            return;
        }
        $before = $run->removed;
        if ($this->settleOrphans($run, $deadline, false, (int) $rec['local'], (int) $rec['remote'])) {
            $this->store->set('reconcile', null);
            $this->store->set('last_reconcile', array('at' => $this->now(), 'removed' => $run->removed - $before, 'held' => $run->guardHeld));
        }
    }

    // ================================================================ connect status and widget

    private function statusDue(): bool
    {
        $cached = $this->store->get('connect_status');
        return !isset($cached['checked_at']) || $this->now() - (int) $cached['checked_at'] >= self::STATUS_TTL;
    }

    /**
     * GET /status into kv `connect_status` (project, source, entitlement, widget, checked_at).
     * Clears a halt and the plan flag when MomoBird answers normally again.
     */
    private function refreshStatusCache(MomoBird_SyncResult $run): bool
    {
        $previous = $this->store->get('connect_status');
        $previous = is_array($previous) ? $previous : array();
        try {
            $data = $this->client->status();
        } catch (MomoBird_ConnectError $e) {
            $entry = $previous;
            $entry['ok'] = false;
            $entry['checked_at'] = $this->now();
            $entry['error_kind'] = $e->kind();
            $entry['error'] = $e->getMessage();
            $kind = $e->kind();
            if (in_array($kind, array(MomoBird_ConnectError::UNAUTHORIZED, MomoBird_ConnectError::DISABLED, MomoBird_ConnectError::PLAN_REQUIRED), true)
                && isset($entry['widget']) && is_array($entry['widget'])) {
                $entry['widget']['available'] = false;
            }
            $this->store->set('connect_status', $entry);
            if ($kind === MomoBird_ConnectError::UNAUTHORIZED || $kind === MomoBird_ConnectError::DISABLED) {
                $this->halt($run, $e);
            } else {
                $run->fail('无法读取 MomoBird 的连接状态：' . $e->getMessage());
            }
            return false;
        }

        $entry = array('ok' => true, 'checked_at' => $this->now());
        foreach (array('project', 'source', 'entitlement', 'widget') as $part) {
            $entry[$part] = isset($data[$part]) && is_array($data[$part]) ? $this->clean($data[$part]) : null;
        }
        $this->store->set('connect_status', $entry);
        $this->store->set('halt', null);
        $active = $entry['entitlement']['active'] ?? null;
        if ($active === false) {
            $this->store->set('plan', array('at' => $this->now()));
        } elseif ($this->store->get('plan') !== null) {
            $this->store->set('plan', null);
        }
        $this->gate = null;
        return true;
    }

    /**
     * After the first full sync, when the settings ask for the widget and MomoBird says it
     * was never published, switch it on once. Any other state (live, switched off by the
     * owner) is left alone and never touched again; WIDGET_NOT_READY and network trouble
     * are tried again on a later run.
     */
    private function maybeEnableWidget(MomoBird_SyncResult $run, int $deadline): void
    {
        if (!$this->settings->widgetEnabled() || $this->store->get('widget_enable') !== null || $this->stopped($run)
            || !$this->hasTime($deadline)) {
            return;
        }
        $full = $this->store->get('full_sync');
        if (empty($full['finished_at'])) {
            return;
        }
        $cached = $this->store->get('connect_status');
        $widget = isset($cached['widget']) && is_array($cached['widget']) ? $cached['widget'] : null;
        if (empty($cached['ok']) || $widget === null) {
            return;
        }
        if (!empty($widget['available']) || ($widget['reason'] ?? null) !== 'not_published') {
            $this->store->set('widget_enable', array('at' => $this->now(), 'result' => 'left_alone', 'reason' => $widget['reason'] ?? null));
            return;
        }
        try {
            $this->client->enableWidget();
        } catch (MomoBird_ConnectError $e) {
            $kind = $e->kind();
            if ($kind === MomoBird_ConnectError::WIDGET_NOT_READY || $kind === MomoBird_ConnectError::NETWORK
                || ($kind === MomoBird_ConnectError::HTTP && $e->status() >= 500) || $e->status() === 0) {
                $run->note('问答助手暂时没能开启，稍后再试：' . $e->getMessage());
                return;
            }
            $this->store->set('widget_enable', array('at' => $this->now(), 'result' => 'failed', 'error' => $e->getMessage()));
            $run->note('开启问答助手失败：' . $e->getMessage());
            return;
        }
        $this->store->set('widget_enable', array('at' => $this->now(), 'result' => 'enabled'));
        $run->note('已开启问答助手');
        if ($this->hasTime($deadline)) {
            $this->refreshStatusCache($run);
        }
    }

    // ================================================================ full sync

    /**
     * Thread phase of a full sync step: newest first from $below, until 20 threads were
     * pushed (or held), 1000 rows were read, or time ran out.
     *
     * @return int|null the cursor for the next step, null when every thread was read
     */
    private function fullThreads(MomoBird_SyncResult $run, array $ctx, ?int $below, int $deadline): ?int
    {
        $full = $this->store->get('full_sync') ?: array('eligible' => 0);
        $push = array();
        $withdraw = array();
        $read = 0;
        $finished = false;
        $cursor = $below;

        while (count($push) < self::PUSH_BATCH && $read < self::READ_ROWS) {
            if (!$this->hasTime($deadline)) {
                $run->deadlineReached = true;
                break;
            }
            $rows = (array) $this->src('threadsBelow', array($cursor, self::FULL_READ_ROWS));
            $read += count($rows);
            $consumed = 0;
            foreach (array_chunk($rows, self::PUSH_BATCH) as $chunk) {
                $threads = array();
                foreach ($chunk as $thread) {
                    $threads[(int) $thread['tid']] = $thread;
                }
                $posts = $this->postsFor($ctx, $threads);
                $stored = $this->store->posts(array_keys($threads));
                foreach ($threads as $tid => $thread) {
                    $d = $this->decide($ctx, $tid, $thread, $posts[$tid] ?? null);
                    if ($d['eligible']) {
                        $full['eligible'] = (int) $full['eligible'] + 1;
                    }
                    $this->route($run, $d, $stored[$tid] ?? null, $push, $withdraw);
                    $cursor = $tid;
                    $consumed++;
                    if (count($push) >= self::PUSH_BATCH) {
                        break 2;
                    }
                }
            }
            if (count($rows) < self::FULL_READ_ROWS && $consumed === count($rows)) {
                $finished = true;
                break;
            }
        }

        $this->store->set('full_sync', $full);
        $this->sendWithdrawals($run, $withdraw, $deadline, false);
        $this->sendPushes($run, $push, $deadline);
        return $finished ? null : (int) $cursor;
    }

    // ================================================================ helpers

    /** @return int[] valid, unique tids, highest first */
    private static function tids(array $values): array
    {
        $out = array();
        foreach ($values as $value) {
            if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                $tid = (int) $value;
                if ($tid > 0) {
                    $out[$tid] = $tid;
                }
            }
        }
        $out = array_values($out);
        rsort($out);
        return $out;
    }

    private static function bodyHash(array $payload): string
    {
        $json = json_encode(array((string) ($payload['title'] ?? ''), (string) ($payload['content'] ?? '')), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        return hash('sha256', (string) $json);
    }

    /** An error code shown in the failure list: a short identifier that cannot be a key. */
    private static function code($code, string $fallback): string
    {
        if (is_string($code) && preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $code) === 1 && stripos($code, 'mbk_') === false) {
            return $code;
        }
        return $fallback;
    }

    private static function plainTitle(string $subject): string
    {
        $title = html_entity_decode(strip_tags($subject), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string) preg_replace('/\s+/u', ' ', $title));
    }

    /** At most $max characters, never cutting a UTF-8 sequence. */
    private static function truncate(string $text, int $max): string
    {
        if (preg_match('/^.{0,' . $max . '}/su', $text, $m) === 1) {
            return $m[0];
        }
        return substr((string) @iconv('UTF-8', 'UTF-8//IGNORE', $text), 0, $max);
    }

    /** Status data from MomoBird, with anything shaped like a key removed. */
    private function clean(array $data): array
    {
        $out = array();
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $out[$k] = $this->clean($v);
            } elseif (is_string($v)) {
                $out[$k] = MomoBird_Redactor::clean($v, $this->settings->key());
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    private function originMessage(): string
    {
        $cached = $this->store->get('connect_status');
        $siteUrl = $cached['source']['site_url'] ?? null;
        $source = is_string($siteUrl) && $siteUrl !== '' ? $siteUrl : '（MomoBird 里还没有填写）';
        return '论坛地址（' . $this->settings->forumUrl() . '）和 MomoBird 来源的网站地址（' . $source . '）不一致，'
            . '两边必须同源（协议、域名、端口都一样，注意 www 和 http/https）。请在插件设置里改「论坛地址」，'
            . '或在 Discuz! 后台「全局 → 站点信息」改站点 URL。在这之前不推送帖子，撤下照常';
    }

    private function kindNotice(?string $kind): string
    {
        return '这个知识源的类型是 ' . ($kind === null || $kind === '' ? '未知' : $kind)
            . '，不是 discuz：照常推送，但不会自动撤下论坛里已不该同步的帖子';
    }
}
