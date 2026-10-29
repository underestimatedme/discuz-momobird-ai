<?php
/**
 * The plugin's own page in Discuz! admincp (应用 → 插件 → MomoBird AI, module type 3):
 * settings with a write-only key, the forums to sync, the connection status, the
 * actions and the failure list (spec §3.6).
 *
 * handle() answers one request and never prints: admincp.inc.php echoes the html or,
 * for the AJAX actions the page's static/admin.js sends, clears Discuz!'s output buffer
 * and sends the JSON. Markup uses Discuz! admincp's own classes (tb tb2, partition, td27,
 * rowform, tips2, btn, infotitle2/3, error, diffcolor2), no colours of its own.
 *
 * Rules held here: the key is never written into the page (the input is always empty,
 * only the 8-character prefix is shown); a blank key keeps the stored one; every POST
 * and AJAX action checks Discuz!'s formhash; invalid settings save nothing; every text
 * that comes from the engine, MomoBird or an exception goes through MomoBird_Redactor.
 */

if (!defined('IN_DISCUZ')) {
    exit('Access Denied');
}

final class MomoBird_AdminPage
{
    const VERSION = '0.1.0';
    const UNSUPPORTED = '本插件只支持 UTF-8 编码的论坛';
    const EXPIRED = '页面已过期，请刷新页面后重试。';
    const QUOTA_HINT = '部分主题还没有索引，可能是本月额度已用完，请到控制台『用量』查看';
    /** The quota hint shows when MomoBird holds more than this share of posts that are not searchable yet. */
    const QUOTA_GAP = 0.2;
    const ACTIONS = array('test', 'sync_batch', 'retry', 'confirm_withdraw');
    /** Stands in for a missing key while checking the other settings (a key may be added later). */
    const PLACEHOLDER_KEY = 'mbk_0000000000000000000000000000';
    /** Part of MomoBird_Settings' key format message; never a key. */
    const KEY_HINT = '以 mbk_ 开头';

    /** Visibility::forum reasons, as shown next to a forum guests cannot read. */
    const FORUM_REASONS = array(
        'hidden' => '版块已隐藏',
        'password' => '设置了访问密码',
        'formula' => '设置了访问条件（积分公式）',
        'priced' => '需要付费进入',
        'redirect' => '是跳转链接',
        'viewperm' => '浏览权限里没有游客',
        'missing_forumfield' => '版块资料不完整',
    );

    const MODES = array(
        'drain' => '发帖后推送',
        'cron' => '定时任务',
        'full' => '全量同步',
        'retry' => '重试失败项',
        'confirm' => '确认撤下',
    );

    private $store;
    private $source;
    private $engineFactory;
    private $save;
    private $env;
    /** @var MomoBird_Settings|null */
    private $settings;
    /** @var MomoBird_SyncEngine|null */
    private $engine;

    /**
     * @param callable $engineFactory function (MomoBird_Settings $settings): MomoBird_SyncEngine
     * @param callable $save          function (array $values, ?string $key): void, throws a
     *                                RuntimeException with a redacted message (Bootstrap::saveSettings)
     * @param array    $env           supported (bool), charset, formhash, pageUrl, scriptUrl, siteUrl
     *                                (Discuz!'s 站点 URL), httpHosts, formatTime (callable int => string)
     */
    public function __construct(MomoBird_Store $store, MomoBird_ForumSource $source, callable $engineFactory, callable $save, array $env)
    {
        $this->store = $store;
        $this->source = $source;
        $this->engineFactory = $engineFactory;
        $this->save = $save;
        $this->env = $env + array(
            'supported' => false,
            'charset' => '',
            'formhash' => '',
            'pageUrl' => '',
            'scriptUrl' => '',
            'siteUrl' => '',
            'httpHosts' => array(),
            'formatTime' => null,
        );
    }

    /**
     * Answer one request. $request is Discuz!'s $_GET (POST fields are merged into it).
     *
     * @return array{type: string, body: string} type html or json
     */
    public function handle(string $method, array $request): array
    {
        $post = strtoupper($method) === 'POST';
        $action = isset($request['mb_action']) && is_string($request['mb_action']) ? $request['mb_action'] : '';
        if ($action !== '') {
            return array('type' => 'json', 'body' => $this->encode($this->ajax($action, $post, $request)));
        }
        if (!$this->env['supported']) {
            return array('type' => 'html', 'body' => $this->unsupported());
        }

        $notice = '';
        $form = null;
        if (isset($request['momobird_save'])) {
            if (!$post || !$this->formhashOk($request)) {
                $notice = $this->notice(false, self::EXPIRED, array());
            } else {
                list($notice, $form) = $this->saveForm(isset($request['mb']) && is_array($request['mb']) ? $request['mb'] : array());
            }
        }
        return array('type' => 'html', 'body' => $this->page($notice, $form));
    }

    // ================================================================ AJAX

    private function ajax(string $action, bool $post, array $request): array
    {
        if (!$this->env['supported']) {
            return array('ok' => false, 'message' => self::UNSUPPORTED . '。');
        }
        if (!$post || !$this->formhashOk($request)) {
            return array('ok' => false, 'message' => self::EXPIRED);
        }
        if (!in_array($action, self::ACTIONS, true)) {
            return array('ok' => false, 'message' => '未知的操作。');
        }
        try {
            switch ($action) {
                case 'test':
                    return $this->testConnection();
                case 'sync_batch':
                    return $this->syncBatch(isset($request['cursor']) ? $request['cursor'] : null);
                case 'retry':
                    return $this->afterRun($this->engine()->retryFailed(), '重试完成：');
                default:
                    return $this->afterRun($this->engine()->confirmWithdraw(), null);
            }
        } catch (Throwable $e) {
            return array('ok' => false, 'message' => '操作失败：' . self::truncate($this->clean($e->getMessage()), 300));
        }
    }

