<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    /**
     * RFC-005 Amendment 1 §B — the new UsageMeter economic identity, additive
     * to PlatformFeature. Slice 1 EXPAND only: created at final shape, but
     * carries zero real rows and is read/written by no legacy code path
     * (RFC-005 Amendment 1 Slice 1 EXPAND Implementation Contract §4.A).
     */
    return new class extends Migration {
        public function up(): void
        {
            Schema::create('usage_meters', function (Blueprint $table) {
                $table->id();
                $table->string('meter_key', 128)->unique();
                $table->string('feature_key', 64);
                $table->unsignedBigInteger('business_id')->nullable();
                $table->unsignedBigInteger('currency_id');
                $table->boolean('is_metered')->default(false);
                $table->unsignedBigInteger('active_rate_id')->nullable();
                $table->text('description');
                $table->unsignedBigInteger('updated_by_user_id');
                $table->timestamps();

                $table->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();
                $table->foreign('currency_id')->references('id')->on('currencies')->restrictOnDelete();
                $table->unique(['meter_key', 'currency_id']);
                $table->index('feature_key');
                $table->index('business_id');
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('usage_meters');
        }
    };
