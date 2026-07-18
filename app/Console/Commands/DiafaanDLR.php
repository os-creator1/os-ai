<?php

    namespace App\Console\Commands;

    use App\Http\Controllers\Customer\DLRController;
    use App\Models\Reports;
    use App\Models\SendingServer;
    use Carbon\Carbon;
    use Illuminate\Console\Command;
    use Illuminate\Support\Facades\Http;
    use Illuminate\Support\Str;

    class DiafaanDLR extends Command
    {
        protected $signature = 'diafaan:dlr';
        protected $description = 'Check Diafaan SMS delivery reports';

        public function handle()
        {
            $servers = SendingServer::where('settings', SendingServer::TYPE_DIAFAAN)
                ->where('status', 1)
                ->get();

            foreach ($servers as $server) {
                $reports = Reports::where('sending_server_id', $server->id)
                    ->where('created_at', '>=', Carbon::now()->subMinutes(10))
                    ->get()
                    ->filter(function ($report) {
                        $status = explode('|', $report->status);
                        return count($status) > 1;
                    });

                foreach ($reports->chunk(100) as $chunk) {
                    $responses = Http::pool(fn ($pool) =>
                    $chunk->map(fn ($report) => $this->prepareRequest($pool, $server, $report))->toArray()
                    );

                    foreach ($chunk as $index => $report) {
                        $status = explode('|', $report->status);
                        $sms_id = $status[1];
                        $response = $responses[$index];

                        $sms_status = $this->parseResponse($response);
                        DLRController::updateDLR($sms_id, $sms_status);
                    }
                }
            }

            return 0;
        }

        protected function prepareRequest($pool, $server, $report)
        {
            $status = explode('|', $report->status);
            $sms_id = $status[1];
            $params = http_build_query([
                'username' => $server->username,
                'password' => $server->password,
                'message-id' => $sms_id,
            ]);

            $url = $server->api_link . '/http/request-status-update?' . $params;
            return $pool->as($sms_id)->get($url);
        }

        protected function parseResponse($response)
        {
            if ($response->failed()) {
                return 'Failed';
            }

            $body = $response->body();

            return Str::contains($body, '201') ? 'Delivered'
                : (Str::contains($body, '200') ? 'Sent'
                    : (Str::contains($body, '301') ? 'Undelivered' : 'Unknown'));
        }
    }
