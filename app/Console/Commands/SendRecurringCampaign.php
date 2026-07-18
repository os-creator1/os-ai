<?php

namespace App\Console\Commands;

use App\Models\Campaigns;
use App\Models\CampaignsList;
use App\Models\CampaignsSenderid;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

class SendRecurringCampaign extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'campaign:recurring';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send Recurring Campaigns';

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
     * @throws Throwable
     */
    public function handle(): int
    {

        $fromDate = Carbon::now()->subDay()->toDateTimeString();
        $toDate = Carbon::now()->toDateTimeString();
        //collect recurring campaign and check status
        $recurring = Campaigns::where('schedule_type', 'recurring')->where('status', '!=', 'paused')->where('recurring_created', 0)->whereBetween('schedule_time', [$fromDate, $toDate])->get();

        foreach ($recurring as $sms) {
            if ($sms->recurring_end->gt(Carbon::now())) {
                // recurring running
                $sms->execute();

                if ($sms->frequency_cycle != 'custom') {
                    $schedule_cycle = $sms::scheduleCycleValues();
                    $limits = $schedule_cycle[$sms->frequency_cycle];
                    $frequency_amount = $limits['frequency_amount'];
                    $frequency_unit = $limits['frequency_unit'];
                } else {
                    $frequency_amount = $sms->frequency_amount;
                    $frequency_unit = $sms->frequency_unit;
                }

                $schedule_date = $sms->nextScheduleDate($sms->schedule_time, $frequency_unit, $frequency_amount);

                $new_camp = $sms->replicate()->fill([
                    'status' => Campaigns::STATUS_SCHEDULED,
                    'schedule_time' => $schedule_date,
                ]);

                $new_camp->uid = uniqid();
                $data = $new_camp->save();
                $sms->recurring_created = true;
                $sms->save();

                if ($data) {

                    //insert campaign contact list
                    foreach (CampaignsList::where('campaign_id', $sms->id)->cursor() as $list) {
                        CampaignsList::create([
                            'campaign_id' => $new_camp->id,
                            'contact_list_id' => $list->contact_list_id,
                        ]);
                    }

                    //insert campaign sender ids
                    foreach (CampaignsSenderid::where('campaign_id', $sms->id)->get() as $sender_ids) {
                        $new_camp->senderids()->create([
                            'sender_id' => $sender_ids->sender_id,
                            'originator' => $sender_ids->originator,
                        ]);
                    }
                }

            } else {
                //recurring date end
                $sms->setDone();
            }
        }

        return 0;
    }
}
