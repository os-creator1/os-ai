<?php

    /** @var Factory $factory */

    use App\Models\Reports;
    use Illuminate\Database\Eloquent\Factory;
    use Faker\Generator as Faker;

    /*
    |--------------------------------------------------------------------------
    | Model Factories
    |--------------------------------------------------------------------------
    |
    | This directory should contain each of the model factory definitions for
    | your application. Factories provide a convenient way to generate new
    | model instances for testing / seeding your application's database.
    |
    */

    $factory->define(Reports::class, function (Faker $faker) {

        $direction = collect(['incoming', 'outgoing', 'api']);
        $smsTypes  = collect(['plain', 'mms', 'voice', 'viber', 'whatsapp', 'otp']);
        $status    = collect(['Delivered', 'Enroute', 'Undelivered', 'Expired', 'Rejected', 'Accepted', 'Skipped', 'Failed']);

        return [
            'uid'               => uniqid(),
            'user_id'           => 1,
            'from'              => ltrim('+', $faker->e164PhoneNumber()),
            'to'                => ltrim('+', $faker->e164PhoneNumber()),
            'message'           => $faker->text,
            'sms_type'          => $smsTypes->random(),
            'status'            => $status->random(),
            'customer_status'   => $status->random(),
            'direction'         => $direction->random(),
            'sms_count'         => 1,
            'cost'              => 1,
            'sending_server_id' => 219,
            'created_at'        => $faker->dateTimeThisMonth(),
            'updated_at'        => $faker->dateTimeThisMonth(),
        ];
    });
