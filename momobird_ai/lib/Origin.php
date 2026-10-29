<?php
/**
 * Same-origin check between the forum address and the MomoBird source address.
 */

if (!defined('IN_DISCUZ')) {
    exit('Access Denied');
}

final class MomoBird_Origin
{
    /**
     * True when both are http(s) URLs with the same scheme, host and port.
     * The path is ignored; "www." is part of the host. Unusable URLs never match.
     */
    public static function same(string $a, string $b): bool
    {
        $first = self::origin($a);
        $second = self::origin($b);
        return $first !== null && $first === $second;
    }

    private static function origin(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }
        $host = rtrim(strtolower($parts['host']), '.');
        if ($host === '') {
            return null;
        }
        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        return $scheme . '://' . $host . ':' . $port;
    }
}
