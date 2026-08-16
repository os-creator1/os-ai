<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    /**
     * RFC-005 §15, M2 contract §11.3 — append-only audit for every
     * configured-limit-value change (business_spend_cap, feature_limit,
     * platform_safety_limit). Never an authoritative source for a
     * formula-derived usage counter (M2 contract §6.D).
     */
    return new class extends Migration {
        public function up(): void
        {
            Schema::create('business_usage_limit_transitions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->nullable()
                    ->constrained('businesses')->restrictOnDelete();
                $table->string('limit_type', 24);
                $table->string('feature_key', 64)->nullable();
                $table->bigInteger('from_value_micro')->nullable();
                $table->bigInteger('to_value_micro')->nullable();
                $table->unsignedBigInteger('actor_user_id');
                $table->text('reason');
                $table->timestamp('created_at');

                $table->index('business_id');
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('business_usage_limit_transitions');
        }
    };
