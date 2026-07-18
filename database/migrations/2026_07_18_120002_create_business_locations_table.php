<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    return new class extends Migration {
        public function up(): void
        {
            Schema::create('business_locations', function (Blueprint $table) {
                $table->id();
                $table->uuid('uid')->unique();
                $table->foreignId('business_id')->constrained('businesses')->onDelete('cascade');
                $table->string('name')->default('Primary Location');
                $table->string('service_mode', 32);
                $table->string('address_line_1')->nullable();
                $table->string('address_line_2')->nullable();
                $table->string('city', 120)->nullable();
                $table->string('region', 120)->nullable();
                $table->string('postal_code', 32)->nullable();
                $table->char('country_code', 2);
                $table->decimal('latitude', 10, 7)->nullable();
                $table->decimal('longitude', 10, 7)->nullable();
                $table->boolean('public_address')->default(false);
                $table->unsignedSmallInteger('service_radius_km')->nullable();
                $table->json('service_area_cities')->nullable();
                $table->boolean('is_primary')->default(false);
                $table->timestamps();

                $table->index('business_id');
                $table->index(['business_id', 'is_primary']);
                $table->index(['country_code', 'region', 'city']);
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('business_locations');
        }
    };
