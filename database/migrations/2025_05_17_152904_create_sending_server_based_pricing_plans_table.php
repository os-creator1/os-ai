<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    return new class extends Migration {
        /**
         * Run the migrations.
         */
        public function up(): void
        {
            Schema::create('sending_server_based_pricing_plans', function (Blueprint $table) {
                $table->id();
                $table->uuid('uid');
                $table->unsignedBigInteger('country_id');
                $table->unsignedBigInteger('sending_server')->nullable();
                $table->boolean('status')->default(true);
                $table->text('options')->nullable();

                $table->timestamps();
            });
        }

        /**
         * Reverse the migrations.
         */
        public function down(): void
        {
            Schema::dropIfExists('sending_server_based_pricing_plans');
        }

    };
