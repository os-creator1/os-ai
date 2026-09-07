<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    /**
     * Website Guided Generation contract §4.2, §16 Slice 1, migration 1 of
     * 5. One row per Business (business_id unique). Owns only the facts
     * §2's evidence table marks MISSING/PARTIAL — canonical identity,
     * location, and service facts already on businesses/business_locations/
     * business_services are never duplicated here. Hours live on
     * business_locations (migration 2), never on this table (§5.5).
     */
    return new class extends Migration {
        public function up(): void
        {
            Schema::create('business_knowledge_profiles', function (Blueprint $table) {
                $table->id();
                $table->uuid('uid')->unique();
                $table->foreignId('business_id')->unique()->constrained('businesses')->cascadeOnDelete();

                $table->string('vertical_key', 40)->nullable();
                $table->string('pricing_method', 24)->nullable();
                $table->boolean('financing_available')->nullable();
                $table->json('offers')->nullable();
                $table->json('differentiators')->nullable();
                $table->text('ideal_customers')->nullable();
                $table->json('customer_problems')->nullable();
                $table->json('credentials')->nullable();
                $table->unsignedSmallInteger('years_operating')->nullable();
                $table->text('warranties_guarantees')->nullable();
                $table->string('primary_conversion_goal', 24)->nullable();
                $table->string('conversion_target', 255)->nullable();
                $table->text('brand_voice')->nullable();
                $table->json('prohibited_claims')->nullable();
                $table->json('growth_priority_service_ids')->nullable();
                $table->json('growth_priority_location_ids')->nullable();
                $table->json('testimonials')->nullable();
                // Derived by BusinessKnowledgeProfileManager on every write
                // (§4.2) -- never independently writable. No `gbp_future`
                // value exists (§22 decision 4, locked).
                $table->string('reviews_source', 16)->default('none');

                $table->timestamps();
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('business_knowledge_profiles');
        }
    };
