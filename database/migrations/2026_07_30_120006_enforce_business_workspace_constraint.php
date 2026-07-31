<?php

    use App\Exceptions\Workspace\DanglingWorkspaceReferenceException;
    use App\Exceptions\Workspace\WorkspaceBackfillIncompleteException;
    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;

    return new class extends Migration {
        /**
         * Enforces the RFC-003 §10.7 invariant "every Business has exactly
         * one Workspace" at the database level (§10.6 step 5, §9.4). Both
         * preconditions below run as plain SELECTs before any DDL — neither
         * relies on an opaque MySQL constraint-addition error as the
         * primary signal.
         *
         * This migration does not itself close the window between its own
         * precondition queries and the subsequent DDL: MySQL DDL is not
         * transactional, so a Business write landing in that window is a
         * deployment-process concern, not something this migration can
         * self-enforce. Business-write traffic must be quiesced for the
         * duration of this migration when it is applied to a live
         * database.
         */
        public function up(): void
        {
            $remainingNullCount = DB::table('businesses')->whereNull('workspace_id')->count();

            if ($remainingNullCount > 0) {
                throw new WorkspaceBackfillIncompleteException($remainingNullCount);
            }

            $danglingWorkspaceIds = DB::table('businesses')
                ->whereNotNull('workspace_id')
                ->whereNotExists(function ($query) {
                    $query->select(DB::raw(1))
                        ->from('workspaces')
                        ->whereColumn('workspaces.id', 'businesses.workspace_id');
                })
                ->pluck('workspace_id')
                ->all();

            if ($danglingWorkspaceIds !== []) {
                throw new DanglingWorkspaceReferenceException($danglingWorkspaceIds);
            }

            Schema::table('businesses', function (Blueprint $table) {
                $table->unsignedBigInteger('workspace_id')->nullable(false)->change();
            });

            Schema::table('businesses', function (Blueprint $table) {
                // Indexes before the FK (below), so InnoDB reuses this
                // named index for the constraint rather than creating a
                // redundant implicit one.
                $table->index('workspace_id', 'businesses_workspace_id_index');
                $table->index(['workspace_id', 'status'], 'businesses_workspace_id_status_index');
            });

            Schema::table('businesses', function (Blueprint $table) {
                $table->foreign('workspace_id', 'businesses_workspace_id_foreign')
                    ->references('id')->on('workspaces')
                    ->restrictOnDelete();
            });
        }

        public function down(): void
        {
            Schema::table('businesses', function (Blueprint $table) {
                $table->dropForeign('businesses_workspace_id_foreign');
            });

            Schema::table('businesses', function (Blueprint $table) {
                $table->dropIndex('businesses_workspace_id_status_index');
                $table->dropIndex('businesses_workspace_id_index');
            });

            Schema::table('businesses', function (Blueprint $table) {
                $table->unsignedBigInteger('workspace_id')->nullable()->change();
            });
        }
    };
