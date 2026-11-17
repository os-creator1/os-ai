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
        });
    }

    public function down(): void
    {
        Schema::table('workspace_member_invitations', function (Blueprint $table) {
            $table->dropColumn('hidden_modules');
        });
    }
};
