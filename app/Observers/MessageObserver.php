<?php

namespace App\Observers;

use App\Models\AuditLogEntry;
use App\Models\Channel;
use App\Models\Message;
use App\Support\AuditLog;
use Illuminate\Support\Facades\Auth;

/**
 * Audit log (ISSUE-30): a server message deleted by someone other than its
 * author (Manage Messages), like Discord. Deleting your own message and
 * anything in DMs is not logged. A ban's message purge is not logged per
 * message (BanMember pauses the log).
 */
class MessageObserver
{
    public function __construct(private readonly AuditLog $log) {}

    public function deleted(Message $message): void
    {
        if (! $this->log->isRecording() || $message->channel_id === null || (int) Auth::id() === (int) $message->user_id) {
            return;
        }

        $channel = Channel::query()->find($message->channel_id);
        if ($channel === null) {
            return;
        }

        $this->log->record((int) $channel->server_id, AuditLogEntry::MESSAGE_DELETE, $message->author, $message->authorName(), [
            'channel' => ['new' => '#'.$channel->name],
        ]);
    }
}
