<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // A unicode emoji (may be several code points) or a custom ":name:".
            $table->string('emoji', 64);
            $table->timestamps();

            // A user reacts with a given emoji at most once; the index also
            // covers grouping a message's reactions by emoji.
            $table->unique(['message_id', 'emoji', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reactions');
    }
};
