<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings → Team invitations for people who have no account yet.
 *
 * A pending invitation is only a record: it creates no User, Customer,
 * Workspace, Business, subscription or trial. `token_hash` follows the
 * client_workspace_invitations convention (Hash::make()'d, never the
 * plaintext). The role, Business access scope and selected Business ids are
 * stored as the inviter's REQUEST and are re-validated, against the inviter's
 * authority at that moment, when the invitation is accepted.
 *
 * `pending_key` is `workspace_id|email` while the invitation is pending and
 * NULL otherwise; its unique index is what makes "one active invitation per
 * person per account" a database guarantee rather than a read-then-write race.
 * `invited_by_user_id` is an immutable scalar actor column (no foreign key),
 * like the other audit-style tables, so it can never block user deletion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_member_invitations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();

            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('invited_by_user_id');

            $table->string('email');
            $table->string('role', 16);
            $table->string('business_access_scope', 16);
            $table->json('business_ids')->nullable();

            $table->string('token_hash');
            $table->string('status', 16);
            $table->string('pending_key')->nullable()->unique();

            $table->timestamp('expires_at');
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->unsignedBigInteger('accepted_user_id')->nullable();
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            $table->index(['workspace_id', 'status'], 'workspace_member_invitations_workspace_status_index');
            $table->index('email', 'workspace_member_invitations_email_index');

            $table->foreign('workspace_id', 'workspace_member_invitations_workspace_id_foreign')
                ->references('id')->on('workspaces')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_member_invitations');
    }
};