    private function testConnection(): array
    {
        $cached = $this->engine()->refreshStatus();
        if (!empty($cached['ok'])) {
            $name = isset($cached['project']['name']) && is_string($cached['project']['name']) ? $cached['project']['name'] : '';
            $message = '连接正常' . ($name !== '' ? '：项目「' . $name . '」' : '') . '。';
            $ok = true;
        } else {
            $error = isset($cached['error']) && is_string($cached['error']) ? $cached['error'] : '没有拿到 MomoBird 的回复';
            $message = '连接失败：' . $error;
            $ok = false;
        }
        return array('ok' => $ok, 'message' => $message, 'status_html' => $this->statusSection());
    }

    /**
     * One step of the browser-driven full sync. An empty or unknown cursor starts the sync.
     * When another run holds the lock the answer is busy with the cursor unchanged (null on a
     * first call): admin.js waits and sends it again.
     *
     * @param mixed $cursor
     */
    private function syncBatch($cursor): array
    {
        $cursor = is_string($cursor) && preg_match('/^(?:t:\d*|r)$/D', $cursor) === 1 ? $cursor : null;
        $batch = $this->engine()->fullSyncBatch($cursor);
        /** @var MomoBird_SyncResult $result */
        $result = $batch['result'];
        $busy = !empty($batch['busy']);
        $done = !empty($batch['done']);
        $out = array(
            'ok' => !$busy && $result->error === null,
            'busy' => $busy,
            'done' => $done,
            'cursor' => $batch['cursor'],
            'result' => $result->toArray(),
        );
        if ($busy) {
            $out['message'] = MomoBird_SyncEngine::BUSY_MESSAGE;
            return $out;
        }
        if ($result->error !== null) {
            $out['message'] = $result->error;
        } elseif ($done) {
            $out['message'] = '全量同步完成。' . $this->resultNotes($result);
        } else {
            $out['message'] = '正在同步，请保持页面打开…';
        }
        if ($done || $result->error !== null) {
            $out['status_html'] = $this->statusSection();
        }
        return $out;
    }

    /** @param string|null $prefix null = a confirmed withdrawal, which only removes */
    private function afterRun(MomoBird_SyncResult $result, ?string $prefix): array
    {
        $ok = $result->error === null;
        $done = $prefix === null ? '已确认撤下：撤下 ' . $result->removed . ' 篇' : $prefix . self::summary($result->toArray());
        $message = $ok ? $done . '。' . $this->resultNotes($result) : $result->error;
        return array('ok' => $ok, 'message' => $message, 'result' => $result->toArray(), 'status_html' => $this->statusSection());
    }

    private function resultNotes(MomoBird_SyncResult $result): string
    {
        $notes = array();
        if ($result->planRequired) {
            $notes[] = MomoBird_SyncEngine::PLAN_MESSAGE . '。';
        }
        if ($result->guardHeld) {
            $notes[] = '有主题因为清理保护没有撤下，见下方说明。';
        }
        if ($result->deadlineReached) {
            $notes[] = '这次没有做完，剩下的由定时任务继续。';
        }
        foreach ($result->notes as $note) {
            $notes[] = self::sentence($note);
        }
        return implode('', array_unique($notes));
    }

    // ================================================================ saving

    /**
     * Validate the submitted form and save it. Nothing is written when anything is wrong.
     *
     * @return array{0: string, 1: ?array} the notice html, and the values to show again in
     *                                     the form after an error (null after a save)
     */
    private function saveForm(array $form): array
    {
        $stored = $this->store->get(MomoBird_Settings::KV_SETTINGS);
        $values = is_array($stored) ? $stored : array();
        foreach (array('baseUrl', 'timeout', 'forumUrl') as $field) {
            if (isset($form[$field]) && is_scalar($form[$field])) {
                $values[$field] = trim((string) $form[$field]);
            }
        }
        if (isset($form['widgetPosition']) && is_scalar($form['widgetPosition'])) {
            $values['widgetPosition'] = (string) $form['widgetPosition'] === 'left' ? 'left' : 'right';
        }
        foreach (array('widgetEnabled', 'podcastEnabled', 'podcastTranscript') as $field) {
            if (isset($form[$field]) && is_scalar($form[$field])) {
                $values[$field] = (string) $form[$field] === '1';
            }
        }

        $errors = array();
        if (isset($form['forumScope']) && is_scalar($form['forumScope'])) {
            if ((string) $form['forumScope'] === 'selected') {
                $values['forumIds'] = $this->selectableIds(isset($form['forumIds']) ? $form['forumIds'] : array());
                if (!$values['forumIds']) {
                    $errors[] = '请至少选择一个版块，或改选「全部游客可见的版块」。';
                }
            } else {
                $values['forumIds'] = null;
            }
        }

        $key = isset($form['key']) && is_string($form['key']) ? trim($form['key']) : '';
        $storedSecret = $this->store->get(MomoBird_Settings::KV_SECRET);
        $hasStoredKey = isset($storedSecret['key']) && is_string($storedSecret['key']) && $storedSecret['key'] !== '';
        // A key may be added later; a key that was typed in must be valid.
        $secret = $key !== '' ? array('key' => $key) : ($hasStoredKey ? $storedSecret : array('key' => self::PLACEHOLDER_KEY));
        $candidate = MomoBird_Settings::fromStored($values, $secret, (string) $this->env['siteUrl'], (array) $this->env['httpHosts']);
        $errors = array_merge($candidate->validate(), $errors);

        // The form shows what was typed again, never the key.
        unset($values['key']);
        if ($errors) {
            return array($this->notice(false, '设置没有保存，请改正下面的问题：', $errors), $values);
        }

        $baseUrlChanged = !is_array($stored) || !isset($stored['baseUrl']) || rtrim((string) $stored['baseUrl'], '/') !== $candidate->baseUrl();
        try {
            call_user_func($this->save, $values, $key !== '' ? $key : null);
        } catch (Throwable $e) {
            $message = MomoBird_Redactor::clean($this->clean($e->getMessage()), $key);
            if (strpos($message, '保存设置失败') !== 0) {
                $message = '保存设置失败：' . $message;
            }
            return array($this->notice(false, self::truncate($message, 300), array()), $values);
        }
        $this->settings = null;
        $this->engine = null;

        $lines = array();
        if (($key !== '' || $baseUrlChanged) && !$this->settings()->validate()) {
            // A new key (or server) clears a stop at once instead of waiting for the hourly check.
            try {
                $cached = $this->engine()->refreshStatus();
                $lines[] = !empty($cached['ok'])
                    ? '已用新的设置检查连接：连接正常。'
                    : '已用新的设置检查连接：连接失败：' . (isset($cached['error']) && is_string($cached['error']) ? $cached['error'] : '没有拿到 MomoBird 的回复');
            } catch (Throwable $e) {
                $lines[] = '检查连接失败：' . self::truncate($this->clean($e->getMessage()), 300);
            }
        }
        return array($this->notice(true, '设置已保存。', $lines), null);
    }

