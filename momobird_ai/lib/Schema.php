<?php
/**
 * The plugin's two tables, as SQL for Discuz!'s runquery(): it turns `pre_` into
 * the real table prefix, splits on ";\n" and appends the site's charset to every
 * CREATE TABLE. Columns are documented on MomoBird_Store.
 */

if (!defined('IN_DISCUZ')) {
    exit('Access Denied');
}

final class MomoBird_Schema
{
    const POST_TABLE = 'momobird_ai_post';
    const KV_TABLE = 'momobird_ai_kv';

    /** CREATE TABLE IF NOT EXISTS for both tables (install and upgrade). */
    public static function createSql(): string
    {
        return "CREATE TABLE IF NOT EXISTS pre_momobird_ai_post (\n"
            . "  tid INT UNSIGNED NOT NULL,\n"
            . "  state VARCHAR(16) NOT NULL DEFAULT 'pending',\n"
            . "  content_hash CHAR(64) NOT NULL DEFAULT '',\n"
            . "  body_hash CHAR(64) NOT NULL DEFAULT '',\n"
            . "  category VARCHAR(255) NOT NULL DEFAULT '',\n"
            . "  title VARCHAR(255) NOT NULL DEFAULT '',\n"
            . "  attempts INT NOT NULL DEFAULT 0,\n"
            . "  last_error VARCHAR(255) NOT NULL DEFAULT '',\n"
            . "  remote TINYINT NOT NULL DEFAULT 0,\n"
            . "  content_changed_at INT UNSIGNED NOT NULL DEFAULT 0,\n"
            . "  updated_at INT UNSIGNED NOT NULL DEFAULT 0,\n"
            . "  queued_at INT UNSIGNED NULL DEFAULT NULL,\n"
            . "  priority TINYINT NOT NULL DEFAULT 0,\n"
            . "  listed TINYINT NOT NULL DEFAULT 0,\n"
            . "  PRIMARY KEY (tid),\n"
            . "  KEY priority_queued (priority, queued_at),\n"
            . "  KEY state (state)\n"
            . ") ENGINE=InnoDB;\n"
            . "CREATE TABLE IF NOT EXISTS pre_momobird_ai_kv (\n"
            . "  k VARCHAR(64) NOT NULL,\n"
            . "  v MEDIUMTEXT NOT NULL,\n"
            . "  PRIMARY KEY (k)\n"
            . ") ENGINE=InnoDB;";
    }

    /** DROP TABLE for both tables (uninstall). */
    public static function dropSql(): string
    {
        return "DROP TABLE IF EXISTS pre_momobird_ai_post;\nDROP TABLE IF EXISTS pre_momobird_ai_kv;";
    }
}
