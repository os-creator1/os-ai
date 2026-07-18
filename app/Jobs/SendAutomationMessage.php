<?php

namespace App\Jobs;


use App\Exceptions\CampaignPausedException;
use App\Library\Exception\QuotaExceeded;
use App\Library\QuotaManager;
use DateTime;
use Exception;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SendAutomationMessage implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    protected $automation;
    protected $contacts;
    protected $server;
    protected $user;
    protected $stopOnError = false;
    protected $priceOption;

    /**
     * Create a new job instance.
     */
    public function __construct($automation, $contact, $server, $user, $priceOption)
    {
        $this->automation  = $automation;
        $this->contacts    = $contact;
        $this->server      = $server;
        $this->user        = $user;
        $this->priceOption = $priceOption;
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

    /**
     * Determine the time at which the job should timeout.
     *
     * @return DateTime
     */
    public function retryUntil(): DateTime
    {
        return now()->addHours(12);
    }


    /**
     * @throws QuotaExceeded|Exception
     */
    public function handle(): void
    {
        if ($this->batch() && $this->batch()->cancelled()) {
            return;
        }

        $this->send();

    }

    /**
     * Execute the job.
     *
     * @throws Exception
     */
    public function send($exceptionCallback = null): void
    {

        $subscription = $this->user->customer->getCurrentSubscription();

        try {

            if ($this->user->sms_unit != '-1' && $this->user->sms_unit == 0) {
                throw new CampaignPausedException(sprintf("Automation `%s` (%s) halted, customer exceeds sms balance", $this->automation->name, $this->automation->uid));
            }

            QuotaManager::with($subscription, 'send')->enforce();
            QuotaManager::with($this->server, 'send')->enforce();

            $sent = $this->automation->send($this->contacts, $this->priceOption, $this->server);

            $this->automation->track_message($sent, $this->contacts, $this->server);

        } catch (QuotaExceeded $ex) {
            if ( ! is_null($exceptionCallback)) {
                $exceptionCallback($ex);
            }
            $this->release(60);
        } catch (CampaignPausedException $ex) {
            if ( ! is_null($exceptionCallback)) {
                $exceptionCallback($ex);
            }
            $this->automation->pause($ex->getMessage());

        } catch (Throwable $ex) {
            if ( ! is_null($exceptionCallback)) {
                $exceptionCallback($ex);
            }
            $message = sprintf("Error sending to [%s]. Error: %s", $this->contact, $ex->getMessage());
            if ($this->stopOnError) {
                $this->campaign->setError($message);
            }
        }

    }
}
