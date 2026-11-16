<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            // text | voice | announcement. Kept as a plain string so new types
            // never need an ALTER TABLE (SQLite would have to rebuild the table).
            $table->string('type', 20)->default('text');
            $table->string('topic', 1024)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            // Sidebar listing: channels of a server in display order.
            $table->index(['server_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channels');
    }
};
