<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-member module visibility. One nullable JSON column on the membership row
 * itself, so the preference is per Workspace AND per user (a person who belongs
 * to several accounts is configured independently in each) and needs no second
 * permission table.
 *
 * The column stores the modules that are HIDDEN, never the ones that are shown:
 * NULL or [] means "every module the member's role, permissions and plan already
 * allow", so every existing member keeps exactly their current access with no
 * backfill, and a module added later is visible by default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_memberships', function (Blueprint $table) {
            $table->json('hidden_modules')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('workspace_memberships', function (Blueprint $table) {
            $table->dropColumn('hidden_modules');
        });
    }
};
