<?php
/**
 * Wiring inside Discuz!: class autoloading, the UTF-8 gate, settings from the
 * plugin's kv table, and the engine, front end and hook entry points.
 *
 * Settings live in kv `settings` (MomoBird_Settings::toArray(), no key) and the
 * connection key alone in kv `secret`; neither is a Discuz! plugin variable, so
 * no generated page ever echoes the key.
 *
 * Hosts other than localhost that may use http:// for the Base URL (the mock
 * connect server in integration tests) come from config_global.php only:
 *   $_config['momobird_ai']['http_hosts'] = array('mock-connect');
 * For those hosts Discuz!'s private-address guard is also lifted.
 */

if (!defined('IN_DISCUZ')) {
    exit('Access Denied');
}

// Prepended: Discuz!'s own autoloader (core::autoload) treats every unknown class as a lost
// system file and stops the page, so ours must run first.
spl_autoload_register(function ($class) {
    if (strpos($class, 'MomoBird_') !== 0) {
        return;
    }
    $file = __DIR__ . '/' . substr($class, 9) . '.php';
    if (preg_match('/^[A-Za-z]+$/', substr($class, 9)) === 1 && is_file($file)) {
        require_once $file;
    }
}, true, true);

final class MomoBird_Bootstrap
{
    const SETTINGS_KEY = MomoBird_Settings::KV_SETTINGS;
    const SECRET_KEY = MomoBird_Settings::KV_SECRET;
    /** Seconds a request may spend syncing after its response (hook drain, cron tick). */
    const RUN_SECONDS = 20;

    private static $store;
    private static $source;
    private static $settings;
    private static $frontEnd;
    /** 'drain' or 'cron' once a run after the response is scheduled */
    private static $pending = null;

    /** The plugin only works on UTF-8 sites (spec §3.1). */
    public static function supported(): bool
    {
        return defined('CHARSET') && strtolower(str_replace('_', '-', (string) CHARSET)) === 'utf-8';
    }

    public static function store(): MomoBird_Store
    {
        if (self::$store === null) {
            self::$store = new MomoBird_DiscuzStore();
        }
        return self::$store;
    }

    public static function source(): MomoBird_ForumSource
    {
        if (self::$source === null) {
            self::$source = new MomoBird_DiscuzForumSource();
        }
        return self::$source;
    }

    /** Stored settings; the forum address falls back to Discuz!'s 站点 URL. */
    public static function settings(): MomoBird_Settings
    {
        if (self::$settings === null) {
            $store = self::store();
            self::$settings = MomoBird_Settings::fromStored($store->get(self::SETTINGS_KEY), $store->get(self::SECRET_KEY), self::siteUrl(), self::httpHosts());
        }
        return self::$settings;
    }

    /**
     * Save settings (the admin page). $key null keeps the stored key. The forum address is
     * stored as entered; empty means "follow 站点 URL". A failed write throws a RuntimeException
     * whose message has the key removed and that does not chain the original (Discuz!'s
     * DbException carries the SQL, key included, to the error page and the error log).
     */
    public static function saveSettings(array $values, ?string $key): void
    {
        $store = self::store();
        try {
            $settings = MomoBird_Settings::fromArray($values, self::httpHosts());
            $store->set(self::SETTINGS_KEY, $settings->toArray());
            if ($key !== null) {
                $store->set(self::SECRET_KEY, array('key' => $key));
            }
        } catch (Throwable $e) {
            throw new RuntimeException('保存设置失败：' . MomoBird_Redactor::clean($e->getMessage(), (string) $key));
        } finally {
            self::$settings = null;
            self::$frontEnd = null;
        }
    }

    /** @internal tests: replace the store (null restores the Discuz! one). */
    public static function useStore(?MomoBird_Store $store): void
    {
        self::$store = $store;
        self::$settings = null;
        self::$frontEnd = null;
    }

    /** The engine for the stored settings, or for $settings (the admin page's fresh copy). */
    public static function engine(?MomoBird_Settings $settings = null): MomoBird_SyncEngine
    {
        $settings = $settings !== null ? $settings : self::settings();
        $host = strtolower((string) parse_url($settings->baseUrl(), PHP_URL_HOST));
        $transport = new MomoBird_FilesockTransport($host !== '' && in_array($host, self::httpHosts(), true));
        return new MomoBird_SyncEngine($settings, new MomoBird_ConnectClient($settings, $transport), self::store(), self::source(), function () {
            return time();
        });
    }

