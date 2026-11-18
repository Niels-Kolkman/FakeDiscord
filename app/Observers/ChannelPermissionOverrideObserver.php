<?php

namespace App\Observers;

use App\Models\AuditLogEntry;
use App\Models\ChannelPermissionOverride;
use App\Support\AuditLog;

/**
 * Audit log (ISSUE-30): a role's allow/deny overrides in a channel. The
 * target is the channel; the role goes in the changes.
 */
class ChannelPermissionOverrideObserver
{
    public function __construct(private readonly AuditLog $log) {}

    public function created(ChannelPermissionOverride $override): void
    {
        $this->record($override, AuditLogEntry::CHANNEL_OVERRIDE_CREATE, [
            'allow' => ['new' => (int) $override->allow],
            'deny' => ['new' => (int) $override->deny],
        ]);
    }

    public function updated(ChannelPermissionOverride $override): void
    {
        $changes = AuditLog::changesOf($override, ['allow', 'deny']);

        if ($changes !== []) {
            $this->record($override, AuditLogEntry::CHANNEL_OVERRIDE_UPDATE, $changes);
        }
    }

    public function deleted(ChannelPermissionOverride $override): void
    {
        $this->record($override, AuditLogEntry::CHANNEL_OVERRIDE_DELETE, []);
    }

    /**
     * @param  array<string, array{old?: mixed, new?: mixed}>  $changes
     */
    private function record(ChannelPermissionOverride $override, string $action, array $changes): void
    {
        $channel = $override->channel;
        if ($channel === null) {
            return;
        }

        $this->log->record((int) $channel->server_id, $action, $channel, $channel->name, [
            'role' => ['new' => $override->role?->name],
            ...$changes,
        ]);
    }
}
