<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    /**
     * RFC-005 §11, M1 contract §6.3 — append-only record of which rate was
     * active for a feature, and when. Deterministic historical lookup ties
     * on activated_at using id as the tiebreaker.
     */
    return new class extends Migration {
        public function up(): void
        {
            Schema::create('business_usage_rate_activations', function (Blueprint $table) {
                $table->id();
                $table->string('feature_key', 64);
                $table->foreignId('rate_id')->constrained('business_usage_rates')->restrictOnDelete();
                $table->timestamp('activated_at');
                $table->unsignedBigInteger('activated_by_user_id');
                $table->text('reason');
                $table->timestamp('created_at');

                $table->index('feature_key');
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('business_usage_rate_activations');
        }
    };
