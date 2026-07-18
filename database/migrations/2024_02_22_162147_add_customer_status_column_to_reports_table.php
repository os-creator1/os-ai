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

            if ( ! Schema::hasColumn('reports', 'customer_status')) {

                Schema::table('reports', function (Blueprint $table) {
                    $table->string('customer_status')->after('status')->nullable();
                });

                DB::table('reports')->update(['customer_status' => DB::raw('status')]);
            }
        }

        /**
         * Reverse the migrations.
         */
        public function down(): void
        {
            if (Schema::hasColumn('reports', 'customer_status')) {
                Schema::table('reports', function (Blueprint $table) {
                    $table->dropColumn('customer_status');
                });
            }
        }

    };
