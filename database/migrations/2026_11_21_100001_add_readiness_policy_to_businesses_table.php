<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which Home readiness policy governs a Business (see docs/product/LAUNCH-HOME-READINESS.md).
 *
 *   NULL          an established Business that predates the policy: it keeps its original Home-stage rules
 *   seo_first_v1  every Business created from now on, and any Draft Business whose onboarding is completed
 *                 in the SEO-first flow
 *
 * The marker belongs to the BUSINESS, not to a customer's one-per-customer onboarding row: it is written at the
 * single creation seam (BusinessRepository::createForCustomerInWorkspace) so a second Business, a Business created
 * through Workspace management or Agency provisioning, and a Business later moved to another Workspace all carry it
 * independently of who created or now accesses them.
 *
 * Deliberately NO backfill: existing Businesses stay NULL (legacy). Additive and nullable, so the migration is safe on
 * a populated database and old code simply never reads the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            $table->string('readiness_policy', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            $table->dropColumn('readiness_policy');
        });
    }
};
