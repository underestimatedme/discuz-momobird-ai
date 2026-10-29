<?php
/**
 * Forum categories for the knowledge base: the name of the top-level forum a
 * thread lives under. The same function feeds the push payload and the
 * front-end data-category attribute, so both sides always agree.
 */
final class MomoBird_Categories
{
    /** The backend rejects a post whose category is longer than this many characters. */
    const MAX_LENGTH = 64;

    /**
     * @param array $forums forum_forum rows (fid, fup, type, name) for the whole forum tree,
     *                      groups included (their names disambiguate same-named forums)
     * @return array<int,string> fid => category, for every forum and sub-forum in $forums
     */
    public static function build(array $forums): array
    {
        $byFid = array();
        foreach ($forums as $row) {
            if (is_array($row) && isset($row['fid'])) {
                $byFid[(int) $row['fid']] = $row;
            }
        }

        // Every forum/sub resolves to its top-level forum (sub-forums climb to their parent).
        $topOf = array();
        $tops = array();
        foreach ($byFid as $fid => $row) {
            if (!self::isPostable($row)) {
                continue;
            }
            $topFid = self::topFid($fid, $byFid);
            $topOf[$fid] = $topFid;
            $tops[$topFid] = $byFid[$topFid];
        }
        ksort($tops);

        $names = array();
        foreach ($tops as $fid => $row) {
            $name = self::cleanName(isset($row['name']) ? (string) $row['name'] : '');
            $names[$fid] = $name === '' ? '#' . $fid : $name;
        }

        $sameName = array_count_values($names);
        $full = array();
        foreach ($tops as $fid => $row) {
            $full[$fid] = $names[$fid];
            if ($sameName[$names[$fid]] > 1) {
                $group = self::groupName($row, $byFid);
                if ($group !== '') {
                    $full[$fid] = $group . ' / ' . $names[$fid];
                }
            }
        }

        $final = array();
        foreach ($full as $fid => $text) {
            $final[$fid] = self::truncate($text, self::MAX_LENGTH);
        }

        // Still the same after joining and cutting (or equal to a literal name): add the fid.
        // The suffix is reserved room, so cutting never removes it. A row that already carries
        // its own fid cannot collide with another row, so this ends after at most one pass per row.
        $suffixed = array();
        for ($pass = 0; $pass <= count($tops); $pass++) {
            $count = array_count_values($final);
            $changed = false;
            foreach ($final as $fid => $category) {
                if ($count[$category] > 1 && !isset($suffixed[$fid])) {
                    $suffix = ' #' . $fid;
                    $final[$fid] = self::truncate($full[$fid], self::MAX_LENGTH - self::length($suffix)) . $suffix;
                    $suffixed[$fid] = true;
                    $changed = true;
                }
            }
            if (!$changed) {
                break;
            }
        }

        $result = array();
        foreach ($topOf as $fid => $topFid) {
            $result[$fid] = $final[$topFid];
        }
        ksort($result);
        return $result;
    }

    /** First $max characters (not bytes) of $text, trimmed. */
    public static function truncate(string $text, int $max = self::MAX_LENGTH): string
    {
        $text = trim($text);
        if ($max <= 0 || $text === '') {
            return '';
        }
        if (preg_match('/^.{0,' . $max . '}/us', $text, $match) !== 1) {
            return '';
        }
        return trim($match[0]);
    }

    private static function isPostable(array $row): bool
    {
        $type = isset($row['type']) ? (string) $row['type'] : '';
        return $type === 'forum' || $type === 'sub';
    }

    /** fid of the top-level forum: follow fup while the row is a sub-forum with a known parent. */
    private static function topFid(int $fid, array $byFid): int
    {
        $seen = array($fid => true);
        while (isset($byFid[$fid]) && ($byFid[$fid]['type'] ?? '') === 'sub') {
            $parent = (int) ($byFid[$fid]['fup'] ?? 0);
            if (!isset($byFid[$parent]) || isset($seen[$parent]) || !self::isPostable($byFid[$parent])) {
                break;
            }
            $fid = $parent;
            $seen[$fid] = true;
        }
        return $fid;
    }

    private static function groupName(array $topRow, array $byFid): string
    {
        $fup = (int) ($topRow['fup'] ?? 0);
        if (!isset($byFid[$fup]) || ($byFid[$fup]['type'] ?? '') !== 'group') {
            return '';
        }
        return self::cleanName((string) ($byFid[$fup]['name'] ?? ''));
    }

    /**
     * Strip tags, decode entities, collapse whitespace, drop control and invisible format
     * characters (the backend rejects the whole post for one), cut to the maximum length.
     */
    private static function cleanName(string $name): string
    {
        if ($name !== '' && preg_match('//u', $name) !== 1) {
            $name = (string) @iconv('UTF-8', 'UTF-8//IGNORE', $name);
        }
        $name = html_entity_decode(strip_tags($name), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Whitespace controls (tab, newline, NEL, ...) become spaces first; the rest of
        // Cc and the invisible Cf marks (zero-width, BOM, bidi) are removed outright.
        $name = preg_replace('/[\s\x{00A0}\x{3000}]+/u', ' ', $name);
        $name = preg_replace('/[\p{Cc}\p{Cf}]+/u', '', (string) $name);
        $name = preg_replace('/ {2,}/', ' ', (string) $name);
        return self::truncate((string) $name, self::MAX_LENGTH);
    }

    private static function length(string $text): int
    {
        return (int) preg_match_all('/./us', $text);
    }
}
