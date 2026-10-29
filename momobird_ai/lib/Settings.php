<?php
/**
 * Plugin settings as an immutable value. Built from stored/submitted values by
 * fromArray(), which never throws: validate() lists what is wrong instead.
 *
 * The connection key is write-only: toArray() leaves it out, keyPrefix() shows
 * 8 characters, and debug dumps hide it.
 */

if (!defined('IN_DISCUZ')) {
    exit('Access Denied');
}

final class MomoBird_Settings
{
    const DEFAULT_BASE_URL = 'https://api.valley.atlaspaces.com';
    const DEFAULT_TIMEOUT = 10;
    const MIN_TIMEOUT = 2;
    const MAX_TIMEOUT = 30;
    const KEY_PATTERN = '/^mbk_[A-Za-z0-9_-]{16,200}$/D';
    const DISCUZ_DEFAULT_SITE = 'www.discuz.vip';

    /** Hosts that may use plain http:// for Base URL. */
    private static $localHosts = array('localhost', '127.0.0.1', '[::1]', 'host.docker.internal');

    private $baseUrl;
    /**
     * The key lives behind a closure, not in a plain property: var_export and
     * (array) casts then show an empty Closure instead of the secret.
     */
    private $readKey;
    private $timeout;
    private $forumUrl;
    private $forumIds;
    private $widgetEnabled;
    private $widgetPosition;
    private $podcastEnabled;
    private $podcastTranscript;
    private $httpHosts;

    private function __construct(string $baseUrl, string $key, int $timeout, string $forumUrl, ?array $forumIds, bool $widgetEnabled, string $widgetPosition, bool $podcastEnabled, bool $podcastTranscript, array $httpHosts)
    {
        $this->baseUrl = $baseUrl;
        $this->readKey = static function () use ($key) {
            return $key;
        };
        $this->timeout = $timeout;
        $this->forumUrl = $forumUrl;
        $this->forumIds = $forumIds;
        $this->widgetEnabled = $widgetEnabled;
        $this->widgetPosition = $widgetPosition;
        $this->podcastEnabled = $podcastEnabled;
        $this->podcastTranscript = $podcastTranscript;
        $this->httpHosts = $httpHosts;
    }

    /**
     * @param array    $values        keys baseUrl, key, timeout, forumUrl, forumIds, widgetEnabled,
     *                                widgetPosition, podcastEnabled, podcastTranscript (all optional)
     * @param string[] $extraHttpHosts hosts besides the local ones that may use http:// (the
     *                                simulated connect server in integration tests)
     */
    public static function fromArray(array $values, array $extraHttpHosts = array()): self
    {
        $baseUrl = isset($values['baseUrl']) ? trim((string) $values['baseUrl']) : self::DEFAULT_BASE_URL;
        $timeout = self::DEFAULT_TIMEOUT;
        if (isset($values['timeout']) && $values['timeout'] !== '') {
            $timeout = is_numeric($values['timeout']) ? (int) $values['timeout'] : 0;
        }
        $position = isset($values['widgetPosition']) && $values['widgetPosition'] === 'left' ? 'left' : 'right';

        return new self(
            rtrim($baseUrl, '/'),
            isset($values['key']) ? trim((string) $values['key']) : '',
            $timeout,
            isset($values['forumUrl']) ? trim((string) $values['forumUrl']) : '',
            self::forumIdsFrom($values['forumIds'] ?? null),
            self::flag($values, 'widgetEnabled', false),
            $position,
            self::flag($values, 'podcastEnabled', true),
            self::flag($values, 'podcastTranscript', false),
            array_map('strtolower', array_map('strval', $extraHttpHosts))
        );
    }

