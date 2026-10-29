<?php

require_once __DIR__ . '/FakeStore.php';
require_once __DIR__ . '/FakeForumSource.php';
require_once __DIR__ . '/FakeConnect.php';

const MB_SE_KEY = 'mbk_DISCUZLOCALTESTKEYDISCUZLOCALTESTKEYDISC';

class MomoBird_TestClock
{
    public $now = 1800000000;

    public function __invoke(): int
    {
        return $this->now;
    }
}

/** A forum (group 1, forums 2 汽车 and 3 数码), an empty MomoBird, and an engine over them. */
function mb_se(array $settings = array()): stdClass
{
    $w = new stdClass();
    $w->clock = new MomoBird_TestClock();
    $w->store = new MomoBird_FakeStore();
    $w->forum = new MomoBird_FakeForumSource();
    $w->forum->addGroup(1, '分区')->addForum(2, '汽车')->addForum(3, '数码');
    $w->server = new MomoBird_FakeConnect();
    mb_se_configure($w, $settings);
    return $w;
}

function mb_se_configure(stdClass $w, array $settings = array()): void
{
    $w->settings = MomoBird_Settings::fromArray($settings + array(
        'baseUrl' => 'https://api.valley.atlaspaces.com',
        'key' => MB_SE_KEY,
        'forumUrl' => 'https://bbs.example.com/',
    ));
    $w->engine = new MomoBird_SyncEngine($w->settings, new MomoBird_ConnectClient($w->settings, $w->server->transport), $w->store, $w->forum, $w->clock);
}

function mb_se_deadline(stdClass $w, int $seconds = 20): int
{
    return $w->clock->now + $seconds;
}

function mb_se_threads(stdClass $w, array $tids, int $fid = 2): void
{
    foreach ($tids as $tid) {
        $w->forum->addThread($tid, $fid);
    }
}

/** Enqueue and drain; returns the result. */
function mb_se_sync(stdClass $w, array $tids): MomoBird_SyncResult
{
    $w->engine->enqueue($tids, 'test');
    return $w->engine->drain(mb_se_deadline($w));
}

/** Run fullSyncBatch until done; returns the number of calls. */
function mb_se_full(stdClass $w): int
{
    $cursor = null;
    for ($i = 1; $i <= 1000; $i++) {
        $batch = $w->engine->fullSyncBatch($cursor);
        if ($batch['done']) {
            return $i;
        }
        $cursor = $batch['cursor'];
    }
    throw new RuntimeException('full sync never finished');
}

function mb_se_reset_requests(stdClass $w): void
{
    $w->server->transport->requests = array();
}

// ---------------------------------------------------------------- hooks

mb_test('hooks never touch the network', function () {
    $w = mb_se();
    $w->server->transport = new MomoBird_FakeTransport(function () {
        throw new RuntimeException('network used');
    });
    mb_se_configure($w);
    mb_se_threads($w, array(12, 13));
    $w->engine->enqueue(array(12, '13', 0, -4, 'x'), 'post_newthread_succeed');
    mb_assert_same(0, count($w->server->transport->requests), 'no requests');
    mb_assert_same(array(), $w->forum->reads, 'no forum reads');
    mb_assert_same(array(13, 12), $w->store->queued(10), 'queued valid tids only');
    mb_assert_same($w->clock->now, $w->store->rows[12]['queued_at'], 'queued_at set');
});

// ---------------------------------------------------------------- drain

mb_test('drain pushes a new thread with its category and url', function () {
    $w = mb_se();
    mb_se_threads($w, array(12));
    $result = mb_se_sync($w, array(12));
    mb_assert_same(1, $result->pushed, 'pushed');
    $post = $w->server->posts['12'];
    mb_assert_same('Thread 12', $post['title'], 'title');
    mb_assert_same('汽车', $post['category'], 'category');
    mb_assert_same('https://bbs.example.com/forum.php?mod=viewthread&tid=12', $post['url'], 'url');
    mb_assert_same('Body of thread 12', $post['content'], 'content');
    $row = $w->store->rows[12];
    mb_assert_same('synced', $row['state'], 'state');
    mb_assert_same(1, $row['remote'], 'remote');
    mb_assert_same(null, $row['queued_at'], 'dequeued');
    mb_assert_same(MomoBird_ThreadMapper::hash($post), $row['content_hash'], 'hash stored');
    mb_assert_same('汽车', $row['category'], 'category stored');
});

mb_test('drain skips a synced thread whose content and category did not change', function () {
    $w = mb_se();
    mb_se_threads($w, array(12));
    mb_se_sync($w, array(12));
    mb_se_reset_requests($w);
    $result = mb_se_sync($w, array(12));
    mb_assert_same(0, $w->server->count('PUT /posts'), 'no push');
    mb_assert_same(1, $result->unchanged, 'counted unchanged');
});

mb_test('drain re-pushes when only the category changed', function () {
    $w = mb_se();
    mb_se_threads($w, array(12));
    mb_se_sync($w, array(12));
    $w->forum->forums[2]['name'] = '新能源汽车';
    mb_se_reset_requests($w);
    mb_se_sync($w, array(12));
    mb_assert_same(array('12'), $w->server->pushedIds(), 're-pushed');
    mb_assert_same('新能源汽车', $w->server->posts['12']['category'], 'new category');
});

mb_test('drain withdraws a thread that became invisible', function () {
    $w = mb_se();
    mb_se_threads($w, array(12, 13));
    mb_se_sync($w, array(12, 13));
    $w->forum->threads[12]['displayorder'] = -1;
    mb_se_reset_requests($w);
    $result = mb_se_sync($w, array(12, 13));
    mb_assert_same(array('12'), $w->server->deletedIds(), 'withdrawn');
    mb_assert_same(1, $result->removed, 'removed count');
    mb_assert_same(null, $w->store->state(12), 'row gone');
    mb_assert_same('synced', $w->store->state(13), 'other kept');
    mb_assert(!isset($w->server->posts['12']), 'gone from MomoBird');
});

