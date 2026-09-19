<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contract 20 §5.4, Sub-slice A, migration 4 of 4 — the installation record.
 *
 * THIS TABLE IS THE "MUST NEVER BE SILENTLY INSTALLED OR ACTIVATED" GUARANTEE.
 * It is also the provenance Blueprint §22 requires, the idempotency key, and
 * the input to the upgrade-surfacing query. It has no substitute: probing the
 * destination table (the technique `BusinessTemplateApplier` uses for
 * pipelines) cannot express a component that was deliberately SKIPPED, which
 * is exactly the state Addendum §16's upgrade rule must read back later.
 *
 * `UNIQUE (business_id, blueprint_id, component_key)` IS THE MECHANISM. An
 * installation run may only call an adapter for a component with no row, or
 * with a row in state `failed`. A row in state `installed` is never re-run, so
 * "never silently update or reactivate" is true by construction rather than by
 * the installer behaving.
 *
 * SKIP STATES ARE PROVENANCE, NEVER AUTHORITY. `skipped_unentitled` and
 * `skipped_unavailable` record only why a past run declined to install.
 * Neither grants, withholds or suppresses visibility on any later render:
 * `installed` is the ONLY state that permanently removes a component from the
 * addable query, and every other row — and every absent row — is re-decided by
 * `EntitlementManager::decide()` on every render. A stale skip row can never
 * durably cut a Business off from part of its own Blueprint.
 *
 * `required_feature_key` is NOT NULL here too: every recorded decision names
 * the feature it was made about, because every component declares one (§5.3).
 *
 * `installed_record_id` and `installed_by_user_id` are plain scalars with NO
 * foreign keys, deliberately. `installed_record_id` points at a row in
 * whichever bounded context the adapter wrote to (`crm_pipelines` today,
 * others later) and a polymorphic foreign key is not expressible; the honest
 * consequence is that this column is provenance for a human reading an audit
 * trail, not a referential guarantee, and nothing dereferences it to make a
 * decision. `installed_by_user_id` follows the
 * `workspace_entitlement_transitions` convention — an actor-identity column
 * must never block a legitimate user-deletion feature — and is NULL for a
 * system-initiated install, with no fabricated system-actor id of any kind.
 *
 * State transitions are a field rewrite, not a new row, and there is
 * deliberately NO `*_transitions` audit table: no governing document asks for
 * Blueprint-install history, and `CLAUDE.md` forbids a table without a purpose.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_blueprint_component_installations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('blueprint_id');
            $table->string('component_key', 64);
            $table->string('component_type', 40);

            // The version_number this decision was made from — never a version
            // row id, so a retired version stays meaningful as provenance.
            $table->unsignedInteger('installed_from_version');

            // installed | skipped_unentitled | skipped_unavailable | failed
            $table->string('state', 24);

            $table->string('required_feature_key', 64);
            $table->string('decision_reason', 48)->nullable();
            $table->string('installed_record_type', 64)->nullable();
            $table->unsignedBigInteger('installed_record_id')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->unsignedBigInteger('installed_by_user_id')->nullable();
            $table->timestamps();

            // THE idempotency key.
            $table->unique(
                ['business_id', 'blueprint_id', 'component_key'],
                'bbci_business_blueprint_component_unique'
            );

            // The upgrade-surfacing selector.
            $table->index(['business_id', 'state'], 'bbci_business_state_index');

            $table->foreign('business_id', 'bbci_business_foreign')
                ->references('id')->on('businesses')->cascadeOnDelete();

            $table->foreign('blueprint_id', 'bbci_blueprint_foreign')
                ->references('id')->on('niche_blueprints')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_blueprint_component_installations');
    }
};
