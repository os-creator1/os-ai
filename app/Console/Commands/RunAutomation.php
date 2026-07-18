<?php

namespace App\Console\Commands;

use App\Models\Automation;
use Illuminate\Console\Command;

class RunAutomation extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'automation:run';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run automation campaigns';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        Automation::where('status', 'active')->chunk(50, function ($automations) {
            $automations->tap(function ($automations) {
                $automations->each->start();
            });
        });
    }
}
