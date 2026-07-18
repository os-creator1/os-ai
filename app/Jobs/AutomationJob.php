<?php

    namespace App\Jobs;

    use App\Library\Tool;
    use App\Library\Traits\Trackable;
    use App\Models\Automation;
    use App\Models\CustomerBasedPricingPlan;
    use App\Models\PlansCoverageCountries;
    use Illuminate\Support\Facades\Bus;
    use libphonenumber\NumberParseException;
    use libphonenumber\PhoneNumberUtil;
    use Throwable;

    class AutomationJob extends Base
    {
        use Trackable;

        protected Automation $automation;
        protected            $contacts;

        /**
         * Create a new job instance.
         */
        public function __construct(Automation $automation, $contacts)
        {
            $this->automation = $automation;
            $this->contacts   = $contacts;
        }

        /**
         * @return void
         * @throws NumberParseException
         * @throws Throwable
         */
        public function handle()
        {
            $batchList = [];
            $user      = $this->automation->user;

            Tool::resetMaxExecutionTime();

            $phoneUtil = PhoneNumberUtil::getInstance();

            $this->contacts->each(function ($contact) use (&$batchList, $user, $phoneUtil) {
                $phoneNumberObject = $phoneUtil->parse('+' . $contact->phone);
                $countryCode       = $phoneNumberObject->getCountryCode();
                $isoCode           = $phoneUtil->getRegionCodeForNumber($phoneNumberObject);

                if ( ! empty($countryCode) && ! empty($isoCode)) {

                    $coverage = CustomerBasedPricingPlan::where('user_id', $user->id)
                        ->whereHas('country', function ($query) use ($countryCode, $isoCode) {
                            $query->where('country_code', $countryCode)
                                ->where('iso_code', $isoCode)
                                ->where('status', 1);
                        })
                        ->with('sendingServer')
                        ->first();

                    if ( ! $coverage) {
                        $coverage = PlansCoverageCountries::where(function ($query) use ($user, $countryCode, $isoCode) {
                            $query->whereHas('country', function ($query) use ($countryCode, $isoCode) {
                                $query->where('country_code', $countryCode)
                                    ->where('iso_code', $isoCode)
                                    ->where('status', 1);
                            })->where('plan_id', $user->customer->activeSubscription()->plan_id);
                        })
                            ->with('sendingServer')
                            ->first();
                    }


                    if ($coverage) {
                        $priceOption = json_decode($coverage->options, true);

                        if (isset($this->automation->sending_server_id)) {
                            $sending_server = $this->automation->sendingServer;
                        } else {
                            $sending_server = $coverage->sendingServer;
                        }

                        $batchList[] = new SendAutomationMessage($this->automation, $contact, $sending_server, $user, $priceOption);
                    }
                }
            });

            $status = Bus::batch($batchList)
                ->allowFailures(false)
                ->onQueue('automation')
                ->dispatch();

            if ($status) {
                $this->automation->updateCache();
            }
        }

    }
