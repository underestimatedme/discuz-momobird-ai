<?php
/**
 * Decides whether an anonymous visitor can read a forum or thread, from raw
 * Discuz! table rows (the adapter reads them, nothing here touches the database).
 *
 * The knowledge base answers anyone on the internet, so every check fails
 * closed: a column that is missing or has a value we do not understand counts
 * as "guests cannot read this". Each method returns null when the content may
 * be synced, otherwise a reason code.
 */
final class MomoBird_Visibility
{
    /** The id of Discuz!'s built-in guest user group. */
    const DEFAULT_GUEST_GROUP_ID = 7;

    /**
     * Whole-site gate: forum switched off, or the guest group may not visit.
     *
     * @return string|null site_closed|guest_denied
     */
    public static function site(array $guestGroup, array $setting): ?string
    {
        // bbclosed is 0 when the forum is open; anything else (or nothing) is closed.
        if (!array_key_exists('bbclosed', $setting) || self::number($setting['bbclosed']) !== 0) {
            return 'site_closed';
        }
        if (!array_key_exists('allowvisit', $guestGroup) || self::number($guestGroup['allowvisit']) !== 1) {
            return 'guest_denied';
        }
        return null;
    }

    /**
     * @param array      $forum        forum_forum row (fid, fup, type, name, status)
     * @param array      $forumfield   forum_forumfield row (viewperm, password, formulaperm, price, redirect)
     * @param array      $guestGroup   guest user group (groupid, readaccess)
     * @param array|null $selectedFids null = every guest-visible forum, else the fids chosen in the plugin
     * @return string|null not_forum|hidden|group|password|formula|priced|redirect|viewperm|not_selected
     */
    public static function forum(array $forum, array $forumfield, array $guestGroup, ?array $selectedFids): ?string
    {
        $type = isset($forum['type']) ? (string) $forum['type'] : '';
        if ($type !== 'forum' && $type !== 'sub') {
            return 'not_forum';
        }

        // status 1 = displayed. 0 is reachable by URL but the owner did not publish it,
        // 3 is a user-created group forum with its own membership rules.
        $status = array_key_exists('status', $forum) ? self::number($forum['status']) : null;
        if ($status === 3) {
            return 'group';
        }
        if ($status !== 1) {
            return 'hidden';
        }

        if (self::filled($forumfield, 'password')) {
            return 'password';
        }
        if (self::formulaSet($forumfield)) {
            return 'formula';
        }
        if (self::priceSet($forumfield)) {
            return 'priced';
        }
        if (self::filled($forumfield, 'redirect')) {
            return 'redirect';
        }
        if (!self::guestMayView($forumfield, $guestGroup)) {
            return 'viewperm';
        }

        if ($selectedFids !== null) {
            $fid = isset($forum['fid']) ? self::number($forum['fid']) : null;
            if ($fid === null || !in_array($fid, array_map('intval', $selectedFids), true)) {
                return 'not_selected';
            }
        }
        return null;
    }

    /**
     * @param array $thread    forum_thread row
     * @param array $firstPost the thread's first forum_post row (message, and X5's content)
     * @return string|null displayorder|moved|readperm|price|isgroup|post_invisible|post_banned|password_post|missing_post
     */
    public static function thread(array $thread, array $firstPost, array $guestGroup): ?string
    {
        // 0 normal, 1-3 sticky. Below 0: recycle bin, moderation queue, ignored, draft.
        $displayorder = self::column($thread, 'displayorder');
        if ($displayorder === null || $displayorder < 0) {
            return 'displayorder';
        }

        // 0 open, 1 closed (still readable), above 1 = "moved" stub pointing at another tid.
        $closed = self::column($thread, 'closed');
        if ($closed === null || $closed > 1) {
            return 'moved';
        }

        $readperm = self::column($thread, 'readperm');
        $readaccess = self::column($guestGroup, 'readaccess');
        if ($readperm === null || $readaccess === null || $readperm > $readaccess) {
            return 'readperm';
        }

        // Paid threads (positive) and reward threads (stored negative) are not free to read.
        if (self::column($thread, 'price') !== 0) {
            return 'price';
        }
        if (self::column($thread, 'isgroup') !== 0) {
            return 'isgroup';
        }

        if (!$firstPost || self::column($firstPost, 'first') !== 1) {
            return 'missing_post';
        }
        if (isset($thread['tid'], $firstPost['tid']) && self::number($thread['tid']) !== self::number($firstPost['tid'])) {
            return 'missing_post';
        }

        // invisible: 0 visible, -1 recycle bin, -2 awaiting audit, -3 draft, -5 post recycled.
        if (self::column($firstPost, 'invisible') !== 0) {
            return 'post_invisible';
        }
        // status bit 0 = banned (屏蔽); bit 1 (warned) is still readable.
        $status = self::column($firstPost, 'status');
        if ($status === null || ($status & 1) !== 0) {
            return 'post_banned';
        }

        // A [password] post is blank for guests; X5 may carry the tag in either column.
        foreach (array('message', 'content') as $key) {
            if (isset($firstPost[$key]) && is_string($firstPost[$key]) && preg_match(MomoBird_ContentText::PASSWORD_PATTERN, $firstPost[$key]) !== 0) {
                return 'password_post';
            }
        }
        return null;
    }

    /** Integer value of a database column, or null when it is absent or not numeric. */
    private static function column(array $row, string $key): ?int
    {
        return array_key_exists($key, $row) ? self::number($row[$key]) : null;
    }

    private static function number($value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_bool($value) || $value === null) {
            return null;
        }
        if (is_string($value) && preg_match('/^\s*[+-]?\d+\s*$/', $value) === 1) {
            return (int) $value;
        }
        if (is_float($value) && $value == floor($value)) {
            return (int) $value;
        }
        return null;
    }

    /** True when a text column holds anything at all (a missing column counts as filled). */
    private static function filled(array $row, string $key): bool
    {
        if (!array_key_exists($key, $row)) {
            return true;
        }
        return $row[$key] !== null && (string) $row[$key] !== '';
    }

    private static function formulaSet(array $forumfield): bool
    {
        if (!array_key_exists('formulaperm', $forumfield)) {
            return true;
        }
        $value = trim((string) $forumfield['formulaperm']);
        return $value !== '' && $value !== 'a:0:{}';
    }

    private static function priceSet(array $forumfield): bool
    {
        if (!array_key_exists('price', $forumfield)) {
            return true;
        }
        $price = $forumfield['price'];
        if ($price === null || (string) $price === '') {
            return false;
        }
        if (!is_numeric($price)) {
            return true;
        }
        return (float) $price != 0;
    }

    /**
     * Discuz! rule (forum_viewthread.php): an empty viewperm falls back to the user
     * group's readaccess; a filled one must list the guest group.
     */
    private static function guestMayView(array $forumfield, array $guestGroup): bool
    {
        if (!array_key_exists('viewperm', $forumfield)) {
            return false;
        }
        $viewperm = trim((string) $forumfield['viewperm']);
        if ($viewperm === '') {
            $readaccess = self::column($guestGroup, 'readaccess');
            return $readaccess !== null && $readaccess > 0;
        }

        $groupId = self::column($guestGroup, 'groupid');
        if ($groupId === null) {
            $groupId = self::DEFAULT_GUEST_GROUP_ID;
        }
        foreach (preg_split('/\s+/', $viewperm) as $id) {
            if ($id !== '' && self::number($id) === $groupId) {
                return true;
            }
        }
        return false;
    }
}
