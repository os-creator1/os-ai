<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    return new class extends Migration {
        public function up(): void
        {
            Schema::create('business_services', function (Blueprint $table) {
                $table->id();
                $table->uuid('uid')->unique();
                $table->foreignId('business_id')->constrained('businesses')->onDelete('cascade');
                $table->string('name');
                $table->string('slug');
                $table->text('description')->nullable();
                $table->boolean('is_primary')->default(false);
                $table->decimal('starting_price', 12, 2)->nullable();
                $table->char('currency_code', 3)->nullable();
                $table->string('status', 32)->default('active');
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index('business_id');
                $table->index(['business_id', 'status']);
                $table->index(['business_id', 'is_primary']);
                $table->unique(['business_id', 'slug']);
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('business_services');
        }
    };
