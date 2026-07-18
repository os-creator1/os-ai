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
            Schema::table('sending_servers', function (Blueprint $table) {
                $table->string('auth_link')->after('settings')->nullable();
            });
        }

        /**
         * Reverse the migrations.
         */
        public function down(): void
        {
            Schema::table('sending_servers', function (Blueprint $table) {
                $table->dropColumn('auth_link');
            });
        }

    };
