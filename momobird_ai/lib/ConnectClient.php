<?php
/**
 * Client for MomoBird's connect API. One method per endpoint; every failure is
 * a MomoBird_ConnectError.
 *
 * Nothing is retried here (the sync engine retries hourly), redirects are
 * errors, and the transport is injected so tests need no network.
 */

if (!defined('IN_DISCUZ')) {
    exit('Access Denied');
}

final class MomoBird_ConnectClient
{
    const API_PREFIX = '/momobird/api/public/connect/v1';
    const MAX_RESPONSE_BYTES = 5242880;   // 5 MB
    const MAX_REQUEST_BYTES = 4194304;    // 4 MiB
    const MAX_UPSERT_POSTS = 20;
    const MAX_DELETE_IDS = 100;
    const LIST_LIMIT = 500;
    const MAX_MESSAGE_CHARS = 200;

    /** Length of {"posts":[]} around the posts. */
    const UPSERT_ENVELOPE_BYTES = 12;

    const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    private $settings;
    private $transport;

    public function __construct(MomoBird_Settings $settings, MomoBird_Transport $transport)
    {
        $this->settings = $settings;
        $this->transport = $transport;
    }

    /** Project, source, entitlement and widget state. */
    public function status(): array
    {
        return $this->request('GET', '/status', null);
    }

