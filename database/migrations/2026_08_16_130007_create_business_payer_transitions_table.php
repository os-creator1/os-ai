<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    /**
     * RFC-005 §16, M2 contract §11.7 — append-only, every explicit
     * BillingProfileManager::changePayer() call. No FK on
     * from_instrument_id/to_instrument_id at M2 (deferred, same rationale
     * as business_payer_assignments); always NULL at M2.
     */
    return new class extends Migration {
        public function up(): void
        {
            Schema::create('business_payer_transitions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
                $table->string('from_payer_type', 16);
                $table->string('to_payer_type', 16);
                $table->unsignedBigInteger('from_instrument_id')->nullable();
                $table->unsignedBigInteger('to_instrument_id')->nullable();
                $table->unsignedBigInteger('actor_user_id');
                $table->text('reason');
                $table->timestamp('created_at');

                $table->index('business_id');
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('business_payer_transitions');
        }
    };
