<?php

require_once __DIR__ . '/FakeTransport.php';

/**
 * An in-memory MomoBird connect server behind a FakeTransport: keeps the posts
 * it was sent, answers status/list/upsert/delete/widget, and fails on request.
 */
class MomoBird_FakeConnect
{
    /** @var array<string, array> external_id => last payload */
    public $posts = array();
    public $kind = 'discuz';
    public $sourceId = 'src-1';
    public $siteUrl = 'https://bbs.example.com';
    public $entitlementActive = true;
    public $widget = array('public_id' => 'pub_test', 'available' => false, 'reason' => 'not_published');
    public $pageSize = 500;
    /** @var array<string, string> external_id => error code for a per-post rejection */
    public $rejectIds = array();
    /** @var string[] ids that make the whole PUT fail with 400 */
    public $poison = array();
    /** @var MomoBird_FakeTransport */
    public $transport;

    private $failures = array();

    public function __construct()
    {
        $self = $this;
        $this->transport = new MomoBird_FakeTransport(function ($method, $url, $headers, $body) use ($self) {
            return $self->handle($method, $url, $body);
        });
    }

    /**
     * Fail the next $times requests whose "METHOD /path" starts with $route.
     * $status 0 = network error.
     */
    public function fail(string $route, int $status, string $code = '', int $times = 1): self
    {
        $this->failures[] = array('route' => $route, 'status' => $status, 'code' => $code, 'times' => $times);
        return $this;
    }

    /** Seed posts MomoBird already holds (ids only). */
    public function seed(array $ids): self
    {
        foreach ($ids as $id) {
            $this->posts[(string) $id] = array('external_id' => (string) $id, 'title' => 'seeded');
        }
        return $this;
    }

    /** @return string[] "METHOD /path" of every request */
    public function routes(): array
    {
        $out = array();
        foreach ($this->transport->requests as $request) {
            $out[] = $request['method'] . ' ' . self::path($request['url']);
        }
        return $out;
    }

    public function count(string $route): int
    {
        $n = 0;
        foreach ($this->routes() as $r) {
            $n += $r === $route ? 1 : 0;
        }
        return $n;
    }

    /** @return array[] decoded bodies of the requests on a route */
    public function bodies(string $route): array
    {
        $out = array();
        foreach ($this->transport->requests as $request) {
            if ($request['method'] . ' ' . self::path($request['url']) === $route) {
                $out[] = json_decode((string) $request['body'], true);
            }
        }
        return $out;
    }

    /** @return string[] external ids pushed, in order */
    public function pushedIds(): array
    {
        $ids = array();
        foreach ($this->bodies('PUT /posts') as $body) {
            foreach ($body['posts'] as $post) {
                $ids[] = $post['external_id'];
            }
        }
        return $ids;
    }

    /** @return string[] external ids sent for deletion, in order */
    public function deletedIds(): array
    {
        $ids = array();
        foreach ($this->bodies('POST /posts/delete') as $body) {
            $ids = array_merge($ids, $body['external_ids']);
        }
        return $ids;
    }

    /** Requests that change MomoBird (push, delete, widget). */
    public function writes(): array
    {
        return array_values(array_filter($this->routes(), function ($r) {
            return strpos($r, 'GET ') !== 0;
        }));
    }

    public function handle(string $method, string $url, ?string $body): array
    {
        $path = self::path($url);
        $route = $method . ' ' . $path;
        foreach ($this->failures as $i => $failure) {
            if (strpos($route, $failure['route']) === 0 && $failure['times'] > 0) {
                $this->failures[$i]['times']--;
                if ($failure['status'] === 0) {
                    return array('status' => 0, 'body' => '', 'error' => 'connection refused');
                }
                return array('status' => $failure['status'], 'body' => json_encode(array('code' => $failure['code'] ?: 'ERR', 'message' => 'failed ' . $failure['status'])));
            }
        }

        $data = json_decode((string) $body, true);
        switch ($route) {
            case 'GET /status':
                return self::ok(array(
                    'project' => array('id' => 'p1', 'name' => '测试项目'),
                    'source' => array('id' => $this->sourceId, 'kind' => $this->kind, 'name' => '论坛', 'site_url' => $this->siteUrl,
                        'document_count' => count($this->posts), 'ready_document_count' => count($this->posts)),
                    'entitlement' => array('status' => $this->entitlementActive ? 'pro' : 'expired', 'active' => $this->entitlementActive),
                    'widget' => $this->widget,
                ));
            case 'GET /posts':
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                $ids = array_keys($this->posts);
                sort($ids);
                $offset = isset($query['cursor']) ? (int) $query['cursor'] : 0;
                $page = array_slice($ids, $offset, $this->pageSize);
                $items = array();
                foreach ($page as $id) {
                    $items[] = array('external_id' => (string) $id);
                }
                $next = $offset + $this->pageSize < count($ids) ? (string) ($offset + $this->pageSize) : null;
                return self::ok(array('items' => $items, 'next_cursor' => $next));
            case 'PUT /posts':
                foreach ($data['posts'] as $post) {
                    if (in_array($post['external_id'], $this->poison, true)) {
                        return array('status' => 400, 'body' => json_encode(array('code' => 'VALIDATION_FAILED', 'message' => 'bad post')));
                    }
                }
                $results = array();
                foreach ($data['posts'] as $post) {
                    $id = $post['external_id'];
                    if (isset($this->rejectIds[$id])) {
                        $results[] = array('external_id' => $id, 'document_id' => null, 'status' => 'rejected', 'error_code' => $this->rejectIds[$id]);
                        continue;
                    }
                    $status = !isset($this->posts[$id]) ? 'created' : ($this->posts[$id] == $post ? 'unchanged' : 'updated');
                    $this->posts[$id] = $post;
                    $results[] = array('external_id' => $id, 'document_id' => 'd' . $id, 'status' => $status, 'error_code' => null);
                }
                return self::ok(array('results' => $results));
            case 'POST /posts/delete':
                $results = array();
                foreach ($data['external_ids'] as $id) {
                    $results[] = array('external_id' => $id, 'status' => isset($this->posts[$id]) ? 'deleted' : 'not_found');
                    unset($this->posts[$id]);
                }
                return self::ok(array('results' => $results));
            case 'POST /widget/enable':
                $this->widget = array('public_id' => 'pub_test', 'available' => true, 'reason' => 'ok');
                return self::ok($this->widget);
        }
        return array('status' => 404, 'body' => '{"code":"NOT_FOUND"}');
    }

    private static function ok(array $data): array
    {
        return array('status' => 200, 'body' => json_encode(array('code' => 'OK', 'data' => $data)));
    }

    private static function path(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $prefix = MomoBird_ConnectClient::API_PREFIX;
        return strpos($path, $prefix) === 0 ? substr($path, strlen($prefix)) : $path;
    }
}
