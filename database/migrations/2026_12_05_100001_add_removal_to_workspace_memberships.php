<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Logical member removal. A removed membership keeps its row (the
 * (workspace_id, user_id) unique key and every foreign key stay valid);
 * `removed_at` marks it as removed rather than merely deactivated, and
 * workspace_membership_removals keeps the audit snapshot of what was removed.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('workspace_memberships', function (Blueprint $table) {
            $table->timestamp('removed_at')->nullable()->after('is_active');
        });

        Schema::create('workspace_membership_removals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_membership_id')->constrained('workspace_memberships')->restrictOnDelete();
            $table->foreignId('workspace_id')->constrained('workspaces')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('removed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('role', 32);
            $table->string('business_access_scope', 32);
            $table->string('location_access_scope', 32)->nullable();
            $table->json('business_ids');
            $table->json('location_ids');
            $table->timestamp('removed_at');
            $table->foreignId('reinstated_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reinstated_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_membership_removals');

        Schema::table('workspace_memberships', function (Blueprint $table) {
            $table->dropColumn('removed_at');
        });
    }
};
