<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    /**
     * RFC-005 §14.1, M1 contract §6.4 — one row per PlatformFeature case.
     * Backfilled is_metered=false/active_rate_id=null for every existing
     * case by migration 8. No M1 code path ever flips is_metered — M5
     * scope exclusively.
     *
     * updated_by_user_id is nullable: the M1 contract's own schema table
     * marked it NOT NULL, but its own backfill algorithm (§9.1) explicitly
     * sets it null for the system-authored backfill row, citing
     * complimentary_granted_by_user_id's identical nullable/no-FK
     * precedent (RFC-004 M1). Resolved as nullable here to match that
     * deliberate, reasoned backfill design rather than the schema table's
     * apparent carry-over notation — an implementation-detail correction,
     * not a structural conflict (M1 contract §17).
     */
    return new class extends Migration {
        public function up(): void
        {
            Schema::create('platform_feature_usage_classifications', function (Blueprint $table) {
                $table->id();
                $table->string('feature_key', 64)->unique();
                $table->boolean('is_metered')->default(false);
                $table->foreignId('active_rate_id')->nullable()
                    ->constrained('business_usage_rates')->restrictOnDelete();
                $table->unsignedBigInteger('updated_by_user_id')->nullable();
                $table->timestamps();
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('platform_feature_usage_classifications');
        }
    };
