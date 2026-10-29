<?php
/**
 * MomoBird_Transport on top of Discuz!'s own `filesock`.
 *
 * Why not dfsockopen(): it drops the timeout and cannot send headers or a
 * JSON body. Discuz! also leaves TLS verification off by default, so every
 * request here sets verifypeer. Redirects are never followed: filesock's cURL
 * backend does not, but its stream backend does (and would resend the
 * Authorization header), so without cURL nothing is sent at all.
 *
 * Only runs inside Discuz!; kept thin on purpose (unit tests use a stub).
 */
final class MomoBird_FilesockTransport implements MomoBird_Transport
{
    const USER_AGENT = 'MomoBirdDiscuz/0.1.0';
    const MAX_RESPONSE_BYTES = 5242880;
    const MAX_CONNECT_TIMEOUT = 5;

    private $unsafe;
    private $curlAvailable;

    /**
     * @param bool      $unsafe        turn off Discuz!'s SSRF guard (private addresses, ports); for the
     *                                 local mock server only, never for a real Base URL
     * @param bool|null $curlAvailable override the cURL check (tests)
     */
    public function __construct(bool $unsafe = false, ?bool $curlAvailable = null)
    {
        $this->unsafe = $unsafe;
        $this->curlAvailable = $curlAvailable;
    }

    public function send(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        if (!class_exists('filesock')) {
            return self::failure('找不到 Discuz! 的网络组件 filesock，无法发送请求。');
        }
        $curl = $this->curlAvailable !== null ? $this->curlAvailable : (function_exists('curl_init') && function_exists('curl_exec'));
        if (!$curl) {
            return self::failure('服务器没有启用 PHP 的 cURL 扩展，插件不会用无法保证安全的方式发送连接密钥。请先启用 cURL。');
        }

        $param = array(
            'url' => $url,
            'method' => $method,
            'header' => self::headerMap($headers),
            'timeout' => $timeout,
            'conntimeout' => min($timeout, self::MAX_CONNECT_TIMEOUT),
            'failonerror' => false,
            'returnbody' => true,
            'verifypeer' => self::verifyPeer(),
            'useragent' => self::USER_AGENT,
            'limit' => self::MAX_RESPONSE_BYTES + 1,
            'allowcurl' => true,
        );
        if ($body !== null) {
            $param['rawdata'] = $body;
            $param['encodetype'] = 'application/json';
        }
        if ($this->unsafe) {
            $param['unsafe'] = true;
        }

        try {
            $fs = filesock::open($param);
            $result = $fs->request();
        } catch (Throwable $e) {
            return self::failure('请求失败：' . $e->getMessage());
        }

        if ($fs->safequery === false) {
            return self::failure('Discuz! 的安全设置（fsockopensafe）拦截了这个地址：内网地址和非 80/443 端口默认不允许访问。');
        }
        if ($fs->errno) {
            return self::failure('网络请求失败（错误码 ' . $fs->errno . '）：' . $fs->errstr);
        }
        $status = is_array($fs->curlstatus) && isset($fs->curlstatus['http_code']) ? (int) $fs->curlstatus['http_code'] : 0;
        if ($status === 0) {
            return self::failure('没有收到 HTTP 响应。');
        }

        return array('status' => $status, 'body' => (string) $result, 'error' => null);
    }

    /**
     * TLS verification is always on. If the admin configured a CA file in
     * $_config['security']['fsockopensafe']['verifypeer'] (a path string),
     * keep it: on hosts without a system CA bundle it is the only way that works.
     *
     * @return bool|string
     */
    private static function verifyPeer()
    {
        $configured = isset($GLOBALS['_G']['config']['security']['fsockopensafe']['verifypeer'])
            ? $GLOBALS['_G']['config']['security']['fsockopensafe']['verifypeer']
            : null;
        return is_string($configured) && $configured !== '' ? $configured : true;
    }

    /** @param string[] $lines "Name: value" */
    private static function headerMap(array $lines): array
    {
        $map = array();
        foreach ($lines as $line) {
            $pos = strpos((string) $line, ':');
            if ($pos === false || $pos === 0) {
                continue;
            }
            $map[trim(substr($line, 0, $pos))] = trim(substr($line, $pos + 1));
        }
        return $map;
    }

    private static function failure(string $message): array
    {
        return array('status' => 0, 'body' => '', 'error' => $message);
    }
}
