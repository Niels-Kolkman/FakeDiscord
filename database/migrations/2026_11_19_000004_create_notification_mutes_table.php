<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mute settings (ISSUE-34): servers and channels a user muted. A row
     * with channel_id NULL mutes the whole server; with a channel_id it
     * mutes that channel (server_id is still its server, so leaving the
     * server clears both kinds in one go). Muted places send no browser
     * notifications; the inbox and unread badges are unchanged.
     */
    public function up(): void
    {
        Schema::create('notification_mutes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();

            // NULLs are distinct in a unique index, so server mutes are
            // kept single by App\Support\NotificationMutes, not this.
            $table->unique(['user_id', 'server_id', 'channel_id']);
            $table->index('server_id');
            $table->index('channel_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_mutes');
    }
};
