<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Collapsible groups of channels in a server's sidebar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            // Sidebar listing: categories of a server in display order.
            $table->index(['server_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_categories');
    }
};
