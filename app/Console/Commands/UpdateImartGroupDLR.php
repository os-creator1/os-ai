<?php

namespace App\Console\Commands;

use App\Http\Controllers\Customer\DLRController;
use App\Models\Reports;
use App\Models\SendingServer;
use Carbon\Carbon;
use Illuminate\Console\Command;

class UpdateImartGroupDLR extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'imartgroup:dlr';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check ImartGroup DLR';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $sending_servers = SendingServer::where('settings', SendingServer::TYPE_IMARTGROUP)->where('status', 1)->get();

        if ($sending_servers->count() > 0) {
            foreach ($sending_servers as $server) {

                $reports = Reports::where('sending_server_id', $server->id)->where('created_at', '>=', Carbon::now()->subHour())->get();

                foreach ($reports->chunk(100) as $chunk) {
                    foreach ($chunk as $report) {
                        $status = explode('|', $report->status);
                        if (is_array($status) && count($status) > 1 && array_key_exists('1', $status)) {
                            $sms_id = $status[1];
                            $api_key = $server->api_key;

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, "http://smsportal.imartgroup.co.tz/app/miscapi/$api_key/getDLR/$sms_id");
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            $result = curl_exec($ch);

                            curl_close($ch);

                            if ($result != null) {

                                $get_result = json_decode($result, true);

                                if (is_array($get_result) && ! isset($get_result['DLR']) && isset($get_result['MSISDN'])) {
                                    DLRController::updateDLR($sms_id, $get_result['DLR'], $get_result['MSISDN']);
                                }
                            }
                        }
                    }
                }
            }
        }

        return 0;
    }
}
