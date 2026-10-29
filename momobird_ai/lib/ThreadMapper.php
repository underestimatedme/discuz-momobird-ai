<?php
/**
 * Maps a Discuz! thread and its first post to the connect API push payload.
 */
final class MomoBird_ThreadMapper
{
    /**
     * @param array  $thread    forum_thread row (tid, subject, dateline)
     * @param array  $firstPost first forum_post row (message, X5 content, dateline, optional lastedit)
     * @param string $category  from MomoBird_Categories ('' = none)
     * @return array|null null when the post has no text a guest could read (skip or withdraw it)
     */
    public static function payload(array $thread, array $firstPost, string $category, string $url): ?array
    {
        $content = self::body($firstPost);
        if ($content === '') {
            return null;
        }

        $payload = array(
            'external_id' => (string) (int) ($thread['tid'] ?? 0),
            'title' => self::title((string) ($thread['subject'] ?? '')),
            'content' => $content,
            'url' => $url,
            'modified_at' => gmdate('Y-m-d\TH:i:s\Z', self::modifiedAt($thread, $firstPost)),
        );

        $category = MomoBird_Categories::truncate($category, MomoBird_Categories::MAX_LENGTH);
        if ($category !== '') {
            $payload['category'] = $category;
        }
        return $payload;
    }

    /**
     * Digest of what the knowledge base stores: title, link, text and category.
     * The edit time is left out on purpose, so touching a post does not re-push it.
     */
    public static function hash(array $payload): string
    {
        $parts = array(
            (string) ($payload['title'] ?? ''),
            (string) ($payload['url'] ?? ''),
            (string) ($payload['content'] ?? ''),
            (string) ($payload['category'] ?? ''),
        );
        $json = json_encode($parts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        return hash('sha256', $json === false ? serialize($parts) : $json);
    }

    /** X5 editor JSON when present and readable, otherwise the BBCode message. */
    private static function body(array $firstPost): string
    {
        $json = $firstPost['content'] ?? null;
        if (is_string($json) && trim($json) !== '') {
            $text = MomoBird_ContentText::fromX5Json($json);
            if ($text !== null) {
                // Valid JSON that holds no text (only images) is an empty post, not a reason
                // to fall back to the message, which is just a summary of the same content.
                return $text;
            }
        }
        return MomoBird_ContentText::fromBbcode((string) ($firstPost['message'] ?? ''));
    }

    private static function title(string $subject): string
    {
        if ($subject !== '' && preg_match('//u', $subject) !== 1) {
            $subject = (string) @iconv('UTF-8', 'UTF-8//IGNORE', $subject);
        }
        // Discuz! stores subjects HTML-escaped: &amp; is a literal ampersand.
        $title = html_entity_decode(strip_tags($subject), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string) preg_replace('/[\s\x{00A0}\x{3000}]+/u', ' ', $title));
    }

    private static function modifiedAt(array $thread, array $firstPost): int
    {
        $edited = self::time($firstPost, 'lastedit');
        $postTime = $edited > 0 ? $edited : self::time($firstPost, 'dateline');
        return max($postTime, self::time($thread, 'dateline'));
    }

    private static function time(array $row, string $key): int
    {
        $value = $row[$key] ?? 0;
        return is_numeric($value) && $value > 0 ? (int) $value : 0;
    }
}
