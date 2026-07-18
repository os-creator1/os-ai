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
            Schema::table('invoices', function (Blueprint $table) {
                $table->string('total_amount')->after('tax')->default(0)->nullable();
            });


            // Set total_amount equal to the updated amount
            DB::table('invoices')->update([
                'total_amount' => DB::raw('amount'),
            ]);

            DB::table('invoices')->update([
                'amount' => DB::raw('amount - tax'),
            ]);

        }

        /**
         * Reverse the migrations.
         */
        public function down(): void
        {
            Schema::table('invoices', function (Blueprint $table) {
                $table->dropColumn('total_amount');
            });
        }

    };
