<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A channel optionally sits in a category. Deleting the category keeps its
 * channels (they become uncategorised), like Discord.
 *
 * Adding a foreign key makes Laravel rebuild the channels table on SQLite;
 * that is fine while the table is small.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->foreignId('category_id')
                ->nullable()
                ->after('server_id')
                ->constrained('channel_categories')
                ->nullOnDelete();

            // SQLite does not index FK columns on its own.
            $table->index('category_id');
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
            $table->dropIndex(['category_id']);
            $table->dropColumn('category_id');
        });
    }
};
