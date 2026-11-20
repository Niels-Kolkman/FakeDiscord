<?php

namespace App\Observers;

use App\Models\AuditLogEntry;
use App\Models\Channel;
use App\Support\AuditLog;

/**
 * Audit log: channels created, changed and deleted. Position and
 * category moves (dragging in the sidebar) are left out, like the reorder
 * itself, which does not go through model events.
 */
class ChannelObserver
{
    private const FIELDS = ['name', 'type', 'topic', 'is_private'];

    public function __construct(private readonly AuditLog $log) {}

    public function created(Channel $channel): void
    {
        $this->log->record((int) $channel->server_id, AuditLogEntry::CHANNEL_CREATE, $channel, $channel->name,
            AuditLog::valuesOf($channel, ['type', 'topic', 'is_private']));
    }

    public function updated(Channel $channel): void
    {
        $changes = AuditLog::changesOf($channel, self::FIELDS);

        if ($changes !== []) {
            $this->log->record((int) $channel->server_id, AuditLogEntry::CHANNEL_UPDATE, $channel, $channel->name, $changes);
        }
    }

    public function deleted(Channel $channel): void
    {
        $this->log->record((int) $channel->server_id, AuditLogEntry::CHANNEL_DELETE, $channel, $channel->name);
    }
}
