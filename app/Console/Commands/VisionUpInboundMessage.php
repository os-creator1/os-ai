<?php

namespace App\Console\Commands;

use App\Http\Controllers\Customer\DLRController;
use App\Models\PhoneNumbers;
use App\Models\Reports;
use App\Models\Senderid;
use App\Models\SendingServer;
use Carbon\Carbon;
use Illuminate\Console\Command;
use libphonenumber\NumberParseException;
use Throwable;

class VisionUpInboundMessage extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'visionup:inbound';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Store Vision Up inbound messages';

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
     *
     * @throws NumberParseException
     * @throws Throwable
     */
    public function handle(): int
    {
        $sending_servers = SendingServer::where('settings', 'VisionUp')->where('status', 1)->cursor();
        if ($sending_servers->count() > 0) {
            foreach ($sending_servers as $server) {
                $reports = Reports::where('sending_server_id', $server->id)->where('created_at', '>=', Carbon::now()->subDay())->cursor();
                foreach ($reports->chunk(100) as $chunk) {
                    foreach ($chunk as $report) {
                        $status = explode('|', $report->status);
                        if (is_array($status) && count($status) > 1 && array_key_exists('1', $status)) {
                            $sms_id = $status[1];

                            $headers = [
                                'Content-Type: application/json',
                                'Accept: application/json',
                                'Authorization: Basic '.base64_encode("$server->username:$server->password"),
                            ];

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                            curl_setopt($ch, CURLOPT_URL, "http://142.93.78.16/api/sms/$sms_id/responses");
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            $result = curl_exec($ch);

                            curl_close($ch);

                            if ($result != null) {

                                $get_result = json_decode($result, true);

                                if (is_array($get_result) && ! isset($get_result['message'])) {
                                    foreach ($get_result as $data) {
                                        $message_count = strlen(preg_replace('/\s+/', ' ', trim($data['content']))) / 160;
                                        $cost = (int) ceil($message_count);

                                        $extra = null;
                                        $phone_number = PhoneNumbers::where('number', $report->from)->where('status', 'assigned')->first();
                                        if (! $phone_number) {
                                            $sender_id = Senderid::where('sender_id', $report->from)->first();
                                            if ($sender_id) {
                                                $extra = $report->from;
                                            }
                                        }

                                        $callback = DLRController::inboundDLR($report->to, $data['content'], $server->settings, $cost, $report->from, null);
                                        if ($callback == 'Success') {
                                            $report->update([
                                                'status' => 'Delivered',
                                            ]);
                                        }
                                    }

                                    return 1;
                                }
                            }

                            return 0;
                        }
                    }
                }
            }
        }

        return 0;
    }
}
