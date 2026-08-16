<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    /**
     * RFC-005 §15, M2 contract §11.1 — one row per (Business, feature_key)
     * an authorized actor has explicitly configured a limit for. Absence of
     * a row means "no limit configured," never zero. monthly_limit_micro
     * is nullable — no default value is ever invented (M2 contract §6.B).
     */
    return new class extends Migration {
        public function up(): void
        {
            Schema::create('business_feature_usage_limits', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
                $table->string('feature_key', 64);
                $table->bigInteger('monthly_limit_micro')->nullable();
                $table->unsignedBigInteger('updated_by_user_id');
                $table->timestamps();

                $table->unique(['business_id', 'feature_key']);
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('business_feature_usage_limits');
        }
    };