    /**
     * Submitted forum ids that are forums or sub-forums of this site.
     *
     * @param mixed $ids
     * @return int[]
     */
    private function selectableIds($ids): array
    {
        $ids = is_array($ids) ? $ids : array();
        $wanted = array();
        foreach ($ids as $id) {
            if (is_scalar($id) && preg_match('/^\d+$/D', trim((string) $id)) === 1 && (int) $id > 0) {
                $wanted[(int) $id] = (int) $id;
            }
        }
        $tree = $this->forumTree();
        if (isset($tree['selectable'])) {
            $wanted = array_intersect_key($wanted, $tree['selectable']);
        }
        $wanted = array_values($wanted);
        sort($wanted);
        return $wanted;
    }

    // ================================================================ page

    private function page(string $notice, ?array $form): string
    {
        $settings = $this->settings();
        $values = $form !== null ? $form : $this->storedValues($settings);

        return '<div id="momobird-admin" data-url="' . self::h((string) $this->env['pageUrl']) . '" data-formhash="' . self::h((string) $this->env['formhash']) . '">'
            . $notice
            . $this->settingsForm($settings, $values)
            . '<p id="momobird-message" class="marginbot" role="status" style="display:none"></p>'
            . '<div id="momobird-status">' . $this->statusSection() . '</div>'
            . '</div>'
            . '<script type="text/javascript" src="' . self::h((string) $this->env['scriptUrl']) . '"></script>';
    }

    private function unsupported(): string
    {
        $charset = trim((string) $this->env['charset']);
        return '<div class="infobox"><h4 class="infotitle3">' . self::UNSUPPORTED . '</h4>'
            . '<p class="marginbot">这个论坛的编码是 ' . self::h($charset !== '' ? strtoupper($charset) : '未知')
            . '，MomoBird AI 不会同步帖子，也不会在前台显示问答助手和播客。Discuz! X3.5 和 X5.0 的官方版本都是 UTF-8 编码。</p></div>';
    }

    /** The values the form shows: what is stored (an empty forum address means 站点 URL). */
    private function storedValues(MomoBird_Settings $settings): array
    {
        $stored = $this->store->get(MomoBird_Settings::KV_SETTINGS);
        $values = $settings->toArray();
        $values['forumUrl'] = is_array($stored) && isset($stored['forumUrl']) ? (string) $stored['forumUrl'] : '';
        return $values;
    }

