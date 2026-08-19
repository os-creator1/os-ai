<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    /**
     * RFC-004 Amendment 1 §6 — purely additive. Adds exactly two nullable
     * columns to the existing workspace_entitlement_transitions table so
     * EntitlementManager::allocateAdditionalBusinessSlotsFromVerifiedPayment()
     * can record the requesting customer separately from the (always-null)
     * system/payment actor, and enforce its own independent idempotency
     * guarantee. No existing column, row, or index is modified.
     */
    return new class extends Migration {
        public function up(): void
        {
            Schema::table('workspace_entitlement_transitions', function (Blueprint $table) {
                $table->unsignedBigInteger('requesting_customer_user_id')->nullable()->after('actor_user_id');
                $table->string('payment_idempotency_key', 191)->nullable()->unique()->after('reason');
            });
        }

        public function down(): void
        {
            Schema::table('workspace_entitlement_transitions', function (Blueprint $table) {
                $table->dropUnique(['payment_idempotency_key']);
                $table->dropColumn(['requesting_customer_user_id', 'payment_idempotency_key']);
            });
        }
    };
