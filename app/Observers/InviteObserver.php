<?php

namespace App\Observers;

use App\Models\AuditLogEntry;
use App\Models\Invite;
use App\Support\AuditLog;

/**
 * Audit log: invites created and revoked. Uses are not logged.
 */
class InviteObserver
{
    public function __construct(private readonly AuditLog $log) {}

    public function created(Invite $invite): void
    {
        $this->log->record((int) $invite->server_id, AuditLogEntry::INVITE_CREATE, $invite, $invite->code, [
            'max_uses' => ['new' => $invite->max_uses],
            'expires_at' => ['new' => $invite->expires_at?->toIso8601String()],
        ]);
    }

    public function deleted(Invite $invite): void
    {
        $this->log->record((int) $invite->server_id, AuditLogEntry::INVITE_DELETE, $invite, $invite->code);
    }
}
