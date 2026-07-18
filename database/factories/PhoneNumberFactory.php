<?php

/** @var Factory $factory */

use App\Models\PhoneNumbers;
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

$factory->define(PhoneNumbers::class, function (Faker $faker) {
    return [
            'user_id'          => 3,
            'number'           => ltrim($faker->unique()->e164PhoneNumber, '+'),
            'status'           => 'assigned',
            'capabilities'     => json_encode(['sms', 'voice', 'mms', 'whatsapp']),
            'price'            => 5,
            'billing_cycle'    => 'monthly',
            'frequency_amount' => 1,
            'frequency_unit'   => 'month',
            'currency_id'      => 3,
    ];
});
