<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    /**
     * RFC-005 §11, M1 contract §6.2 — fully immutable rate rows (no
     * updated_at; created_at is the only timestamp). No rate is ever
     * activated by any M1 code path (M1 contract §5.8 item 1) — this table
     * is empty at the end of M1 in any real deployment.
     */
    return new class extends Migration {
        public function up(): void
        {
            Schema::create('business_usage_rates', function (Blueprint $table) {
                $table->id();
                $table->string('feature_key', 64);
                $table->unsignedInteger('version');
                $table->bigInteger('retail_rate_micro')->unsigned();
                $table->bigInteger('provider_cost_micro')->unsigned();
                $table->string('unit_label', 64);
                $table->string('rounding_rule', 32)->default('round_half_up');
                $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
                $table->unsignedBigInteger('created_by_user_id');
                $table->timestamp('created_at');

                $table->unique(['feature_key', 'version']);
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('business_usage_rates');
        }
    };