mb_test('drain withdraws a thread that no longer exists', function () {
    $w = mb_se();
    mb_se_threads($w, array(12));
    mb_se_sync($w, array(12));
    unset($w->forum->threads[12], $w->forum->posts[12]);
    mb_se_sync($w, array(12));
    mb_assert_same(array('12'), $w->server->deletedIds(), 'withdrawn');
    mb_assert_same(null, $w->store->state(12), 'row gone');
});

mb_test('drain never sends anything for an invisible thread MomoBird never had', function () {
    $w = mb_se();
    $w->forum->addThread(12, 2, null, array('displayorder' => -2));
    mb_se_sync($w, array(12, 99));
    mb_assert_same(array(), $w->server->writes(), 'no writes');
    mb_assert_same(array(), $w->store->rows, 'rows removed');
});

mb_test('drain skips an empty first post and withdraws it when it was synced', function () {
    $w = mb_se();
    $w->forum->addThread(12, 2, '[attach]1[/attach]');
    mb_se_threads($w, array(13));
    $result = mb_se_sync($w, array(12, 13));
    mb_assert_same(array('13'), $w->server->pushedIds(), 'only the post with text');
    mb_assert_same(1, $result->skipped, 'skipped counted');
    mb_assert_same(null, $w->store->state(12), 'no row for the empty post');

    $w->forum->posts[13]['message'] = '[img]x.png[/img]';
    mb_se_sync($w, array(13));
    mb_assert_same(array('13'), $w->server->deletedIds(), 'emptied post withdrawn');
    mb_assert_same(null, $w->store->state(13), 'row gone');
});

mb_test('drain records a rejected post with its title and error code', function () {
    $w = mb_se();
    mb_se_threads($w, array(12, 13));
    $w->forum->threads[13]['subject'] = '坏帖子';
    $w->server->rejectIds['13'] = 'VALIDATION_FAILED';
    $result = mb_se_sync($w, array(12, 13));
    mb_assert_same(1, $result->pushed, 'one pushed');
    mb_assert_same(1, $result->rejected, 'one rejected');
    mb_assert_same('rejected', $w->store->state(13), 'state');
    $failures = $w->engine->status()['failures'];
    mb_assert_same(1, count($failures), 'one failure listed');
    mb_assert_same(13, $failures[0]['tid'], 'tid');
    mb_assert_same('坏帖子', $failures[0]['title'], 'title');
    mb_assert_same('VALIDATION_FAILED', $failures[0]['error'], 'code');
    mb_assert_same('rejected', $failures[0]['state'], 'state listed');
});

mb_test('a rejected batch is re-pushed one post at a time', function () {
    $w = mb_se();
    mb_se_threads($w, array(11, 12, 13));
    $w->server->poison = array('12');
    $result = mb_se_sync($w, array(11, 12, 13));
    mb_assert_same(array(array('13', '12', '11'), array('13'), array('12'), array('11')), array_map(function ($b) {
        return array_column($b['posts'], 'external_id');
    }, $w->server->bodies('PUT /posts')), 'batch then singles');
    mb_assert_same('synced', $w->store->state(11), '11 synced');
    mb_assert_same('synced', $w->store->state(13), '13 synced');
    mb_assert_same('rejected', $w->store->state(12), '12 rejected');
    mb_assert_same('VALIDATION_FAILED', $w->store->rows[12]['last_error'], 'code kept');
    mb_assert_same(2, $result->pushed, 'pushed');
    mb_assert_same(1, $result->rejected, 'rejected');
});

mb_test('a too-large batch is re-pushed one post at a time', function () {
    $w = mb_se();
    mb_se_threads($w, array(11, 12));
    $w->server->fail('PUT /posts', 413, 'PAYLOAD_TOO_LARGE');
    mb_se_sync($w, array(11, 12));
    mb_assert_same(3, $w->server->count('PUT /posts'), 'batch then two singles');
    mb_assert_same('synced', $w->store->state(11), '11');
    mb_assert_same('synced', $w->store->state(12), '12');
});

mb_test('drain records network errors as failures and stops sending', function () {
    $w = mb_se();
    mb_se_threads($w, range(1, 25));
    $w->server->fail('PUT /posts', 0);
    $result = mb_se_sync($w, range(1, 25));
    mb_assert_same(1, $w->server->count('PUT /posts'), 'one attempt, then stop');
    mb_assert_same(20, $result->failed, 'first batch failed');
    mb_assert_same('failed', $w->store->state(25), 'newest failed');
    mb_assert_same(1, $w->store->rows[25]['attempts'], 'attempt counted');
    mb_assert(strpos($w->store->rows[25]['last_error'], '无法连接') !== false, 'error kept: ' . $w->store->rows[25]['last_error']);
    mb_assert_same(array(5, 4, 3, 2, 1), $w->store->queued(10), 'rest still queued');
    mb_assert_same(5, $result->queued, 'remaining queue reported');
});

mb_test('drain pushes newest threads first in batches of 20', function () {
    $w = mb_se();
    mb_se_threads($w, range(1, 45));
    $w->engine->enqueue(range(1, 45), 'test');
    $w->engine->drain(mb_se_deadline($w));
    $batches = array_map(function ($b) {
        return array_column($b['posts'], 'external_id');
    }, $w->server->bodies('PUT /posts'));
    mb_assert_same(3, count($batches), 'three batches');
    mb_assert_same(array_map('strval', range(45, 26)), $batches[0], 'first batch newest');
    mb_assert_same(array_map('strval', range(5, 1)), $batches[2], 'last batch oldest');
});

mb_test('drain stops at the deadline before the next network call', function () {
    $w = mb_se();
    mb_se_threads($w, range(1, 45));
    $w->engine->enqueue(range(1, 45), 'test');
    $clock = $w->clock;
    $w->server->transport = new MomoBird_FakeTransport(function ($m, $u, $h, $b) use ($w, $clock) {
        $clock->now += 15;
        return $w->server->handle($m, $u, $b);
    });
    mb_se_configure($w);
    $start = $clock->now;
    $result = $w->engine->drain($start + 20);
    // status (15s) + one push (30s): no time left for the second push
    mb_assert_same(1, $w->server->count('PUT /posts'), 'one push only');
    mb_assert_same(25, $w->store->countQueued(), 'rest kept in the queue');
    mb_assert($result->deadlineReached, 'reports deadline');
});

