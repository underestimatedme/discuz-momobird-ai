<?php
/**
 * Read-only access to the Discuz! tables the engine needs. Rows are plain
 * associative arrays with at least the columns MomoBird_Visibility names in
 * its *_COLUMNS constants; missing columns fail closed there.
 *
 * Thread rows: THREAD_COLUMNS plus fid, subject, dateline (and posttableid if
 * the adapter needs it to find the first post).
 * First-post rows: POST_COLUMNS plus dateline and, on X5, content.
 */
interface MomoBird_ForumSource
{
    /** $_G['setting'] values: Visibility::SETTING_KEYS. */
    public function setting(): array;

    /** The guest group: Visibility::GUEST_GROUP_COLUMNS. */
    public function guestGroup(): array;

    /** Every forum_forum row (group, forum and sub): Visibility::FORUM_COLUMNS. */
    public function forums(): array;

    /** @return array<int, array> fid => forum_forumfield row (Visibility::FORUMFIELD_COLUMNS) */
    public function forumFields(): array;

    /** The forum_viewthread rewrite rule when that rewrite is switched on, else null. */
    public function rewriteRule(): ?string;

    /** @return array<int, array> tid => thread row; tids that do not exist are absent */
    public function threads(array $tids): array;

    /**
     * @param array<int, array> $threads tid => thread row
     * @return array<int, array> tid => first post row; threads without one are absent
     */
    public function firstPosts(array $threads): array;

    /** @return array[] at most $limit thread rows with tid < $belowTid (null = no bound), highest tid first */
    public function threadsBelow(?int $belowTid, int $limit): array;

    /** @return int[] tids of threads in these forums with tid < $belowTid (null = no bound), highest first */
    public function threadIdsInForums(array $fids, ?int $belowTid, int $limit): array;

    /**
     * Estimate for the admin page before the first full round: threads in these forums that pass
     * the thread-level checks (displayorder >= 0, closed <= 1, readperm <= $readaccess, price = 0,
     * isgroup = 0). Post-level checks are not counted.
     */
    public function countCandidates(array $fids, int $readaccess): int;
}