    private function settingsForm(MomoBird_Settings $settings, array $values): string
    {
        $v = function (string $name, $default = '') use ($values) {
            return isset($values[$name]) && is_scalar($values[$name]) ? (string) $values[$name] : (string) $default;
        };
        $flag = function (string $name, bool $default) use ($values) {
            return isset($values[$name]) ? (bool) $values[$name] : $default;
        };

        $prefix = $settings->key() !== '' ? '已保存：' . self::h($settings->keyPrefix()) . '…（留空保存会保留已保存的密钥）' : '未设置';
        $siteUrl = trim((string) $this->env['siteUrl']);

        $html = '<form name="momobird_settings" method="post" autocomplete="off" action="' . self::h((string) $this->env['pageUrl']) . '">'
            . '<input type="hidden" name="formhash" value="' . self::h((string) $this->env['formhash']) . '" />'
            . '<table class="tb tb2">'
            . '<tr><th colspan="15" class="partition">连接</th></tr>'
            . self::row('连接密钥', '<input type="password" class="txt" name="mb[key]" value="" autocomplete="new-password" placeholder="mbk_…" />',
                $prefix . '<br />在 MomoBird 控制台「知识 → 新增来源 → 博客 / 论坛 → Discuz!」生成。')
            . self::row('MomoBird Base URL', '<input type="text" class="txt" name="mb[baseUrl]" value="' . self::h($v('baseUrl', MomoBird_Settings::DEFAULT_BASE_URL)) . '" />',
                '一般不用改。必须是 https:// 开头的公网地址。')
            . self::row('请求超时（秒）', '<input type="text" class="txt" name="mb[timeout]" value="' . self::h($v('timeout', MomoBird_Settings::DEFAULT_TIMEOUT)) . '" />',
                MomoBird_Settings::MIN_TIMEOUT . ' 到 ' . MomoBird_Settings::MAX_TIMEOUT . ' 秒，默认 ' . MomoBird_Settings::DEFAULT_TIMEOUT . ' 秒。')
            . self::row('论坛地址', '<input type="text" class="txt" name="mb[forumUrl]" value="' . self::h($v('forumUrl')) . '" placeholder="' . self::h($siteUrl) . '" />',
                '帖子链接以它开头，必须和 MomoBird 来源的网站地址同源（协议、域名、端口都一样）。留空则使用 Discuz! 后台「全局 → 站点信息」里的站点 URL'
                . ($siteUrl !== '' ? '（现在是 ' . self::h($siteUrl) . '）' : '（现在没有填写）') . '。')
            . '<tr><th colspan="15" class="partition">同步哪些版块</th></tr>'
            . $this->forumRows(array_key_exists('forumIds', $values) ? $values['forumIds'] : null)
            . '<tr><th colspan="15" class="partition">前台</th></tr>'
            . self::row('显示问答助手', self::yesNo('widgetEnabled', $flag('widgetEnabled', false)),
                '在论坛每个页面加载问答助手。在版块页和帖子页只用这个版块的内容回答。第一次全量同步完成后，如果网站接入还没上线会自动开启一次。')
            . self::row('问答助手位置', '<select name="mb[widgetPosition]">'
                . '<option value="right"' . ($v('widgetPosition', 'right') !== 'left' ? ' selected="selected"' : '') . '>右下角</option>'
                . '<option value="left"' . ($v('widgetPosition', 'right') === 'left' ? ' selected="selected"' : '') . '>左下角</option></select>', '')
            . self::row('帖子页显示播客播放器', self::yesNo('podcastEnabled', $flag('podcastEnabled', true)),
                '在同步范围内的帖子一楼下方放播放器；这个帖子还没有播客时什么都不显示。')
            . self::row('播放器显示文字稿', self::yesNo('podcastTranscript', $flag('podcastTranscript', false)), '')
            . '<tr><td colspan="15"><div class="fixsel"><input type="submit" class="btn" name="momobird_save" value="保存设置" /></div></td></tr>'
            . '</table></form>';
        return $html;
    }

    /** @param mixed $forumIds null = every guest-visible forum */
    private function forumRows($forumIds): string
    {
        $tree = $this->forumTree();
        if (isset($tree['error'])) {
            $error = '：' . self::h($tree['error']);
            return '<tr class="noborder"><td colspan="2" class="error">读取版块列表失败' . $error . '。保存时会保留原来的版块选择。</td></tr>';
        }
        $selected = is_array($forumIds) ? array_map('intval', $forumIds) : array();
        $all = !is_array($forumIds);

        $options = '';
        foreach ($tree['groups'] as $group) {
            $options .= '<optgroup label="' . self::h($group['name']) . '">';
            foreach ($group['forums'] as $forum) {
                $label = ($forum['type'] === 'sub' ? '　└ ' : '') . $forum['name'];
                if ($forum['reason'] !== null) {
                    $options .= '<option value="' . $forum['fid'] . '" disabled="disabled">' . self::h($label . '（游客不可见：' . $forum['reason'] . '）') . '</option>';
                } else {
                    $options .= '<option value="' . $forum['fid'] . '"' . (in_array($forum['fid'], $selected, true) ? ' selected="selected"' : '') . '>' . self::h($label) . '</option>';
                }
            }
            $options .= '</optgroup>';
        }

        $scope = '<ul class="nofloat" onmouseover="altStyle(this);">'
            . '<li' . ($all ? ' class="checked"' : '') . '><input class="radio" type="radio" name="mb[forumScope]" value="all"' . ($all ? ' checked="checked"' : '') . ' />&nbsp;全部游客可见的版块</li>'
            . '<li' . (!$all ? ' class="checked"' : '') . '><input class="radio" type="radio" name="mb[forumScope]" value="selected"' . (!$all ? ' checked="checked"' : '') . ' />&nbsp;只同步下面选中的版块</li>'
            . '</ul>';
        $select = '<select name="mb[forumIds][]" multiple="multiple" size="12">' . $options . '</select>';

        return self::row('同步范围', $scope . $select,
            '只有游客能看到的主题才会同步。灰色的版块游客看不到，不会同步，括号里是原因。没选中的版块里的主题不同步，已经同步的会撤下（一次要撤下很多时会先请你确认）。按住 Ctrl（Mac 上是 Command）可以多选。');
    }

