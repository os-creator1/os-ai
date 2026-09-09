<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer Experience Slice 5 (contract §12.2 E-19/E-20, §19, §20) — the
 * Workspace-level spending controls RFC-005 never had:
 *
 *  - workspace_usage_controls: one row per Workspace holding the aggregate
 *    monthly spending limit across every Business whose usage the
 *    Workspace pays for, the aggregate monthly automatic top-up ceiling,
 *    and the Workspace-wide emergency stop. Locked (SELECT ... FOR UPDATE)
 *    inside UsageWalletManager::reserve() after the wallet row — a fixed
 *    lock order, so two Businesses in one Workspace serialize on this row
 *    and can never both consume the final unit of the aggregate allowance.
 *  - usage_control_transitions: append-only audit of every change to a
 *    Business or Workspace control introduced by this slice (pause /
 *    resume, aggregate caps), with actor and mandatory reason.
 *
 * Additive and reversible: down() drops exactly these two new tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_usage_controls', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained('workspaces')->restrictOnDelete();
            $table->bigInteger('monthly_aggregate_spend_cap_micro')->nullable();
            $table->bigInteger('monthly_aggregate_recharge_cap_micro')->nullable();
            $table->timestamp('paid_activity_paused_at')->nullable();
            $table->unsignedBigInteger('paid_activity_paused_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamps();
        });

        Schema::create('usage_control_transitions', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 16);
            $table->unsignedBigInteger('scope_id');
            $table->string('control', 40);
            $table->string('from_value', 64)->nullable();
            $table->string('to_value', 64)->nullable();
            $table->unsignedBigInteger('actor_user_id');
            $table->text('reason');
            $table->timestamp('created_at');
            $table->index(['scope', 'scope_id'], 'usage_control_transitions_scope_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_control_transitions');
        Schema::dropIfExists('workspace_usage_controls');
    }
};
