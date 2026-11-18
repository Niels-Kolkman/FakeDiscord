<?php

namespace App\Observers;

use App\Models\AuditLogEntry;
use App\Models\Server;
use App\Models\User;
use App\Support\AuditLog;

/**
 * Audit log (ISSUE-30): server settings (name, description, icon) and
 * ownership transfers. Creating and deleting a server are not logged: the
 * log belongs to the server and goes with it.
 */
class ServerObserver
{
    public function __construct(private readonly AuditLog $log) {}

    public function updated(Server $server): void
    {
        $changes = AuditLog::changesOf($server, ['name', 'description']);

        // The file path means nothing to the reader; say what happened.
        if ($server->wasChanged('icon_path')) {
            $changes['icon'] = ['new' => $server->icon_path === null ? 'removed' : 'uploaded'];
        }

        if ($changes !== []) {
            $this->log->record($server, AuditLogEntry::SERVER_UPDATE, $server, $server->name, $changes);
        }

        if ($server->wasChanged('owner_id')) {
            $previous = User::query()->find($server->getOriginal('owner_id'));
            $owner = User::query()->find($server->owner_id);

            $this->log->record($server, AuditLogEntry::SERVER_OWNER_TRANSFER, $owner, $owner?->name, [
                'owner' => ['old' => $previous?->name, 'new' => $owner?->name],
            ]);
        }
    }
}
