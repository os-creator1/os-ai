<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phone Numbers + A2P lane — messaging contract §13.2/§13.3 number
 * lifecycle: renewal, advance warning, grace period and explicit,
 * audited release. Additive only; no existing column, status value or
 * behavior changes.
 *
 * next_renewal_at is populated at provisioning time (one billing cycle
 * from activation) so this mechanism is fully wired end to end, but it
 * remains structurally inert everywhere a real charge would be required:
 * no UsageMeter/rate exists yet for the number-rental feature key
 * (NumberLifecycleManager::FEATURE_NUMBER_RENTAL_RENEWAL), so an attempted
 * renewal reserve() throws "not configured" today, exactly like every
 * other provisioning feature key in this repository — never a guessed or
 * invented charge.
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
            $table->timestamp('release_notice_sent_at')->nullable()->after('grace_expires_at');
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
                'release_notice_sent_at',
            ]);
        });
    }
};
