<?php

/**
 * Test-only transport over PHP streams, to drive the real client against the
 * mock connect server. Does not follow redirects.
 */
class MomoBird_StreamTransport implements MomoBird_Transport
{
    public function send(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        $http = array('method' => $method, 'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => $timeout);
        if ($body !== null) {
            $http['content'] = $body;
        }
        $handle = @fopen($url, 'r', false, stream_context_create(array('http' => $http)));
        if ($handle === false) {
            return array('status' => 0, 'body' => '', 'error' => 'connection failed');
        }
        $response = stream_get_contents($handle);
        $meta = stream_get_meta_data($handle);
        fclose($handle);
        $responseHeaders = isset($meta['wrapper_data']) ? $meta['wrapper_data'] : array();
        if ($response === false || empty($responseHeaders)) {
            return array('status' => 0, 'body' => '', 'error' => 'connection failed');
        }
        preg_match('#^HTTP/\S+\s+(\d{3})#', $responseHeaders[0], $m);
        return array('status' => (int) ($m[1] ?? 0), 'body' => $response, 'error' => null);
    }
}
