<?php

namespace App\Console\Commands;

use App\Models\Campaigns;
use App\Models\ScheduleMessage;
use App\Models\SendingServer;
use App\Models\User;
use Carbon\Carbon;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendScheduleAPIMessage extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sms:schedule-api-message';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send Schedule API Message';

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
     * @throws Exception
     */
    public function handle(): void
    {
        $fromDate = Carbon::now()->subMinutes(3)->toDateTimeString();
        $toDate = Carbon::now()->toDateTimeString();
        //collect recurring campaign and check status
        $messages = ScheduleMessage::whereBetween('schedule_on', [$fromDate, $toDate])->cursor();
        $campaign = new Campaigns();
        foreach ($messages as $message) {
            $sms_type = $message->sms_type;
            $message['phone'] = $message->to;
            $message['sender_id'] = $message->from;
            $message['sending_server'] = SendingServer::find($message->sending_server);
            $status = null;

            if ($sms_type == 'plain' || $sms_type == 'unicode') {
                $status = $campaign->sendPlainSMS($message);
            }

            if ($sms_type == 'voice') {
                $status = $campaign->sendVoiceSMS($message);
            }

            if ($sms_type == 'mms') {
                $status = $campaign->sendMMS($message);
            }

            if ($sms_type == 'whatsapp') {
                $status = $campaign->sendWhatsApp($message);
            }

            if ($sms_type == 'viber') {
                $status = $campaign->sendViber($message);
            }

            if ($sms_type == 'otp') {
                $status = $campaign->sendOTP($message);
            }

            if (! substr_count($status, 'Delivered')) {
                $user = User::find($message->user_id);
                if ($user->sms_unit != '-1') {
                    DB::transaction(function () use ($user, $message) {
                        $remaining_balance = $user->sms_unit + $message->cost;
                        $user->lockForUpdate();
                        $user->update(['sms_unit' => $remaining_balance]);
                    });
                }
            }

            $message->delete();
        }

        $this->info('Message sent successfully');
    }
}
