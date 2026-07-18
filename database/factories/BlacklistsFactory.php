<?php

    namespace Database\Factories;

    use App\Models\Blacklists;
    use Illuminate\Database\Eloquent\Factories\Factory;

    /**
     * @extends Factory<Blacklists>
     */
    class BlacklistsFactory extends Factory
    {

        protected $model = Blacklists::class;

        /**
         * Define the model's default state.
         *
         * @return array<string, mixed>
         */
        public function definition(): array
        {

            return [
                'user_id' => 3,
                'number'  => ltrim($this->faker->unique()->e164PhoneNumber(), '+'), // Removes leading '+' directly
                'reason'  => $this->faker->optional(0.9)->randomElement(['Optout by user', 'Blacklisted by admin', 'Other reasons']),
            ];
        }

    }
