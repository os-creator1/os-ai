<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    /**
     * Website Guided Generation contract §5.5, §16 Slice 1, migration 2 of
     * 5. Hours are a business_locations fact, not a
     * business_knowledge_profiles fact (§5.5 correction) -- support
     * multiple daily periods, never one open/close pair. A NULL `hours`
     * value means "not yet answered"; every day present as an empty array
     * means "closed every day" -- these are deliberately distinct states.
     */
    return new class extends Migration {
        public function up(): void
        {
            Schema::table('business_locations', function (Blueprint $table) {
                $table->json('hours')->nullable()->after('service_area_cities');
                $table->string('hours_source', 24)->nullable()->after('hours');
                $table->string('hours_verification_status', 24)->default('unverified')->after('hours_source');
                $table->foreignId('hours_verified_by_user_id')->nullable()->after('hours_verification_status')->constrained('users')->nullOnDelete();
                $table->timestamp('hours_verified_at')->nullable()->after('hours_verified_by_user_id');
            });
        }

        public function down(): void
        {
            Schema::table('business_locations', function (Blueprint $table) {
                $table->dropConstrainedForeignId('hours_verified_by_user_id');
                $table->dropColumn(['hours', 'hours_source', 'hours_verification_status', 'hours_verified_at']);
            });
        }
    };
