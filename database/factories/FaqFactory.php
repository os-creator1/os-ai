<?php

    namespace Database\Factories;

    use Codeglen\Usupport\Models\Faq;

    // Adjust model path if different
    use Codeglen\Usupport\Models\FaqCategory;

    // Need the category model to link
    use Illuminate\Database\Eloquent\Factories\Factory;
    use Illuminate\Support\Str;

    class FaqFactory extends Factory
    {
        /**
         * The name of the factory's corresponding model.
         *
         * @var string
         */
        protected $model = Faq::class;

        /**
         * Define the model's default state.
         *
         * @return array
         */
        public function definition()
        {
            return [
                'uid'             => (string) Str::uuid(),
                // Link to a random existing FaqCategory
                'faq_category_id' => FaqCategory::inRandomOrder()->first() ?? FaqCategory::factory(),

                'question'     => $this->faker->unique()->sentence(8) . '?',
                'answer'       => $this->faker->paragraphs(3, true),
                'sort_order'   => $this->faker->numberBetween(1, 100),
                'is_published' => $this->faker->boolean(90), // 90% chance of being published
            ];
        }

    }
