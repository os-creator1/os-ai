<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    /**
     * RFC-005 Amendment 1 §C, Slice 1 EXPAND — mirrors
     * platform_feature_usage_classification_transitions' own shape, scoped
     * to meter identity. Created at final shape: no legacy writer exists
     * for this table (activateMetering() has no production caller and no
     * test exercises it), so no later tightening is ever required (RFC-005
     * Amendment 1 Slice 1 EXPAND Implementation Contract §4.E).
     */
    return new class extends Migration {
        public function up(): void
        {
            Schema::create('usage_meter_transitions', function (Blueprint $table) {
                $table->id();
                $table->string('meter_key', 128);
                $table->boolean('from_is_metered');
                $table->boolean('to_is_metered');
                $table->unsignedBigInteger('from_active_rate_id')->nullable();
                $table->unsignedBigInteger('to_active_rate_id')->nullable();
                $table->unsignedBigInteger('actor_user_id');
                $table->text('reason');
                $table->timestamp('created_at');

                $table->foreign('meter_key', 'umt_meter_key_foreign')
                    ->references('meter_key')->on('usage_meters')->restrictOnDelete();
                $table->foreign(['meter_key', 'from_active_rate_id'], 'umt_from_rate_same_meter_foreign')
                    ->references(['meter_key', 'id'])->on('business_usage_rates')->restrictOnDelete();
                $table->foreign(['meter_key', 'to_active_rate_id'], 'umt_to_rate_same_meter_foreign')
                    ->references(['meter_key', 'id'])->on('business_usage_rates')->restrictOnDelete();

                $table->index('meter_key');
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('usage_meter_transitions');
        }
    };
