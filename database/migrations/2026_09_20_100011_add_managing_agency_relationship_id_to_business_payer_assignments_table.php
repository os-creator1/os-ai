<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Implementation Contract 09 §5.1 — AgencyRebill activation schema.
 *
 * business_payer_assignments gains the single source of truth for WHICH
 * Agency pays (managing_agency_relationship_id, a restrict-on-delete FK to
 * Contract 01's relationship row — never an arbitrary Workspace id) and the
 * managing Agency owner's standing consent (agency_rebill_consented_at/_by),
 * shaped exactly like the wallet's auto_recharge_consented_at/_by precedent.
 * No timestamp means no standing consent; nothing here fabricates one.
 *
 * business_payer_transitions — the existing payer audit, reused rather than
 * duplicated — gains the relationship each AgencyRebill grant/revoke/
 * assignment concerned, and which consent change it recorded.
 *
 * Every column is nullable and additive: agency_rebill has never been
 * assignable, so every existing row keeps NULL and needs no backfill (§8).
 * Restrict, never cascade: a relationship row carrying money history must
 * not be deletable out from under the payer audit.
 */
return new class extends Migration
{
    private const ASSIGNMENT_RELATIONSHIP_FOREIGN_KEY = 'bpa_managing_agency_relationship_id_foreign';

    private const TRANSITION_RELATIONSHIP_FOREIGN_KEY = 'bpt_managing_agency_relationship_id_foreign';

    public function up(): void
    {
        Schema::table('business_payer_assignments', function (Blueprint $table): void {
            $table->unsignedBigInteger('managing_agency_relationship_id')->nullable()->after('effective_payment_instrument_id');
            $table->timestamp('agency_rebill_consented_at')->nullable()->after('managing_agency_relationship_id');
            $table->unsignedBigInteger('agency_rebill_consented_by_user_id')->nullable()->after('agency_rebill_consented_at');

            $table->foreign('managing_agency_relationship_id', self::ASSIGNMENT_RELATIONSHIP_FOREIGN_KEY)
                ->references('id')
                ->on('agency_client_workspace_relationships')
                ->restrictOnDelete();
        });

        Schema::table('business_payer_transitions', function (Blueprint $table): void {
            $table->unsignedBigInteger('managing_agency_relationship_id')->nullable()->after('to_instrument_id');
            $table->string('agency_rebill_consent', 16)->nullable()->after('managing_agency_relationship_id');

            $table->foreign('managing_agency_relationship_id', self::TRANSITION_RELATIONSHIP_FOREIGN_KEY)
                ->references('id')
                ->on('agency_client_workspace_relationships')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('business_payer_transitions', function (Blueprint $table): void {
            $table->dropForeign(self::TRANSITION_RELATIONSHIP_FOREIGN_KEY);
            $table->dropColumn(['managing_agency_relationship_id', 'agency_rebill_consent']);
        });

        Schema::table('business_payer_assignments', function (Blueprint $table): void {
            $table->dropForeign(self::ASSIGNMENT_RELATIONSHIP_FOREIGN_KEY);
            $table->dropColumn(['managing_agency_relationship_id', 'agency_rebill_consented_at', 'agency_rebill_consented_by_user_id']);
        });
    }
};
