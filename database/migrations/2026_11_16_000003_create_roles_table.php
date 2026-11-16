<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('color', 7)->nullable(); // "#5865F2"
            // Permission bitmask. SQLite stores it as INTEGER (64-bit signed),
            // so keep flags within bits 0-62.
            $table->unsignedBigInteger('permissions')->default(0);
            // Higher position = higher in the role hierarchy.
            $table->unsignedInteger('position')->default(0);
            // The implicit "@everyone" role every member has.
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['server_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
