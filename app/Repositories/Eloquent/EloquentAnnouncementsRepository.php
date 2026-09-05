<?php

    namespace App\Repositories\Eloquent;

    use App\Library\SMSCounter;
    use App\Models\Announcements;
    use App\Models\Campaigns;
    use App\Models\SendingServer;
    use App\Models\User;
    use App\Notifications\AnnouncementNotification;
    use App\Repositories\Contracts\AnnouncementsRepository;
    use Illuminate\Http\Client\ConnectionException;
    use Illuminate\Http\JsonResponse;
    use Illuminate\Support\Arr;

    class EloquentAnnouncementsRepository extends EloquentBaseRepository implements AnnouncementsRepository
    {
        /**
         * EloquentAnnouncementsRepository constructor.
         */
        public function __construct(Announcements $announcement)
        {
            parent::__construct($announcement);
        }

        /**
         * @throws ConnectionException
         */
        public function store(array $input)
        {
            if (isset($input['customer']) && $input['customer'] != '0') {

                if (isset($input['users_id']) && is_array($input['users_id']) && count($input['users_id']) > 0) {

                    $customers = User::whereIn('id', $input['users_id'])->get();

                } else {

                    return response()->json([
                        'status'  => 'error',
                        'message' => __('locale.labels.select_one_or_multiple') . ' ' . __('locale.menu.Customers'),
                    ]);
                }

            } else {
                $customers = User::where('status', 1)->where('is_customer', true)->get();
            }

            if ($customers->count() <= 0) {

                return response()->json([
                    'status'  => 'error',
                    'message' => __('locale.announcements.no_customer_found'),
                ]);
            }

            $sendBy = isset($input['send_by']) && $input['send_by'] == 'send_by_email' ? 'email' : 'sms';

            $users_ids = Arr::pluck($customers, 'id');

            $announcement = new Announcements([
                'user_id'     => auth()->id(),
                'title'       => $input['title'],
                'description' => $input['description'],
                'type'        => $sendBy,
            ]);

            $announcement->save();

            $announcement->users()->attach($users_ids);

            Announcements::with('users')->latest()->get();

            if ($sendBy == 'email' && isset($input['send_email']) && $input['send_email'] == 'yes') {
                foreach ($customers as $customer) {
                    $customer->notify(new AnnouncementNotification($announcement));
                }
            }

            if ($sendBy == 'sms' && isset($input['sending_server']) && $input['sending_server'] != '0') {
                $sendingServer = SendingServer::where('status', 1)->find($input['sending_server']);

                $sender_id    = $input['sender_id'] ?? null;
                $sms_counter  = new SMSCounter();
                $message_data = $sms_counter->count($input['title']);
                $sms_count    = $message_data->messages;


                foreach ($customers as $user) {
                    if ($sendingServer && isset($user->customer->phone)) {

                        $sendData = [
                            'sender_id'      => $sender_id,
                            'phone'          => $user->customer->phone,
                            'sending_server' => $sendingServer,
                            'user_id'        => 1,
                            'sms_type'       => 'plain',
                            'status'         => null,
                            'cost'           => $sms_count * 1,
                            'sms_count'      => $sms_count,
                            'message'        => $input['title'],
                        ];

                        $campaign = new Campaigns();
                        $campaign->sendPlainSMS($sendData);
                    }
                }

            }

            return response()->json([
                'status'  => 'success',
                'message' => __('locale.announcements.announcement_sent_successfully'),
            ]);

        }

        private function save(Announcements $announcement): bool
        {
            if ( ! $announcement->save()) {
                return false;
            }

            return true;
        }

        /**
         * Update the announcements with the provided input.
         *
         * @param Announcements $announcements The announcements to update
         * @param array         $input The input data for the update
         * @return JsonResponse The JSON response indicating the status and message
         */
        public function update(Announcements $announcements, array $input): JsonResponse
        {
            if ( ! $announcements->update($input)) {

                return response()->json([
                    'status'  => 'success',
                    'message' => __('locale.exceptions.something_went_wrong'),
                ]);
            }

            return response()->json([
                'status'  => 'success',
                'message' => __('locale.announcements.announcement_updated_successfully'),
            ]);
        }

        public function destroy(Announcements $announcements)
        {
            $announcements->users()->detach();

            if ( ! $announcements->delete()) {
                return response()->json([
                    'status'  => 'success',
                    'message' => __('locale.exceptions.something_went_wrong'),
                ]);
            }


            return response()->json([
                'status'  => 'success',
                'message' => __('locale.announcements.announcement_deleted_successfully'),
            ]);
        }

        /**
         * A function to batch destroy announcements.
         *
         * @param array $ids The array of announcement ids to be destroyed
         * @return JsonResponse
         */
        public function batchDestroy(array $ids): JsonResponse
        {

            if (count($ids) > 0) {
                // Detach users for each announcement
                Announcements::whereIn('uid', $ids)->each(function ($announcement) {
                    $announcement->users()->detach();
                });

                // Delete the announcements
                Announcements::whereIn('uid', $ids)->delete();

                return response()->json([
                    'status'  => 'success',
                    'message' => __('locale.announcements.announcement_deleted_successfully'),
                ]);
            }

            return response()->json([
                'status'  => 'error',
                'message' => __('locale.labels.at_least_one_data'),
            ]);
        }

    }