mb_test('drain does nothing while the settings are invalid', function () {
    $w = mb_se(array('key' => ''));
    mb_se_threads($w, array(12));
    $result = mb_se_sync($w, array(12));
    mb_assert_same(array(), $w->server->routes(), 'no requests');
    mb_assert_same(array(12), $w->store->queued(10), 'still queued');
    mb_assert($result->error !== null, 'error reported');
});

mb_test('modified_at follows text changes only', function () {
    $w = mb_se();
    mb_se_threads($w, array(12));
    mb_se_sync($w, array(12));
    mb_assert_same(gmdate('Y-m-d\TH:i:s\Z', 1700000012), $w->server->posts['12']['modified_at'], 'first sync uses dateline');
    mb_assert_same(0, $w->store->rows[12]['content_changed_at'], 'no change recorded yet');

    $w->clock->now += 600;
    $w->forum->forums[2]['name'] = '新名字';
    mb_se_sync($w, array(12));
    mb_assert_same(gmdate('Y-m-d\TH:i:s\Z', 1700000012), $w->server->posts['12']['modified_at'], 'category change keeps the time');

    $w->clock->now += 600;
    $edited = $w->clock->now;
    $w->forum->posts[12]['message'] = 'Edited body';
    mb_se_sync($w, array(12));
    mb_assert_same(gmdate('Y-m-d\TH:i:s\Z', $edited), $w->server->posts['12']['modified_at'], 'edit moves the time');
    mb_assert_same($edited, $w->store->rows[12]['content_changed_at'], 'stored');

    $w->clock->now += 600;
    $w->forum->forums[2]['name'] = '又改名';
    mb_se_sync($w, array(12));
    mb_assert_same(gmdate('Y-m-d\TH:i:s\Z', $edited), $w->server->posts['12']['modified_at'], 'later category change keeps the edit time');
});

// ---------------------------------------------------------------- push rules

mb_test('402 stops pushing, withdrawals go on, plan shows as expired', function () {
    $w = mb_se();
    mb_se_threads($w, array(11, 12));
    mb_se_sync($w, array(11, 12));
    $w->forum->threads[11]['displayorder'] = -1;
    $w->forum->posts[12]['message'] = 'changed';
    mb_se_threads($w, array(13));
    $w->server->fail('PUT /posts', 402, 'PLAN_REQUIRED');
    mb_se_reset_requests($w);
    $result = mb_se_sync($w, array(11, 12, 13));
    mb_assert($result->planRequired, 'plan flagged');
    mb_assert_same(array('11'), $w->server->deletedIds(), 'withdrawal still sent');
    mb_assert_same('held', $w->store->state(12), '12 held');
    mb_assert_same('held', $w->store->state(13), '13 held');
    mb_assert_same(1, $w->server->count('PUT /posts'), 'one push attempt');
    $status = $w->engine->status();
    mb_assert(strpos($status['plan']['message'], '套餐已到期') !== false, 'plan message');

    // While the plan is expired, new threads are held without another push.
    mb_se_threads($w, array(14));
    mb_se_reset_requests($w);
    mb_se_sync($w, array(14));
    mb_assert_same(0, $w->server->count('PUT /posts'), 'no push while expired');
    mb_assert_same('held', $w->store->state(14), '14 held');

    // Renewed: the hourly status refresh reopens pushing and the held threads go out.
    $w->clock->now += 3700;
    $w->engine->cronTick(mb_se_deadline($w));
    mb_assert_same('synced', $w->store->state(12), '12 synced after renewal');
    mb_assert_same('synced', $w->store->state(14), '14 synced after renewal');
    mb_assert_same(null, $w->engine->status()['plan'], 'plan notice cleared');
});

mb_test('401 stops everything until the connection works again', function () {
    $w = mb_se();
    mb_se_threads($w, array(11, 12));
    $w->server->fail('PUT /posts', 401, 'UNAUTHORIZED');
    $result = mb_se_sync($w, array(11, 12));
    mb_assert_same('UNAUTHORIZED', $result->halted, 'halted');
    mb_assert_same(array(12, 11), $w->store->queued(10), 'kept in the queue');
    mb_se_reset_requests($w);
    mb_se_sync($w, array(11));
    $w->engine->cronTick(mb_se_deadline($w));
    mb_assert_same(array(), $w->server->writes(), 'no writes while halted');
    $status = $w->engine->status();
    mb_assert_same('UNAUTHORIZED', $status['halted']['kind'], 'status shows it');

    // The hourly status check succeeds again (new key saved): sync resumes.
    $w->clock->now += 3700;
    $w->engine->cronTick(mb_se_deadline($w));
    mb_assert_same(null, $w->engine->status()['halted'], 'cleared');
    mb_assert_same('synced', $w->store->state(12), 'pushed after recovery');
});

mb_test('409 SOURCE_DISABLED stops withdrawals too', function () {
    $w = mb_se();
    mb_se_threads($w, array(11, 12));
    mb_se_sync($w, array(11, 12));
    $w->forum->threads[11]['displayorder'] = -1;
    $w->server->fail('POST /posts/delete', 409, 'SOURCE_DISABLED');
    $result = mb_se_sync($w, array(11));
    mb_assert_same('DISABLED', $result->halted, 'halted');
    mb_assert_same(array(11), $w->store->queued(10), 'withdrawal kept queued');
    mb_se_reset_requests($w);
    $w->engine->drain(mb_se_deadline($w));
    mb_assert_same(array(), $w->server->routes(), 'no requests while disabled');
});

mb_test('different origin: nothing is pushed, withdrawals go on', function () {
    $w = mb_se();
    mb_se_threads($w, array(11, 12));
    mb_se_sync($w, array(11, 12));
    $w->server->siteUrl = 'https://www.example.com';
    $w->clock->now += 3700;
    $w->forum->threads[11]['displayorder'] = -1;
    $w->forum->posts[12]['message'] = 'changed';
    mb_se_reset_requests($w);
    $result = mb_se_sync($w, array(11, 12));
    mb_assert($result->originBlocked, 'origin blocked');
    mb_assert_same(0, $w->server->count('PUT /posts'), 'no push');
    mb_assert_same(array('11'), $w->server->deletedIds(), 'withdrawal sent');
    mb_assert_same('held', $w->store->state(12), 'held');
    $origin = $w->engine->status()['origin'];
    mb_assert_same(false, $origin['ok'], 'status origin');
    mb_assert_same('https://bbs.example.com/', $origin['forum_url'], 'forum url shown');
    mb_assert_same('https://www.example.com', $origin['source_url'], 'source url shown');
});

