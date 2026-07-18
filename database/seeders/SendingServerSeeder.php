<?php

    namespace Database\Seeders;

    use App\Models\SendingServer;
    use App\Repositories\Eloquent\EloquentSendingServerRepository;
    use Illuminate\Database\Seeder;
    use Illuminate\Support\Facades\DB;

    class SendingServerSeeder extends Seeder
    {
        /**
         * Run the database seeds.
         *
         * @return void
         */
        public function run()
        {

            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            DB::table('sending_servers')->truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            $sendingServersRepo = new EloquentSendingServerRepository(new SendingServer());
            $sendingServers     = collect($sendingServersRepo->allSendingServer());

            foreach ($sendingServers->reverse() as $server) {
                $server['user_id'] = 1;
                $server['status']  = true;

                SendingServer::create($server);
            }
        }

    }
