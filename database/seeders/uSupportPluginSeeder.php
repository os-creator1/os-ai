<?php

    namespace Database\Seeders;

    use Illuminate\Database\Seeder;

    class uSupportPluginSeeder extends Seeder
    {
        /**
         * Run the database seeds.
         */
        public function run(): void
        {
            $this->call([
                // User and Role related seeders
                SupportAdministratorsSeeder::class,

                // Support Module Seeders
                SupportSettingsSeeder::class,
                SupportCategorySeeder::class,
                SupportArticleSeeder::class,
                SupportTicketSeeder::class,

            ]);
        }

    }