mb_test('a closed site sends nothing at all', function () {
    $w = mb_se();
    mb_se_threads($w, array(11, 12));
    mb_se_sync($w, array(11, 12));
    $w->forum->setting['bbclosed'] = 1;
    $w->forum->threads[11]['displayorder'] = -1;
    mb_se_threads($w, array(13));
    mb_se_reset_requests($w);
    $result = mb_se_sync($w, array(11, 13));
    $w->clock->now += 86400 * 2;
    $w->engine->cronTick(mb_se_deadline($w));
    mb_assert($result->siteClosed, 'reported');
    mb_assert_same(array(), $w->server->writes(), 'no push, no withdrawal');
    mb_assert_same(array(13, 11), $w->store->queued(10), 'kept for later');
    mb_assert_same('site_closed', $w->engine->status()['site'], 'status shows closed');
});

mb_test('failed threads are retried hourly, at most five times', function () {
    $w = mb_se();
    mb_se_threads($w, array(12));
    $w->server->fail('PUT /posts', 503, '', 100);
    mb_se_sync($w, array(12));
    mb_assert_same(1, $w->store->rows[12]['attempts'], 'first failure');

    $w->clock->now += 1800;
    $w->engine->cronTick(mb_se_deadline($w));
    mb_assert_same(1, $w->server->count('PUT /posts'), 'not retried within the hour');

    for ($i = 0; $i < 7; $i++) {
        $w->clock->now += 3601;
        $w->engine->cronTick(mb_se_deadline($w));
    }
    mb_assert_same(6, $w->server->count('PUT /posts'), 'first try plus five retries');
    mb_assert_same('failed', $w->store->state(12), 'still failed');

    $w->server = new MomoBird_FakeConnect();
    mb_se_configure($w);
    $result = $w->engine->retryFailed();
    mb_assert_same('synced', $w->store->state(12), 'manual retry works');
    mb_assert_same(1, $result->pushed, 'pushed');
    mb_assert_same(0, $w->store->rows[12]['attempts'], 'attempts reset');
});

// ---------------------------------------------------------------- fingerprint

mb_test('fingerprint is recorded on the first tick without requeueing', function () {
    $w = mb_se();
    mb_se_threads($w, array(11, 12));
    $w->engine->cronTick(mb_se_deadline($w));
    mb_assert($w->store->get('fingerprint') !== null, 'stored');
    mb_assert_same(null, $w->store->get('requeue'), 'no forum requeued');
    $w->clock->now += 300;
    $w->engine->cronTick(mb_se_deadline($w));
    mb_assert_same(null, $w->store->get('requeue'), 'unchanged settings requeue nothing');
});

mb_test('fingerprint change requeues the forum: view permission withdraws its threads', function () {
    $w = mb_se();
    mb_se_threads($w, array(11, 12), 2);
    mb_se_threads($w, array(21), 3);
    mb_se_full($w);
    $w->engine->cronTick(mb_se_deadline($w));
    mb_se_reset_requests($w);

    $w->forum->fields[2]['viewperm'] = "\t1\t2\t";
    $w->clock->now += 300;
    $w->engine->cronTick(mb_se_deadline($w));
    $deleted = $w->server->deletedIds();
    sort($deleted);
    mb_assert_same(array('11', '12'), $deleted, 'forum 2 withdrawn');
    mb_assert_same('synced', $w->store->state(21), 'forum 3 untouched');
    mb_assert_same(0, $w->server->count('PUT /posts'), 'nothing pushed');
});

mb_test('fingerprint change requeues the forum: renaming a top forum re-pushes it and its sub-forums', function () {
    $w = mb_se();
    $w->forum->addForum(4, '新能源', 2, 'sub');
    mb_se_threads($w, array(11), 2);
    mb_se_threads($w, array(12), 4);
    mb_se_threads($w, array(21), 3);
    mb_se_full($w);
    $w->engine->cronTick(mb_se_deadline($w));
    mb_assert_same('汽车', $w->server->posts['12']['category'], 'sub-forum uses the top name');
    mb_se_reset_requests($w);

    $w->forum->forums[2]['name'] = '汽车 <b>论坛</b>';
    $w->clock->now += 300;
    $w->engine->cronTick(mb_se_deadline($w));
    $pushed = $w->server->pushedIds();
    sort($pushed);
    mb_assert_same(array('11', '12'), $pushed, 'forum and sub-forum re-pushed');
    mb_assert_same('汽车 论坛', $w->server->posts['11']['category'], 'new category');
    mb_assert_same('汽车 论坛', $w->server->posts['12']['category'], 'new category on sub');
});

mb_test('fingerprint change requeues the forum: a newly visible forum is pushed', function () {
    $w = mb_se();
    $w->forum->fields[3]['viewperm'] = '1';
    mb_se_threads($w, array(11), 2);
    mb_se_threads($w, array(21, 22), 3);
    mb_se_full($w);
    $w->engine->cronTick(mb_se_deadline($w));
    mb_assert(!isset($w->server->posts['21']), 'hidden forum not pushed');
    $w->forum->fields[3]['viewperm'] = '';
    $w->clock->now += 300;
    $w->engine->cronTick(mb_se_deadline($w));
    mb_assert(isset($w->server->posts['21']) && isset($w->server->posts['22']), 'pushed once visible');
});

