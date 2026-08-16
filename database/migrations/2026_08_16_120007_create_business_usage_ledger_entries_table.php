<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    /**
     * RFC-005 §12, M1 contract §6.7 — append-only, composite-protected on
     * (wallet_id, business_id). funding_attempt_id is a plain nullable
     * unsignedBigInteger with no FK at M1 — business_funding_attempts
     * does not exist until M3, which adds the FK constraint once its
     * target table exists (documented deferred-FK note, M1 contract §6.7).
     */
    return new class extends Migration {
        public function up(): void
        {
            Schema::create('business_usage_ledger_entries', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('business_id');
                $table->unsignedBigInteger('wallet_id');
                $table->string('entry_type', 32);
                $table->bigInteger('available_delta_micro')->default(0);
                $table->bigInteger('reserved_delta_micro')->default(0);
                $table->bigInteger('debt_delta_micro')->default(0);
                $table->bigInteger('gross_amount_micro')->unsigned()->nullable();
                $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
                $table->string('feature_key', 64)->nullable();
                $table->string('period_key', 7)->nullable();
                $table->decimal('quantity', 14, 6)->nullable();
                $table->foreignId('rate_id')->nullable()->constrained('business_usage_rates')->restrictOnDelete();
                $table->unsignedInteger('rate_version')->nullable();
                $table->bigInteger('retail_rate_micro')->unsigned()->nullable();
                $table->bigInteger('provider_cost_micro')->unsigned()->nullable();
                $table->string('unit_label', 64)->nullable();
                $table->string('rounding_rule', 32)->nullable();
                $table->foreignId('reservation_id')->nullable()
                    ->constrained('business_usage_reservations')->restrictOnDelete();
                $table->unsignedBigInteger('funding_attempt_id')->nullable();
                $table->string('correlation_key', 191)->unique();
                $table->string('provider_reference', 191)->nullable();
                $table->unsignedBigInteger('actor_user_id')->nullable();
                $table->text('reason')->nullable();
                $table->unsignedBigInteger('reversed_entry_id')->nullable();
                $table->timestamp('created_at');

                $table->foreign(['wallet_id', 'business_id'], 'business_usage_ledger_entries_wallet_business_foreign')
                    ->references(['id', 'business_id'])->on('business_usage_wallets')
                    ->restrictOnDelete();

                $table->foreign('reversed_entry_id', 'business_usage_ledger_entries_reversed_entry_id_foreign')
                    ->references('id')->on('business_usage_ledger_entries')
                    ->restrictOnDelete();

                $table->index(['wallet_id', 'business_id'], 'business_usage_ledger_entries_wallet_id_business_id_index');
                $table->index('entry_type');
                $table->index('reservation_id');
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('business_usage_ledger_entries');
        }
    };
