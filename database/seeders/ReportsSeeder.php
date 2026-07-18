<?php

    namespace Database\Seeders;

    use App\Models\Reports;
    use Illuminate\Database\Seeder;

    class ReportsSeeder extends Seeder
    {
        /**
         * Run the database seeds.
         *
         * @return void
         */
        public function run()
        {

            Reports::truncate();

            for ($i = 0; $i < 200; $i++) {
                factory(Reports::class, 5000)
                    ->create();
            }
        }

    }
