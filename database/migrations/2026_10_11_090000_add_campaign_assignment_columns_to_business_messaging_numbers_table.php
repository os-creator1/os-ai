<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phone Numbers + A2P lane — review correction: a local (10DLC) number's
 * carrier-side enablement (its Messaging Profile linked to an already-
 * approved campaign) must be a DURABLE, queryable fact, not an in-memory
 * assumption made once at purchase time. Telnyx's own "Assign Messaging
 * Profile To Campaign" endpoint responds with a background task id, never
 * an immediate confirmation, so this platform must be able to persist
 * that a request was made, poll it, and record the real outcome whenever
 * it eventually resolves — including never resolving cleanly, which must
 * stay visible to operators rather than silently forgotten.
 *
 * Null on every row by default and forever for a toll-free number (its
 * own carrier verification is submitted directly against the number, no
 * separate profile-to-campaign link exists) or for any number that never
 * went through this platform's own verify-first local sequence at all —
 * null is deliberately treated as "not applicable", never as a passed
 * check, and the only code path that ever writes anything here
 * (BusinessMessagingProvisioningService::assignToApprovedCampaign()) is
 * reachable exclusively for a freshly purchased local number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_messaging_numbers', function (Blueprint $table): void {
            $table->string('campaign_assignment_status', 16)->nullable()->after('number_type');
            $table->string('campaign_assignment_task_id', 191)->nullable()->after('campaign_assignment_status');
            $table->timestamp('campaign_assignment_confirmed_at')->nullable()->after('campaign_assignment_task_id');
            $table->timestamp('campaign_assignment_failed_at')->nullable()->after('campaign_assignment_confirmed_at');
            $table->string('campaign_assignment_failure_reason', 500)->nullable()->after('campaign_assignment_failed_at');
        });
    }

    public function down(): void
    {
        Schema::table('business_messaging_numbers', function (Blueprint $table): void {
            $table->dropColumn([
                'campaign_assignment_status',
                'campaign_assignment_task_id',
                'campaign_assignment_confirmed_at',
                'campaign_assignment_failed_at',
                'campaign_assignment_failure_reason',
            ]);
        });
    }
};
