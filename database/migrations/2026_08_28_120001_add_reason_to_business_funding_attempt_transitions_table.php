<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    /**
     * RFC-005 Admin Usage Billing Surface Contract §2.1.2 — nullable
     * reason column so retryFundingAttemptAsAdministrator()'s own
     * mandatory admin-supplied reason can be persisted on the resulting
     * transition row. Nullable, unlike the wallet-billing-status and
     * limit-transition tables' own NOT NULL reason columns, because
     * business_funding_attempt_transitions is shared by four transition
     * sources (SyncResponse, WebhookEvent, ReconciliationJob, AdminAction)
     * and every existing, non-admin-actor row must keep a null reason.
     */
    return new class extends Migration {
        public function up(): void
        {
            Schema::table('business_funding_attempt_transitions', function (Blueprint $table) {
                $table->text('reason')->nullable()->after('actor_user_id');
            });
        }

        public function down(): void
        {
            Schema::table('business_funding_attempt_transitions', function (Blueprint $table) {
                $table->dropColumn('reason');
            });
        }
    };
