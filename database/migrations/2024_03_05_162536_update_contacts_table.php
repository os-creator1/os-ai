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
            $columnsToRemove = ['first_name', 'last_name', 'username', 'email', 'company', 'address', 'birth_date', 'anniversary_date'];

            Schema::table('contacts', function (Blueprint $table) use ($columnsToRemove) {
                foreach ($columnsToRemove as $column) {
                    if (Schema::hasColumn('contacts', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        /**
         * Reverse the migrations.
         */
        public function down(): void
        {
            Schema::table('contacts', function (Blueprint $table) {
                $table->string('first_name')->nullable(false);
                $table->string('last_name')->nullable(false);
                $table->string('username')->nullable(false);
                $table->string('email')->nullable(false);
                $table->string('company')->nullable(false);
                $table->string('address')->nullable(false);
                $table->string('birth_date')->nullable(false);
                $table->string('anniversary_date')->nullable(false);
            });
        }


    };
