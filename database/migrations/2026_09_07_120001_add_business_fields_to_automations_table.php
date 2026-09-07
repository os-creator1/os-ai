<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B4 Business Automations — contract §3.1/§3.2. Additive only.
 *
 * Adds the five Business-scoping/definition columns to the legacy
 * `automations` table. Every legacy column (including the proven-inert
 * `running_pid`) is deliberately RETAINED so this migration stays
 * rollback-safe; no column is dropped or repurposed.
 *
 * `business_id` is nullable on purpose: a legacy row that cannot be
 * deterministically mapped to exactly one Business (see the data-only
 * backfill migration) stays NULL forever and is inert — never guessed,
 * never reassigned. `workspace_id` is intentionally NOT added:
 * businesses.workspace_id is already authoritative and enforced, and
 * EntitlementManager::decide() re-derives/validates it (contract §3.3).
 *
 * Three legacy NOT NULL columns that only the removed Birthday builder ever
 * populated — `contact_list_id` (FK to contact_groups), `sms_type` and
 * `data` — are relaxed to NULLABLE: the exact three compatibility
 * relaxations the contract authorizes (§3.1a), and no others. A v1
 * definition carries its group (if any), channel type and configuration in
 * trigger_config/action_config, so it has nothing meaningful to write
 * there; on a strict-mode MySQL the INSERT would otherwise be rejected.
 * The columns, their types and the existing FK are kept exactly as they
 * were (nothing dropped, renamed, or repurposed).
 *
 * Column/index/FK naming follows the tenancy-foundation convention used by
 * 2026_09_05_120001_add_nullable_business_id_to_tenancy_tables.php.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('automations', function (Blueprint $table) {
            $table->unsignedBigInteger('business_id')->nullable()->after('user_id');
            $table->string('trigger_type', 32)->nullable()->after('status');
            $table->json('trigger_config')->nullable()->after('trigger_type');
            $table->string('action_type', 32)->nullable()->after('trigger_config');
            $table->json('action_config')->nullable()->after('action_type');
        });

        Schema::table('automations', function (Blueprint $table) {
            $table->unsignedBigInteger('contact_list_id')->nullable()->change();
            $table->string('sms_type', 15)->nullable()->change();
            $table->longText('data')->nullable()->change();
        });

        Schema::table('automations', function (Blueprint $table) {
            $table->index('business_id', 'automations_business_id_index');
            $table->index(['status', 'trigger_type'], 'automations_status_trigger_type_index');
            $table->foreign('business_id', 'automations_business_id_foreign')
                ->references('id')->on('businesses')->restrictOnDelete();
        });
    }

    /**
     * Contract §3.6 — drop the FK, then the indexes, then the five new
     * columns. Legacy columns and every legacy row are untouched.
     *
     * The three relaxed legacy columns deliberately stay NULLABLE on
     * rollback (contract §3.6): re-imposing NOT NULL would fail, or would
     * have to destructively invent values, once any B4-era row carries a
     * NULL there — and a nullable column is strictly more permissive than
     * the pre-B4 schema for every legacy row.
     */
    public function down(): void
    {
        Schema::table('automations', function (Blueprint $table) {
            $table->dropForeign('automations_business_id_foreign');
            $table->dropIndex('automations_status_trigger_type_index');
            $table->dropIndex('automations_business_id_index');
        });

        Schema::table('automations', function (Blueprint $table) {
            $table->dropColumn(['business_id', 'trigger_type', 'trigger_config', 'action_type', 'action_config']);
        });
    }
};
