<?php

    namespace Database\Seeders;

    use Codeglen\Usupport\Models\Faq;

    // Adjust model path if different
    use Codeglen\Usupport\Models\FaqCategory;

    // Adjust model path if different
    use Illuminate\Database\Seeder;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Str;

    class FaqSeeder extends Seeder
    {
        /**
         * Run the database seeds.
         *
         * @return void
         */
        public function run()
        {
            $this->command->info('Seeding FAQ Categories...');

            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            FaqCategory::truncate();
            Faq::truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            // 1. Define Categories with Icons and Descriptions from HTML
            $categories = [
                [
                    'name'        => 'Payment',
                    'icon'        => 'credit-card',
                    'description' => 'Which license do I need?',
                    'faqs'        => [
                        [
                            'question' => 'Does my subscription automatically renew?',
                            'answer'   => 'Pastry pudding cookie toffee bonbon jujubes jujubes powder topping. Jelly beans gummi bears sweet roll bonbon muffin liquorice. Wafer lollipop sesame snaps. Brownie macaroon cookie muffin cupcake candy caramels tiramisu.',
                        ],
                        [
                            'question' => 'Can I store the item on an intranet so everyone has access?',
                            'answer'   => 'Sweet pie candy jelly. Sesame snaps biscuit sugar plum. Sweet roll topping fruitcake. Caramels liquorice biscuit ice cream fruitcake cotton candy tart. Donut caramels gingerbread jelly-o gingerbread pudding.',
                        ],
                        [
                            'question' => 'What does non-exclusive mean?',
                            'answer'   => 'Tart gummies dragée lollipop fruitcake pastry oat cake. Cookie jelly jelly macaroon icing jelly beans soufflé cake sweet. Macaroon sesame snaps cheesecake tart cake sugar plum.',
                        ],
                        [
                            'question' => 'Is the Regular License the same thing as an editorial license?',
                            'answer'   => 'Cheesecake muffin cupcake dragée lemon drops tiramisu cake gummies chocolate cake. Marshmallow tart croissant. Tart dessert tiramisu marzipan lollipop lemon drops.',
                        ],
                        [
                            'question' => 'Which license do I need for an end product that is only accessible to paying users?',
                            'answer'   => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.',
                        ],
                        [
                            'question' => 'Which license do I need to use an item in a commercial?',
                            'answer'   => 'At tempor commodo ullamcorper a lacus vestibulum. Ultrices neque ornare aenean euismod. Dui vivamus arcu felis bibendum. Turpis in eu mi bibendum neque egestas congue.',
                        ],
                        [
                            'question' => 'Can I re-distribute an item? What about under an Extended License?',
                            'answer'   => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Euismod lacinia at quis risus sed vulputate odio ut enim.',
                        ],
                    ],
                ],
                [
                    'name'        => 'Delivery',
                    'icon'        => 'shopping-bag',
                    'description' => 'Which license do I need?',
                    'faqs'        => [
                        [
                            'question' => 'Where has my order reached?',
                            'answer'   => 'Pastry pudding cookie toffee bonbon jujubes jujubes powder topping. Jelly beans gummi bears sweet roll bonbon muffin liquorice. Wafer lollipop sesame snaps.',
                        ],
                        [
                            'question' => 'The shipment status shows that it has been returned/cancelled. What does it mean and who do I contact?',
                            'answer'   => 'Sweet pie candy jelly. Sesame snaps biscuit sugar plum. Sweet roll topping fruitcake. Caramels liquorice biscuit ice cream fruitcake cotton candy tart.',
                        ],
                        [
                            'question' => 'What if my shipment is marked as lost?',
                            'answer'   => 'Tart gummies dragée lollipop fruitcake pastry oat cake. Cookie jelly jelly macaroon icing jelly beans soufflé cake sweet. Macaroon sesame snaps cheesecake tart cake sugar plum.',
                        ],
                        [
                            'question' => 'My shipment status shows that it’s out for delivery. By when will I receive it?',
                            'answer'   => 'Cheesecake muffin cupcake dragée lemon drops tiramisu cake gummies chocolate cake. Marshmallow tart croissant. Tart dessert tiramisu marzipan lollipop lemon drops.',
                        ],
                        [
                            'question' => 'What do I need to do to get the shipment delivered within a specific timeframe?',
                            'answer'   => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.',
                        ],
                    ],
                ],
                [
                    'name'        => 'Cancellation & Return',
                    'icon'        => 'refresh-cw',
                    'description' => 'Which license do I need?',
                    'faqs'        => [
                        [
                            'question' => 'Can my security guard or neighbour receive my shipment if I am not available?',
                            'answer'   => 'Pastry pudding cookie toffee bonbon jujubes jujubes powder topping. Jelly beans gummi bears sweet roll bonbon muffin liquorice.',
                        ],
                        [
                            'question' => 'How can I get the contact number of my delivery agent?',
                            'answer'   => 'Sweet pie candy jelly. Sesame snaps biscuit sugar plum. Sweet roll topping fruitcake. Caramels liquorice biscuit ice cream fruitcake cotton candy tart.',
                        ],
                        [
                            'question' => 'How can I cancel my shipment?',
                            'answer'   => 'Tart gummies dragée lollipop fruitcake pastry oat cake. Cookie jelly jelly macaroon icing jelly beans soufflé cake sweet.',
                        ],
                        [
                            'question' => 'I have received a defective/damaged product. What do I do?',
                            'answer'   => 'Cheesecake muffin cupcake dragée lemon drops tiramisu cake gummies chocolate cake. Marshmallow tart croissant.',
                        ],
                        [
                            'question' => 'How do I change my delivery address?',
                            'answer'   => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.',
                        ],
                        [
                            'question' => 'What documents do I need to carry for self-collection of my shipment?',
                            'answer'   => 'At tempor commodo ullamcorper a lacus vestibulum. Ultrices neque ornare aenean euismod. Dui vivamus arcu felis bibendum.',
                        ],
                        [
                            'question' => 'What are the timings for self-collecting shipments from the Delhivery Branch?',
                            'answer'   => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.',
                        ],
                    ],
                ],
                [
                    'name'        => 'My Orders',
                    'icon'        => 'package',
                    'description' => 'Track and manage your orders',
                    'faqs'        => [
                        [
                            'question' => 'Can I avail of an open delivery?',
                            'answer'   => 'Pastry pudding cookie toffee bonbon jujubes jujubes powder topping. Jelly beans gummi bears sweet roll bonbon muffin liquorice.',
                        ],
                        [
                            'question' => 'How do I change my delivery address?',
                            'answer'   => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.',
                        ],
                        [
                            'question' => 'What are the timings for self-collecting shipments from the Delhivery Branch?',
                            'answer'   => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.',
                        ],
                    ],
                ],
                [
                    'name'        => 'Product & Services',
                    'icon'        => 'settings',
                    'description' => 'Information about our offerings',
                    'faqs'        => [
                        [
                            'question' => 'How do I get technical support?',
                            'answer'   => 'You can reach out to our technical support team via the contact form or call our helpline directly.',
                        ],
                        [
                            'question' => 'How do I change my delivery address?',
                            'answer'   => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.',
                        ],
                        [
                            'question' => 'What documents do I need to carry for self-collection of my shipment?',
                            'answer'   => 'At tempor commodo ullamcorper a lacus vestibulum. Ultrices neque ornare aenean euismod. Dui vivamus arcu felis bibendum.',
                        ],
                        [
                            'question' => 'What are the timings for self-collecting shipments from the Delhivery Branch?',
                            'answer'   => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.',
                        ],
                    ],
                ],
            ];

            // 2. Loop and Create
            foreach ($categories as $index => $catData) {
                $category = FaqCategory::create([
                    'name'        => $catData['name'],
                    'slug'        => Str::slug($catData['name']),
                    'icon'        => $catData['icon'],
                    'description' => $catData['description'],
                    'sort_order'  => $index + 1,
                    'is_active'   => true,
                ]);

                foreach ($catData['faqs'] as $faqIndex => $faqData) {
                    Faq::create([
                        'faq_category_id' => $category->id,
                        'question'        => $faqData['question'],
                        'answer'          => $faqData['answer'],
                        'sort_order'      => $faqIndex + 1,
                        'is_published'    => true,
                    ]);
                }
            }


            $this->command->info('FAQ modules seeded successfully.');
        }

    }
