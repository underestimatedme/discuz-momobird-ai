<?php
/**
 * Removes the connection key (and anything shaped like one) from text that is
 * logged, stored, or shown to a user.
 */

if (!defined('IN_DISCUZ')) {
    exit('Access Denied');
}

final class MomoBird_Redactor
{
    const PLACEHOLDER = '[已隐藏的密钥]';

    public static function clean(string $text, string $key): string
    {
        $key = trim($key);
        if (strlen($key) >= 4) {
            $variants = array_unique(array($key, rawurlencode($key), urlencode($key)));
            // Longest first so an encoded form is not half-replaced by a shorter one.
            usort($variants, function ($a, $b) {
                return strlen($b) - strlen($a);
            });
            $text = str_replace($variants, self::PLACEHOLDER, $text);
        }
        // Any key, not only the configured one (a stale key, one echoed by a server).
        $text = preg_replace('/mbk_[A-Za-z0-9_\-]*/i', self::PLACEHOLDER, $text);
        // Whatever follows "Bearer" in an echoed Authorization header.
        $text = preg_replace('/(Bearer\s+)[A-Za-z0-9._~+\/=\-]+/i', '$1' . self::PLACEHOLDER, $text);
        return $text;
    }
}
