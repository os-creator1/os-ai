<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    /**
     * RFC-005 §16, M2 contract §11.6 — one row per Business, initialized by
     * the extended InitializeBusinessUsageProfile listener (new Businesses)
     * and the M2 backfill migration (pre-existing Businesses). No FK on
     * effective_payment_instrument_id at M2 — business_payment_instruments
     * does not exist until M3 (deferred FK, mirroring M1's own
     * funding_attempt_id precedent); always NULL at M2.
     */
    return new class extends Migration {
        public function up(): void
        {
            Schema::create('business_payer_assignments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->unique()->constrained('businesses')->restrictOnDelete();
                $table->string('payer_type', 16);
                $table->unsignedBigInteger('effective_payment_instrument_id')->nullable();
                $table->timestamps();
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('business_payer_assignments');
        }
    };
