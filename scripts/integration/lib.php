<?php
/**
 * Host-side helpers for scripts/integration/checks.php: an HTTP client with its own
 * cookie jar (one per browser: guest, admin), SQL through the database container,
 * the mock's request log, the plugin cron on demand and a check recorder.
 */

final class It_Http
{
    private $base;
    private $jar;
    public $lastStatus = 0;
    public $lastTime = 0.0;
    public $lastUrl = '';

    public function __construct(string $base, string $jar)
    {
        $this->base = rtrim($base, '/') . '/';
        $this->jar = $jar;
        @unlink($jar);
    }

    public function get(string $path, array $query = array()): string
    {
        return $this->request('GET', $path . ($query ? (strpos($path, '?') === false ? '?' : '&') . http_build_query($query) : ''), null);
    }

    /** POST without following a redirect (the posting request alone is timed). */
    public function post(string $path, array $fields): string
    {
        return $this->request('POST', $path, http_build_query($fields), false);
    }

    public function request(string $method, string $path, ?string $body, bool $follow = true): string
    {
        $ch = curl_init($this->base . ltrim($path, '/'));
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_FOLLOWLOCATION => $follow,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
            CURLOPT_HTTPHEADER => array('Accept-Language: zh-CN'),
        ));
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded', 'Accept-Language: zh-CN'));
        }
        $start = microtime(true);
        $out = curl_exec($ch);
        $this->lastTime = microtime(true) - $start;
        $this->lastStatus = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $this->lastUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        if ($out === false) {
            $error = curl_error($ch);
            throw new RuntimeException("HTTP $method $path failed: $error");
        }
        return (string) $out;
    }

    public function cookies(): string
    {
        return is_file($this->jar) ? (string) file_get_contents($this->jar) : '';
    }
}

final class It
{
    public $suite;
    public $prefix;
    public $site;
    public $fpm;
    public $setup;
    /** @var It_Http */
    public $guest;
    /** @var It_Http */
    public $admin;
    /** @var It_Http */
    public $member;
    public $formhash = '';
    public $adminFormhash = '';
    public $results = array();
    private $tmp;

    public function __construct(string $suite, string $prefix, string $site, array $setup, string $tmp)
    {
        $this->suite = $suite;
        $this->prefix = $prefix;
        $this->site = rtrim($site, '/');
        $this->setup = $setup;
        $this->fpm = $suite === 'x5';
        $this->tmp = $tmp;
        $this->guest = new It_Http($this->site, $tmp . '/guest.jar');
        $this->admin = new It_Http($this->site, $tmp . '/admin.jar');
        $this->member = new It_Http($this->site, $tmp . '/member.jar');
    }

    public function fid(string $name): int
    {
        return (int) $this->setup['fids'][$name];
    }

    // --- docker ------------------------------------------------------------------------------

    public function sh(string $command, &$code = null): string
    {
        $out = array();
        exec($command . ' 2>&1', $out, $code);
        return implode("\n", $out);
    }

    public function sql(string $query): array
    {
        $cmd = 'docker exec ' . escapeshellarg($this->prefix . '-db') . ' mariadb --default-character-set=utf8mb4 -uroot -p' . escapeshellarg(getenv('IT_DB_PASSWORD'))
            . ' discuz -N -B -e ' . escapeshellarg($query);
        $out = $this->sh($cmd, $code);
        if ($code !== 0) {
            throw new RuntimeException("SQL failed: $query\n$out");
        }
        $rows = array();
        foreach ($out === '' ? array() : explode("\n", $out) as $line) {
            $rows[] = explode("\t", $line);
        }
        return $rows;
    }

    public function one(string $query): ?string
    {
        $rows = $this->sql($query);
        return $rows ? $rows[0][0] : null;
    }

    public function dz(string $args): array
    {
        $out = $this->sh('docker exec -u www-data ' . escapeshellarg($this->prefix . '-web') . ' php /opt/it/dz.php ' . $args, $code);
        $lines = explode("\n", trim($out));
        $data = json_decode((string) end($lines), true);
        if ($code !== 0 || !is_array($data)) {
            throw new RuntimeException("dz.php $args failed: $out");
        }
        return $data;
    }

    public function web(string $command): string
    {
        return $this->sh('docker exec ' . escapeshellarg($this->prefix . '-web') . ' sh -c ' . escapeshellarg($command));
    }

    // --- mock connect ---------------------------------------------------------------------------

    /** @return array[] every request the mock has logged */
    public function mockLog(): array
    {
        $out = $this->sh('docker exec ' . escapeshellarg($this->prefix . '-mock') . ' cat /mock/requests.log', $code);
        if ($code !== 0) {
            return array();
        }
        $rows = array();
        foreach (explode("\n", $out) as $line) {
            $data = json_decode($line, true);
            if (is_array($data)) {
                $rows[] = $data;
            }
        }
        return $rows;
    }

    public function mark(): int
    {
        return count($this->mockLog());
    }

    /** @return array[] requests logged after $mark */
    public function since(int $mark): array
    {
        return array_slice($this->mockLog(), $mark);
    }

    /** The post pushed for $tid in requests after $mark (the last one), or null. */
    public function pushed(int $mark, int $tid): ?array
    {
        $found = null;
        foreach ($this->since($mark) as $request) {
            if ($request['method'] === 'PUT' && substr($request['path'], -6) === '/posts' && isset($request['body']['posts'])) {
                foreach ($request['body']['posts'] as $post) {
                    if ((string) ($post['external_id'] ?? '') === (string) $tid) {
                        $found = $post;
                    }
                }
            }
        }
        return $found;
    }

