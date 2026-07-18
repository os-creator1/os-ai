<?php

    namespace Database\Seeders;

    use App\Models\Blacklists;
    use Illuminate\Database\Seeder;
    use Illuminate\Support\Facades\DB;

    class BlacklistSeeder extends Seeder
    {
        /**
         * Run the database seeds.
         *
         * @return void
         */
        public function run()
        {
            Blacklists::factory()->count(40)->create();

        }

    }