mb_test('fingerprint change requeues the forum: deselected forums are withdrawn behind the cleanup guard', function () {
    // Few threads in the deselected forum: withdrawn.
    $w = mb_se();
    mb_se_threads($w, array(11, 12), 2);
    mb_se_threads($w, range(21, 40), 3);
    mb_se_full($w);
    $w->engine->cronTick(mb_se_deadline($w));
    mb_se_reset_requests($w);
    mb_se_configure($w, array('forumIds' => array(3)));
    $w->clock->now += 300;
    $w->engine->cronTick(mb_se_deadline($w));
    $deleted = $w->server->deletedIds();
    sort($deleted);
    mb_assert_same(array('11', '12'), $deleted, 'deselected forum withdrawn');

    // Most of the knowledge base in the deselected forum: held until confirmed.
    $w = mb_se();
    mb_se_threads($w, range(1, 15), 2);
    mb_se_threads($w, array(21, 22), 3);
    mb_se_full($w);
    $w->engine->cronTick(mb_se_deadline($w));
    mb_se_reset_requests($w);
    mb_se_configure($w, array('forumIds' => array(3)));
    $w->clock->now += 300;
    $result = $w->engine->cronTick(mb_se_deadline($w));
    mb_assert_same(array(), $w->server->deletedIds(), 'nothing withdrawn');
    mb_assert($result->guardHeld, 'guard reported');
    $guard = $w->engine->status()['guard'];
    mb_assert_same(15, $guard['would_remove'], 'would remove 15');
    mb_assert_same('guard', $guard['reason'], 'reason');
    $w->engine->confirmWithdraw();
    mb_assert_same(15, count($w->server->deletedIds()), 'withdrawn after confirmation');
    mb_assert_same(null, $w->engine->status()['guard'], 'guard cleared');
    mb_assert(isset($w->server->posts['21']), 'selected forum kept');
});

// ---------------------------------------------------------------- scan and reconcile

mb_test('scan resumes from cursor', function () {
    $w = mb_se();
    mb_se_threads($w, range(1, 2500));
    mb_se_full($w);
    $w->engine->cronTick(mb_se_deadline($w));
    $w->clock->now += 86400 + 1;
    mb_se_reset_requests($w);
    $w->forum->reads = array();
    $clock = $w->clock;
    $w->forum->onRead = function ($what) use ($clock) {
        if (strpos($what, 'threadsBelow:') === 0) {
            $clock->now += 11;
        }
    };

    $w->engine->cronTick($clock->now + 20);
    $scans = array_values(array_filter($w->forum->reads, function ($r) {
        return strpos($r, 'threadsBelow:') === 0;
    }));
    mb_assert_same(array('threadsBelow:-:1000', 'threadsBelow:1501:1000'), $scans, 'two reads then out of time');
    $scan = $w->engine->status()['scan'];
    mb_assert_same(501, $scan['cursor'], 'cursor saved');
    mb_assert_same(true, $scan['active'], 'round still running');
    mb_assert_same(array(), $w->server->writes(), 'nothing changed, nothing sent');

    $w->forum->reads = array();
    $w->clock->now += 300;
    $w->engine->cronTick($clock->now + 20);
    $scans = array_values(array_filter($w->forum->reads, function ($r) {
        return strpos($r, 'threadsBelow:') === 0;
    }));
    mb_assert_same(array('threadsBelow:501:1000'), $scans, 'continued from the cursor');
    $scan = $w->engine->status()['scan'];
    mb_assert_same(null, $scan['cursor'], 'cursor reset');
    mb_assert_same(false, $scan['active'], 'round finished');
    mb_assert_same($clock->now, $scan['round_finished_at'], 'finish time recorded');
    mb_assert_same(2500, $scan['eligible'], 'eligible counted');
});

mb_test('scan finds changes without hooks and the next round waits a day', function () {
    $w = mb_se();
    mb_se_threads($w, array(11, 12, 13));
    mb_se_full($w);
    $w->engine->cronTick(mb_se_deadline($w));
    $w->forum->threads[11]['price'] = 5;          // made paid in the admin panel
    $w->forum->posts[12]['message'] = 'new text'; // edited outside the hooks
    $w->forum->addThread(14, 2);                  // approved in the admin panel
    $w->clock->now += 3600;
    mb_se_reset_requests($w);
    $w->engine->cronTick(mb_se_deadline($w));
    mb_assert_same(array(), $w->server->writes(), 'no new round within a day');

    $w->clock->now += 86400;
    $w->engine->cronTick(mb_se_deadline($w));
    mb_assert_same(array('11'), $w->server->deletedIds(), 'paid thread withdrawn');
    $pushed = $w->server->pushedIds();
    sort($pushed);
    mb_assert_same(array('12', '14'), $pushed, 'edited and new pushed, unchanged skipped');
});

mb_test('withdraw waits for a full round', function () {
    $w = mb_se();
    mb_se_threads($w, range(1, 2500));
    mb_se_full($w);
    $w->engine->cronTick(mb_se_deadline($w));
    $w->server->seed(array(9001, 9002));
    $w->clock->now += 86400 + 1;
    $clock = $w->clock;
    $w->forum->onRead = function ($what) use ($clock) {
        if (strpos($what, 'threadsBelow:') === 0) {
            $clock->now += 11;
        }
    };
    mb_se_reset_requests($w);
    $w->engine->cronTick($clock->now + 20);
    mb_assert_same(array(), $w->server->deletedIds(), 'not before the round ends');
    mb_assert_same(0, $w->server->count('GET /posts'), 'no listing yet');

    $w->clock->now += 300;
    $w->engine->cronTick($clock->now + 20);
    $deleted = $w->server->deletedIds();
    sort($deleted);
    mb_assert_same(array('9001', '9002'), $deleted, 'leftovers withdrawn after the round');
    mb_assert_same(2500, count($w->server->posts), 'local threads kept');
    mb_assert($w->engine->status()['last_reconcile_at'] !== null, 'reconcile time recorded');
});

mb_test('cleanup guard holds a reconcile when nothing local should sync', function () {
    $w = mb_se();
    $w->forum->forums[2]['status'] = 0;
    $w->forum->forums[3]['status'] = 0;
    mb_se_threads($w, array(11));
    $w->server->seed(array(11, 12, 13));
    $w->clock->now += 86400;
    $result = $w->engine->cronTick(mb_se_deadline($w));
    mb_assert_same(array(), $w->server->deletedIds(), 'nothing withdrawn');
    mb_assert($result->guardHeld, 'held');
    $guard = $w->engine->status()['guard'];
    mb_assert_same(3, $guard['would_remove'], 'would remove');
    mb_assert_same(0, $guard['local'], 'local');
    $w->engine->confirmWithdraw();
    $deleted = $w->server->deletedIds();
    sort($deleted);
    mb_assert_same(array('11', '12', '13'), $deleted, 'withdrawn after confirmation');
});