    public function withdrawn(int $mark, int $tid): bool
    {
        foreach ($this->since($mark) as $request) {
            if ($request['method'] === 'POST' && substr($request['path'], -13) === '/posts/delete'
                && in_array((string) $tid, array_map('strval', (array) ($request['body']['external_ids'] ?? array())), true)) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string, array> the posts the mock holds, by external id */
    public function mockPosts(): array
    {
        $out = $this->sh('docker exec ' . escapeshellarg($this->prefix . '-mock') . ' cat /mock/store.json', $code);
        $data = $code === 0 ? json_decode($out, true) : null;
        return is_array($data) && isset($data['posts']) ? $data['posts'] : array();
    }

    public function mockStop(): void
    {
        $this->sh('docker stop -t 1 ' . escapeshellarg($this->prefix . '-mock'));
    }

    public function mockStart(): void
    {
        $this->sh('docker start ' . escapeshellarg($this->prefix . '-mock'));
        for ($i = 0; $i < 50; $i++) {
            $out = $this->web('php -r ' . escapeshellarg('echo @fsockopen("' . $this->prefix . '-mock", 8080) ? "up" : "down";'));
            if (trim($out) === 'up') {
                return;
            }
            usleep(200000);
        }
        throw new RuntimeException('mock did not come back');
    }

    public function mockDelay(int $ms): void
    {
        $cmd = $ms > 0 ? 'echo ' . $ms . ' > /mock/store.json.delay' : 'rm -f /mock/store.json.delay';
        $this->sh('docker exec ' . escapeshellarg($this->prefix . '-mock') . ' sh -c ' . escapeshellarg($cmd));
    }

    // --- plugin state ---------------------------------------------------------------------------

    public function row(int $tid): ?array
    {
        $rows = $this->sql('SELECT state, queued_at IS NOT NULL, category, attempts, last_error, remote FROM pre_momobird_ai_post WHERE tid=' . $tid);
        if (!$rows) {
            return null;
        }
        list($state, $queued, $category, $attempts, $error, $remote) = $rows[0];
        return array('state' => $state, 'queued' => $queued === '1', 'category' => $category, 'attempts' => (int) $attempts, 'last_error' => $error, 'remote' => (int) $remote);
    }

    public function queuedCount(): int
    {
        return (int) $this->one('SELECT COUNT(*) FROM pre_momobird_ai_post WHERE queued_at IS NOT NULL');
    }

    public function rowCount(): int
    {
        return (int) $this->one('SELECT COUNT(*) FROM pre_momobird_ai_post');
    }

    public function kv(string $key): ?array
    {
        $value = $this->one('SELECT v FROM pre_momobird_ai_kv WHERE k=' . self::q($key));
        $data = $value === null ? null : json_decode(stripcslashes($value), true);
        return is_array($data) ? $data : null;
    }

    public static function q(string $value): string
    {
        return "'" . str_replace(array('\\', "'"), array('\\\\', "\\'"), $value) . "'";
    }

    // --- waiting -----------------------------------------------------------------------------------

    /** On FPM: until no PHP worker is busy (the after-response run has finished). */
    public function quiesce(int $seconds = 90): void
    {
        if (!$this->fpm) {
            return;
        }
        $idle = 0;
        $until = microtime(true) + $seconds;
        while (microtime(true) < $until) {
            $status = $this->web('curl -s http://127.0.0.1/fpm-status');
            if (preg_match('/^active processes:\s+(\d+)/m', $status, $m) && (int) $m[1] <= 1) {
                if (++$idle >= 2) {
                    return;
                }
            } else {
                $idle = 0;
            }
            usleep(250000);
        }
        throw new RuntimeException('PHP-FPM workers still busy after ' . $seconds . ' s');
    }

    /**
     * Run the plugin cron through Discuz!: make it due (Task 5 notes), view a page as a guest,
     * wait for the after-response run on FPM, then switch it off again so no other page view
     * runs it by surprise. Returns the page time.
     */
    public function cron(): float
    {
        $this->dz('cron-arm');
        $this->guest->get('forum.php');
        $time = $this->guest->lastTime;
        $this->quiesce();
        $this->sql("UPDATE pre_common_cron SET available=0 WHERE type='plugin'");
        return $time;
    }

    /** After a hooked action: FPM pushes after the response; elsewhere the cron does it. */
    public function settle(): void
    {
        if ($this->fpm) {
            $this->quiesce();
            return;
        }
        $this->cron();
    }

    // --- recording --------------------------------------------------------------------------------

    public function check(string $id, string $name, callable $test): void
    {
        $start = microtime(true);
        try {
            $note = $test();
            $this->results[] = array($id, $name, 'PASS', is_string($note) ? $note : '');
            printf("PASS %-4s %s%s\n", $id, $name, is_string($note) && $note !== '' ? ' (' . $note . ')' : '');
        } catch (Throwable $e) {
            $this->results[] = array($id, $name, 'FAIL', $e->getMessage());
            printf("FAIL %-4s %s\n      %s\n", $id, $name, str_replace("\n", "\n      ", $e->getMessage()));
        }
        unset($start);
    }
}

function it_assert($condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** Visible text of an HTML page, whitespace collapsed. */
function it_text(string $html): string
{
    $html = preg_replace('#<(script|style)\b.*?</\1>#is', '', $html);
    return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $html), ENT_QUOTES, 'UTF-8')));
}

function it_short($value): string
{
    $text = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return mb_strlen($text) > 300 ? mb_substr($text, 0, 300) . '…' : $text;
}
