<?php
/**
 * Turns Discuz! post bodies (BBCode, or X5 EditorJS JSON) into the plain text
 * that goes into the MomoBird knowledge base.
 *
 * The knowledge base answers anonymous visitors, so everything a guest cannot
 * read on the thread page (reply/credit/day-gated [hide], quotes, attachments,
 * special-thread data after three NULs, ...) must never come out of here.
 * Whenever the markup is ambiguous (unclosed tags, nesting) the converter
 * drops more rather than less.
 */
class MomoBird_ContentText
{
    /** Tags that wrap hidden or duplicated content; nesting is tracked and an unclosed one swallows the rest. */
    private static $guardedTags = array('hide', 'quote', 'password', 'groupid', 'postappend');

    /** Tags whose whole span is dropped (no nesting expected). */
    private static $dropTags = array('attach', 'attachimg', 'img', 'media', 'audio', 'flash', 'swf', 'payto');

    /** Tags that only format: the markup goes, the text stays. */
    private static $keepTextTags = array('b', 'i', 'u', 's', 'p', 'color', 'backcolor', 'size', 'font', 'align', 'indent', 'float', 'free', 'md', 'url', 'code', 'email', 'table', 'tr', 'th', 'td', 'list');

    /**
     * Discuz! post message (BBCode, optionally with HTML) to plain text.
     */
    public static function fromBbcode($message)
    {
        $text = self::utf8((string) $message);
        $text = str_replace(array("\r\n", "\r"), "\n", $text);

        // Special thread data (poll options, activity fields, ...) follows three NULs.
        $nul = strpos($text, "\0\0\0");
        if ($nul !== false) {
            $text = substr($text, 0, $nul);
        }
        $text = str_replace("\0", '', $text);

        $text = self::removeHidden($text);

        // Table: cells separated by a space, rows by a line break.
        $text = self::replace('/\[\/t[dh]\]/i', ' ', $text);
        $text = self::replace('/\[\/tr\]/i', "\n", $text);
        // Lists: every [*] starts a "- " line.
        $text = self::replace('/\s*\[list(?:=[^\]]{0,200})?\]\s*/i', "\n", $text);
        $text = self::replace('/\s*\[\*\]\s*/', "\n- ", $text);
        $text = self::replace('/\s*\[\/list\]/i', "\n", $text);

        $text = self::stripKnownTags($text);
        $text = self::htmlToText($text);
        // Smilies such as {:1_1:}.
        $text = self::replace('/\{:[^{}:\s]{1,32}:\}/', '', $text);

        return self::normalizeSpace($text);
    }

    /**
     * X5 EditorJS JSON to plain text. Null when the input is not JSON or has no blocks.
     */
    public static function fromX5Json($json)
    {
        $data = json_decode((string) $json, true);
        if (!is_array($data)) {
            return null;
        }

        $blocks = null;
        if (isset($data['content'])) {
            $content = $data['content'];
            if (is_string($content)) {
                $content = json_decode($content, true);
            }
            if (is_array($content) && isset($content['blocks']) && is_array($content['blocks'])) {
                $blocks = $content['blocks'];
            }
        }
        if ($blocks === null && isset($data['blocks']) && is_array($data['blocks'])) {
            $blocks = $data['blocks'];
        }
        if (!$blocks) {
            return null;
        }

        $parts = array();
        foreach ($blocks as $block) {
            $part = self::blockText($block);
            if ($part !== '') {
                $parts[] = $part;
            }
        }

        return implode("\n\n", $parts);
    }

    /**
     * Title line plus body, the document the knowledge base stores. Empty when there is no body.
     */
    public static function document($title, $body)
    {
        $body = trim((string) $body);
        if ($body === '') {
            return '';
        }

        $title = self::utf8((string) $title);
        $title = html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = trim(self::replace('/[\s\x{00A0}\x{3000}]+/u', ' ', $title));
        if ($title === '') {
            return $body;
        }

        return '# ' . $title . "\n\n" . $body;
    }

    private static function blockText($block)
    {
        if (!is_array($block)) {
            return '';
        }
        $type = isset($block['type']) && is_string($block['type']) ? strtolower($block['type']) : '';
        $data = isset($block['data']) && is_array($block['data']) ? $block['data'] : array();

        switch ($type) {
            case 'image':
            case 'video':
            case 'audio':
            case 'attaches':
                return '';
            case 'header':
                $text = self::inlineText(isset($data['text']) ? $data['text'] : '');
                if ($text === '') {
                    return '';
                }
                $level = isset($data['level']) ? (int) $data['level'] : 2;
                $level = max(1, min(6, $level));
                return str_repeat('#', $level) . ' ' . $text;
            case 'list':
                $style = isset($data['style']) && is_string($data['style']) ? strtolower($data['style']) : 'unordered';
                $items = isset($data['items']) && is_array($data['items']) ? $data['items'] : array();
                return implode("\n", self::listLines($items, $style === 'ordered', 0));
            case 'code':
                return self::codeText(isset($data['code']) ? $data['code'] : '');
            case 'quote':
            case 'paragraph':
            default:
                return self::paragraphText(isset($data['text']) ? $data['text'] : '');
        }
    }

    private static function paragraphText($value)
    {
        if (!is_string($value)) {
            return '';
        }
        $text = self::removeHidden(self::utf8($value));
        return self::normalizeSpace(self::htmlToText($text));
    }

    private static function inlineText($value)
    {
        return trim(self::replace('/\s*\n\s*/', ' ', self::paragraphText($value)));
    }

