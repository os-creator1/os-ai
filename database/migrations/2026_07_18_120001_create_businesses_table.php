<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    return new class extends Migration {
        public function up(): void
        {
            Schema::create('businesses', function (Blueprint $table) {
                $table->id();
                $table->uuid('uid')->unique();
                $table->foreignId('customer_id')->constrained('users')->onDelete('cascade');
                $table->string('name');
                $table->string('industry', 64);
                $table->string('industry_other')->nullable();
                $table->text('description')->nullable();
                $table->string('email')->nullable();
                $table->string('phone', 50)->nullable();
                $table->string('website_url', 2048)->nullable();
                $table->string('canonical_domain')->nullable();
                $table->string('google_business_profile_url', 2048)->nullable();
                $table->string('facebook_url', 2048)->nullable();
                $table->string('instagram_url', 2048)->nullable();
                $table->char('country_code', 2);
                $table->string('timezone', 64);
                $table->char('currency_code', 3);
                $table->string('status', 32)->default('draft');
                $table->boolean('is_primary')->default(false);
                $table->timestamp('activated_at')->nullable();
                $table->timestamps();

                $table->index('customer_id');
                $table->index('status');
                $table->index('canonical_domain');
                $table->index(['customer_id', 'is_primary']);
                $table->index(['customer_id', 'status']);
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('businesses');
        }
    };