mb_test('cleanup guard holds more than 10 removals that are more than half', function () {
    $w = mb_se();
    mb_se_threads($w, range(1, 5));
    mb_se_full($w);
    $w->server->seed(range(101, 112));
    $w->clock->now += 86400 + 1;
    $w->engine->cronTick(mb_se_deadline($w));
    mb_assert_same(array(), $w->server->deletedIds(), '12 of 17 held');
    mb_assert_same(12, $w->engine->status()['guard']['would_remove'], 'reported');

    $w = mb_se();
    mb_se_threads($w, range(1, 18));
    mb_se_full($w);
    $w->server->seed(range(101, 112));
    $w->clock->now += 86400 + 1;
    $w->engine->cronTick(mb_se_deadline($w));
    mb_assert_same(12, count($w->server->deletedIds()), '12 of 30 withdrawn');
    mb_assert_same(null, $w->engine->status()['guard'], 'no guard');
});

mb_test('a source that is not discuz is pushed to but never cleaned up automatically', function () {
    $w = mb_se();
    $w->server->kind = 'wordpress';
    mb_se_threads($w, array(11));
    $w->server->seed(array(500));
    mb_se_full($w);
    mb_assert(isset($w->server->posts['11']), 'pushed');
    mb_assert(isset($w->server->posts['500']), 'not withdrawn');
    $status = $w->engine->status();
    mb_assert_same('kind', $status['guard']['reason'], 'held because of the kind');
    mb_assert(strpos($status['source']['notice'], 'wordpress') !== false, 'kind notice: ' . $status['source']['notice']);
    $w->engine->confirmWithdraw();
    mb_assert(!isset($w->server->posts['500']), 'withdrawn after confirmation');
});

// ---------------------------------------------------------------- full sync

mb_test('fullSyncBatch pushes 20 per batch and withdraws in the last one', function () {
    $w = mb_se();
    mb_se_threads($w, range(1, 45));
    $w->forum->threads[44]['displayorder'] = -1;
    $w->server->seed(array(44, 999));
    $first = $w->engine->fullSyncBatch(null);
    mb_assert_same(false, $first['done'], 'not done');
    mb_assert_same(20, $first['result']->pushed, 'first batch');
    mb_assert_same(array_map('strval', array_merge(array(45), range(43, 25))), $w->server->pushedIds(), 'newest first');
    mb_assert_same(array(), $w->server->deletedIds(), 'no cleanup before the end');

    $calls = 1 + mb_se_full_from($w, $first['cursor']);
    mb_assert_same(44, count($w->server->pushedIds()), 'every eligible thread pushed once');
    foreach ($w->server->bodies('PUT /posts') as $body) {
        mb_assert(count($body['posts']) <= 20, 'at most 20 per request');
    }
    $deleted = $w->server->deletedIds();
    sort($deleted);
    mb_assert_same(array('44', '999'), $deleted, 'cleanup at the end');
    mb_assert($calls >= 3, 'several calls');
    $status = $w->engine->status();
    mb_assert($status['last_full_sync_at'] !== null, 'full sync recorded');
    mb_assert_same(44, $status['local']['count'], 'local count from the round');
});

function mb_se_full_from(stdClass $w, ?string $cursor): int
{
    for ($i = 1; $i <= 1000; $i++) {
        $batch = $w->engine->fullSyncBatch($cursor);
        if ($batch['done']) {
            return $i;
        }
        $cursor = $batch['cursor'];
    }
    throw new RuntimeException('full sync never finished');
}

mb_test('fullSyncBatch stops at once when the addresses differ', function () {
    $w = mb_se();
    $w->server->siteUrl = 'https://other.example.com';
    mb_se_threads($w, array(11));
    $batch = $w->engine->fullSyncBatch(null);
    mb_assert_same(true, $batch['done'], 'done');
    mb_assert($batch['result']->originBlocked, 'origin blocked');
    mb_assert(strpos((string) $batch['result']->error, 'https://other.example.com') !== false, 'both addresses in the message');
    mb_assert_same(array(), $w->server->writes(), 'nothing sent');
});

// ---------------------------------------------------------------- connect status and widget

mb_test('cron stores the connect status hourly', function () {
    $w = mb_se();
    $w->engine->cronTick(mb_se_deadline($w));
    $cached = $w->store->get('connect_status');
    mb_assert_same($w->clock->now, $cached['checked_at'], 'timestamp');
    mb_assert_same('p1', $cached['project']['id'], 'project');
    mb_assert_same('discuz', $cached['source']['kind'], 'source');
    mb_assert_same(true, $cached['entitlement']['active'], 'entitlement');
    mb_assert_same('pub_test', $cached['widget']['public_id'], 'widget');
    mb_assert(strpos(json_encode($cached), 'mbk_') === false, 'no key in the cache');
    $w->clock->now += 1800;
    $w->engine->cronTick(mb_se_deadline($w));
    mb_assert_same(1, $w->server->count('GET /status'), 'not again within the hour');
    $w->clock->now += 1801;
    $w->engine->cronTick(mb_se_deadline($w));
    mb_assert_same(2, $w->server->count('GET /status'), 'refreshed after an hour');
});

mb_test('widget is switched on once after the first full sync', function () {
    $w = mb_se(array('widgetEnabled' => true));
    mb_se_threads($w, array(11));
    $w->engine->cronTick(mb_se_deadline($w));
    mb_assert_same(0, $w->server->count('POST /widget/enable'), 'not before a full sync');
    mb_se_full($w);
    mb_assert_same(1, $w->server->count('POST /widget/enable'), 'enabled after the full sync');
    mb_assert_same(true, $w->store->get('connect_status')['widget']['available'], 'cache refreshed');

    // The owner switches it off in the console: it stays off.
    $w->server->widget = array('public_id' => 'pub_test', 'available' => false, 'reason' => 'disabled');
    $w->clock->now += 3700;
    $w->engine->cronTick(mb_se_deadline($w));
    mb_se_full($w);
    mb_assert_same(1, $w->server->count('POST /widget/enable'), 'never again');
});

