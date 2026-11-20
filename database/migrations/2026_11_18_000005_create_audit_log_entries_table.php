<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The server audit log: who changed what, and when. Rows are
 * written by App\Support\AuditLog and never updated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            // NULL once the user who made the change deletes their account.
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64);
            // What was changed. No foreign key: the entry outlives it (a
            // deleted channel or role keeps its log), so target_label keeps
            // its name as it was.
            $table->string('target_type', 32)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('target_label')->nullable();
            // {field: {old?, new}}. Not called "changes": Eloquent models
            // already have a $changes property.
            $table->json('details')->nullable();
            $table->string('reason', 512)->nullable();
            $table->timestamp('created_at')->nullable();

            // The page lists a server's entries newest first (by id), with
            // optional action and actor filters.
            $table->index(['server_id', 'action']);
            $table->index(['actor_id', 'server_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log_entries');
    }
};
