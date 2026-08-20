<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    /**
     * RFC-004 Amendment 2 §8 — durable, catalog-scoped (not Workspace-scoped)
     * audit table for workspace_plan_catalog pricing mutations. A new table
     * rather than an extension to workspace_entitlement_transitions, whose
     * own workspace_id column is NOT NULL and cannot accommodate a
     * tier-level event.
     *
     * created_at only — no updated_at, immutable row, matching
     * workspace_entitlement_transitions' own established convention.
     *
     * from_currency_id/to_currency_id are deliberately plain nullable
     * scalars with no foreign key (Amendment 2 §8, locked) — the audit
     * table is immutable historical evidence and must remain readable
     * after a later currency lifecycle change or deletion; current-value
     * currency validity is verified at mutation time instead.
     *
     * workspace_plan_catalog_id receives InnoDB's automatic implicit index
     * for its own FK constraint — no separate bare index is declared,
     * matching workspace_entitlement_transitions' own established
     * from_plan_catalog_id/to_plan_catalog_id precedent. Both FK constraint
     * names are explicit, shorter names — MySQL's default
     * {table}_{column}_foreign auto-generated name exceeds the 64-character
     * identifier limit for this table's own long name.
     */
    return new class extends Migration {
        public function up(): void
        {
            Schema::create('workspace_plan_catalog_pricing_changes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('workspace_plan_catalog_id');
                $table->unsignedBigInteger('actor_user_id');
                $table->text('reason');
                $table->decimal('from_price', 16, 2)->nullable();
                $table->decimal('to_price', 16, 2)->nullable();
                $table->unsignedBigInteger('from_currency_id')->nullable();
                $table->unsignedBigInteger('to_currency_id')->nullable();
                $table->decimal('from_additional_business_slot_price_ratio', 6, 4)->nullable();
                $table->decimal('to_additional_business_slot_price_ratio', 6, 4)->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->foreign('workspace_plan_catalog_id', 'wpc_pricing_changes_catalog_id_foreign')->references('id')->on('workspace_plan_catalog')->restrictOnDelete();
                $table->foreign('actor_user_id')->references('id')->on('users')->restrictOnDelete();
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('workspace_plan_catalog_pricing_changes');
        }
    };
