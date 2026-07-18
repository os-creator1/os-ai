<?php

namespace App\Console\Commands;

use App\Models\ChatBox;
use Illuminate\Console\Command;

class ClearChatbox extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:clear-chatbox';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear last 7 days of chatbox data';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Clearing chatbox data...');

        ChatBox::where('updated_at', '<', now()->subDays(7))->with('chatBoxMessages')->delete();

        $this->info('Chatbox data cleared.');

        return true;
    }
}
