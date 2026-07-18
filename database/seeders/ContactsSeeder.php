<?php

namespace Database\Seeders;

use App\Models\ContactGroups;
use App\Models\Contacts;
use Faker\Factory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ContactsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {

        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::table('contact_groups')->truncate();
        DB::table('contact_groups_optin_keywords')->truncate();
        DB::table('contact_groups_optout_keywords')->truncate();
        DB::table('contact_group_fields')->truncate();
        DB::table('contact_group_field_options')->truncate();
        DB::table('contacts')->truncate();
        DB::table('contacts_custom_field')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        //contact groups
        $contact_groups = [
            /*Customer ID 1*/
            [
                'customer_id'              => 1,
                'name'                     => 'BlackFriday',
                'sender_id'                => 'USMS',
                'send_welcome_sms'         => true,
                'unsubscribe_notification' => true,
                'send_keyword_message'     => true,
                'status'                   => true,
                'cache'                    => json_encode([
                    'SubscribersCount'  => 95,
                    'TotalSubscribers'  => 100,
                    'UnsubscribesCount' => 5,
                ]),

            ],
            [
                'customer_id'              => 1,
                'name'                     => 'CyberMonday',
                'sender_id'                => '8801526970168',
                'send_welcome_sms'         => true,
                'unsubscribe_notification' => true,
                'send_keyword_message'     => false,
                'status'                   => true,
                'cache'                    => json_encode([
                    'SubscribersCount'  => 100,
                    'TotalSubscribers'  => 100,
                    'UnsubscribesCount' => 0,
                ]),
            ],
            [
                'customer_id'              => 1,
                'name'                     => 'Codeglen',
                'sender_id'                => 'Codeglen',
                'send_welcome_sms'         => true,
                'unsubscribe_notification' => true,
                'send_keyword_message'     => false,
                'status'                   => true,
                'cache'                    => json_encode([
                    'SubscribersCount'  => 9,
                    'TotalSubscribers'  => 10,
                    'UnsubscribesCount' => 1,
                ]),
            ],

            /*Customer ID 3*/

            [
                'customer_id'              => 3,
                'name'                     => 'MonthlyPromotion',
                'sender_id'                => 'Codeglen',
                'send_welcome_sms'         => true,
                'unsubscribe_notification' => true,
                'send_keyword_message'     => true,
                'status'                   => true,
                'cache'                    => json_encode([
                    'SubscribersCount'  => 95,
                    'TotalSubscribers'  => 100,
                    'UnsubscribesCount' => 5,
                ]),

            ],
            [
                'customer_id'              => 3,
                'name'                     => 'HalfYearlyPromotion',
                'sender_id'                => '8801921970168',
                'send_welcome_sms'         => true,
                'unsubscribe_notification' => true,
                'send_keyword_message'     => false,
                'status'                   => true,
                'cache'                    => json_encode([
                    'SubscribersCount'  => 100,
                    'TotalSubscribers'  => 100,
                    'UnsubscribesCount' => 0,
                ]),
            ],
            [
                'customer_id'              => 3,
                'name'                     => 'YearlyPromotion',
                'sender_id'                => 'CoderPixel',
                'send_welcome_sms'         => true,
                'unsubscribe_notification' => true,
                'send_keyword_message'     => false,
                'status'                   => true,
                'cache'                    => json_encode([
                    'SubscribersCount'  => 9,
                    'TotalSubscribers'  => 10,
                    'UnsubscribesCount' => 1,
                ]),
            ],
            /*Customer ID 4*/

            [
                'customer_id'              => 4,
                'name'                     => 'EidPromotion',
                'sender_id'                => 'DLT',
                'send_welcome_sms'         => true,
                'unsubscribe_notification' => true,
                'send_keyword_message'     => true,
                'status'                   => true,
                'cache'                    => json_encode([
                    'SubscribersCount'  => 95,
                    'TotalSubscribers'  => 100,
                    'UnsubscribesCount' => 5,
                ]),

            ],
            [
                'customer_id'              => 4,
                'name'                     => 'IndependenceDayPromotion',
                'sender_id'                => '8801521970168',
                'send_welcome_sms'         => true,
                'unsubscribe_notification' => true,
                'send_keyword_message'     => false,
                'status'                   => true,
                'cache'                    => json_encode([
                    'SubscribersCount'  => 100,
                    'TotalSubscribers'  => 100,
                    'UnsubscribesCount' => 0,
                ]),
            ],
            [
                'customer_id'              => 4,
                'name'                     => 'RepublicDayPromotion',
                'sender_id'                => '8801821970168',
                'send_welcome_sms'         => true,
                'unsubscribe_notification' => true,
                'send_keyword_message'     => false,
                'status'                   => true,
                'cache'                    => json_encode([
                    'SubscribersCount'  => 9,
                    'TotalSubscribers'  => 10,
                    'UnsubscribesCount' => 1,
                ]),
            ],
        ];

        foreach ($contact_groups as $group) {
            (new ContactGroups)->create($group);
        }

        $factory      = Factory::create();
        $data         = [];
        $customer_ids = [1, 3, 4];
        for ($i = 0; $i < 95; $i++) {
            $number = '88017' . $i . time();
            $number = substr($number, 0, 13);

            foreach ($customer_ids as $customer_id) {

                $group_id = match ($customer_id) {
                    3 => 4,
                    4 => 7,
                    default => 1,
                };

                $data[] = [
                    'uid'         => uniqid(),
                    'customer_id' => $customer_id,
                    'group_id'    => $group_id,
                    'phone'       => $number,
                    'status'      => 'subscribe',
                ];
            }
        }


        for ($i = 0; $i < 5; $i++) {
            $number = '88017' . $i . time();
            $number = substr($number, 0, 13);

            foreach ($customer_ids as $customer_id) {

                $group_id = match ($customer_id) {
                    3 => 4,
                    4 => 7,
                    default => 1,
                };

                $data[] = [
                    'uid'         => uniqid(),
                    'customer_id' => $customer_id,
                    'group_id'    => $group_id,
                    'phone'       => $number,
                    'status'      => 'unsubscribe',
                ];
            }
        }


        for ($i = 0; $i < 100; $i++) {
            $number = '88016' . $i . time();
            $number = substr($number, 0, 13);


            foreach ($customer_ids as $customer_id) {

                $group_id = match ($customer_id) {
                    3 => 5,
                    4 => 8,
                    default => 2,
                };

                $data[] = [
                    'uid'         => uniqid(),
                    'customer_id' => $customer_id,
                    'group_id'    => $group_id,
                    'phone'       => $number,
                    'status'      => 'subscribe',
                ];
            }

        }

        for ($i = 0; $i < 9; $i++) {
            $number = '88015' . $i . time();
            $number = substr($number, 0, 13);

            foreach ($customer_ids as $customer_id) {

                $group_id = match ($customer_id) {
                    3 => 6,
                    4 => 9,
                    default => 3,
                };

                $data[] = [
                    'uid'         => uniqid(),
                    'customer_id' => $customer_id,
                    'group_id'    => $group_id,
                    'phone'       => $number,
                    'status'      => 'subscribe',
                ];
            }
        }


        for ($i = 0; $i < 1; $i++) {
            $number = '88015' . $i . time();
            $number = substr($number, 0, 13);

            foreach ($customer_ids as $customer_id) {

                $group_id = match ($customer_id) {
                    3 => 6,
                    4 => 9,
                    default => 3,
                };

                $data[] = [
                    'uid'         => uniqid(),
                    'customer_id' => $customer_id,
                    'group_id'    => $group_id,
                    'phone'       => $number,
                    'status'      => 'unsubscribe',
                ];
            }
        }

        foreach ($data as $row) {
            $subscriber = Contacts::create($row);
            $subscriber->updateFields([
                "PHONE"      => $row['phone'],
                "FIRST_NAME" => $factory->firstName,
                "LAST_NAME"  => $factory->lastName,
            ]);
        }

    }
}
