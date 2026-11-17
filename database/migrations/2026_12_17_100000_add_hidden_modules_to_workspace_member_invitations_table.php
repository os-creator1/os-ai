<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The module restrictions an inviter chose for a pending invitation, applied
 * verbatim to the new workspace_memberships.hidden_modules row at acceptance.
 * Same convention as the membership column: the HIDDEN modules are stored, NULL
 * or [] means nothing hidden, so every existing invitation keeps its previous
 * unrestricted behaviour with no backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_member_invitations', function (Blueprint $table) {
            $table->json('hidden_modules')->nullable();
            // Who last approved this invitation's access settings. invited_by_user_id stays the ORIGINAL inviter
            // (audit); acceptance re-validates and grants as the approver when there is one.
            $table->unsignedBigInteger('access_approved_by_user_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('workspace_member_invitations', function (Blueprint $table) {
            $table->dropColumn(['hidden_modules', 'access_approved_by_user_id']);
        });
    }
};
