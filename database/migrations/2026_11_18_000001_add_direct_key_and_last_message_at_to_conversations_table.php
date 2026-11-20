<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 1:1 direct messages.
     *
     * direct_key is "{lower user id}:{higher user id}" for a direct
     * conversation and NULL for group DMs. The unique index makes sure a
     * pair of users has one conversation, also when both open it at the
     * same moment (App\Actions\Conversations\OpenDirectConversation).
     *
     * last_message_at orders the DM list in the sidebar by latest activity;
     * App\Actions\Messages\SendMessage keeps it up to date.
     *
     * Existing rows are backfilled: direct conversations with exactly two
     * participants get their key (the oldest one, should a pair have two),
     * every conversation the time of its newest message.
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('direct_key', 41)->nullable()->unique();
            $table->timestamp('last_message_at')->nullable()->index();
        });

        $taken = [];
        $conversations = DB::table('conversations')->orderBy('id')->get(['id', 'type']);

        foreach ($conversations as $conversation) {
            $values = [];

            if ($conversation->type === 'direct') {
                $ids = DB::table('conversation_participants')
                    ->where('conversation_id', $conversation->id)
                    ->orderBy('user_id')
                    ->pluck('user_id')
                    ->map(fn ($id): int => (int) $id);

                $key = $ids->implode(':');
                if ($ids->count() === 2 && ! isset($taken[$key])) {
                    $taken[$key] = true;
                    $values['direct_key'] = $key;
                }
            }

            $latest = DB::table('messages')
                ->where('conversation_id', $conversation->id)
                ->whereNull('deleted_at')
                ->max('created_at');
            if ($latest !== null) {
                $values['last_message_at'] = $latest;
            }

            if ($values !== []) {
                DB::table('conversations')->where('id', $conversation->id)->update($values);
            }
        }
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropUnique(['direct_key']);
            $table->dropIndex(['last_message_at']);
            $table->dropColumn(['direct_key', 'last_message_at']);
        });
    }
};
