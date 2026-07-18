<?php

    namespace App\Console\Commands;

    use App\Http\Controllers\Customer\DLRController;
    use App\Models\Reports;
    use App\Models\SendingServer;
    use Carbon\Carbon;
    use Illuminate\Console\Command;

    class GatewayapiDLR extends Command
    {
        protected $signature   = 'gatewayapi:dlr';
        protected $description = 'Check Gatewayapi DLR';

        public function handle()
        {
            $server = SendingServer::where('settings', 'Gatewayapi')
                ->where('status', 1)
                ->first();

            if ( ! $server) {
                $this->error('No active Gatewayapi server found.');

                return 1;
            }

            $authHeader = 'Basic ' . base64_encode($server->api_token . ':');
            $headers    = [
                'Authorization: ' . $authHeader,
                'Accept: application/json, text/javascript',
                'Content-Type: application/json',
            ];

            Reports::where('sending_server_id', $server->id)
                ->where('created_at', '>=', Carbon::now()->subHour())
                ->where('direction', Reports::DIRECTION_OUTGOING)
                ->chunkById(100, function ($reports) use ($server, $headers) {
                    foreach ($reports as $report) {
                        $statusParts = explode('|', $report->status);

                        if (count($statusParts) < 2) {
                            continue;
                        }

                        $sms_id = $statusParts[1];
                        $url    = rtrim($server->api_link, '/') . '/' . $sms_id;

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $url);
                        curl_setopt($ch, CURLOPT_HTTPGET, true);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                        $response = curl_exec($ch);

                        if (curl_errno($ch)) {
                            $sms_status = 'Error: ' . curl_error($ch);
                        } else {
                            $parsed = json_decode($response, true);

                            if (is_array($parsed) && isset($parsed['recipients'][0]['dsnstatus'], $parsed['id'])) {
                                $sms_status = $parsed['recipients'][0]['dsnstatus'];
                            } else $sms_status = $parsed['message'] ?? (string) $response;
                        }

                        curl_close($ch);

                        if ($sms_status == 'DELIVRD' || $sms_status == 'DELIVERED') {
                            $sms_status = 'Delivered';
                        } else {
                            $sms_status = ucfirst(strtolower($sms_status));
                        }

                        DLRController::updateDLR($sms_id, $sms_status);
                    }
                });

            return 0;
        }

    }
