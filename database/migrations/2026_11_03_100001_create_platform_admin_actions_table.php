<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform Owner V1 final — the append-only trail for Platform Owner actions
 * that are NOT scoped to one Workspace (plan catalog edits, user account
 * actions, administrator/role changes, announcement lifecycle, support
 * lookups that touch a person). Workspace-scoped actions keep landing in
 * workspace_entitlement_transitions; this table exists because that one
 * requires a workspace_id and cannot describe "an administrator was invited".
 * Never updated, never deleted: rows have no updated_at.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('platform_admin_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 64);
            $table->string('subject_type', 32);
            $table->string('subject_ref', 64)->nullable();
            $table->string('summary', 255);
            $table->text('reason')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_ref'], 'platform_admin_actions_subject_idx');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_admin_actions');
    }
};
