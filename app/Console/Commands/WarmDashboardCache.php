<?php

    namespace App\Console\Commands;

    use App\Http\Controllers\Admin\AdminBaseController;
    use Illuminate\Console\Command;

    class WarmDashboardCache extends Command
    {

        protected $signature = 'dashboard:warm';

        protected $description = 'Precompute and cache dashboard statistics';

        /**
         * Execute the console command.
         */
        public function handle()
        {
            app(AdminBaseController::class)->warmCache();
            $this->info('Dashboard cache warmed successfully.');
        }

    }
