<?php

    namespace App\Console\Commands;

    use Illuminate\Console\Command;
    use Illuminate\Support\Facades\DB;

    class ClearTicketsData extends Command
    {
        /**
         * The name and signature of the console command.
         *
         * @var string
         */
        protected $signature = 'app:clear-tickets-data';

        /**
         * The console command description.
         *
         * @var string
         */
        protected $description = 'Remove all tickets data';

        /**
         * Execute the console command.
         */
        public function handle()
        {
            $this->comment('Clearing tickets data...');

            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            DB::table('support_tickets')->truncate();
            DB::table('ticket_replies')->truncate();
            DB::table('ticket_attachments')->truncate();
            DB::table('ticket_ticket_tag')->truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
            $this->comment('Tickets data cleared!');
        }

    }