    /** One per request, so the footer and the podcast share the forum reads. */
    public static function frontEnd(): MomoBird_FrontEnd
    {
        if (self::$frontEnd === null) {
            self::$frontEnd = new MomoBird_FrontEnd(self::settings(), new MomoBird_WidgetState(self::store()), self::source());
        }
        return self::$frontEnd;
    }

    /**
     * Function and message hooks: work out the threads (MomoBird_Hooks), queue them (one write,
     * no network) and, on PHP-FPM, process the queue after the response has been sent. Elsewhere
     * the 5-minute cron job does it. Nothing here may reach the page: errors are swallowed and
     * the daily scan finds what was missed.
     *
     * @param string $event deletethread|deletepost|undeletethreads|threadpubsave|post|misc
     * @param mixed  $param the hook's parameter
     */
    public static function hook(string $event, $param): void
    {
        if (!self::supported()) {
            return;
        }
        try {
            $param = is_array($param) ? $param : array();
            switch ($event) {
                case 'deletethread':
                    $tids = MomoBird_Hooks::deleteThreadTids($param);
                    break;
                case 'deletepost':
                    $tids = MomoBird_Hooks::deletePostTids($param);
                    break;
                case 'undeletethreads':
                    $tids = MomoBird_Hooks::undeleteTids($param);
                    break;
                case 'threadpubsave':
                    $tids = MomoBird_Hooks::publishTids($param);
                    break;
                case 'post':
                    $tids = MomoBird_Hooks::messageTids($param);
                    break;
                case 'misc':
                    $tids = MomoBird_Hooks::miscTids($param);
                    break;
                default:
                    $tids = array();
            }
            if ($tids) {
                self::engine()->enqueue($tids, $event);
                self::scheduleDrain();
            }
        } catch (Throwable $e) {
            // Posting must never fail because of the plugin.
        }
    }

    /**
     * topicadmin / modcp message hooks: only a logged-in moderator's successful action, and ids
     * from the request never create rows for threads that do not exist.
     */
    public static function moderation(string $module, $param): void
    {
        global $_G;
        if (!self::supported()) {
            return;
        }
        try {
            $uid = isset($_G['uid']) ? (int) $_G['uid'] : 0;
            $adminid = isset($_G['adminid']) ? (int) $_G['adminid'] : 0;
            $forumModerator = !empty($_G['forum']['ismoderator']);
            $moderator = $uid > 0 && ($adminid > 0 || $forumModerator);
            $get = isset($_GET) && is_array($_GET) ? $_GET : array();
            $tid = isset($_G['tid']) && is_numeric($_G['tid']) ? (int) $_G['tid'] : 0;
            $tids = MomoBird_Hooks::moderationTids($module, is_array($param) ? $param : array(), $get, $tid, $moderator);
            if ($tids) {
                MomoBird_Hooks::enqueueRequested(self::engine(), self::source(), $tids, $module);
                self::scheduleDrain();
            }
        } catch (Throwable $e) {
            // Moderation must never fail because of the plugin.
        }
    }

    private static function scheduleDrain(): void
    {
        if (!self::settings()->validate()) {
            self::afterResponse('drain');
        }
    }

    /** global_footer / global_footer_mobile. */
    public static function footer(): string
    {
        global $_G;
        if (!self::supported()) {
            return '';
        }
        try {
            $script = isset($_G['basescript']) ? (string) $_G['basescript'] : '';
            $module = defined('CURMODULE') ? (string) CURMODULE : '';
            $fid = isset($_G['fid']) && is_numeric($_G['fid']) ? (int) $_G['fid'] : 0;
            return self::frontEnd()->footer($script, $module, $fid, time());
        } catch (Throwable $e) {
            return '';
        }
    }

