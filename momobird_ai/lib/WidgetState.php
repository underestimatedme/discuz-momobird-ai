<?php
/**
 * What the front end may show, read from the plugin's own cache only: kv
 * `connect_status`. The engine writes that entry (cronTick refreshes it from
 * GET /status every hour and switches the assistant on once after the first
 * full sync; see MomoBird_SyncEngine::refreshStatusCache / maybeEnableWidget).
 * Nothing here makes a network request.
 */

if (!defined('IN_DISCUZ')) {
    exit('Access Denied');
}

final class MomoBird_WidgetState
{
    const LOADER_URL = 'https://momobird.atlaspaces.com/widget/loader.v1.js';
    /** An entry older than this (cron not running, settings broken) no longer counts. */
    const MAX_AGE_SECONDS = 86400;
    const PUBLIC_ID_PATTERN = '/^[A-Za-z0-9_-]{1,128}$/D';
    const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

    private $store;
    /** @var array|null|false false = not read yet */
    private $status = false;

    public function __construct(MomoBird_Store $store)
    {
        $this->store = $store;
    }

    /**
     * The project's public id when the assistant may be shown: MomoBird published it
     * (`widget.available`), the plan is active, and the entry is recent. The engine clears
     * `available` itself when the key is revoked (401), the source is disabled (409) or the
     * plan has expired (402); a network failure keeps the last known answer.
     */
    public function publicId(int $now): ?string
    {
        $status = $this->status();
        if ($status === null) {
            return null;
        }
        $checkedAt = isset($status['checked_at']) && is_numeric($status['checked_at']) ? (int) $status['checked_at'] : 0;
        if ($checkedAt <= 0 || $now - $checkedAt > self::MAX_AGE_SECONDS) {
            return null;
        }
        if (isset($status['entitlement']['active']) && $status['entitlement']['active'] === false) {
            return null;
        }
        $widget = isset($status['widget']) && is_array($status['widget']) ? $status['widget'] : array();
        if (empty($widget['available']) || $widget['available'] !== true) {
            return null;
        }
        $id = isset($widget['public_id']) && is_string($widget['public_id']) ? $widget['public_id'] : '';
        return preg_match(self::PUBLIC_ID_PATTERN, $id) === 1 ? $id : null;
    }

    /** The MomoBird source id (podcasts are looked up by source and post), or null. */
    public function sourceId(): ?string
    {
        $status = $this->status();
        $id = isset($status['source']['id']) && is_string($status['source']['id']) ? strtolower($status['source']['id']) : '';
        return preg_match(self::UUID_PATTERN, $id) === 1 ? $id : null;
    }

    private function status(): ?array
    {
        if ($this->status === false) {
            $value = $this->store->get('connect_status');
            $this->status = is_array($value) ? $value : null;
        }
        return $this->status;
    }
}
