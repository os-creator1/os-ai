<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer Experience contract §5.5 / Slice 1B — the View-as-client audit
 * table. One row per session: actor, Workspace, Business, start, expiry,
 * end (with reason) and the prohibited actions refused meanwhile.
 *
 * Additive and reversible: up() creates one new table and nothing else;
 * down() drops exactly that table. No existing row anywhere is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('view_as_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('actor_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('reason', 255)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('expires_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason', 32)->nullable();
            $table->json('refusals')->nullable();
            $table->timestamps();

            $table->index(['actor_user_id', 'ended_at']);
            $table->index(['business_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('view_as_sessions');
    }
};