    /** viewthread_postbottom(_mobile)_output: slot => markup, for the first post only. */
    public static function podcast(): array
    {
        global $postlist;
        if (!self::supported() || !is_array($postlist)) {
            return array();
        }
        try {
            return self::frontEnd()->podcastSlots($postlist);
        } catch (Throwable $e) {
            return array();
        }
    }

    /** The plugin cron job: run the tick after the response when possible, else now. */
    public static function cron(): void
    {
        if (!self::supported()) {
            return;
        }
        if (function_exists('fastcgi_finish_request')) {
            self::afterResponse('cron');
            return;
        }
        self::run('cron');
    }

    /**
     * One run after the response. A cron tick drains the queue too, so it replaces a pending
     * drain; at most one run per request.
     */
    private static function afterResponse(string $kind): void
    {
        if (!function_exists('fastcgi_finish_request')) {
            return;
        }
        if (self::$pending === null) {
            register_shutdown_function(array(__CLASS__, 'shutdown'));
        }
        if (self::$pending !== 'cron') {
            self::$pending = $kind;
        }
    }

    /** @internal shutdown function */
    public static function shutdown(): void
    {
        $kind = self::$pending;
        self::$pending = null;
        if ($kind === null) {
            return;
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        self::run($kind);
    }

    private static function run(string $kind): void
    {
        self::allowRun();
        try {
            $engine = self::engine();
            $deadline = time() + self::RUN_SECONDS;
            if ($kind === 'cron') {
                $engine->cronTick($deadline);
            } else {
                $engine->drain($deadline);
            }
        } catch (Throwable $e) {
            // The engine records its own errors; anything else waits for the next tick.
        }
    }

    /**
     * Before a run that may reach MomoBird (hook drain, cron tick, admin action): keep going if
     * the visitor leaves, and room for the 20 s engine budget plus one request that started just
     * before its deadline (PHP's default max_execution_time is 30 s).
     */
    public static function allowRun(): void
    {
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }
        if (!function_exists('set_time_limit')) {
            return;
        }
        try {
            $timeout = self::settings()->timeout();
        } catch (Throwable $e) {
            $timeout = MomoBird_Settings::MAX_TIMEOUT;
        }
        $timeout = max(MomoBird_Settings::MIN_TIMEOUT, min(MomoBird_Settings::MAX_TIMEOUT, $timeout));
        @set_time_limit(max(self::RUN_SECONDS, MomoBird_SyncEngine::FULL_BATCH_SECONDS) + $timeout + 30);
    }

    /**
     * The admin page (admincp.inc.php), wired to the Discuz! tables and this request.
     *
     * @param string $pageUrl the page's own admin.php URL (forms and AJAX post to it)
     */
    public static function adminPage(string $pageUrl, string $formhash, callable $formatTime): MomoBird_AdminPage
    {
        return new MomoBird_AdminPage(
            self::store(),
            self::source(),
            function (MomoBird_Settings $settings) {
                return self::engine($settings);
            },
            array(__CLASS__, 'saveSettings'),
            array(
                'supported' => self::supported(),
                'charset' => defined('CHARSET') ? (string) CHARSET : '',
                'formhash' => $formhash,
                'pageUrl' => $pageUrl,
                'scriptUrl' => 'source/plugin/momobird_ai/static/admin.js?v=' . MomoBird_AdminPage::VERSION,
                'siteUrl' => self::siteUrl(),
                'httpHosts' => self::httpHosts(),
                'formatTime' => $formatTime,
                'beforeRun' => array(__CLASS__, 'allowRun'),
            )
        );
    }

    /** Discuz!'s 站点 URL (全局 → 站点信息). */
    public static function siteUrl(): string
    {
        global $_G;
        return isset($_G['setting']['siteurl']) && is_string($_G['setting']['siteurl']) ? trim($_G['setting']['siteurl']) : '';
    }

    /** @return string[] */
    public static function httpHosts(): array
    {
        global $_G;
        $hosts = isset($_G['config']['momobird_ai']['http_hosts']) ? $_G['config']['momobird_ai']['http_hosts'] : array();
        $out = array();
        foreach ((array) $hosts as $host) {
            if (is_string($host) && trim($host) !== '') {
                $out[] = strtolower(trim($host));
            }
        }
        return $out;
    }
}
