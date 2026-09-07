<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B5 Business Analytics — contract §11.1. The ONE additive index
 * migration: exactly the six Business-scoped composite indexes the
 * range-filtered KPI reads need. Each was mechanically confirmed absent
 * on the post-B4 tree (the tenancy foundation added only single-column
 * business_id indexes; the legacy performance migration indexed
 * (user_id, created_at) on reports).
 *
 * No new column, no NOT NULL enforcement, no backfill, no type change, no
 * rollup table. automation_executions is B4-owned and is deliberately NOT
 * touched here — its (business_id, created_at) index ships with B4.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->index(['business_id', 'created_at'], 'reports_business_id_created_at_index');
            $table->index(['business_id', 'direction', 'created_at'], 'reports_business_id_direction_created_at_index');
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->index(['business_id', 'created_at'], 'campaigns_business_id_created_at_index');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->index(['business_id', 'created_at'], 'contacts_business_id_created_at_index');
            $table->index(['business_id', 'status'], 'contacts_business_id_status_index');
        });

        Schema::table('tracking_logs', function (Blueprint $table) {
            $table->index(['business_id', 'campaign_id'], 'tracking_logs_business_id_campaign_id_index');
        });
    }

    /** Drops exactly the six indexes above and nothing else. */
    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropIndex('reports_business_id_created_at_index');
            $table->dropIndex('reports_business_id_direction_created_at_index');
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropIndex('campaigns_business_id_created_at_index');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex('contacts_business_id_created_at_index');
            $table->dropIndex('contacts_business_id_status_index');
        });

        Schema::table('tracking_logs', function (Blueprint $table) {
            $table->dropIndex('tracking_logs_business_id_campaign_id_index');
        });
    }
};