mb_test('widget is left alone when the setting is off or it is already live', function () {
    $w = mb_se();
    mb_se_threads($w, array(11));
    mb_se_full($w);
    mb_assert_same(0, $w->server->count('POST /widget/enable'), 'setting off');

    $w = mb_se(array('widgetEnabled' => true));
    $w->server->widget = array('public_id' => 'pub_test', 'available' => true, 'reason' => 'ok');
    mb_se_threads($w, array(11));
    mb_se_full($w);
    mb_assert_same(0, $w->server->count('POST /widget/enable'), 'already live');
});

mb_test('widget enable waits when the project is not ready', function () {
    $w = mb_se(array('widgetEnabled' => true));
    mb_se_threads($w, array(11));
    $w->server->fail('POST /widget/enable', 409, 'WIDGET_NOT_READY');
    mb_se_full($w);
    mb_assert_same(1, $w->server->count('POST /widget/enable'), 'tried');
    $w->clock->now += 3700;
    $w->engine->cronTick(mb_se_deadline($w));
    mb_assert_same(2, $w->server->count('POST /widget/enable'), 'tried again later');
    mb_assert_same(true, $w->server->widget['available'], 'on');
});

// ---------------------------------------------------------------- status

mb_test('status reports counts, last sync and the origin check', function () {
    $w = mb_se();
    mb_se_threads($w, array(11, 12, 13), 2);
    $before = $w->engine->status();
    mb_assert_same(3, $before['local']['count'], 'estimate before the first round');
    mb_assert_same(false, $before['local']['exact'], 'marked as estimate');
    mb_se_full($w);
    $status = $w->engine->status();
    mb_assert_same(3, $status['local']['count'], 'local');
    mb_assert_same(true, $status['local']['exact'], 'exact after a round');
    mb_assert_same(3, $status['remote']['documents'], 'MomoBird documents');
    mb_assert_same(3, $status['remote']['ready'], 'MomoBird searchable');
    mb_assert($status['last_sync']['at'] !== null, 'last sync');
    mb_assert($status['scan']['round_finished_at'] !== null, 'round time');
    mb_assert_same(true, $status['origin']['ok'], 'origin ok');
    mb_assert_same(array(), $status['failures'], 'no failures');
    mb_assert_same(null, $status['guard'], 'no guard');
    mb_assert_same(0, $status['queue'], 'queue');
    mb_assert(strpos(json_encode($status, JSON_UNESCAPED_UNICODE), 'mbk_') === false, 'no key in the status');
});

mb_test('scan pauses while the queue is long', function () {
    $w = mb_se();
    mb_se_threads($w, range(1, 2500));
    $w->engine->cronTick(mb_se_deadline($w));
    $scans = array_values(array_filter($w->forum->reads, function ($r) {
        return strpos($r, 'threadsBelow:') === 0;
    }));
    mb_assert_same(array('threadsBelow:-:1000'), $scans, 'one read of 1000 new threads, then wait for the queue');
    mb_assert_same(1501, $w->engine->status()['scan']['cursor'], 'cursor kept');
    mb_assert_same(1000, count($w->server->posts), 'the queued threads were pushed in the same tick');
    $w->clock->now += 300;
    $w->engine->cronTick(mb_se_deadline($w));
    mb_assert_same(2000, count($w->server->posts), 'next segment');
});

mb_test('orphans wait while a reconcile is still listing', function () {
    $w = mb_se();
    mb_se_threads($w, array(11));
    mb_se_sync($w, array(11));
    $w->server->seed(array(500));
    $w->store->markRemote(array(500), $w->clock->now);
    $w->store->set('reconcile', array('phase' => 'listing', 'cursor' => null, 'pages' => 0, 'remote' => 0, 'local' => 1));
    mb_se_sync($w, array(11));
    mb_assert_same(array(), $w->server->deletedIds(), 'not withdrawn from a partial listing');
    mb_assert_same('orphan', $w->store->state(500), 'still waiting');
});

// ---------------------------------------------------------------- fix round 1

mb_test('a hidden thread in a deselected forum is withdrawn at once, outside the guard', function () {
    $w = mb_se();
    mb_se_threads($w, range(1, 15), 2);
    mb_se_threads($w, array(21, 22), 3);
    mb_se_full($w);
    $w->engine->cronTick(mb_se_deadline($w));
    mb_se_configure($w, array('forumIds' => array(3)));
    $w->clock->now += 300;
    $w->engine->cronTick(mb_se_deadline($w));
    mb_assert_same('guard', $w->engine->status()['guard']['reason'], 'guard holds the deselection');
    $w->forum->threads[7]['displayorder'] = -1;
    $w->forum->posts[8]['message'] = '[password]x[/password]';
    mb_se_reset_requests($w);
    mb_se_sync($w, array(7, 8));
    $deleted = $w->server->deletedIds();
    sort($deleted);
    mb_assert_same(array('7', '8'), $deleted, 'recycled and password threads withdrawn');
    mb_assert_same('orphan', $w->store->state(6), 'merely deselected still waits');

    // The daily scan finds the same thing without a hook.
    $w->forum->threads[6]['readperm'] = 200;
    $w->clock->now += 86400 + 1;
    mb_se_reset_requests($w);
    $w->engine->cronTick(mb_se_deadline($w));
    mb_assert(in_array('6', $w->server->deletedIds(), true), 'scan withdraws the readperm thread');
});

mb_test('a push with an unknown outcome is withdrawn when the thread is hidden', function () {
    $w = mb_se();
    mb_se_threads($w, array(50));
    $server = $w->server;
    $w->engine->refreshStatus();
    $w->server->transport->push(function ($method, $url, $headers, $body) use ($server) {
        $server->handle($method, $url, $body);
        return array('status' => 0, 'body' => '', 'error' => 'timed out');
    });
    mb_se_sync($w, array(50));
    mb_assert_same(1, $w->store->rows[50]['remote'], 'MomoBird may hold it');
    $w->forum->threads[50]['displayorder'] = -1;
    mb_se_sync($w, array(50));
    mb_assert_same(array('50'), $w->server->deletedIds(), 'delete sent');
    mb_assert(!isset($w->server->posts['50']), 'gone');
});

