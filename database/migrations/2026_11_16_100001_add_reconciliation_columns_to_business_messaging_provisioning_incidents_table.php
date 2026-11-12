<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automatic reconciliation of a number order whose carrier outcome was unknown (order accepted but still pending).
 * Bookkeeping only, all additive and nullable/defaulted: how many verified reconciliation attempts were made, when
 * the next one (or the in-flight lease) is due, what the last one found, and when the case was escalated to an
 * operator. Written only through ProvisioningIncidentRecorder::recordReconcileAttempt(), like resolved_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_messaging_provisioning_incidents', function (Blueprint $table): void {
            $table->unsignedSmallInteger('reconcile_attempts')->default(0);
            $table->timestamp('reconcile_next_attempt_at')->nullable();
            $table->string('reconcile_outcome', 16)->nullable(); // pending | failed | blocked
            $table->string('reconcile_detail', 255)->nullable();
            $table->timestamp('escalated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('business_messaging_provisioning_incidents', function (Blueprint $table): void {
            $table->dropColumn(['reconcile_attempts', 'reconcile_next_attempt_at', 'reconcile_outcome', 'reconcile_detail', 'escalated_at']);
        });
    }
};
