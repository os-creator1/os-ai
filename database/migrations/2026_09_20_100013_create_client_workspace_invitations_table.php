<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Implementation Contract 07 §5 — the durable pre-consent record for
 * Agency-initiated client provisioning. It exists, and is fully
 * reviewable/revocable, before any Workspace, Business, Location, or
 * Contract 01 relationship is created; the row alone is created at
 * invitation time, nothing else.
 *
 * `token_hash` mirrors `password_resets`' own hashed-at-rest convention
 * (config/auth.php's password broker): the plaintext token exists only in
 * the emailed claim link, never stored.
 *
 * `invited_by_user_id` is a deliberately immutable scalar actor column with
 * no foreign key, matching `agency_client_workspace_relationships.
 * established_by_user_id`'s own precedent (workspace_transitions.
 * actor_user_id before it): this audit record must never block a
 * legitimate user-deletion feature elsewhere.
 *
 * `email` is a plain string, not a `user_id` FK — no User need exist at
 * invitation time. `agency_workspace_id` and `created_client_workspace_id`
 * both carry a real FK with restrictOnDelete(), matching every other
 * Workspace-referencing audit table in this codebase: Workspaces are never
 * hard-deleted (RFC-003 §17), so the constraint costs nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_workspace_invitations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();

            $table->unsignedBigInteger('agency_workspace_id');
            $table->unsignedBigInteger('invited_by_user_id');

            $table->string('email');
            $table->string('token_hash');
            $table->string('intended_business_name')->nullable();

            $table->string('status', 16);

            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();

            $table->unsignedBigInteger('created_client_workspace_id')->nullable();

            $table->timestamps();

            $table->index(['agency_workspace_id', 'status'], 'client_workspace_invitations_agency_status_index');
            $table->index('email', 'client_workspace_invitations_email_index');

            $table->foreign('agency_workspace_id', 'client_workspace_invitations_agency_workspace_id_foreign')
                ->references('id')->on('workspaces')->restrictOnDelete();

            $table->foreign('created_client_workspace_id', 'client_workspace_invitations_client_workspace_id_foreign')
                ->references('id')->on('workspaces')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_workspace_invitations');
    }
};