mb_test('an empty forum tree aborts the tick without changing anything', function () {
    $w = mb_se();
    mb_se_threads($w, range(1, 30));
    mb_se_full($w);
    $w->engine->cronTick(mb_se_deadline($w));
    $fingerprint = $w->store->get('fingerprint');
    $saved = $w->forum->forums;
    $w->forum->forums = array();
    $w->clock->now += 300;
    mb_se_reset_requests($w);
    $result = $w->engine->cronTick(mb_se_deadline($w));
    mb_assert_same(array(), $w->server->writes(), 'nothing withdrawn');
    mb_assert_same($fingerprint, $w->store->get('fingerprint'), 'fingerprint kept');
    mb_assert_same(null, $w->store->get('requeue'), 'nothing requeued');
    mb_assert(strpos((string) $result->error, '读取论坛数据失败') !== false, 'run error: ' . $result->error);
    $readError = $w->engine->status()['read_error'];
    mb_assert(strpos($readError['message'], '读取论坛数据失败') !== false, 'status shows it');
    mb_assert_same($w->clock->now, $readError['at'], 'with the time');

    $w->forum->forums = $saved;
    $w->clock->now += 300;
    $w->engine->cronTick(mb_se_deadline($w));
    mb_assert_same(array(), $w->server->writes(), 'good read afterwards: nothing to do');
    mb_assert_same(30, count($w->server->posts), 'knowledge base intact');
    mb_assert_same(null, $w->engine->status()['read_error'], 'cleared');
});

mb_test('a missing guest group or forum settings abort the run', function () {
    $w = mb_se();
    mb_se_threads($w, array(11));
    mb_se_sync($w, array(11));
    $w->forum->threads[11]['displayorder'] = -1;
    $w->forum->guest = array();
    $result = mb_se_sync($w, array(11));
    mb_assert(strpos((string) $result->error, '读取论坛数据失败') !== false, 'guest group');
    mb_assert_same(array(11), $w->store->queued(10), 'queue kept');
    $w->forum->guest = array('groupid' => 7, 'readaccess' => 1, 'allowvisit' => 1);
    $w->forum->setting = array();
    $result = mb_se_sync($w, array(11));
    mb_assert(strpos((string) $result->error, '读取论坛数据失败') !== false, 'settings');
    mb_assert_same(array(), $w->server->deletedIds(), 'nothing withdrawn');
});

mb_test('a forum read exception mid-drain keeps the queue and changes nothing', function () {
    $w = mb_se();
    mb_se_threads($w, array(11, 12));
    mb_se_sync($w, array(11, 12));
    $w->forum->threads[11]['displayorder'] = -1;
    $w->forum->onRead = function ($what) {
        if (strpos($what, 'threads:') === 0) {
            throw new RuntimeException('MySQL server has gone away');
        }
    };
    $result = mb_se_sync($w, array(11, 12));
    mb_assert(strpos((string) $result->error, '读取论坛数据失败') !== false, 'reported');
    mb_assert_same(array(12, 11), $w->store->queued(10), 'queue restored');
    mb_assert_same('synced', $w->store->state(11), 'row untouched');
    mb_assert_same(array(), $w->server->deletedIds(), 'nothing withdrawn');

    // First posts that all vanish at once are a read failure, not a withdrawal.
    $w->forum->onRead = null;
    $w->forum->posts = array();
    mb_se_sync($w, array(12, 11));
    mb_assert_same(array(), $w->server->deletedIds(), 'no withdrawal on missing first posts');
    mb_assert_same(array(12, 11), $w->store->queued(10), 'still queued');
});

mb_test('runs take a lock and skip or report busy when it is held', function () {
    $w = mb_se();
    mb_se_threads($w, array(11));
    $w->engine->enqueue(array(11), 'hook');
    $w->store->locks['sync'] = true;
    $result = $w->engine->drain(mb_se_deadline($w));
    mb_assert_same(array(), $w->server->routes(), 'drain does nothing');
    mb_assert_same(array(11), $w->store->queued(10), 'queue kept for cron');
    mb_assert_same(array(), $w->forum->reads, 'not even a forum read');
    $w->engine->cronTick(mb_se_deadline($w));
    mb_assert_same(array(), $w->server->routes(), 'cron does nothing');
    foreach (array('retryFailed', 'confirmWithdraw') as $method) {
        $r = $w->engine->$method();
        mb_assert_same('正在同步，请稍后再试', $r->error, $method . ' reports busy');
    }
    $batch = $w->engine->fullSyncBatch(null);
    mb_assert_same('正在同步，请稍后再试', $batch['result']->error, 'full sync reports busy');
    mb_assert_same(false, $batch['done'], 'the page may try again');
    mb_assert_same(array(), $w->server->writes(), 'nothing sent');

    unset($w->store->locks['sync']);
    $w->engine->drain(mb_se_deadline($w));
    mb_assert_same('synced', $w->store->state(11), 'runs once free');
    mb_assert_same(array(), $w->store->locks, 'lock released');
    mb_assert($w->store->lockTtls['sync'] >= 20 + $w->settings->timeout(), 'ttl covers the deadline and a request');
});

mb_test('fingerprint-requeued threads go first and withdrawals go 100 per request', function () {
    $w = mb_se();
    mb_se_threads($w, range(1, 150), 2);
    mb_se_full($w);
    $w->engine->cronTick(mb_se_deadline($w));
    mb_se_threads($w, range(1001, 1150), 3);
    $w->engine->enqueue(range(1001, 1150), 'hook');
    $w->forum->fields[2]['viewperm'] = '1';
    $w->clock->now += 300;
    mb_se_reset_requests($w);
    $w->engine->cronTick(mb_se_deadline($w));
    $writes = $w->server->writes();
    mb_assert_same('POST /posts/delete', $writes[0], 'withdrawals before the push backlog');
    mb_assert_same(2, $w->server->count('POST /posts/delete'), '150 ids in two requests');
    mb_assert_same(100, count($w->server->bodies('POST /posts/delete')[0]['external_ids']), '100 in the first');
    mb_assert_same(150, count($w->server->posts), 'forum 3 pushed afterwards');
});
