<?php

    namespace App\Console\Commands;

    use Exception;
    use Illuminate\Console\Command;

    class uSupportDemo extends Command
    {
        /**
         * The name and signature of the console command.
         *
         * @var string
         */
        protected $signature = 'app:usupport-demo';

        /**
         * The console command description.
         *
         * @var string
         */
        protected $description = 'Update uSupport - Support Ticket Plugin for Ultimate SMS demo data';

        /**
         * Execute the console command.
         */
        public function handle()
        {
            $this->info('Starting uSupport demo data seeding...');

            try {
                // Run PluginsSeeder
                $this->info('Seeding plugins...');
                $this->call('db:seed', [
                    '--class' => 'Database\Seeders\PluginsSeeder',
                ]);

                // Run uSupportPluginSeeder
                $this->info('Seeding support administrators...');
                $this->call('db:seed', [
                    '--class' => 'Database\Seeders\uSupportPluginSeeder',
                ]);

                $this->info('uSupport demo data seeding completed successfully.');

                return 0; // Return success status code

            } catch (Exception $e) {
                $this->error('An error occurred during seeding: ' . $e->getMessage());

                return 1; // Return a non-zero exit code to indicate failure
            }
        }


    }
