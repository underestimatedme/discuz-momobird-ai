<?php
/**
 * What readers see: the assistant loader in the page footer and the podcast
 * placeholder under the first post of a synced thread. Markup follows the
 * WordPress plugin (class-widget-state.php, class-podcast-player.php).
 *
 * Page views read the plugin's cache (MomoBird_WidgetState) and the forum
 * tables; they never call MomoBird. The output carries only the project's
 * public id, the source id, the tid and the Base URL, never the key. Every
 * failure prints nothing (or drops the category): a page must never break
 * because of the plugin.
 */

if (!defined('IN_DISCUZ')) {
    exit('Access Denied');
}

final class MomoBird_FrontEnd
{
    const PODCAST_URL = 'https://momobird.atlaspaces.com/widget/podcast.v1.js';

    private $settings;
    private $widget;
    private $source;
    /** @var array|null|false per request: false = not read, null = read failed */
    private $scope = false;

    public function __construct(MomoBird_Settings $settings, MomoBird_WidgetState $widget, MomoBird_ForumSource $source)
    {
        $this->settings = $settings;
        $this->widget = $widget;
        $this->source = $source;
    }

    /**
     * global_footer / global_footer_mobile. On forum and thread pages of a synced forum the
     * loader gets data-category (the same MomoBird_Categories value the push sends), so the
     * assistant answers from that forum only.
     *
     * @param string $script $_G['basescript'] (forum, portal, home, ...)
     * @param string $module CURMODULE (forumdisplay, viewthread, ...)
     * @param int    $fid    $_G['fid']
     */
    public function footer(string $script, string $module, int $fid, int $now): string
    {
        if (!$this->settings->widgetEnabled()) {
            return '';
        }
        $publicId = $this->widget->publicId($now);
        if ($publicId === null) {
            return '';
        }

        $html = '<script defer src="' . MomoBird_WidgetState::LOADER_URL . '" data-project="' . self::attr($publicId) . '"';
        if ($this->settings->widgetPosition() === 'left') {
            $html .= ' data-position="left"';
        }
        if ($script === 'forum' && ($module === 'forumdisplay' || $module === 'viewthread') && $fid > 0) {
            $category = $this->category($fid);
            if ($category !== null && $category !== '') {
                $html .= ' data-category="' . self::attr($category) . '"';
            }
        }
        return $html . '></script>';
    }

    /**
     * viewthread_postbottom(_mobile)_output: the hook's slot (position on the page) of the
     * thread's first post => placeholder and script. Other posts get nothing.
     *
     * @param array $postlist viewthread's $postlist, in page order
     * @return array<int, string>
     */
    public function podcastSlots(array $postlist): array
    {
        $index = 0;
        foreach ($postlist as $post) {
            if (is_array($post) && self::isFirst($post)) {
                $html = $this->podcast($post);
                return $html === '' ? array() : array($index => $html);
            }
            $index++;
        }
        return array();
    }

    /** Placeholder and podcast.v1.js for the first post of a synced thread, else ''. */
    public function podcast(array $post): string
    {
        if (!$this->settings->podcastEnabled() || !self::isFirst($post)) {
            return '';
        }
        $tid = isset($post['tid']) && is_numeric($post['tid']) ? (int) $post['tid'] : 0;
        $sourceId = $this->widget->sourceId();
        if ($tid < 1 || $sourceId === null || !$this->threadSynced($tid)) {
            return '';
        }
        return '<div class="momobird-podcast" data-source="' . $sourceId . '" data-post="' . $tid
            . '" data-api="' . self::attr(rtrim($this->settings->baseUrl(), '/')) . '"'
            . ($this->settings->podcastTranscript() ? ' data-transcript="1"' : '') . '></div>'
            . '<script defer src="' . self::PODCAST_URL . '"></script>';
    }

    /** Category of a synced forum, or null when the forum is outside the sync. */
    private function category(int $fid): ?string
    {
        $scope = $this->scope();
        if ($scope === null || !$this->forumSynced($scope, $fid)) {
            return null;
        }
        return isset($scope['categories'][$fid]) ? $scope['categories'][$fid] : null;
    }

    /** The same guest-visibility checks the engine runs before it pushes a thread. */
    private function threadSynced(int $tid): bool
    {
        $scope = $this->scope();
        if ($scope === null) {
            return false;
        }
        try {
            $threads = $this->source->threads(array($tid));
            if (!isset($threads[$tid]) || !is_array($threads[$tid])) {
                return false;
            }
            $thread = $threads[$tid];
            $fid = isset($thread['fid']) && is_numeric($thread['fid']) ? (int) $thread['fid'] : 0;
            if (!$this->forumSynced($scope, $fid)) {
                return false;
            }
            $posts = $this->source->firstPosts(array($tid => $thread));
        } catch (Throwable $e) {
            return false;
        }
        $post = isset($posts[$tid]) && is_array($posts[$tid]) ? $posts[$tid] : array();
        return MomoBird_Visibility::thread($thread, $post, $scope['guest'], $scope['setting']) === null;
    }

    private function forumSynced(array $scope, int $fid): bool
    {
        if ($scope['site'] !== null || !isset($scope['forums'][$fid])) {
            return false;
        }
        $field = isset($scope['fields'][$fid]) && is_array($scope['fields'][$fid]) ? $scope['fields'][$fid] : array();
        return MomoBird_Visibility::forum($scope['forums'][$fid], $field, $scope['guest'], $this->settings->forumIds()) === null;
    }

    /** Forum settings, guest group, forum tree and categories, read once per request. */
    private function scope(): ?array
    {
        if ($this->scope !== false) {
            return $this->scope;
        }
        $this->scope = null;
        try {
            $setting = $this->source->setting();
            $guest = $this->source->guestGroup();
            $forums = array();
            foreach ($this->source->forums() as $row) {
                if (is_array($row) && isset($row['fid'])) {
                    $forums[(int) $row['fid']] = $row;
                }
            }
            $fields = $this->source->forumFields();
        } catch (Throwable $e) {
            return null;
        }
        if (!is_array($setting) || !$setting || !is_array($guest) || !$guest || !$forums) {
            return null;
        }
        $this->scope = array(
            'site' => MomoBird_Visibility::site($guest, $setting),
            'setting' => $setting,
            'guest' => $guest,
            'forums' => $forums,
            'fields' => is_array($fields) ? $fields : array(),
            'categories' => MomoBird_Categories::build(array_values($forums)),
        );
        return $this->scope;
    }

    private static function isFirst(array $post): bool
    {
        return isset($post['first']) && is_numeric($post['first']) && (int) $post['first'] === 1;
    }

    private static function attr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
