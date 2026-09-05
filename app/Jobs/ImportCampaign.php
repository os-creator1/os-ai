<?php

    namespace App\Jobs;

    use App\Library\SMSCounter;
    use App\Library\Tool;
    use App\Models\Campaigns;
    use App\Models\CsvData;
    use App\Models\CustomerBasedPricingPlan;
    use App\Models\FileCampaignData;
    use App\Models\PlansCoverageCountries;
    use App\Models\Reports;
    use App\Models\SendingServerBasedPricingPlans;
    use Carbon\Carbon;
    use Exception;
    use Illuminate\Bus\Queueable;
    use Illuminate\Contracts\Queue\ShouldQueue;
    use Illuminate\Foundation\Bus\Dispatchable;
    use Illuminate\Queue\InteractsWithQueue;
    use Illuminate\Queue\SerializesModels;
    use libphonenumber\PhoneNumberUtil;
    use Maatwebsite\Excel\Facades\Excel;
    use Maatwebsite\Excel\Excel as ExcelFormat;
    use Throwable;

    class ImportCampaign implements ShouldQueue
    {
        use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

        protected Campaigns $campaign;
        protected CsvData   $csvData;
        protected           $db_fields;
        protected           $plan_id;

        public function __construct(Campaigns $campaign, CsvData $csvData, $db_fields, $plan_id)
        {
            $this->campaign  = $campaign;
            $this->csvData   = $csvData;
            $this->db_fields = $db_fields;
            $this->plan_id   = $plan_id;
        }

        /**
         * @throws Throwable
         */
        public function handle(): void
        {
            $csvPath = storage_path($this->csvData->csv_data);

            // Detect file extension
            $extension = pathinfo($csvPath, PATHINFO_EXTENSION);

            if (strtolower($extension) === 'csv') {
                $sample   = file_get_contents($csvPath, false, null, 0, 1000);
                $encoding = mb_detect_encoding($sample, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true) ?: 'UTF-8';

                if ($encoding !== 'UTF-8') {
                    $utf8Path = storage_path('app/tmp_import_utf8.csv');
                    file_put_contents($utf8Path, mb_convert_encoding(file_get_contents($csvPath), 'UTF-8', $encoding));
                    $csvPath = $utf8Path;
                }

                $sample    = file_get_contents($csvPath, false, null, 0, 1000);
                $delimiter = substr_count($sample, ';') > substr_count($sample, ',') ? ';' : ',';

                $collection = Excel::toCollection(null, $csvPath, null, ExcelFormat::CSV, ['delimiter' => $delimiter])->first();
            } else {
                $collection = Excel::toCollection(null, $csvPath)->first();
            }

            $filteredCollection = $collection->reject(fn($row) => empty(array_filter($row->toArray(), fn($v) => $v !== null)));

            $campaign_fields = $collection->shift()->toArray();
            $collection      = $filteredCollection->skip($this->csvData->csv_header);
            $total           = $collection->count();
            $sending_server  = $this->campaign->sending_server_id ?? null;

            // Initialize campaign cache once
            $cache                 = [
                'ContactCount'         => $total,
                'DeliveredCount'       => 0,
                'FailedDeliveredCount' => 0,
                'NotDeliveredCount'    => 0,
            ];
            $this->campaign->cache = json_encode($cache);
            $this->campaign->update();

            Tool::resetMaxExecutionTime();

            $importData      = [];
            $failed_messages = [];
            $failed_reports  = [];
            $check_sender_id = $this->campaign->getSenderIds();
            $phoneUtil       = PhoneNumberUtil::getInstance(); // reuse instance

            // Cache coverage to avoid repeated queries

            $collection->chunk(1000)->each(function ($lines) use (
                &$importData,
                &$failed_messages,
                &$failed_reports,
                $campaign_fields,
                $check_sender_id,
                $sending_server,
                $phoneUtil,
                $cache
            ) {
                $coverageCache = [];
                foreach ($lines as $line) {
                    $sender_id = count($check_sender_id) > 0 ? $this->campaign->pickSenderIds() : null;
                    $line      = $line->toArray();
                    $data      = array_combine($this->db_fields, $line);

                    if (empty($data['phone'])) {
                        $failed_messages[] = "Missing phone number in line: " . json_encode($line);
                        continue;
                    }

                    $phone = str_replace(['+', '(', ')', '-', ' '], '', $data['phone']);

                    if ( ! Tool::validatePhone($phone)) {
                        $failed_messages[] = "Invalid phone number: $phone";
                        $failed_reports[]  = $phone;
                        continue;
                    }

                    $sms_type  = $this->campaign->sms_type;
                    $sms_count = 1;
                    $message   = null;

                    if ($this->campaign->message) {
                        $modify_data = array_combine($campaign_fields, array_map('trim', $line));
                        $message     = Tool::renderSMS($this->campaign->message, $modify_data);

                        $sms_counter = new SMSCounter();
                        $sms_count   = $sms_counter->count($message, $sms_type == 'whatsapp' ? 'WHATSAPP' : null)->messages;
                    }

                    // Parse phone number
                    try {
                        $phoneNumberObject = $phoneUtil->parse('+' . $phone);
                        $countryCode       = $phoneNumberObject->getCountryCode();
                        $isoCode           = $phoneUtil->getRegionCodeForNumber($phoneNumberObject);
                    } catch (Exception) {
                        $failed_messages[] = "Invalid phone format: $phone";
                        $failed_reports[]  = $phone;
                        continue;
                    }

                    $cacheKey = $countryCode . '_' . $isoCode;
                    if (isset($coverageCache[$cacheKey])) {
                        $coverage = $coverageCache[$cacheKey];
                    } else {
                        $coverage = CustomerBasedPricingPlan::where('user_id', $this->campaign->user_id)
                            ->whereHas('country', fn($q) => $q->where('country_code', $countryCode)->where('iso_code', $isoCode)->where('status', 1))
                            ->with('sendingServer')
                            ->first();

                        if ( ! $coverage) {
                            $coverage = PlansCoverageCountries::where(fn($q) => $q->whereHas('country', fn($q2) => $q2->where('country_code', $countryCode)->where('iso_code', $isoCode)->where('status', 1))->where('plan_id', $this->plan_id))
                                ->with('sendingServer')
                                ->first();
                        }

                        $coverageCache[$cacheKey] = $coverage;
                    }

                    if ( ! $coverage) {
                        $failed_messages[] = "Permission not enabled for region: $phone";
                        $failed_reports[]  = $phone;
                        continue;
                    }

                    $priceOption = json_decode($coverage->options, true);

                    if (config('app.gateway_wise_billing') && $sending_server) {
                        $getCoverage = SendingServerBasedPricingPlans::where('sending_server', $sending_server)
                            ->where('country_id', $coverage->country_id)
                            ->first();

                        if ( ! $getCoverage) {
                            $failed_messages[] = "Gateway region not enabled: $phone";
                            $failed_reports[]  = $phone;
                            continue;
                        }

                        $priceOption = json_decode($getCoverage->options, true);
                    }

                    if ( ! $sending_server) {
                        $serverMap      = ['unicode' => 'plain', 'voice' => 'voiceSendingServer', 'mms' => 'mmsSendingServer', 'whatsapp' => 'whatsappSendingServer', 'viber' => 'viberSendingServer', 'otp' => 'otpSendingServer'];
                        $db_sms_type    = $sms_type == 'unicode' ? 'plain' : $sms_type;
                        $serverKey      = $serverMap[$db_sms_type] ?? 'sendingServer';
                        $sending_server = $coverage->{$serverKey}->id ?? null;
                    }

                    if ( ! $sending_server) {
                        $failed_messages[] = "Sending server not found: $phone";
                        $failed_reports[]  = $phone;
                        continue;
                    }

                    $cost = $sms_count * $this->campaign->getCost($priceOption);

                    $importData[] = [
                        'user_id'           => $this->campaign->user_id,
                        'sending_server_id' => $sending_server,
                        'campaign_id'       => $this->campaign->id,
                        'sender_id'         => $sender_id,
                        'phone'             => $phone,
                        'sms_type'          => $sms_type,
                        'sms_count'         => $sms_count,
                        'cost'              => $cost,
                        'message'           => $message,
                        'created_at'        => Carbon::now(),
                        'updated_at'        => Carbon::now(),
                    ];
                }

                // Bulk insert valid numbers
                if ( ! empty($importData)) {
                    FileCampaignData::insert($importData);
                    $importData = [];
                }

                // Bulk insert reports for failed numbers
                if ( ! empty($failed_reports)) {
                    $reportData = array_map(fn($phone) => [
                        'uid'               => uniqid(),
                        'user_id'           => $this->campaign->user_id,
                        'business_id'       => $this->campaign->business_id,
                        'to'                => $phone,
                        'message'           => $this->campaign->message,
                        'sms_type'          => $this->campaign->sms_type,
                        'status'            => __('locale.customer.invalid_phone_number', ['phone' => $phone]),
                        'customer_status'   => 'Failed',
                        'sms_count'         => 0,
                        'cost'              => 0,
                        'sending_server_id' => $sending_server,
                        'campaign_id'       => $this->campaign->id,
                        'direction'         => Reports::DIRECTION_OUTGOING,
                        'from'              => $sender_id ?? null,
                        'created_at'        => Carbon::now(),
                        'updated_at'        => Carbon::now(),
                    ], array_unique($failed_reports));

                    Reports::insert($reportData);
                    $failed_reports = [];
                }

                // Update campaign cache
                $cache['FailedDeliveredCount'] += count($failed_messages);
                $this->campaign->cache         = json_encode($cache);
                $this->campaign->update();

                $failed_messages = [];
            });

            $this->campaign->execute();
        }

    }
