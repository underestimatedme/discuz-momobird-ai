<?php
/**
 * What one engine call did. Counters are per call; messages are Chinese and
 * already redacted (they come from MomoBird_ConnectError or the engine).
 */
final class MomoBird_SyncResult
{
    /** drain|cron|full|retry|confirm */
    public $mode;
    public $startedAt;
    public $finishedAt;

    /** created or updated on MomoBird */
    public $pushed = 0;
    /** eligible and identical to what MomoBird holds (no request, or MomoBird answered unchanged) */
    public $unchanged = 0;
    /** withdrawn from MomoBird */
    public $removed = 0;
    /** network / server failures (retried hourly) */
    public $failed = 0;
    /** refused by MomoBird (listed with the error code) */
    public $rejected = 0;
    /** eligible but not pushed: plan expired or address mismatch */
    public $held = 0;
    /** no text a guest could read */
    public $skipped = 0;
    /** threads still waiting in the queue when the call ended */
    public $queued = 0;

    public $planRequired = false;
    /** null, or the ConnectError kind that stopped all syncing (UNAUTHORIZED, DISABLED) */
    public $halted = null;
    public $originBlocked = false;
    public $siteClosed = false;
    public $guardHeld = false;
    public $wrongKind = false;
    public $deadlineReached = false;
    /** first error message, or null */
    public $error = null;
    /** @var string[] */
    public $notes = array();

    public function __construct(string $mode, int $startedAt)
    {
        $this->mode = $mode;
        $this->startedAt = $startedAt;
        $this->finishedAt = $startedAt;
    }

    public function fail(string $message): void
    {
        if ($this->error === null) {
            $this->error = $message;
        }
    }

    public function note(string $text): void
    {
        if (!in_array($text, $this->notes, true)) {
            $this->notes[] = $text;
        }
    }

    /** True when the call sent or changed anything worth recording as "last sync". */
    public function didWork(): bool
    {
        return $this->pushed + $this->unchanged + $this->removed + $this->failed + $this->rejected + $this->held + $this->skipped > 0
            || $this->error !== null || $this->halted !== null || $this->planRequired || $this->originBlocked;
    }

    public function toArray(): array
    {
        return array(
            'mode' => $this->mode,
            'started_at' => $this->startedAt,
            'at' => $this->finishedAt,
            'pushed' => $this->pushed,
            'unchanged' => $this->unchanged,
            'removed' => $this->removed,
            'failed' => $this->failed,
            'rejected' => $this->rejected,
            'held' => $this->held,
            'skipped' => $this->skipped,
            'queued' => $this->queued,
            'plan_required' => $this->planRequired,
            'halted' => $this->halted,
            'origin_blocked' => $this->originBlocked,
            'site_closed' => $this->siteClosed,
            'guard_held' => $this->guardHeld,
            'wrong_kind' => $this->wrongKind,
            'deadline_reached' => $this->deadlineReached,
            'error' => $this->error,
            'notes' => $this->notes,
        );
    }
}
