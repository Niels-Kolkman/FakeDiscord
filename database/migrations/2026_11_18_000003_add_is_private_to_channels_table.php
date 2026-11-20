<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Private channels: @everyone loses View Channels in them;
     * only roles with a channel override that allows it can see them (see
     * App\Services\PermissionService).
     */
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->boolean('is_private')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn('is_private');
        });
    }
};
