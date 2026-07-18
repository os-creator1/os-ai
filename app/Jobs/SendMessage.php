<?php

    namespace App\Jobs;

    use App\Exceptions\CampaignPausedException;
    use App\Helpers\Helper;
    use App\Library\Exception\NoCreditsLeft;
    use App\Library\Exception\RateLimitExceeded;
    use App\Models\Contacts;
    use Carbon\Carbon;
    use DateTime;
    use Exception;
    use Illuminate\Bus\Batchable;
    use Illuminate\Bus\Queueable;
    use Illuminate\Contracts\Queue\ShouldQueue;
    use Illuminate\Foundation\Bus\Dispatchable;
    use Illuminate\Queue\InteractsWithQueue;
    use Illuminate\Queue\SerializesModels;
    use Throwable;
    use function App\Helpers\execute_with_limits;
    use function App\Helpers\plogger;
    use Illuminate\Support\Facades\Log as LaravelLog;

    class SendMessage implements ShouldQueue
    {
        use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

        public int $timeout = 900;

        protected      $contact;
        protected      $campaign;
        protected      $server;
        protected      $priceOption;
        protected      $triggerId;
        protected bool $stopOnError = false;

        public function __construct($campaign, Contacts $contact, $server, $priceOption, $triggerId = null)
        {
            $this->campaign    = $campaign;
            $this->contact     = $contact;
            $this->server      = $server;
            $this->priceOption = $priceOption;
            $this->triggerId   = $triggerId;
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
            return now()->addDays(30);
        }

        /**
         * @throws Exception
         */
        public function handle(): void
        {
            if ($this->batch()?->cancelled()) return;

            $this->campaign->debug(function ($info) {
                $info['last_activity_at'] = Carbon::now()->toString();

                return $info;
            });

            $this->send();
        }

        /**
         * @throws Exception
         */
        public function send($exceptionCallback = null)
        {
            $subscription = $this->campaign->user->customer->getCurrentSubscription();
            $startAt      = Carbon::now()->getTimestampMs();

            $logger  = $this->campaign->logger();
            $plogger = plogger($this->campaign->uid);
            $phone   = $this->contact->phone;

            $logger->info("Sending to {$phone} [Server \"{$this->server->name}\"]");
            $plogger->info("Sending to {$phone} [Server \"{$this->server->name}\"]");

            $rateTrackers = [$this->server->getRateLimitTracker()];
            if ($subscription) {
                $rateTrackers[] = $subscription->getSendSMSRateTracker();
            }

            try {
                if ($this->campaign->user->sms_unit != '-1' && $this->campaign->user->sms_unit == 0) {
                    throw new CampaignPausedException(
                        sprintf(
                            "Campaign `%s` (%s) halted, customer exceeds sms balance",
                            $this->campaign->campaign_name,
                            $this->campaign->uid
                        )
                    );
                }

                $finishPreparingAt = Carbon::now()->getTimestampMs();
                $startGettingLock  = Carbon::now()->getTimestampMs();

                execute_with_limits($rateTrackers, function () use ($startAt, $logger, $plogger, $startGettingLock, $phone) {

                    $getLockAt       = Carbon::now()->getTimestampMs();
                    $getLockDiff     = ($getLockAt - $startAt) / 1000;
                    $lockWaitingTime = ($getLockAt - $startGettingLock) / 1000;

                    $logger->info("Got lock for {$phone} after {$getLockDiff} seconds (lock waiting time {$lockWaitingTime})");
                    $plogger->info("Got lock for {$phone} after {$getLockDiff} seconds (lock waiting time {$lockWaitingTime})");

                    $sent = $this->campaign->send($this->contact, $this->priceOption, $this->server);
                    $this->campaign->track_message($sent, $this->contact, $this->server);

                    $logger->info("Sent to {$phone}");
                    $plogger->info("Sent to {$phone}");
                    $logger->info("Done with {$phone} [Server \"{$this->server->name}\"]");
                    $plogger->info("Done with {$phone} [Server \"{$this->server->name}\"]");
                });

                $now      = Carbon::now();
                $finishAt = $now->getTimestampMs();

                $this->updateDebugStats($startAt, $finishAt, $finishPreparingAt);

            } catch (RateLimitExceeded $ex) {
                $this->handleRateLimit($ex, $exceptionCallback, $logger, $plogger, $phone, $rateTrackers);
            } catch (Throwable $ex) {
                $this->handleException($ex, $exceptionCallback, $logger, $plogger, $phone);
            }

            $plogger->info('SendMessage: ALL done');

            return true;
        }

        protected function updateDebugStats(int $startAt, int $finishAt, int $finishPreparingAt): void
        {
            $this->campaign->debug(function ($info) use ($startAt, $finishAt, $finishPreparingAt) {
                $now         = Carbon::now();
                $diff        = ($finishAt - $startAt) / 1000;
                $prepareDiff = ($finishPreparingAt - $startAt) / 1000;

                $info['send_message_avg_time']         = $this->calcAvg($info['send_message_avg_time'] ?? null, $info['send_message_count'], $diff);
                $info['send_message_prepare_avg_time'] = $this->calcAvg($info['send_message_prepare_avg_time'] ?? null, $info['send_message_count'], $prepareDiff);

                $info['send_message_count'] = ($info['send_message_count'] ?? 0) + 1;

                $info['send_message_min_time'] = min($info['send_message_min_time'] ?? $diff, $diff);
                $info['send_message_max_time'] = max($info['send_message_max_time'] ?? $diff, $diff);

                $campaignStartAt                  = $info['start_at'] ?? Carbon::now();
                $timeSinceCampaignStart           = $now->diffInSeconds(Carbon::parse($campaignStartAt));
                $info['total_time']               = $timeSinceCampaignStart ?: 1;
                $info['messages_sent_per_second'] = $info['send_message_count'] / $info['total_time'];

                $info['last_message_sent_at'] = $now->toString();
                $info['delay_note']           = null;

                return $info;
            });
        }

        protected function calcAvg(?float $avg, int $count, float $newVal): float
        {
            return is_null($avg) ? $newVal : ($avg * $count + $newVal) / ($count + 1);
        }

        /**
         * @throws Exception
         */
        protected function handleRateLimit(RateLimitExceeded $ex, $exceptionCallback, $logger, $plogger, $phone, $rateTrackers)
        {
            if ($exceptionCallback) return $exceptionCallback($ex);

            if ($this->batch()) {
                $lockKey = "campaign-delay-flag-lock-{$this->campaign->uid}";
                Helper::with_cache_lock($lockKey, function () use ($rateTrackers, $logger, $plogger, $phone, $ex) {
                    if ($this->campaign->checkDelayFlag()) {
                        $logger->info("Delayed [{$phone}] due to rate limit: {$ex->getMessage()}");
                        $plogger->warning("Delayed [{$phone}] due to rate limit: {$ex->getMessage()}");

                        return true;
                    }

                    $delayInSeconds = 60;
                    $logger->warning("Delay [{$phone}], dispatch WAITING job ({$delayInSeconds} seconds): {$ex->getMessage()}");
                    $plogger->warning("Delay [{$phone}], dispatch WAITING job ({$delayInSeconds} seconds): {$ex->getMessage()}");

                    $this->campaign->setDelayFlag(true);
                    $delay = new Delay($delayInSeconds, $this->campaign, $rateTrackers);
                    $this->batch()->add($delay);

                    $this->campaign->debug(fn($info) => ['delay_note' => "Speed limit hit: {$ex->getMessage()}"] + $info);

                    return '';
                });
            } else {
                $logger->warning("Delay [{$phone}] for 60 seconds (no batch): {$ex->getMessage()}");
                $plogger->warning("Delay [{$phone}] for 60 seconds (no batch): {$ex->getMessage()}");
                $this->release(60);
            }
        }

        protected function handleException(Throwable $ex, $exceptionCallback, $logger, $plogger, $phone)
        {
            if ($exceptionCallback) return $exceptionCallback($ex);

            $message = "Error sending to [{$phone}]. Error: {$ex->getMessage()}";
            LaravelLog::error('ERROR SENDING EMAIL (debug): ' . $ex->getTraceAsString());
            $logger->error($message);
            $plogger->error($message);

            $forceEndCampaignExceptions = [NoCreditsLeft::class];

            $forceEndCampaign = in_array(get_class($ex), $forceEndCampaignExceptions);

            if ($this->stopOnError || $forceEndCampaign) {
                $this->campaign->pause($message);
                $this->batch()?->cancel();
            } else {
                $this->campaign->track_message((object) [
                    'message_id' => null,
                    'status'     => 'Failed',
                    'sms_count'  => 1,
                    'cost'       => 0,
                ], $this->contact, $this->server);
            }
        }

    }