    /** Chinese error messages, empty when the settings are usable. */
    public function validate(): array
    {
        $errors = array();

        $problem = $this->baseUrlProblem();
        if ($problem !== null) {
            $errors[] = $problem;
        }
        if (!preg_match(self::KEY_PATTERN, $this->key())) {
            $errors[] = '连接密钥格式不对：应以 mbk_ 开头，后面只有字母、数字、下划线和短横线，请到 MomoBird 控制台的「网站接入」里重新复制。';
        }
        if ($this->timeout < self::MIN_TIMEOUT || $this->timeout > self::MAX_TIMEOUT) {
            $errors[] = '超时时间需要在 ' . self::MIN_TIMEOUT . ' 到 ' . self::MAX_TIMEOUT . ' 秒之间。';
        }
        $problem = $this->forumUrlProblem();
        if ($problem !== null) {
            $errors[] = $problem;
        }

        return $errors;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /** The connection key. Never log, store in plain tables, or print it. */
    public function key(): string
    {
        return (string) call_user_func($this->readKey);
    }

    public function timeout(): int
    {
        return $this->timeout;
    }

    public function forumUrl(): string
    {
        return $this->forumUrl;
    }

    /** null = every guest-visible forum; otherwise the chosen forum ids (may be empty). */
    public function forumIds(): ?array
    {
        return $this->forumIds;
    }

    public function widgetEnabled(): bool
    {
        return $this->widgetEnabled;
    }

    /** 'right' or 'left'. */
    public function widgetPosition(): string
    {
        return $this->widgetPosition;
    }

    public function podcastEnabled(): bool
    {
        return $this->podcastEnabled;
    }

    public function podcastTranscript(): bool
    {
        return $this->podcastTranscript;
    }

    /** First 8 characters of the key: what the settings page may show. */
    public function keyPrefix(): string
    {
        return substr($this->key(), 0, 8);
    }

    /** Everything except the key; fromArray($array + ['key' => ...]) rebuilds the settings. */
    public function toArray(): array
    {
        return array(
            'baseUrl' => $this->baseUrl,
            'timeout' => $this->timeout,
            'forumUrl' => $this->forumUrl,
            'forumIds' => $this->forumIds,
            'widgetEnabled' => $this->widgetEnabled,
            'widgetPosition' => $this->widgetPosition,
            'podcastEnabled' => $this->podcastEnabled,
            'podcastTranscript' => $this->podcastTranscript,
        );
    }

    /** Serialized form: everything but the key (a restored object has an empty key). */
    public function __serialize(): array
    {
        return $this->toArray() + array('httpHosts' => $this->httpHosts);
    }

    public function __unserialize(array $data): void
    {
        $this->baseUrl = (string) ($data['baseUrl'] ?? '');
        $this->readKey = static function () {
            return '';
        };
        $this->timeout = (int) ($data['timeout'] ?? self::DEFAULT_TIMEOUT);
        $this->forumUrl = (string) ($data['forumUrl'] ?? '');
        $this->forumIds = self::forumIdsFrom($data['forumIds'] ?? null);
        $this->widgetEnabled = !empty($data['widgetEnabled']);
        $this->widgetPosition = ($data['widgetPosition'] ?? '') === 'left' ? 'left' : 'right';
        $this->podcastEnabled = !array_key_exists('podcastEnabled', $data) || !empty($data['podcastEnabled']);
        $this->podcastTranscript = !empty($data['podcastTranscript']);
        $this->httpHosts = isset($data['httpHosts']) && is_array($data['httpHosts']) ? array_map('strval', $data['httpHosts']) : array();
    }

    /** var_dump/print_r show the same as toArray() plus the key prefix. */
    public function __debugInfo()
    {
        return $this->toArray() + array('keyPrefix' => $this->keyPrefix());
    }

    private function baseUrlProblem(): ?string
    {
        if ($this->baseUrl === '') {
            return '请填写 MomoBird 的 Base URL。';
        }
        $parts = parse_url($this->baseUrl);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return 'Base URL 不是有效的网址。';
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return 'Base URL 不能包含账号、查询参数或锚点。';
        }
        if (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/') {
            return 'Base URL 只填到域名，不要带路径。';
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $localHttp = $scheme === 'http' && (in_array($host, self::$localHosts, true) || in_array($host, $this->httpHosts, true));
        if ($scheme !== 'https' && !$localHttp) {
            return 'Base URL 必须以 https:// 开头（只有本机地址可以用 http://）。';
        }
        return null;
    }

    private function forumUrlProblem(): ?string
    {
        if ($this->forumUrl === '') {
            return '请填写论坛地址。';
        }
        $parts = parse_url($this->forumUrl);
        if (!is_array($parts) || empty($parts['host']) || empty($parts['scheme'])
            || !in_array(strtolower($parts['scheme']), array('http', 'https'), true)
            || isset($parts['user']) || isset($parts['pass'])) {
            return '论坛地址需要是以 http:// 或 https:// 开头的完整网址。';
        }
        $path = isset($parts['path']) ? $parts['path'] : '';
        if (strtolower($parts['host']) === self::DISCUZ_DEFAULT_SITE && ($path === '' || $path === '/')) {
            return '论坛地址还是 Discuz! 的默认值 https://www.discuz.vip/，请改成你论坛的访问地址。';
        }
        return null;
    }

    private static function flag(array $values, string $name, bool $default): bool
    {
        if (!array_key_exists($name, $values) || $values[$name] === null) {
            return $default;
        }
        $value = $values[$name];
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower((string) $value), array('1', 'true', 'on', 'yes'), true);
    }

    private static function forumIdsFrom($value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            $value = $value === '' ? array() : explode(',', $value);
        }
        if (!is_array($value)) {
            return null;
        }
        $ids = array();
        foreach ($value as $item) {
            if (is_int($item) || (is_string($item) && ctype_digit(trim($item)))) {
                $id = (int) $item;
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
        }
        $ids = array_values($ids);
        sort($ids);
        return $ids;
    }
}
