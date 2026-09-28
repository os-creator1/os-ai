<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phone Numbers + A2P lane — the carrier-release boundary that PR #395's
 * own release-decision slice deliberately left unbuilt: `released_at`
 * (present since the original Slice 3 table, never written until now) is
 * finally the confirmed-by-the-carrier timestamp NumberLifecycleManager::
 * confirmCarrierRelease() writes, alongside `status` becoming Released —
 * both written only after a genuine (or explicitly-faked-in-a-test)
 * MessagingProvisioningAdapter::releaseNumber() confirmation, never merely
 * because release_decided_at was set.
 *
 * carrier_release_failed_at/carrier_release_failure_reason are the same
 * "make a failure visible and retryable, never silently swallowed"
 * discipline the release-notice delivered/failed split already
 * established: a carrier response that does not confirm removal (or a
 * transport-level exception/timeout) never changes `status` and never
 * pretends the number is gone — it is recorded here so an operator can
 * see it and retry, exactly like a failed release notice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_messaging_numbers', function (Blueprint $table): void {
            $table->timestamp('carrier_release_failed_at')->nullable()->after('release_decided_at');
            $table->string('carrier_release_failure_reason', 500)->nullable()->after('carrier_release_failed_at');
        });
    }

    public function down(): void
    {
        Schema::table('business_messaging_numbers', function (Blueprint $table): void {
            $table->dropColumn([
                'carrier_release_failed_at',
                'carrier_release_failure_reason',
            ]);
        });
    }
};
