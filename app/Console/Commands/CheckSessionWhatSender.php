<?php

namespace App\Console\Commands;

use App\Models\SendingServer;
use Illuminate\Console\Command;

class CheckSessionWhatSender extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'session:whatsender';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check WhatSender Sessions';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): bool
    {

        foreach (SendingServer::where('settings', 'Whatsender')->where('type', 'whatsapp')->lazy() as $server) {

            $ch = curl_init();

            curl_setopt_array($ch, [
                CURLOPT_URL => 'https://api.whatsender.io/v1/devices/'.$server->device_id.'/sync',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'GET',
                CURLOPT_HTTPHEADER => [
                    'Token: '.$server['api_token'],
                ],
            ]);

            $response = curl_exec($ch);
            $err = curl_error($ch);

            curl_close($ch);

            if ($err) {
                return false;
            }

            $sync = json_decode($response, true);

            if (is_array($sync) && array_key_exists('status', $sync)) {
                if ($sync['status'] == 'operative' && $sync['sessionStatus'] == 'online') {
                    $server->update([
                        'status' => true,
                    ]);

                    return true;
                }

                $server->update([
                    'status' => false,
                ]);

                return false;
            }
            $server->update([
                'status' => false,
            ]);

            return false;
        }

        return true;
    }
}
