<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    /**
     * RFC-005 §15, M2 contract §11.2 — platform-scoped (not Business-scoped)
     * ceiling per feature_key, platform-administrator-only. Zero rows at M2
     * launch — no feature is metered until M5, so nothing requires a
     * ceiling yet (M2 contract §6.C). max_monthly_limit_micro is NOT NULL,
     * safe precisely because no M2 code path ever inserts a row.
     */
    return new class extends Migration {
        public function up(): void
        {
            Schema::create('platform_feature_usage_safety_limits', function (Blueprint $table) {
                $table->id();
                $table->string('feature_key', 64)->unique();
                $table->bigInteger('max_monthly_limit_micro');
                $table->unsignedBigInteger('updated_by_user_id');
                $table->timestamps();
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('platform_feature_usage_safety_limits');
        }
    };