    /**
     * One page of the posts MomoBird holds for this source.
     *
     * @return array{items: array, next_cursor: ?string}
     */
    public function listPosts(?string $cursor): array
    {
        $query = array('limit' => self::LIST_LIMIT);
        if ($cursor !== null && $cursor !== '') {
            $query['cursor'] = $cursor;
        }
        $data = $this->request('GET', '/posts?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986), null);
        if (!isset($data['items']) || !is_array($data['items'])) {
            throw $this->error(MomoBird_ConnectError::HTTP, 'MomoBird 返回的帖子列表格式不对。', 200, 'malformed_response');
        }
        foreach ($data['items'] as $item) {
            if (!is_array($item) || !isset($item['external_id']) || !is_string($item['external_id'])) {
                throw $this->error(MomoBird_ConnectError::HTTP, 'MomoBird 返回的帖子列表格式不对。', 200, 'malformed_response');
            }
        }
        $next = isset($data['next_cursor']) && is_string($data['next_cursor']) && $data['next_cursor'] !== '' ? $data['next_cursor'] : null;
        return array('items' => $data['items'], 'next_cursor' => $next);
    }

    /**
     * Push posts (external_id, title, content, url, modified_at, category).
     * Split into requests of at most 20 posts and 4 MiB. A post that alone
     * would not fit, or cannot be encoded, is not sent: it gets a `rejected`
     * result (TOO_LARGE / INVALID) in its place, and the rest go on.
     *
     * A post the server's answer does not mention gets a `rejected` /
     * MISSING_RESULT result. If a request fails, the thrown error carries the
     * results so far and the failing batch's ids (MomoBird_ConnectError::withBatch);
     * splitting and re-pushing that batch is the engine's job, not the client's.
     *
     * @return array one result per post, in input order: external_id, document_id, status, error_code
     */
    public function upsert(array $posts): array
    {
        if ($posts) {
            $this->assertUsable();
        }
        $results = array();
        $batch = array();
        $batchIds = array();
        $bytes = self::UPSERT_ENVELOPE_BYTES;

        foreach (array_values($posts) as $post) {
            $json = json_encode($post, self::JSON_FLAGS);
            $id = is_array($post) && isset($post['external_id']) ? (string) $post['external_id'] : '';
            $rejection = null;
            if ($json === false) {
                $rejection = 'INVALID';
            } elseif (self::UPSERT_ENVELOPE_BYTES + strlen($json) > self::MAX_REQUEST_BYTES) {
                $rejection = 'TOO_LARGE';
            }
            if ($rejection !== null) {
                $results = array_merge($results, $this->flushUpsert($batch, $batchIds, $results));
                $batch = array();
                $batchIds = array();
                $bytes = self::UPSERT_ENVELOPE_BYTES;
                $results[] = array('external_id' => $id, 'document_id' => null, 'status' => 'rejected', 'error_code' => $rejection);
                continue;
            }

            $added = strlen($json) + ($batch ? 1 : 0);
            if (count($batch) >= self::MAX_UPSERT_POSTS || $bytes + $added > self::MAX_REQUEST_BYTES) {
                $results = array_merge($results, $this->flushUpsert($batch, $batchIds, $results));
                $batch = array();
                $batchIds = array();
                $bytes = self::UPSERT_ENVELOPE_BYTES;
                $added = strlen($json);
            }
            $batch[] = $json;
            $batchIds[] = $id;
            $bytes += $added;
        }

        return array_merge($results, $this->flushUpsert($batch, $batchIds, $results));
    }

    /**
     * Remove posts by external id, 100 per request.
     *
     * @param array $ids
     * @return array one result per id: external_id, status (deleted|not_found)
     */
    public function delete(array $ids): array
    {
        $ids = array_map('strval', array_values($ids));
        if ($ids) {
            $this->assertUsable();
        }
        $results = array();
        foreach (array_chunk($ids, self::MAX_DELETE_IDS) as $chunk) {
            try {
                $data = $this->request('POST', '/posts/delete', json_encode(array('external_ids' => $chunk), self::JSON_FLAGS));
                $results = array_merge($results, $this->results($data, array('deleted', 'not_found')));
            } catch (MomoBird_ConnectError $error) {
                throw $error->withBatch($results, $chunk);
            }
        }
        return $results;
    }

    /** Turn the project's website widget on. */
    public function enableWidget(): array
    {
        return $this->request('POST', '/widget/enable', '{}');
    }

    /**
     * @param string[] $encodedPosts
     * @param string[] $ids          external_ids of those posts
     * @param array    $doneSoFar    results already collected, for the error
     */
    private function flushUpsert(array $encodedPosts, array $ids, array $doneSoFar): array
    {
        if (!$encodedPosts) {
            return array();
        }
        try {
            $data = $this->request('PUT', '/posts', '{"posts":[' . implode(',', $encodedPosts) . ']}');
            $answers = $this->results($data, array('created', 'updated', 'unchanged', 'rejected'));
        } catch (MomoBird_ConnectError $error) {
            throw $error->withBatch($doneSoFar, $ids);
        }

        // Match by id: the answer may be reordered, padded or short.
        $byId = array();
        foreach ($answers as $answer) {
            $byId[$answer['external_id']] = $answer;
        }
        $results = array();
        foreach ($ids as $id) {
            $results[] = isset($byId[$id])
                ? $byId[$id]
                : array('external_id' => $id, 'document_id' => null, 'status' => 'rejected', 'error_code' => 'MISSING_RESULT');
        }
        return $results;
    }

    private function results(array $data, array $statuses): array
    {
        if (!isset($data['results']) || !is_array($data['results'])) {
            throw $this->error(MomoBird_ConnectError::HTTP, 'MomoBird 返回的同步结果格式不对。', 200, 'malformed_response');
        }
        foreach ($data['results'] as $item) {
            if (!is_array($item) || !isset($item['external_id']) || !is_string($item['external_id'])
                || !in_array(isset($item['status']) ? $item['status'] : '', $statuses, true)) {
                throw $this->error(MomoBird_ConnectError::HTTP, 'MomoBird 返回的同步结果格式不对。', 200, 'malformed_response');
            }
        }
        return array_values($data['results']);
    }

    /**
     * Never send with settings validate() rejects: an http:// Base URL would
     * carry the key in clear text, and a timeout of 0 means "no timeout" in curl.
     */
    private function assertUsable(): void
    {
        $problems = $this->settings->validate();
        if ($problems) {
            throw $this->error(MomoBird_ConnectError::REJECTED, '连接设置有误，没有发送任何请求：' . implode(' ', $problems), 0, 'invalid_settings');
        }
    }

    /** @param ?string $json already encoded request body */
    private function request(string $method, string $path, ?string $json): array
    {
        $this->assertUsable();
        $headers = array(
            'Accept: application/json',
            'Authorization: Bearer ' . $this->settings->key(),
        );
        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        try {
            $response = $this->transport->send($method, $this->settings->baseUrl() . self::API_PREFIX . $path, $headers, $json, $this->settings->timeout());
        } catch (Throwable $e) {
            // Redact before the message becomes an argument of error(): with
            // zend.exception_ignore_args off it would sit in the trace as-is.
            throw $this->error(MomoBird_ConnectError::NETWORK, '无法连接 MomoBird：' . $this->scrub($e->getMessage()));
        }

        if (!is_array($response) || !isset($response['status']) || !is_int($response['status'])
            || !isset($response['body']) || !is_string($response['body'])
            || (isset($response['error']) && !is_string($response['error']))) {
            throw $this->error(MomoBird_ConnectError::NETWORK, '无法连接 MomoBird：网络层返回了无法识别的结果。');
        }
        $status = $response['status'];
        $raw = $response['body'];
        if (!empty($response['error']) || $status === 0) {
            $detail = !empty($response['error']) ? $this->scrub($response['error']) : '没有收到 HTTP 响应';
            throw $this->error(MomoBird_ConnectError::NETWORK, '无法连接 MomoBird：' . $detail);
        }
        if (strlen($raw) > self::MAX_RESPONSE_BYTES) {
            throw $this->error(MomoBird_ConnectError::HTTP, 'MomoBird 的响应超过了 5 MB，已丢弃。', $status, 'response_too_large');
        }
        if ($status === 200) {
            $data = self::unwrap($raw);
            if ($data === null) {
                // Thrown here, not inside unwrap(): a frame with the raw body as argument would sit in the trace.
                throw $this->error(MomoBird_ConnectError::HTTP, 'MomoBird 返回的内容格式不对。', $status, 'malformed_response');
            }
            return $data;
        }
        // Scrubbed first: the body is an argument of failure() and so of its trace frame.
        throw $this->failure($status, $this->scrub($raw));
    }

    private static function unwrap(string $raw): ?array
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['code']) || $decoded['code'] !== 'OK'
            || !array_key_exists('data', $decoded) || !is_array($decoded['data'])) {
            return null;
        }
        return $decoded['data'];
    }

    private function failure(int $status, string $raw): MomoBird_ConnectError
    {
        if ($status >= 300 && $status < 400) {
            return $this->error(MomoBird_ConnectError::HTTP, 'MomoBird 返回了跳转（HTTP ' . $status . '），插件不会跟随跳转，请检查 Base URL。', $status, 'redirect');
        }

        $code = 'http_' . $status;
        $serverMessage = '';
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $source = isset($decoded['error']) && is_array($decoded['error']) ? $decoded['error'] : $decoded;
            if (!empty($source['code']) && is_string($source['code'])) {
                $clean = substr(preg_replace('/[^A-Za-z0-9_.-]/', '', $source['code']), 0, 64);
                if ($clean !== '') {
                    $code = $clean;
                }
            }
            if (!empty($source['message']) && is_string($source['message'])) {
                $serverMessage = $this->truncate(trim(strip_tags($source['message'])));
            }
        }

        $kind = MomoBird_ConnectError::HTTP;
        $default = 'MomoBird 请求失败（HTTP ' . $status . '）。';
        if ($status === 401) {
            $kind = MomoBird_ConnectError::UNAUTHORIZED;
            $default = '连接密钥无效或已被撤销，请到 MomoBird 控制台重新复制。';
        } elseif ($status === 402) {
            $kind = MomoBird_ConnectError::PLAN_REQUIRED;
            $default = '当前套餐不支持同步，请在 MomoBird 控制台升级或兑换。';
        } elseif ($status === 409 && $code === 'SOURCE_DISABLED') {
            $kind = MomoBird_ConnectError::DISABLED;
            $default = '这个来源已在 MomoBird 控制台停用。';
        } elseif ($status === 409 && $code === 'WIDGET_NOT_READY') {
            $kind = MomoBird_ConnectError::WIDGET_NOT_READY;
            $default = '项目的网站接入还没有准备好，请先在 MomoBird 控制台完成设置。';
        } elseif ($status === 413) {
            $kind = MomoBird_ConnectError::TOO_LARGE;
            $default = '请求内容太大，MomoBird 拒绝了（HTTP 413）。';
        } elseif ($status === 400 || $status === 422) {
            $kind = MomoBird_ConnectError::REJECTED;
            $default = 'MomoBird 拒绝了这次请求的内容（HTTP ' . $status . '）。';
        }

        return $this->error($kind, $serverMessage !== '' ? $serverMessage : $default, $status, $code);
    }

    private function error(string $kind, string $message, int $status = 0, string $code = ''): MomoBird_ConnectError
    {
        return new MomoBird_ConnectError($kind, $message, $status, $code, $this->settings->key());
    }

    private function scrub(string $text): string
    {
        return MomoBird_Redactor::clean($text, $this->settings->key());
    }

    /** At most MAX_MESSAGE_CHARS characters, never cutting a UTF-8 sequence. */
    private function truncate(string $text): string
    {
        if (preg_match('/^.{0,' . self::MAX_MESSAGE_CHARS . '}/su', $text, $m) === 1) {
            return $m[0];
        }
        return substr(preg_replace('/[^\x20-\x7E]/', '?', $text), 0, self::MAX_MESSAGE_CHARS);
    }
}
