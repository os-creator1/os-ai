<?php

    namespace Database\Seeders;

    use App\Models\ChatBox;
    use App\Models\ChatBoxMessage;
    use Illuminate\Database\Seeder;
    use Illuminate\Support\Facades\DB;
    use Faker\Factory as Faker;

    class ChatBoxSeeder extends Seeder
    {
        /**
         * Run the database seeds.
         *
         * @return void
         */
        public function run()
        {

            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            DB::table('chat_boxes')->truncate();
            DB::table('chat_box_messages')->truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');


// Initialize Faker
            $faker = Faker::create();

// Number of records you want to generate
            $totalRecords = 100;


// ChatBox creation
            for ($i = 1; $i <= $totalRecords; $i++) {
                $number = str_replace('+', '', $faker->e164PhoneNumber);

                $chatbox = [
                    'user_id'           => $faker->numberBetween(1, 4), // Assuming random user_id between 1 and 10
                    'from'              => $number,          // Random phone number for 'from'
                    'to'                => $number,          // Random phone number for 'to'
                    'notification'      => $faker->numberBetween(0, 10), // Random notification count between 0 and 10
                    'created_at'        => $faker->dateTimeBetween('-1 year'),
                    'updated_at'        => $faker->dateTimeBetween('-1 year'),
                    'sending_server_id' => '248',
                    'reply_by_customer' => $faker->boolean,
                    'pinned'            => false,
                ];

                // Create ChatBox entry
                $box = ChatBox::create($chatbox);

                // Create multiple ChatBoxMessages for each ChatBox
                $messageCount = $faker->numberBetween(3, 10); // Random number of messages between 3 and 10
                for ($j = 1; $j <= $messageCount; $j++) {
                    $chatbox_messages = [
                        'box_id'            => $box->id,
                        'message'           => $faker->text(50),  // Generate random message
                        'media_url'         => null,
                        'sms_type'          => 'sms',
                        'direction'         => $faker->randomElement(['outgoing', 'incoming']), // Randomly set send_by as 'from' or 'to'
                        'sending_server_id' => $faker->numberBetween(1, 3), // Random sending_server_id between 1 and 3
                    ];

                    // Create ChatBoxMessage entry
                    ChatBoxMessage::create($chatbox_messages);
                }
            }


            $totalRecords = 10;

            // ChatBox creation
            for ($i = 1; $i <= $totalRecords; $i++) {
                $number = str_replace('+', '', $faker->e164PhoneNumber);

                $chatbox = [
                    'user_id'           => $faker->numberBetween(1, 4), // Assuming random user_id between 1 and 10
                    'from'              => $number,          // Random phone number for 'from'
                    'to'                => $number,          // Random phone number for 'to'
                    'notification'      => $faker->numberBetween(0, 10), // Random notification count between 0 and 10
                    'created_at'        => $faker->dateTimeBetween('-1 year'),
                    'updated_at'        => $faker->dateTimeBetween('-1 year'),
                    'sending_server_id' => '248',
                    'reply_by_customer' => $faker->boolean,
                    'pinned'            => true,
                ];

                // Create ChatBox entry
                $box = ChatBox::create($chatbox);

                // Create multiple ChatBoxMessages for each ChatBox
                $messageCount = $faker->numberBetween(3, 10); // Random number of messages between 3 and 10
                for ($j = 1; $j <= $messageCount; $j++) {
                    $chatbox_messages = [
                        'box_id'            => $box->id,
                        'message'           => $faker->text(50),  // Generate random message
                        'media_url'         => null,
                        'sms_type'          => 'sms',
                        'direction'         => $faker->randomElement(['outgoing', 'incoming']), // Randomly set send_by as 'from' or 'to'
                        'sending_server_id' => $faker->numberBetween(1, 3), // Random sending_server_id between 1 and 3
                    ];

                    // Create ChatBoxMessage entry
                    ChatBoxMessage::create($chatbox_messages);
                }
            }

        }

    }
