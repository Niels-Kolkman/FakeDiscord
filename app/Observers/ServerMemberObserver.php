<?php

namespace App\Observers;

use App\Models\AuditLogEntry;
use App\Models\ServerMember;
use App\Support\AuditLog;

/**
 * Audit log (ISSUE-30): timeouts given and ended early
 * (App\Actions\Moderation\TimeoutMember saves the membership).
 */
class ServerMemberObserver
{
    public function __construct(private readonly AuditLog $log) {}

    public function updated(ServerMember $member): void
    {
        if (! $member->wasChanged('timed_out_until')) {
            return;
        }

        $user = $member->user;

        if ($member->timed_out_until === null) {
            $this->log->record((int) $member->server_id, AuditLogEntry::MEMBER_TIMEOUT_REMOVE, $user, $user->name);

            return;
        }

        $this->log->record((int) $member->server_id, AuditLogEntry::MEMBER_TIMEOUT, $user, $user->name, [
            'timed_out_until' => ['new' => $member->timed_out_until->toIso8601String()],
        ]);
    }
}
