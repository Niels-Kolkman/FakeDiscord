<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mentions: who a server channel message mentions, worked
     * out when it is saved (App\Support\Mentions::resolve()) as JSON
     * {"users": [ids], "roles": [ids], "everyone": bool}. NULL when it
     * mentions nobody, and for DMs. The unread mention badges, the live
     * badges in the browser and the mention notifications all read it, so
     * a later change of usernames, role names or permissions does not
     * change who an old message mentioned.
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->json('mentions')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('mentions');
        });
    }
};
