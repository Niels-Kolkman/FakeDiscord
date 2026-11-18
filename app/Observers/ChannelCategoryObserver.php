<?php

namespace App\Observers;

use App\Models\AuditLogEntry;
use App\Models\ChannelCategory;
use App\Support\AuditLog;

/**
 * Audit log (ISSUE-30): categories created, renamed and deleted.
 */
class ChannelCategoryObserver
{
    public function __construct(private readonly AuditLog $log) {}

    public function created(ChannelCategory $category): void
    {
        $this->log->record((int) $category->server_id, AuditLogEntry::CATEGORY_CREATE, $category, $category->name);
    }

    public function updated(ChannelCategory $category): void
    {
        $changes = AuditLog::changesOf($category, ['name']);

        if ($changes !== []) {
            $this->log->record((int) $category->server_id, AuditLogEntry::CATEGORY_UPDATE, $category, $category->name, $changes);
        }
    }

    public function deleted(ChannelCategory $category): void
    {
        $this->log->record((int) $category->server_id, AuditLogEntry::CATEGORY_DELETE, $category, $category->name);
    }
}
