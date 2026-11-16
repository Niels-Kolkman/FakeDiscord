<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A message is posted in a server channel OR in a conversation (DM).
     * Exactly one of channel_id / conversation_id is set. The app layer
     * enforces this, because a CHECK constraint would need raw SQL.
     */
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->cascadeOnDelete();
            // Keep the history when an account is deleted; show "Deleted User".
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // A reply keeps working after the original is deleted, just without the preview.
            $table->foreignId('reply_to_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->text('content');
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();

            // History pagination: newest messages in a channel or DM.
            $table->index(['channel_id', 'created_at']);
            $table->index(['conversation_id', 'created_at']);
            $table->index('user_id');
            $table->index('reply_to_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
