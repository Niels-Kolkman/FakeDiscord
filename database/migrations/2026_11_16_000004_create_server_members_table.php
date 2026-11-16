<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('nickname', 32)->nullable();
            $table->timestamp('joined_at')->useCurrent();
            $table->timestamps();

            // One membership per user per server; also serves "members of a server".
            $table->unique(['server_id', 'user_id']);
            // "Servers this user is in".
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_members');
    }
};
