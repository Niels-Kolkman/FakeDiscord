<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Unread tracking: how far each user has read in each server
     * channel. Messages with a higher id than last_read_message_id, from
     * someone else, are unread. No row means the user never opened the
     * channel: everything since they joined the server is unread.
     *
     * last_read_message_id has no foreign key: it is only a high-water
     * mark and must survive the message being removed (0 = read an empty
     * channel).
     */
    public function up(): void
    {
        Schema::create('channel_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('last_read_message_id')->default(0);
            $table->timestamps();

            // One row per user per channel; also serves "this user's reads".
            $table->unique(['user_id', 'channel_id']);
            $table->index('channel_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_reads');
    }
};
