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
            Schema::table('customer_based_pricing_plans', function (Blueprint $table) {
                $table->unsignedBigInteger('voice_sending_server')->after('sending_server')->nullable();
                $table->unsignedBigInteger('mms_sending_server')->after('voice_sending_server')->nullable();
                $table->unsignedBigInteger('whatsapp_sending_server')->after('mms_sending_server')->nullable();
                $table->unsignedBigInteger('viber_sending_server')->after('whatsapp_sending_server')->nullable();
                $table->unsignedBigInteger('otp_sending_server')->after('viber_sending_server')->nullable();
            });
        }

        /**
         * Reverse the migrations.
         */
        public function down(): void
        {
            Schema::table('customer_based_pricing_plans', function (Blueprint $table) {
                $table->dropColumn('voice_sending_server');
                $table->dropColumn('mms_sending_server');
                $table->dropColumn('whatsapp_sending_server');
                $table->dropColumn('viber_sending_server');
                $table->dropColumn('otp_sending_server');
            });
        }

    };
