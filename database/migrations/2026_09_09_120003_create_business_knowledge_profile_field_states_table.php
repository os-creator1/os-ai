<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    /**
     * Website Guided Generation contract §5.1/§5.2/§5.4, §16 Slice 1,
     * migration 3 of 5. One row per (business_id, field_key) tracking
     * provenance/verification for every business_knowledge_profiles
     * column except the derived `reviews_source` (§4.2) -- `hours`
     * provenance lives on business_locations instead (§5.5), never here.
     */
    return new class extends Migration {
        public function up(): void
        {
            Schema::create('business_knowledge_profile_field_states', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
                $table->string('field_key', 40);
                $table->string('source', 24)->nullable();
                $table->string('verification_status', 24)->default('unverified');
                // Explicit short constraint name: the auto-generated
                // "..._field_states_verified_by_user_id_foreign" name
                // exceeds MySQL's 64-character identifier limit.
                $table->foreignId('verified_by_user_id')->nullable()
                    ->constrained('users', 'id', 'bkp_field_states_verified_by_fk')
                    ->nullOnDelete();
                $table->timestamp('verified_at')->nullable();
                $table->timestamps();

                // Explicit short index name -- same 64-character limit
                // as the foreign key above.
                $table->unique(['business_id', 'field_key'], 'bkp_field_states_business_field_unique');
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('business_knowledge_profile_field_states');
        }
    };
