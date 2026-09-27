<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phone Numbers + A2P lane — the support/ops reconciliation path for
 * business_messaging_provisioning_incidents (PR #295 Correction Round 1,
 * item 3). Until now `resolved_at` existed with nothing to write it, so a
 * partial-failure incident had no recovery path except a manual database
 * edit. These two columns make resolution auditable, mirroring
 * payment_provider_events' own disposed_by_user_id/disposition_note shape
 * (database/migrations/2026_08_16_140004_create_payment_provider_events_table.php):
 * who resolved it, and what reconciliation was actually performed.
 *
 * No foreign key on resolved_by_user_id, matching disposed_by_user_id's own
 * precedent — this is an audit trail column, not a tenancy relationship.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_messaging_provisioning_incidents', function (Blueprint $table): void {
            $table->unsignedBigInteger('resolved_by_user_id')->nullable()->after('resolved_at');
            $table->text('resolution_note')->nullable()->after('resolved_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('business_messaging_provisioning_incidents', function (Blueprint $table): void {
            $table->dropColumn(['resolved_by_user_id', 'resolution_note']);
        });
    }
};