    private static function codeText($value)
    {
        if (!is_string($value)) {
            return '';
        }
        $text = self::removeHidden(self::utf8($value));
        $text = str_replace(array("\r\n", "\r"), "\n", $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim($text, "\n");
    }

    private static function listLines($items, $ordered, $depth)
    {
        $lines = array();
        $number = 0;
        foreach ($items as $item) {
            $nested = array();
            if (is_array($item)) {
                $label = isset($item['content']) ? $item['content'] : (isset($item['text']) ? $item['text'] : '');
                if (isset($item['items']) && is_array($item['items'])) {
                    $nested = $item['items'];
                }
            } else {
                $label = $item;
            }
            $text = self::inlineText($label);
            if ($text !== '') {
                $number++;
                $lines[] = str_repeat('  ', $depth) . ($ordered ? $number . '. ' : '- ') . $text;
            }
            if ($nested) {
                $lines = array_merge($lines, self::listLines($nested, $ordered, $depth + 1));
            }
        }
        return $lines;
    }

    /**
     * Drops every span a guest cannot read. Fails closed on unbalanced markup.
     */
    private static function removeHidden($text)
    {
        // "本帖最后由 ... 编辑" notes.
        $text = self::dropSpans($text, '/\[i=s\]/i', '[/i]');

        $text = self::removeGuardedSpans($text);

        foreach (self::$dropTags as $tag) {
            $text = self::dropSpans($text, '/\[' . $tag . '(?:=[^\]]{0,200})?\]/i', '[/' . $tag . ']');
        }

        return $text;
    }

    /**
     * Removes [hide]/[quote]/... spans with proper nesting. A span that is never
     * closed runs to the end of the text.
     */
    private static function removeGuardedSpans($text)
    {
        $names = implode('|', self::$guardedTags);
        $count = preg_match_all('/\[(' . $names . ')(?:=[^\]]{0,200})?\]|\[\/(' . $names . ')\]/i', $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        if ($count === false) {
            return '';
        }
        if ($count === 0) {
            return $text;
        }

        $stack = array();
        $ranges = array();
        foreach ($matches as $match) {
            $start = $match[0][1];
            $end = $start + strlen($match[0][0]);
            if (isset($match[2]) && $match[2][1] >= 0 && $match[2][0] !== '') {
                // Closing tag: close the nearest open tag of the same name, and everything opened inside it.
                $name = strtolower($match[2][0]);
                for ($i = count($stack) - 1; $i >= 0; $i--) {
                    if ($stack[$i][0] === $name) {
                        if ($i === 0) {
                            $ranges[] = array($stack[0][1], $end);
                        }
                        while (count($stack) > $i) {
                            array_pop($stack);
                        }
                        break;
                    }
                }
            } else {
                $stack[] = array(strtolower($match[1][0]), $start);
            }
        }
        if ($stack) {
            $ranges[] = array($stack[0][1], strlen($text));
        }

        $out = '';
        $position = 0;
        foreach ($ranges as $range) {
            $out .= substr($text, $position, $range[0] - $position);
            $position = $range[1];
        }
        return $out . substr($text, $position);
    }

    private static function stripKnownTags($text)
    {
        $names = implode('|', array_merge(self::$keepTextTags, self::$dropTags, self::$guardedTags));
        return self::replace('/\[\/?(?:' . $names . ')(?:=[^\]]{0,200})?\]/i', '', $text);
    }

    private static function htmlToText($text)
    {
        $text = self::replace('/<br\s*\/?>/i', "\n", $text);
        $text = self::replace('/<\/(?:p|div|li|tr|h[1-6])>/i', "\n", $text);
        $text = strip_tags($text);
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function normalizeSpace($text)
    {
        $text = str_replace(array("\r\n", "\r"), "\n", $text);
        $lines = explode("\n", $text);
        foreach ($lines as $index => $line) {
            $line = self::replace('/[ \t\x{00A0}\x{3000}]+/u', ' ', $line);
            $lines[$index] = trim($line, " ");
        }
        $text = implode("\n", $lines);
        $text = self::replace('/\n{3,}/', "\n\n", $text);
        return trim($text);
    }

    /**
     * preg_replace that never returns null: when PCRE gives up (backtrack or JIT limits on
     * hostile input) the text becomes empty, so nothing unfiltered gets through.
     */
    private static function replace($pattern, $replacement, $subject)
    {
        $result = preg_replace($pattern, $replacement, $subject);
        return $result === null ? '' : $result;
    }

    /**
     * Removes opener...closer spans in one linear pass. An opener without a closer is
     * left alone (the tag markup is stripped later) and ends the scan.
     */
    private static function dropSpans($text, $openerPattern, $closer)
    {
        $out = '';
        $position = 0;
        while (true) {
            $found = preg_match($openerPattern, $text, $match, PREG_OFFSET_CAPTURE, $position);
            if ($found === false) {
                return '';
            }
            if ($found === 0) {
                break;
            }
            $start = $match[0][1];
            $closeAt = stripos($text, $closer, $start + strlen($match[0][0]));
            if ($closeAt === false) {
                break;
            }
            $out .= substr($text, $position, $start - $position);
            $position = $closeAt + strlen($closer);
        }
        return $out . substr($text, $position);
    }

    /**
     * Keeps only valid UTF-8 sequences so one bad byte does not blank the post.
     */
    private static function utf8($text)
    {
        if ($text === '' || preg_match('//u', $text)) {
            return $text;
        }
        $valid = '[\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2}';
        preg_match_all('/' . $valid . '/', $text, $found);
        return implode('', $found[0]);
    }
}
