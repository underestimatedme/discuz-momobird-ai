<?php
/**
 * Absolute address of a thread, the way Discuz! prints it in rel=canonical.
 */
final class MomoBird_ThreadUrl
{
    /**
     * @param string      $forumUrl    forum address from the settings
     * @param string|null $rewriteRule $_G['setting']['rewriterule']['forum_viewthread'] when that rewrite is on, else null
     */
    public static function build(string $forumUrl, int $tid, ?string $rewriteRule): string
    {
        $base = rtrim(trim($forumUrl), '/') . '/';

        if ($rewriteRule === null || trim($rewriteRule) === '') {
            return $base . 'forum.php?mod=viewthread&tid=' . $tid;
        }

        $path = preg_replace_callback('/\{(\w+)\}/', function ($match) use ($tid) {
            switch ($match[1]) {
                case 'tid':
                    return (string) $tid;
                case 'page':
                case 'prevpage':
                    return '1';
                default:
                    return '';
            }
        }, trim($rewriteRule));

        return $base . ltrim((string) $path, '/');
    }
}
