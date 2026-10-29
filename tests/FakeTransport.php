<?php

/**
 * Scripted transport for connect client tests: answers from a queue (or a
 * default responder) and records every request.
 */
class MomoBird_FakeTransport implements MomoBird_Transport
{
    /** @var array<int, array<string, mixed>> */
    public $requests = array();

    private $queue = array();
    private $default;

    /**
     * @param callable|null $default answers every request once the queue is empty
     */
    public function __construct(?callable $default = null)
    {
        $this->default = $default;
    }

    /**
     * Queue a response: an array {status, body, error}, or a callable returning one.
     *
     * @param array|callable $response
     */
    public function push($response): self
    {
        $this->queue[] = $response;
        return $this;
    }

    public function send(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body', 'timeout');
        if ($this->queue) {
            $next = array_shift($this->queue);
        } elseif ($this->default !== null) {
            $next = $this->default;
        } else {
            throw new RuntimeException('FakeTransport: no response queued for ' . $method . ' ' . $url);
        }
        if (is_callable($next)) {
            $next = call_user_func($next, $method, $url, $headers, $body, $timeout);
        }
        return $next + array('status' => 0, 'body' => '', 'error' => null);
    }

    /** JSON of the n-th request body decoded to an array. */
    public function json(int $index): array
    {
        return json_decode((string) $this->requests[$index]['body'], true);
    }
}
