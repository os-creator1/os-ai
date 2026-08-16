<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    /**
     * RFC-005 §14.1, M1 contract §6.5 — append-only, but never written by
     * any M1 code path (the classification backfill writes classification
     * rows directly, not through activateMetering()). Exists at M1 only so
     * M5's activateMetering() has a ready target.
     */
    return new class extends Migration {
        public function up(): void
        {
            Schema::create('platform_feature_usage_classification_transitions', function (Blueprint $table) {
                $table->id();
                $table->string('feature_key', 64);
                $table->boolean('from_is_metered');
                $table->boolean('to_is_metered');
                $table->foreignId('from_active_rate_id')->nullable();
                $table->foreignId('to_active_rate_id')->nullable();
                $table->unsignedBigInteger('actor_user_id');
                $table->text('reason');
                $table->timestamp('created_at');

                // Explicit short constraint names: the auto-generated name
                // (table name + column + "_foreign") exceeds MySQL's
                // 64-character identifier limit for this table's own long
                // name.
                $table->foreign('from_active_rate_id', 'pfuct_from_active_rate_id_foreign')
                    ->references('id')->on('business_usage_rates')->restrictOnDelete();
                $table->foreign('to_active_rate_id', 'pfuct_to_active_rate_id_foreign')
                    ->references('id')->on('business_usage_rates')->restrictOnDelete();
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('platform_feature_usage_classification_transitions');
        }
    };
