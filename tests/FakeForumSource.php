<?php

/** In-memory forum: forums, threads and their first posts as plain rows. */
class MomoBird_FakeForumSource implements MomoBird_ForumSource
{
    public $setting = array('bbclosed' => 0, 'bannedmessages' => 1);
    public $guest = array('groupid' => 7, 'readaccess' => 1, 'allowvisit' => 1);
    /** @var array<int, array> fid => forum row */
    public $forums = array();
    /** @var array<int, array> fid => forumfield row */
    public $fields = array();
    /** @var array<int, array> tid => thread row */
    public $threads = array();
    /** @var array<int, array> tid => first post row */
    public $posts = array();
    public $rewrite = null;
    /** @var callable|null called on every table read (tests advance the clock with it) */
    public $onRead;
    /** @var string[] log of reads: "threadsBelow:<below>:<limit>", "threads:<n>", "posts:<n>" */
    public $reads = array();

    public function addGroup(int $fid, string $name): self
    {
        $this->forums[$fid] = array('fid' => $fid, 'fup' => 0, 'type' => 'group', 'name' => $name, 'status' => 1);
        return $this;
    }

    public function addForum(int $fid, string $name, int $fup = 1, string $type = 'forum', array $field = array()): self
    {
        $this->forums[$fid] = array('fid' => $fid, 'fup' => $fup, 'type' => $type, 'name' => $name, 'status' => 1);
        $this->fields[$fid] = array_merge(array('fid' => $fid, 'viewperm' => '', 'password' => '', 'formulaperm' => '', 'price' => 0, 'redirect' => ''), $field);
        return $this;
    }

    public function addThread(int $tid, int $fid, ?string $message = null, array $thread = array(), array $post = array()): self
    {
        $this->threads[$tid] = array_merge(array(
            'tid' => $tid, 'fid' => $fid, 'subject' => 'Thread ' . $tid, 'dateline' => 1700000000 + $tid,
            'displayorder' => 0, 'closed' => 0, 'readperm' => 0, 'price' => 0, 'isgroup' => 0, 'digest' => 0,
        ), $thread);
        $this->posts[$tid] = array_merge(array(
            'pid' => $tid * 10, 'tid' => $tid, 'first' => 1, 'invisible' => 0, 'status' => 0,
            'message' => $message === null ? 'Body of thread ' . $tid : $message, 'dateline' => 1700000000 + $tid,
            'authorid' => 5, 'username' => 'alice', 'groupid' => 10, 'memberstatus' => 0,
        ), $post);
        return $this;
    }

    public function setting(): array
    {
        $this->read('setting');
        return $this->setting;
    }

    public function guestGroup(): array
    {
        return $this->guest;
    }

    public function forums(): array
    {
        return array_values($this->forums);
    }

    public function forumFields(): array
    {
        return $this->fields;
    }

    public function rewriteRule(): ?string
    {
        return $this->rewrite;
    }

    public function threads(array $tids): array
    {
        $this->read('threads:' . count($tids));
        $out = array();
        foreach ($tids as $tid) {
            if (isset($this->threads[(int) $tid])) {
                $out[(int) $tid] = $this->threads[(int) $tid];
            }
        }
        return $out;
    }

    public function firstPosts(array $threads): array
    {
        $this->read('posts:' . count($threads));
        $out = array();
        foreach ($threads as $tid => $thread) {
            if (isset($this->posts[(int) $tid])) {
                $out[(int) $tid] = $this->posts[(int) $tid];
            }
        }
        return $out;
    }

    public function threadsBelow(?int $belowTid, int $limit): array
    {
        $this->read('threadsBelow:' . ($belowTid === null ? '-' : $belowTid) . ':' . $limit);
        $tids = array_keys($this->threads);
        rsort($tids);
        $out = array();
        foreach ($tids as $tid) {
            if ($belowTid !== null && $tid >= $belowTid) {
                continue;
            }
            $out[] = $this->threads[$tid];
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    public function threadIdsInForums(array $fids, ?int $belowTid, int $limit): array
    {
        $this->read('threadIds');
        $fids = array_map('intval', $fids);
        $tids = array();
        foreach ($this->threads as $tid => $row) {
            if (in_array((int) $row['fid'], $fids, true) && ($belowTid === null || $tid < $belowTid)) {
                $tids[] = $tid;
            }
        }
        rsort($tids);
        return array_slice($tids, 0, $limit);
    }

    public function countCandidates(array $fids, int $readaccess): int
    {
        $fids = array_map('intval', $fids);
        $n = 0;
        foreach ($this->threads as $row) {
            if (in_array((int) $row['fid'], $fids, true) && $row['displayorder'] >= 0 && $row['closed'] <= 1
                && $row['readperm'] <= $readaccess && $row['price'] == 0 && $row['isgroup'] == 0) {
                $n++;
            }
        }
        return $n;
    }

    private function read(string $what): void
    {
        $this->reads[] = $what;
        if ($this->onRead !== null) {
            call_user_func($this->onRead, $what);
        }
    }
}