    /**
     * Forums and sub-forums by group, each with the reason guests cannot read it (null = they
     * can). User-made group forums (status 3) are left out.
     *
     * @return array{groups: array, selectable: array<int,bool>}|array{error: string}
     */
    private function forumTree(): array
    {
        try {
            $forums = $this->source->forums();
            $fields = $this->source->forumFields();
            $guest = $this->source->guestGroup();
        } catch (Throwable $e) {
            return array('error' => self::truncate($this->clean($e->getMessage()), 200));
        }

        $byFid = array();
        foreach ($forums as $row) {
            if (is_array($row) && isset($row['fid'])) {
                $byFid[(int) $row['fid']] = $row;
            }
        }
        $order = function (array $a, array $b) {
            $da = isset($a['displayorder']) ? (int) $a['displayorder'] : 0;
            $db = isset($b['displayorder']) ? (int) $b['displayorder'] : 0;
            return $da === $db ? (int) $a['fid'] - (int) $b['fid'] : $da - $db;
        };

        $groups = array();
        $forumsOf = array();
        $subsOf = array();
        foreach ($byFid as $fid => $row) {
            $type = isset($row['type']) ? (string) $row['type'] : '';
            if ($type === 'group') {
                $groups[$fid] = $row;
            } elseif (($type === 'forum' || $type === 'sub') && (!isset($row['status']) || (int) $row['status'] !== 3)) {
                $fup = isset($row['fup']) ? (int) $row['fup'] : 0;
                if ($type === 'sub' && isset($byFid[$fup]) && ($byFid[$fup]['type'] ?? '') === 'forum') {
                    $subsOf[$fup][] = $row;
                } else {
                    $forumsOf[isset($groups[$fup]) || (isset($byFid[$fup]) && ($byFid[$fup]['type'] ?? '') === 'group') ? $fup : 0][] = $row;
                }
            }
        }
        uasort($groups, $order);

        $entry = function (array $row) use ($fields, $guest) {
            $fid = (int) $row['fid'];
            $reason = MomoBird_Visibility::forum($row, isset($fields[$fid]) && is_array($fields[$fid]) ? $fields[$fid] : array(), $guest, null);
            $name = trim(html_entity_decode(strip_tags(isset($row['name']) ? (string) $row['name'] : ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            return array(
                'fid' => $fid,
                'type' => (string) $row['type'],
                'name' => $name !== '' ? $name : '#' . $fid,
                'reason' => $reason === null ? null : (isset(self::FORUM_REASONS[$reason]) ? self::FORUM_REASONS[$reason] : $reason),
            );
        };

        $out = array();
        $selectable = array();
        $groupIds = array_keys($groups);
        if (isset($forumsOf[0])) {
            $groupIds[] = 0;
        }
        foreach ($groupIds as $gid) {
            if (empty($forumsOf[$gid])) {
                continue;
            }
            $list = $forumsOf[$gid];
            usort($list, $order);
            $items = array();
            foreach ($list as $row) {
                $items[] = $entry($row);
                $selectable[(int) $row['fid']] = true;
                $subs = isset($subsOf[(int) $row['fid']]) ? $subsOf[(int) $row['fid']] : array();
                usort($subs, $order);
                foreach ($subs as $sub) {
                    $items[] = $entry($sub);
                    $selectable[(int) $sub['fid']] = true;
                }
            }
            $name = $gid === 0 ? '未分区' : trim(html_entity_decode(strip_tags((string) ($groups[$gid]['name'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $out[] = array('name' => $name !== '' ? $name : '#' . $gid, 'forums' => $items);
        }
        return array('groups' => $out, 'selectable' => $selectable);
    }

    // ================================================================ status

    /** Alerts, the connection status, the actions and the failure list (no network). */
    private function statusSection(): string
    {
        try {
            $status = $this->engine()->status();
        } catch (Throwable $e) {
            return '<table class="tb tb2"><tr><th class="partition">连接状态</th></tr><tr><td class="error">读取同步状态失败：'
                . self::h(self::truncate($this->clean($e->getMessage()), 300)) . '</td></tr></table>';
        }
        return $this->alerts($status) . $this->facts($status) . $this->actions($status) . $this->failures($status);
    }

    private function alerts(array $status): string
    {
        $items = array();
        $error = function (string $text) use (&$items) {
            $items[] = '<span class="error">' . self::h($this->clean($text)) . '</span>';
        };
        $connect = isset($status['connect']) && is_array($status['connect']) ? $status['connect'] : null;

        if (!empty($status['settings_problems'])) {
            $error('设置还不完整，暂不同步：' . implode(' ', $status['settings_problems']));
        }
        if (!empty($status['read_error']['message'])) {
            $error((string) $status['read_error']['message']);
        }
        if (isset($status['site']) && $status['site'] !== null) {
            $error($status['site'] === 'guest_denied'
                ? '游客组不能访问论坛，暂停同步（不推送也不撤下）。在 Discuz! 后台「用户 → 用户组」里允许游客访问后自动恢复。'
                : '论坛已关闭，暂停同步（不推送也不撤下）。论坛重新开放后自动恢复。');
        }
        if (is_array($status['halted'] ?? null)) {
            $error('同步已停止：' . (isset($status['halted']['message']) ? self::sentence((string) $status['halted']['message']) : '连接被拒绝。')
                . '请到 MomoBird 控制台确认这个来源还在使用，或换上新的连接密钥后保存（保存时会立即重新检查）。');
        }
        if (!empty($status['plan'])) {
            $error((string) $status['plan']['message'] . '。');
        }
        $origin = isset($status['origin']) && is_array($status['origin']) ? $status['origin'] : array();
        if (!empty($origin['checked']) && empty($origin['ok'])) {
            $error('论坛地址（' . (string) $origin['forum_url'] . '）和 MomoBird 来源的网站地址（' . (string) $origin['source_url'] . '）不一致，'
                . '两边必须同源（协议、域名、端口都一样，注意 www 和 http/https）。请在插件设置里改「论坛地址」，'
                . '或在 Discuz! 后台「全局 → 站点信息」改站点 URL。在这之前不推送帖子，撤下照常。');
        }
        if (!empty($status['source']['wrong_kind'])) {
            $items[] = self::h($this->clean((string) $status['source']['notice'])) . '。建议在 MomoBird 控制台新建 Discuz! 来源，换上它的连接密钥。';
        }
        if ($connect !== null && empty($connect['ok']) && !is_array($status['halted'] ?? null)) {
            $error('最近一次连接检查失败：' . (isset($connect['error']) ? (string) $connect['error'] : '没有拿到 MomoBird 的回复'));
        }
        if (!empty($status['guard'])) {
            $items[] = $this->guardText($status['guard'])
                . ' <input type="button" class="btn" data-mb-action="confirm_withdraw" value="确认撤下" />';
        }
        $documents = $status['remote']['documents'] ?? null;
        $ready = $status['remote']['ready'] ?? null;
        if (is_int($documents) && is_int($ready) && $documents > 0 && ($documents - $ready) > $documents * self::QUOTA_GAP) {
            $items[] = self::QUOTA_HINT . '。也可以用上面的「同步哪些版块」缩小同步范围，把额度留给最重要的版块。';
        }
        if ($connect === null && empty($status['settings_problems'])) {
            $items[] = '还没有检查过连接，点「测试连接」看看。';
        }

        if (!$items) {
            return '';
        }
        return '<table class="tb tb2"><tr><th class="partition">需要注意</th></tr><tr><td class="tipsblock"><ul><li>'
            . implode('</li><li>', $items) . '</li></ul></td></tr></table>';
    }

    private function guardText(array $guard): string
    {
        $count = isset($guard['would_remove']) && is_numeric($guard['would_remove']) ? (int) $guard['would_remove'] : 0;
        $what = $count > 0 ? $count . ' 篇' : '一些';
        if (($guard['reason'] ?? '') === 'kind') {
            return '清理保护：MomoBird 上有 ' . $what . '主题在论坛里已经不该同步了，但这个来源的类型不是 discuz，所以没有自动撤下。'
                . '确认它们都来自这个论坛后，点「确认撤下」。';
        }
        return '清理保护：MomoBird 上有 ' . $what . '主题在论坛里已经不该同步了，但数量很多（或论坛里一篇可同步的都没有），为防止误删，这次没有撤下。'
            . '请先确认没有误删主题、误改版块权限或版块选择；确实要撤下就点「确认撤下」。';
    }

    private function facts(array $status): string
    {
        $connect = isset($status['connect']) && is_array($status['connect']) ? $status['connect'] : array();
        $project = isset($connect['project']['name']) && is_string($connect['project']['name']) ? $connect['project']['name'] : '';
        $source = isset($status['source']) && is_array($status['source']) ? $status['source'] : array();

        $sourceText = '—';
        if (!empty($source['name']) || !empty($source['kind'])) {
            $sourceText = self::h((string) ($source['name'] ?? '')) . '（类型：' . self::h((string) ($source['kind'] ?? '未知')) . '）';
        }

        $origin = isset($status['origin']) && is_array($status['origin']) ? $status['origin'] : array();
        if (empty($origin['checked'])) {
            $originText = '还没有检查';
        } elseif (!empty($origin['ok'])) {
            $originText = '<span class="diffcolor2">一致</span>（' . self::h((string) $origin['source_url']) . '）';
        } else {
            $originText = '<span class="error">不一致</span>（MomoBird 来源：' . self::h((string) $origin['source_url']) . '）';
        }
        $originText .= '<br />论坛地址：' . self::h((string) ($origin['forum_url'] ?? ''));

        $rows = array(
            '项目' => $project !== '' ? self::h($project) : '—',
            '知识源' => $sourceText,
            '套餐' => self::h($this->planText($connect)),
            '问答助手' => self::h($this->widgetText($connect)),
            '站点地址' => $originText,
            '主题' => self::h($this->countText($status)),
            '队列' => self::h('等待推送 ' . (int) ($status['queue'] ?? 0) . ' 篇' . (!empty($status['held']) ? '，暂缓推送 ' . (int) $status['held'] . ' 篇' : '')),
            '上次同步' => $this->lastSyncText(isset($status['last_sync']) && is_array($status['last_sync']) ? $status['last_sync'] : null),
            '上次全量同步' => self::h(!empty($status['last_full_sync_at']) ? $this->time((int) $status['last_full_sync_at']) : '还没有做过'),
            '上次对账' => self::h($this->reconcileText($status)),
            '最近检查连接' => self::h(!empty($connect['checked_at']) ? $this->time((int) $connect['checked_at']) : '还没有检查过'),
        );
        $html = '<table class="tb tb2"><tr><th colspan="2" class="partition">连接状态</th></tr>';
        foreach ($rows as $label => $value) {
            $html .= '<tr><td class="td21">' . $label . '</td><td>' . $value . '</td></tr>';
        }
        return $html . '</table>';
    }

    private function actions(array $status): string
    {
        $local = isset($status['local']['count']) && is_int($status['local']['count']) ? $status['local']['count'] : null;
        $tip = empty($status['last_full_sync_at'])
            ? '还没有做过全量同步' . ($local !== null ? '：本地应同步 ' . $local . ' 篇' : '') . '。全量同步从最新的主题往旧的推，每批 20 篇，请保持页面打开；中途关掉也没关系，定时任务会接着补。'
            : '全量同步由浏览器分批进行（每批 20 篇），请保持页面打开。平时不用点：发帖后会自动推送，每 5 分钟和每天还会各对账一次。';
        return '<table class="tb tb2"><tr><th class="partition">操作</th></tr><tr><td><div class="fixsel">'
            . '<input type="button" class="btn" data-mb-action="test" value="测试连接" /> &nbsp;'
            . '<input type="button" class="btn" data-mb-action="sync" value="立即全量同步" /> &nbsp;'
            . '<input type="button" class="btn" data-mb-action="retry" value="重试失败项" />'
            . '</div></td></tr><tr><td class="tips2">' . self::h($tip) . '</td></tr></table>';
    }

    private function failures(array $status): string
    {
        $html = '<table class="tb tb2"><tr><th colspan="5" class="partition">失败的主题</th></tr>';
        $list = isset($status['failures']) && is_array($status['failures']) ? $status['failures'] : array();
        if (!$list) {
            return $html . '<tr><td colspan="5">没有失败的主题。</td></tr></table>';
        }
        $states = array('failed' => '等待重试', 'rejected' => '被拒绝', 'held' => '暂缓推送');
        $html .= '<tr class="header"><th>主题</th><th>状态</th><th>错误</th><th>尝试次数</th><th>时间</th></tr>';
        $forumUrl = $this->settings()->forumUrl();
        foreach ($list as $row) {
            $tid = (int) $row['tid'];
            $title = (string) $row['title'] !== '' ? (string) $row['title'] : '#' . $tid;
            $titleHtml = self::h($title);
            if ($forumUrl !== '') {
                $titleHtml = '<a href="' . self::h(MomoBird_ThreadUrl::build($forumUrl, $tid, null)) . '" target="_blank">' . $titleHtml . '</a>';
            }
            $html .= '<tr class="hover"><td>' . $titleHtml . '</td>'
                . '<td>' . self::h($states[(string) $row['state']] ?? (string) $row['state']) . '</td>'
                . '<td>' . self::h($this->clean((string) $row['error'])) . '</td>'
                . '<td>' . (int) $row['attempts'] . '</td>'
                . '<td>' . self::h($this->time((int) $row['at'])) . '</td></tr>';
        }
        return $html . '<tr><td colspan="5" class="tips2">网络错误和服务器错误每小时自动重试一次，最多 5 次；之后点「重试失败项」再推送。被拒绝的主题改好内容后会自动重推。</td></tr></table>';
    }

    private function planText(array $connect): string
    {
        $e = isset($connect['entitlement']) && is_array($connect['entitlement']) ? $connect['entitlement'] : null;
        if ($e === null) {
            return '—';
        }
        $status = isset($e['status']) && is_string($e['status']) ? $e['status'] : '';
        $date = function ($key) use ($e) {
            return isset($e[$key]) && is_string($e[$key]) && $e[$key] !== '' ? substr($e[$key], 0, 10) : null;
        };
        if (($e['active'] ?? null) === false || $status === 'expired') {
            return '已到期（续费或兑换后点「立即全量同步」补齐）';
        }
        if ($status === 'trial') {
            return '专业版试用' . ($date('trial_ends_at') !== null ? '，' . $date('trial_ends_at') . ' 到期' : '');
        }
        if ($status === 'pro') {
            return '专业版' . ($date('pro_ends_at') !== null ? '，' . $date('pro_ends_at') . ' 到期' : '');
        }
        return $status !== '' ? $status : '—';
    }

    private function widgetText(array $connect): string
    {
        $off = $this->settings()->widgetEnabled() ? '' : '（插件设置里没有打开）';
        $widget = isset($connect['widget']) && is_array($connect['widget']) ? $connect['widget'] : null;
        if ($widget === null) {
            return '—' . $off;
        }
        if (!empty($widget['available'])) {
            return '已上线' . $off;
        }
        $reason = isset($widget['reason']) && is_string($widget['reason']) ? $widget['reason'] : '';
        if ($reason === 'not_published') {
            return '还没上线' . ($off === '' ? '（第一次全量同步完成后自动开启）' : $off);
        }
        return '未上线' . ($reason !== '' ? '（' . $reason . '）' : '') . $off;
    }

    private function countText(array $status): string
    {
        $local = $status['local'] ?? array();
        if (!isset($local['count']) || !is_int($local['count'])) {
            $text = '本地应同步的篇数读取失败';
        } else {
            $text = '本地应同步 ' . $local['count'] . ' 篇' . (empty($local['exact']) ? '（估计值，扫描完一轮后更准）' : '');
        }
        $documents = $status['remote']['documents'] ?? null;
        $ready = $status['remote']['ready'] ?? null;
        if (is_int($documents)) {
            $text .= '；MomoBird 上有 ' . $documents . ' 篇' . (is_int($ready) ? '，其中 ' . $ready . ' 篇可检索' : '');
        } else {
            $text .= '；MomoBird 上的篇数点「测试连接」后显示';
        }
        return $text;
    }

    private function lastSyncText(?array $last): string
    {
        if ($last === null) {
            return '还没有同步过';
        }
        $mode = isset($last['mode']) && isset(self::MODES[$last['mode']]) ? self::MODES[$last['mode']] : '同步';
        $error = isset($last['error']) && is_string($last['error']) && $last['error'] !== '' ? $last['error'] : null;
        $html = self::h($this->time((int) ($last['at'] ?? 0)) . ' ' . $mode . ($error === null ? '完成' : '没有完成') . '：' . self::summary($last));
        if ($error !== null) {
            $html .= '<br /><span class="error">' . self::h($this->clean($error)) . '</span>';
        }
        return $html;
    }

    private function reconcileText(array $status): string
    {
        $text = !empty($status['last_reconcile_at']) ? $this->time((int) $status['last_reconcile_at']) : '还没有对账过';
        $scan = isset($status['scan']) && is_array($status['scan']) ? $status['scan'] : array();
        if (!empty($scan['round_finished_at'])) {
            $text .= '；上次扫完全部主题：' . $this->time((int) $scan['round_finished_at']);
        }
        if (!empty($scan['active'])) {
            $text .= '；每日扫描进行中';
        }
        return $text;
    }

    // ================================================================ helpers

    private function settings(): MomoBird_Settings
    {
        if ($this->settings === null) {
            $this->settings = MomoBird_Settings::fromStored(
                $this->store->get(MomoBird_Settings::KV_SETTINGS),
                $this->store->get(MomoBird_Settings::KV_SECRET),
                (string) $this->env['siteUrl'],
                (array) $this->env['httpHosts']
            );
        }
        return $this->settings;
    }

    private function engine(): MomoBird_SyncEngine
    {
        if ($this->engine === null) {
            $this->engine = call_user_func($this->engineFactory, $this->settings());
        }
        return $this->engine;
    }

    private function formhashOk(array $request): bool
    {
        $expected = (string) $this->env['formhash'];
        $given = isset($request['formhash']) && is_string($request['formhash']) ? $request['formhash'] : '';
        return $expected !== '' && hash_equals($expected, $given);
    }

    /**
     * Remove the stored key and anything shaped like a key. The settings' own hint
     * 「应以 mbk_ 开头」 is kept: the redactor also removes a bare mbk_, which would blank it.
     */
    private function clean(string $text): string
    {
        try {
            $key = $this->settings()->key();
        } catch (Throwable $e) {
            $key = '';
        }
        $marker = "\x01KEYHINT\x01";
        $text = str_replace(self::KEY_HINT, $marker, str_replace($marker, '', $text));
        return str_replace($marker, self::KEY_HINT, MomoBird_Redactor::clean($text, $key));
    }

    /** JSON for admin.js; every string in it is redacted once more. */
    private function encode(array $data): string
    {
        $json = json_encode($this->cleanAll($data), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        return is_string($json) ? $json : '{"ok":false,"message":"操作失败"}';
    }

    private function cleanAll($value)
    {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = $this->cleanAll($v);
            }
            return $value;
        }
        return is_string($value) ? $this->clean($value) : $value;
    }

    private function time(int $timestamp): string
    {
        if ($timestamp <= 0) {
            return '—';
        }
        if (is_callable($this->env['formatTime'])) {
            return (string) call_user_func($this->env['formatTime'], $timestamp);
        }
        return date('Y-m-d H:i:s', $timestamp);
    }

    /** @param string[] $lines plain text */
    private function notice(bool $ok, string $title, array $lines): string
    {
        $html = '<table class="tb tb2"><tr><td><p class="' . ($ok ? 'infotitle2' : 'infotitle3') . '">' . self::h($title) . '</p>';
        if ($lines) {
            $html .= '<ul>';
            foreach ($lines as $line) {
                $html .= '<li' . ($ok ? '' : ' class="error"') . '>' . self::h($this->clean($line)) . '</li>';
            }
            $html .= '</ul>';
        }
        return $html . '</td></tr></table>';
    }

    private static function summary(array $r): string
    {
        $n = function ($key) use ($r) {
            return isset($r[$key]) ? (int) $r[$key] : 0;
        };
        $parts = array('推送 ' . $n('pushed') . ' 篇', '未变 ' . $n('unchanged') . ' 篇', '撤下 ' . $n('removed') . ' 篇');
        if ($n('failed') > 0) {
            $parts[] = '失败 ' . $n('failed') . ' 篇';
        }
        if ($n('rejected') > 0) {
            $parts[] = '被拒绝 ' . $n('rejected') . ' 篇';
        }
        if ($n('held') > 0) {
            $parts[] = '暂缓 ' . $n('held') . ' 篇';
        }
        return implode('，', $parts);
    }

    /** A setting in admincp's showsetting() layout: bold title row, then control and comment. */
    private static function row(string $title, string $control, string $comment): string
    {
        return '<tr><td colspan="2" class="td27">' . $title . '</td></tr>'
            . '<tr class="noborder"><td class="vtop rowform">' . $control . '</td><td class="vtop tips2">' . $comment . '</td></tr>';
    }

    /** admincp's yes/no radio pair. */
    private static function yesNo(string $name, bool $value): string
    {
        return '<ul onmouseover="altStyle(this);">'
            . '<li' . ($value ? ' class="checked"' : '') . '><input class="radio" type="radio" name="mb[' . $name . ']" value="1"' . ($value ? ' checked="checked"' : '') . ' />&nbsp;是</li>'
            . '<li' . (!$value ? ' class="checked"' : '') . '><input class="radio" type="radio" name="mb[' . $name . ']" value="0"' . (!$value ? ' checked="checked"' : '') . ' />&nbsp;否</li>'
            . '</ul>';
    }

    /** $text ending in exactly one 。 (rtrim() would cut UTF-8 bytes). */
    private static function sentence(string $text): string
    {
        return preg_replace('/[。.\s]+$/u', '', $text) . '。';
    }

    private static function h(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function truncate(string $text, int $length): string
    {
        if (function_exists('mb_substr')) {
            return mb_strlen($text, 'UTF-8') > $length ? mb_substr($text, 0, $length, 'UTF-8') . '…' : $text;
        }
        return strlen($text) > $length ? substr($text, 0, $length) . '…' : $text;
    }
}
