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
            Schema::create('plan_sending_credit_prices', function (Blueprint $table) {
                $table->id();
                $table->uuid('uid');
                $table->unsignedBigInteger('plan_id');
                $table->integer('unit_from');
                $table->integer('unit_to');
                $table->string('per_credit_cost', 20)->default('1.00'); // Assuming price is a decimal with two decimal places

                // foreign
                $table->foreign('plan_id')->references('id')->on('plans')->onDelete('cascade');

                $table->timestamps();
            });
        }

        /**
         * Reverse the migrations.
         */
        public function down(): void
        {
            Schema::dropIfExists('plan_sending_credit_prices');
        }

    };
