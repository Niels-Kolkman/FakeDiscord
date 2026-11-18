<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-channel role overrides (ISSUE-28), like Discord's permission
     * overwrites: `allow` and `deny` are App\Enums\Permission masks that
     * replace the role's server permissions in that one channel. A flag in
     * neither mask is inherited. At most one row per channel and role; a
     * row with both masks 0 is deleted instead of stored. That the role
     * belongs to the channel's server is checked in the app layer.
     */
    public function up(): void
    {
        Schema::create('channel_permission_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('allow')->default(0);
            $table->unsignedBigInteger('deny')->default(0);
            $table->timestamps();

            $table->unique(['channel_id', 'role_id']);
            $table->index('role_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_permission_overrides');
    }
};
