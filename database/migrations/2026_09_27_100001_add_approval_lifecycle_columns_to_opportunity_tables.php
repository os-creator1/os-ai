<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Implementation Contract 19 §8 (`19.D`) — the exact schema this sub-slice
 * is authorised to add, and nothing else:
 *
 *   "`approval_expires_at` and the approval-side cost snapshot columns on
 *    the approval/execution rows; `opportunity_transitions` gains
 *    `actor_user_id`, `view_as_session_id`, `initiated_by_type` and a
 *    nullable `coo_insight_id`/`coo_draft_id` provenance reference. No
 *    backfill of historical transitions."
 *
 * THERE IS NO APPROVAL TABLE. An "approval" in RFC-002 is a STATE — an
 * `opportunities` row sitting in `awaiting_approval` — so §8's "approval/
 * execution rows" resolves to those two tables:
 *   - `opportunities.approval_expires_at` is the live window, stamped when
 *     the approval is requested; the execution captures that value at
 *     confirmation;
 *   - `opportunity_action_executions.approval_expires_at` is the SNAPSHOT
 *     taken at confirmation, so the queued job can re-check the very window
 *     the customer agreed under even after the Opportunity has moved on
 *     (§5.4(2): "none is inherited from the approval" — the execution must
 *     be able to re-verify, not trust).
 *
 * COST SNAPSHOT COLUMNS ARE ADDED EMPTY AND STAY EMPTY HERE. §8 assigns the
 * columns to `19.D` and their population to `19.E` ("cost snapshot columns
 * are written only for `paid_effect` actions; existing rows keep `NULL`,
 * which the executor treats as 'not a paid-effect action' — never as
 * 'unlimited'"). No action in the registry is `paid_effect` today, so every
 * value written by this slice is NULL by construction. These are customer
 * action-cost ceilings, in payer-currency minor units or action units; AI
 * provider cost in micro-USD belongs to the separate AI usage ledger.
 *
 * `actor_user_id` ALREADY EXISTS on `opportunity_transitions`
 * (2026_07_19_120005, as a nullable bigint), so §8's list is satisfied for
 * that column by the existing schema and this migration does not re-add it.
 *
 * NO FOREIGN KEY on `coo_insight_id`/`coo_draft_id`. `coo_drafts` is not
 * created until `19.F`, so an FK would be unsatisfiable today; and
 * provenance follows the same "attribution survives deletion" rule §8 states
 * for `coo_insights.actor_user_id`. They are plain nullable references,
 * indexed only where a lookup is expected.
 *
 * Rule R-13: this is a NEW migration and edits no previously merged one.
 * `down()` drops exactly what `up()` added.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opportunities', function (Blueprint $table): void {
            // The live approval window. NULL means "not awaiting approval",
            // which is every row until one is requested — never "no expiry".
            $table->timestamp('approval_expires_at')->nullable()->after('occurrence_number');
            $table->string('approval_initiated_by_type', 16)->nullable()->after('approval_expires_at');
            $this->addActionCostColumns($table);
        });

        Schema::table('opportunity_action_executions', function (Blueprint $table): void {
            // The window this execution was confirmed under, snapshotted so
            // it can be re-checked without trusting the Opportunity's
            // current state.
            $table->timestamp('approval_expires_at')->nullable()->after('initiated_by_type');
            $table->unsignedBigInteger('confirmed_by_user_id')->nullable()->after('approval_expires_at');
            $table->string('confirmed_by_type', 16)->nullable()->after('confirmed_by_user_id');

            // §8 / §19.E — the approval-side cost snapshot. NULL means "no
            // estimate", which for a paid_effect action is a refusal, not a
            // licence.
            $this->addActionCostColumns($table);
        });

        Schema::table('opportunity_transitions', function (Blueprint $table): void {
            // Who the actor was acting AS, and what proposed the change.
            $table->unsignedBigInteger('view_as_session_id')->nullable()->after('actor_user_id');
            $table->string('initiated_by_type', 16)->nullable()->after('view_as_session_id');

            // Provenance: which COO artefact, if any, this transition came
            // from. Both nullable, neither an FK — see the class docblock.
            $table->unsignedBigInteger('coo_insight_id')->nullable()->after('initiated_by_type');
            $table->unsignedBigInteger('coo_draft_id')->nullable()->after('coo_insight_id');
        });
    }

    public function down(): void
    {
        Schema::table('opportunity_transitions', function (Blueprint $table): void {
            $table->dropColumn(['view_as_session_id', 'initiated_by_type', 'coo_insight_id', 'coo_draft_id']);
        });

        Schema::table('opportunity_action_executions', function (Blueprint $table): void {
            $table->dropColumn([
                'approval_expires_at',
                'confirmed_by_user_id',
                'confirmed_by_type',
                ...$this->actionCostColumnNames(),
            ]);
        });

        Schema::table('opportunities', function (Blueprint $table): void {
            $table->dropColumn(['approval_expires_at', 'approval_initiated_by_type', ...$this->actionCostColumnNames()]);
        });
    }

    private function addActionCostColumns(Blueprint $table): void
    {
        $table->string('action_cost_payer_type', 16)->nullable();
        $table->unsignedBigInteger('action_cost_payer_workspace_id')->nullable();
        $table->char('action_cost_currency_code', 3)->nullable();
        $table->unsignedBigInteger('action_cost_amount_minor_upper_bound')->nullable();
        $table->unsignedBigInteger('action_cost_unit_count')->nullable();
        $table->string('action_cost_unit_kind', 32)->nullable();
        $table->string('action_cost_basis', 16)->nullable();
        $table->string('action_cost_price_version', 64)->nullable();
        $table->timestamp('action_cost_estimated_at')->nullable();
        $table->timestamp('action_cost_expires_at')->nullable();
        $table->boolean('action_cost_wallet_sufficient')->nullable();
    }

    private function actionCostColumnNames(): array
    {
        return [
            'action_cost_payer_type', 'action_cost_payer_workspace_id',
            'action_cost_currency_code', 'action_cost_amount_minor_upper_bound',
            'action_cost_unit_count', 'action_cost_unit_kind', 'action_cost_basis',
            'action_cost_price_version', 'action_cost_estimated_at',
            'action_cost_expires_at', 'action_cost_wallet_sufficient',
        ];
    }
};
