<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pinned messages. pinned_at NULL = not pinned; pinned_by_id
     * is who pinned it, NULL once that account is deleted. The index serves
     * a channel's pinned list, newest pin first.
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->timestamp('pinned_at')->nullable();
            $table->foreignId('pinned_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->index(['channel_id', 'pinned_at']);
            $table->index('pinned_by_id');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['channel_id', 'pinned_at']);
            $table->dropIndex(['pinned_by_id']);
            $table->dropConstrainedForeignId('pinned_by_id');
            $table->dropColumn('pinned_at');
        });
    }
};
