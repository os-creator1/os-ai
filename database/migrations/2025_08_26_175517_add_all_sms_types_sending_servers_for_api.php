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
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedBigInteger('api_voice_sending_server')->after('api_sending_server')->nullable();
                $table->unsignedBigInteger('api_mms_sending_server')->after('api_voice_sending_server')->nullable();
                $table->unsignedBigInteger('api_viber_sending_server')->after('api_mms_sending_server')->nullable();
                $table->unsignedBigInteger('api_whatsapp_sending_server')->after('api_viber_sending_server')->nullable();
                $table->unsignedBigInteger('api_otp_sending_server')->after('api_whatsapp_sending_server')->nullable();

                $table->foreign('api_voice_sending_server')->references('id')->on('sending_servers')->onDelete('set null');
                $table->foreign('api_mms_sending_server')->references('id')->on('sending_servers')->onDelete('set null');
                $table->foreign('api_viber_sending_server')->references('id')->on('sending_servers')->onDelete('set null');
                $table->foreign('api_whatsapp_sending_server')->references('id')->on('sending_servers')->onDelete('set null');
                $table->foreign('api_otp_sending_server')->references('id')->on('sending_servers')->onDelete('set null');

            });
        }

        /**
         * Reverse the migrations.
         */
        public function down(): void
        {
            Schema::table('users', function (Blueprint $table) {
                $table->dropForeign(config('database.connections.mysql.prefix') . 'users_api_voice_sending_server_foreign');
                $table->dropForeign(config('database.connections.mysql.prefix') . 'users_api_mms_sending_server_foreign');
                $table->dropForeign(config('database.connections.mysql.prefix') . 'users_api_viber_sending_server_foreign');
                $table->dropForeign(config('database.connections.mysql.prefix') . 'users_api_whatsapp_sending_server_foreign');
                $table->dropForeign(config('database.connections.mysql.prefix') . 'users_api_otp_sending_server_foreign');

                $table->dropColumn('api_voice_sending_server');
                $table->dropColumn('api_mms_sending_server');
                $table->dropColumn('api_viber_sending_server');
                $table->dropColumn('api_whatsapp_sending_server');
                $table->dropColumn('api_otp_sending_server');
            });
        }

    };
