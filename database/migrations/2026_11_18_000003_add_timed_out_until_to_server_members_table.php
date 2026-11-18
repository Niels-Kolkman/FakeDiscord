<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Timeouts (ISSUE-29): until this moment the member can read the server
 * but not post, react or invite. Null, or a moment in the past, means no
 * timeout.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_members', function (Blueprint $table) {
            $table->timestamp('timed_out_until')->nullable()->after('joined_at');
        });
    }

    public function down(): void
    {
        Schema::table('server_members', function (Blueprint $table) {
            $table->dropColumn('timed_out_until');
        });
    }
};
