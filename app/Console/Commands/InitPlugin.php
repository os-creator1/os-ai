<?php

namespace App\Console\Commands;

use App\Models\Plugins;
use Exception;
use Illuminate\Console\Command;

class InitPlugin extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'plugin:init {name}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Make a sample plugin for Ultimate SMS. For example: php artisan plugin:init awesome/my_plugin';

    /**
     * Execute the console command.
     *
     * @throws Exception
     */
    public function handle(): void
    {
        $name = $this->argument('name');
        Plugins::init($name);

        echo "\e[32mPlugin \e[35m{$name}\033[0m \e[32mcreated & loaded!\n";
        echo "You can find its source files in the \e[35m./storage/app/plugins/{$name}\033[0m \e[32mfolder\n\033[0m";

    }
}
