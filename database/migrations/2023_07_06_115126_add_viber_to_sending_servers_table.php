<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    return new class extends Migration {
        /**
         * Run the migrations.
         *
         * @return void
         */
        public function up(): void
        {
            if ( ! Schema::hasColumn('sending_servers', 'viber') && ! Schema::hasColumn('sending_servers', 'otp')) {
                Schema::table('sending_servers', function (Blueprint $table) {
                    $table->boolean('viber')->after('whatsapp')->default(false);
                    $table->boolean('otp')->after('viber')->default(false);
                });

                $table_name = config('database.connections.mysql.prefix') . 'sending_servers';
                DB::statement("ALTER TABLE " . $table_name . " CHANGE `type` `type` ENUM('http','smpp','whatsapp','viber','viber','otp')");
            }
        }

        /**
         * Reverse the migrations.
         *
         * @return void
         */
        public function down(): void
        {
            Schema::table('sending_servers', function (Blueprint $table) {
                $table->dropColumn('viber');
                $table->dropColumn('otp');
            });
            $table_name = config('database.connections.mysql.prefix') . 'sending_servers';
            DB::statement("ALTER TABLE " . $table_name . " CHANGE `type` `type` ENUM('http','smpp','whatsapp')");
        }

    };
