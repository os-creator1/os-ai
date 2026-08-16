<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    /**
     * RFC-005 §12, M1 contract §6.1 — one wallet row per Business.
     * currency_id is resolved from that Business's own currency_code at
     * initialization time (M1 contract §5.5) and is an immutable
     * accounting snapshot thereafter (§7 invariant 11) — never rewritten
     * by any code path.
     *
     * UNIQUE(id, business_id) is the composite-FK enabler child tables
     * (business_usage_reservations, business_usage_ledger_entries) use to
     * tie their own business_id/wallet_id pair back to this row atomically
     * (RFC-005 §12).
     *
     * monthly_spend_cap_micro, auto_recharge_* , monthly_recharge_cap_micro,
     * recharged_this_period_micro, consecutive_recharge_failures, and
     * low_balance_notified_at all exist at M1 (the full RFC-005 §12
     * schema) but are never set to a non-default value by any M1 code path
     * — their owning configuration/charging surfaces are M2/M3 (M1
     * contract §5.7).
     */
    return new class extends Migration {
        public function up(): void
        {
            Schema::create('business_usage_wallets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->unique()->constrained('businesses')->restrictOnDelete();
                $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();

                $table->bigInteger('available_balance_micro')->default(0);
                $table->bigInteger('reserved_balance_micro')->default(0);
                $table->bigInteger('debt_balance_micro')->default(0);

                $table->bigInteger('monthly_spend_cap_micro')->nullable();

                $table->string('spend_period_key', 7);
                $table->timestamp('spend_period_start_utc');
                $table->timestamp('spend_period_end_utc');

                $table->boolean('auto_recharge_enabled')->default(false);
                $table->bigInteger('auto_recharge_threshold_micro')->nullable();
                $table->bigInteger('auto_recharge_amount_micro')->nullable();
                $table->bigInteger('monthly_recharge_cap_micro')->nullable();

                $table->string('recharge_period_key', 7);
                $table->timestamp('recharge_period_start_utc');
                $table->timestamp('recharge_period_end_utc');

                $table->bigInteger('committed_spend_this_period_micro')->default(0);
                $table->bigInteger('reserved_spend_this_period_micro')->default(0);
                $table->bigInteger('recharged_this_period_micro')->default(0);

                $table->unsignedSmallInteger('consecutive_recharge_failures')->default(0);
                $table->timestamp('low_balance_notified_at')->nullable();

                $table->string('billing_status', 16)->default('active');

                $table->timestamps();

                $table->unique(['id', 'business_id'], 'business_usage_wallets_id_business_id_unique');
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('business_usage_wallets');
        }
    };
