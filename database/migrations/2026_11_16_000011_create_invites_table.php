<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invites', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();
            $table->foreignId('server_id')->index()->constrained()->cascadeOnDelete();
            // Channel the invitee lands in; NULL falls back to the first channel.
            $table->foreignId('channel_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->foreignId('inviter_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('max_uses')->nullable(); // NULL = unlimited
            $table->unsignedInteger('uses')->default(0);
            $table->timestamp('expires_at')->nullable();     // NULL = never
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invites');
    }
};
