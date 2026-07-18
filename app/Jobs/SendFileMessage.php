<?php

    namespace App\Jobs;

    use App\Exceptions\CampaignPausedException;
    use App\Helpers\Helper;
    use App\Library\Exception\NoCreditsLeft;
    use App\Library\Exception\RateLimitExceeded;
    use DateTime;
    use Exception;
    use Illuminate\Bus\Batchable;
    use Illuminate\Bus\Queueable;
    use Illuminate\Contracts\Queue\ShouldQueue;
    use Illuminate\Foundation\Bus\Dispatchable;
    use Illuminate\Queue\InteractsWithQueue;
    use Illuminate\Queue\SerializesModels;
    use Illuminate\Support\Facades\Log as LaravelLog;
    use Throwable;
    use function App\Helpers\execute_with_limits;
    use function App\Helpers\plogger;

    class SendFileMessage implements ShouldQueue
    {
        use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

        public int $timeout = 600;

        protected $sendData;
        protected $campaign;
        protected $triggerId;
        protected $server;
        protected $stopOnError = false;

        public function __construct($campaign, $sendData, $triggerId = null)
        {
            $this->campaign  = $campaign;
            $this->sendData  = $sendData;
            $this->triggerId = $triggerId;
            $this->server    = $sendData->sendingServer;
        }

        /**
         * @throws Exception
         */
        public function setStopOnError($value): void
        {
            if ( ! is_bool($value)) {
                throw new Exception('Parameter passed to setStopOnError must be bool');
            }
            $this->stopOnError = $value;
        }

        public function retryUntil(): DateTime
        {
            return now()->addHours(12);
        }

        /**
         * @throws Exception
         */
        public function handle(): void
        {
            if ($this->batch() && $this->batch()->cancelled()) {
                return;
            }
            $this->send();
        }

        /**
         * @throws Exception
         */
        public function send($exceptionCallback = null)
        {
            $logger  = $this->campaign->logger();
            $plogger = plogger($this->campaign->uid);
            $phone   = $this->sendData->phone;

            $logger->info(sprintf('Sending to %s [Server "%s"]', $phone, $this->server->name));
            $plogger->info(sprintf('Sending to %s [Server "%s"]', $phone, $this->server->name));

            $subscription = $this->campaign->user->customer->getCurrentSubscription();

            $rateTrackers = [
                $this->server->getRateLimitTracker(),
            ];

            if ( ! is_null($subscription)) {
                $rateTrackers[] = $subscription->getSendSMSRateTracker();
            }

            try {
                if ($this->campaign->user->sms_unit != '-1' && $this->campaign->user->sms_unit == 0) {
                    throw new CampaignPausedException(sprintf("Campaign `%s` (%s) halted, customer exceeds sms units limit", $this->campaign->campaign_name, $this->campaign->uid));
                }

                $sms_type = $this->sendData->sms_type;

                $preparedData = [
                    'user_id'        => $this->campaign->user_id,
                    'phone'          => $this->sendData->phone,
                    'sender_id'      => $this->sendData->sender_id,
                    'message'        => $this->sendData->message,
                    'sms_type'       => $sms_type,
                    'cost'           => $this->sendData->cost,
                    'sms_count'      => $this->sendData->sms_count,
                    'campaign_id'    => $this->campaign->id,
                    'sending_server' => $this->sendData->sendingServer,
                ];

                if ($sms_type == 'voice') {
                    $preparedData['language'] = $this->campaign->language;
                    $preparedData['gender']   = $this->campaign->gender;
                }

                if (in_array($sms_type, ['mms', 'whatsapp', 'viber'])) {
                    if (isset($this->campaign->media_url)) {
                        $preparedData['media_url'] = $this->campaign->media_url;
                    }
                    if (isset($this->campaign->language)) {
                        $preparedData['language'] = $this->campaign->language;
                    }
                }

                execute_with_limits($rateTrackers, function () use ($preparedData, $logger, $plogger) {
                    $getData = $this->campaign->sendSMS($preparedData);
                    $this->campaign->updateCache(substr_count($getData->status, 'Delivered') == 1 ? 'DeliveredCount' : 'FailedDeliveredCount');
                    $this->sendData->delete();

                    if (substr_count($getData->status, 'Delivered') == 1 && $this->campaign->user->sms_unit != '-1') {
                        $this->campaign->user->countSMSUnit($getData->cost);
                    }

                    $logger->info(sprintf('Sent to %s', $preparedData['phone']));
                    $plogger->info(sprintf('Sent to %s', $preparedData['phone']));
                });
            } catch (RateLimitExceeded $ex) {
                if ( ! is_null($exceptionCallback)) {
                    return $exceptionCallback($ex);
                }

                if ($this->batch()) {
                    $lockKey = "campaign-delay-flag-lock-{$this->campaign->uid}";
                    Helper::with_cache_lock($lockKey, function () use ($rateTrackers, $logger, $plogger, $phone, $ex) {
                        $delayFlag = $this->campaign->checkDelayFlag();

                        if ($delayFlag) {
                            // just finish the task
                            $logger->info(sprintf("Delayed [%s] due to rate limit: %s", $phone, $ex->getMessage()));
                            $plogger->warning(sprintf("Delayed [%s] due to rate limit: %s", $phone, $ex->getMessage()));

                            return true;
                        } else {
                            // Release the job, have it tried again later on, after 1 minutes

                            $delayInSeconds = 60; // reservation stately, so 60 seconds is good enough

                            $logger->warning(sprintf("Delay [%s], dispatch WAITING job (%s seconds): %s", $phone, $delayInSeconds, $ex->getMessage()));
                            $plogger->warning(sprintf("Delay [%s], dispatch WAITING job (%s seconds): %s", $phone, $delayInSeconds, $ex->getMessage()));

                            // set delay flag to true
                            $this->campaign->setDelayFlag(true);
                            $delay = new Delay($delayInSeconds, $this->campaign, $rateTrackers);
                            $this->batch()->add($delay);

                            $this->campaign->debug(function ($info) use ($ex) {
                                // @todo: consider making it an interface, rather than access the .delay_note attribute directly like this
                                $info['delay_note'] = sprintf("Speed limit hit: %s", $ex->getMessage());

                                // Must return;
                                return $info;
                            });
                        }

                        return '';
                    });

                } else {
                    $logger->warning(sprintf("Delay [%s] for 60 seconds (no batch): %s", $phone, $ex->getMessage()));
                    $plogger->warning(sprintf("Delay [%s] for 60 seconds (no batch): %s", $phone, $ex->getMessage()));
                    $this->release(600); // should be only 60 seconds
                }
            } catch (Throwable|CampaignPausedException $ex) {
                if ( ! is_null($exceptionCallback)) {
                    return $exceptionCallback($ex);
                }

                $message = sprintf("Error sending to [%s]. Error: %s", $phone, $ex->getMessage());
                LaravelLog::error('ERROR SENDING EMAIL (debug): ' . $ex->getTraceAsString());
                $logger->error($message);
                $plogger->error($message);

                // In case of these exceptions, stop campaign immediately even if stopOnError is currently false
                // This is helpful in certain cases: for example, when credits runs out, then it does not make sense to keep sending (and failing)
                $forceEndCampaignExceptions = [
                    NoCreditsLeft::class,
                    // Other "end-game" exception like "SendingServer out of credits, etc."
                ];

                $forceEndCampaign = in_array(get_class($ex), $forceEndCampaignExceptions);

                // There are 2 options here
                // Option 1: throw an exception and show it to users as the campaign status
                //     throw new Exception($message);
                // Option 2: just skip the error, log it and proceed with the next subscriber
                if ($this->stopOnError || $forceEndCampaign) {
                    $this->campaign->pause($message);
                    $this->batch()->cancel();
                } else {
                    $params = [
                        'message_id' => null,
                        'status'     => 'Failed',
                        'sms_count'  => 1,
                        'cost'       => 0,
                    ];

                    $sent = json_decode(json_encode($params));

                    $this->campaign->track_message($sent, $this->contact, $this->server);
                }
            } finally {
                //
            }

            $plogger->info('SendFileMessage: ALL done');

            return true;
        }

    }
