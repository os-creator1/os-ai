<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    /**
     * RFC-005 §13, M1 contract §6.6 — composite-protected on
     * (wallet_id, business_id), tying every reservation's tenancy pair
     * back to its owning wallet row atomically (references
     * business_usage_wallets' own UNIQUE(id, business_id)). Once a
     * reservation's status is committed/released/expired it is never
     * reopened (manager-enforced).
     */
    return new class extends Migration {
        public function up(): void
        {
            Schema::create('business_usage_reservations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('business_id');
                $table->unsignedBigInteger('wallet_id');
                $table->string('feature_key', 64);
                $table->string('period_key', 7);
                $table->string('status', 16)->default('pending');
                $table->bigInteger('reserved_amount_micro');
                $table->decimal('estimated_quantity', 14, 6)->nullable();
                $table->foreignId('rate_id')->constrained('business_usage_rates')->restrictOnDelete();
                $table->unsignedInteger('rate_version');
                $table->bigInteger('retail_rate_micro')->unsigned();
                $table->bigInteger('provider_cost_micro')->unsigned();
                $table->string('rounding_rule', 32);
                $table->string('idempotency_key', 191)->unique();
                $table->string('correlation_key', 191);
                $table->timestamp('reserved_at');
                $table->timestamp('expires_at');
                $table->timestamp('committed_at')->nullable();
                $table->timestamp('released_at')->nullable();
                $table->decimal('final_quantity', 14, 6)->nullable();
                $table->bigInteger('final_amount_micro')->nullable();

                $table->foreign(['wallet_id', 'business_id'], 'business_usage_reservations_wallet_business_foreign')
                    ->references(['id', 'business_id'])->on('business_usage_wallets')
                    ->restrictOnDelete();

                $table->index(['wallet_id', 'business_id'], 'business_usage_reservations_wallet_id_business_id_index');
                $table->index('status');
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('business_usage_reservations');
        }
    };
