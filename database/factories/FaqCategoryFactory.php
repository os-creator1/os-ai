<?php

    namespace Database\Factories;

    use Codeglen\Usupport\Models\FaqCategory;

    // Adjust model path if different
    use Illuminate\Database\Eloquent\Factories\Factory;
    use Illuminate\Support\Str;

    class FaqCategoryFactory extends Factory
    {
        /**
         * The name of the factory's corresponding model.
         *
         * @var string
         */
        protected $model = FaqCategory::class;

        /**
         * Define the model's default state.
         *
         * @return array
         */
        public function definition()
        {
            $name = $this->faker->unique()->words(2, true) . ' Topics';

            // A list of common feather icons for variety
            $icons = ['archive', 'life-buoy', 'cpu', 'shield', 'settings', 'message-circle'];

            return [
                'uid'         => (string) Str::uuid(),
                'name'        => $name,
                'slug'        => Str::slug($name),
                'icon'        => $this->faker->randomElement($icons),
                'description' => $this->faker->text(),
                'sort_order'  => $this->faker->numberBetween(1, 100),
                'is_active'   => $this->faker->boolean(80), // 80% chance of being active
            ];
        }

    }
