<?php

namespace App\Observers;

use App\Models\AuditLogEntry;
use App\Models\Ban;
use App\Support\AuditLog;

/**
 * Audit log (ISSUE-30): bans, with their reason, and unbans. The member's
 * removal that goes with a ban is not logged again as a kick.
 */
class BanObserver
{
    public function __construct(private readonly AuditLog $log) {}

    public function saved(Ban $ban): void
    {
        $this->log->record((int) $ban->server_id, AuditLogEntry::MEMBER_BAN, $ban->user, $ban->user?->name, reason: $ban->reason);
    }

    public function deleted(Ban $ban): void
    {
        $this->log->record((int) $ban->server_id, AuditLogEntry::MEMBER_UNBAN, $ban->user, $ban->user?->name);
    }
}
