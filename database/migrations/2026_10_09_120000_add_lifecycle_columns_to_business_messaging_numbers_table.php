<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phone Numbers + A2P lane — messaging contract §13.2/§13.3 number
 * lifecycle: renewal, advance warning, grace period and an explicit,
 * audited release DECISION. Additive only; no existing column, status
 * value or behavior changes.
 *
 * next_renewal_at is populated at provisioning time (one billing cycle
 * from activation) so this mechanism is fully wired end to end, but it
 * remains structurally inert everywhere a real charge would be required:
 * no UsageMeter/rate exists yet for the number-rental feature key
 * (NumberLifecycleManager::FEATURE_NUMBER_RENTAL_RENEWAL), so an attempted
 * renewal reserve() throws "not configured" today, exactly like every
 * other provisioning feature key in this repository — never a guessed or
 * invented charge.
 *
 * Review correction (ChatGPT, merge blockers on 7c3126f1) — this file is
 * edited in place rather than corrected by a follow-up migration: it has
 * not merged to main, so there is no external consumer of its original
 * column set to keep additive against.
 *
 * 1. No column here ever claims a real carrier release happened — this
 *    slice makes no Telnyx call. release_decided_at (paired with the
 *    lifecycle-events audit row) records only that a platform operator
 *    made the explicit, audited DECISION to release, while the number's
 *    own `status` stays Suspended (never Released) until a future,
 *    separately authorized slice confirms the real carrier-side release.
 * 2. release_notice_sent_at is removed. It was written the moment the
 *    notice job was DISPATCHED, before the job ever ran — a missing
 *    billing contact, an opt-out, or a delivery failure could all satisfy
 *    the "notice was sent" precondition without the customer ever
 *    actually being notified. release_notice_delivered_at is now written
 *    only by the job itself, after a confirmed successful send;
 *    release_notice_failed_at is written on a confirmed failure/skip, so
 *    a stuck number is visible to operators instead of silently
 *    unnoticed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_messaging_numbers', function (Blueprint $table): void {
            $table->timestamp('next_renewal_at')->nullable()->after('activated_at');
            $table->timestamp('renewal_warning_sent_at')->nullable()->after('next_renewal_at');
            $table->timestamp('suspended_at')->nullable()->after('renewal_warning_sent_at');
            $table->timestamp('grace_expires_at')->nullable()->after('suspended_at');
            $table->timestamp('release_notice_delivered_at')->nullable()->after('grace_expires_at');
            $table->timestamp('release_notice_failed_at')->nullable()->after('release_notice_delivered_at');
            $table->timestamp('release_decided_at')->nullable()->after('release_notice_failed_at');
        });
    }

    public function down(): void
    {
        Schema::table('business_messaging_numbers', function (Blueprint $table): void {
            $table->dropColumn([
                'next_renewal_at',
                'renewal_warning_sent_at',
                'suspended_at',
                'grace_expires_at',
                'release_notice_delivered_at',
                'release_notice_failed_at',
                'release_decided_at',
            ]);
        });
    }
};
