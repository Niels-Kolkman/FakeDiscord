<?php

namespace App\Observers;

use App\Models\AuditLogEntry;
use App\Models\Role;
use App\Support\AuditLog;

/**
 * Audit log: roles created, changed (name, colour, permission
 * flags) and deleted. Moving a role up or down is left out.
 */
class RoleObserver
{
    public function __construct(private readonly AuditLog $log) {}

    public function created(Role $role): void
    {
        $this->log->record((int) $role->server_id, AuditLogEntry::ROLE_CREATE, $role, $role->name,
            AuditLog::valuesOf($role, ['color', 'permissions']));
    }

    public function updated(Role $role): void
    {
        $changes = AuditLog::changesOf($role, ['name', 'color', 'permissions']);

        if ($changes !== []) {
            $this->log->record((int) $role->server_id, AuditLogEntry::ROLE_UPDATE, $role, $role->name, $changes);
        }
    }

    public function deleted(Role $role): void
    {
        $this->log->record((int) $role->server_id, AuditLogEntry::ROLE_DELETE, $role, $role->name);
    }
}
