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
    const SETTINGS_KEY = 'settings';
    const SECRET_KEY = 'secret';
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
            $values = $store->get(self::SETTINGS_KEY);
            $values = is_array($values) ? $values : array();
            $secret = $store->get(self::SECRET_KEY);
            $values['key'] = isset($secret['key']) && is_string($secret['key']) ? $secret['key'] : '';
            if (!isset($values['forumUrl']) || trim((string) $values['forumUrl']) === '') {
                $values['forumUrl'] = self::siteUrl();
            }
            self::$settings = MomoBird_Settings::fromArray($values, self::httpHosts());
        }
        return self::$settings;
    }

    /**
     * Save settings (the admin page, Task 6). $key null keeps the stored key. The forum
     * address is stored as entered; empty means "follow 站点 URL".
     */
    public static function saveSettings(array $values, ?string $key): void
    {
        $store = self::store();
        $settings = MomoBird_Settings::fromArray($values, self::httpHosts());
        $store->set(self::SETTINGS_KEY, $settings->toArray());
        if ($key !== null) {
            $store->set(self::SECRET_KEY, array('key' => $key));
        }
        self::$settings = null;
        self::$frontEnd = null;
    }

    public static function engine(): MomoBird_SyncEngine
    {
        $settings = self::settings();
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
     * For hooks: queue the threads (one write, no network) and, on PHP-FPM, process the queue
     * after the response has been sent. Elsewhere the 5-minute cron job does it.
     */
    public static function enqueue(array $tids, string $reason): void
    {
        if (!$tids || !self::supported()) {
            return;
        }
        try {
            self::engine()->enqueue($tids, $reason);
            if (!self::settings()->validate()) {
                self::afterResponse('drain');
            }
        } catch (Throwable $e) {
            // Posting must never fail because of the plugin; the scan finds what was missed.
        }
    }

    /** topicadmin / modcp message hooks: the moderated threads (MomoBird_Hooks::moderationTids). */
    public static function moderation(string $module): void
    {
        global $_G;
        $get = isset($_GET) && is_array($_GET) ? $_GET : array();
        $tid = isset($_G['tid']) && is_numeric($_G['tid']) ? (int) $_G['tid'] : 0;
        self::enqueue(MomoBird_Hooks::moderationTids($module, $get, $tid), $module);
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
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }
        try {
            $engine = self::engine();
            // Room for the deadline plus one request that started just before it.
            if (function_exists('set_time_limit')) {
                @set_time_limit(self::RUN_SECONDS + self::settings()->timeout() + 30);
            }
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

    private static function siteUrl(): string
    {
        global $_G;
        return isset($_G['setting']['siteurl']) && is_string($_G['setting']['siteurl']) ? trim($_G['setting']['siteurl']) : '';
    }

    /** @return string[] */
    private static function httpHosts(): array
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
