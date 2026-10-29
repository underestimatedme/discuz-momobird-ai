<?php
/**
 * One HTTP request, kept tiny so the connect client can be tested without a
 * network and Discuz!'s filesock can sit behind it.
 *
 * Implementations must never throw and must never follow redirects: a 3xx
 * comes back as a normal status and the client treats it as an error.
 */
interface MomoBird_Transport
{
    /**
     * @param string[] $headers request header lines, "Name: value"
     * @return array{status:int, body:string, error:?string} status 0 and a
     *         non-null error mean the request never produced an HTTP response
     */
    public function send(string $method, string $url, array $headers, ?string $body, int $timeout): array;
}
