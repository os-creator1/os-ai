<?php

namespace App\Console\Commands;

use Artisan;
use Illuminate\Console\Command;

class RunEveryTenSeconds extends Command
{
    protected $signature = 'custom:run';

    protected $description = 'Run task every ten seconds';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle(): void
    {
        $step = 10;
        $max = 60;
        for ($i = 0; $i < $max; $i += $step) {
            Artisan::call('queue:work --queue=automation,default,batch --timeout=120 --tries=1 --max-time=180 --stop-when-empty');
            sleep($step);
        }

    }
}
