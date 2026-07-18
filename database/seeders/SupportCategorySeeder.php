<?php

    namespace Database\Seeders;

    use Codeglen\Usupport\Models\SupportAgent;
    use Codeglen\Usupport\Models\SupportCategory;
    use Illuminate\Database\Seeder;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Str;

    class SupportCategorySeeder extends Seeder
    {
        /**
         * Run the database seeds.
         *
         * @return void
         */
        public function run(): void
        {
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            SupportCategory::truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            // Get all support agent IDs and shuffle them
            $agentIds = SupportAgent::where('is_active', true)->pluck('id')->toArray();
            shuffle($agentIds);

            if (empty($agentIds)) {
                $this->command->error('No support agents found. Please run SupportAgentsSeeder first.');

                return;
            }

            $categories = [
                [
                    'name'        => 'Account Settings',
                    'description' => 'Manage your user account, profile, and settings.',
                    'is_active'   => true,
                    'created_by'  => 1,
                    'icon'        => 'settings',
                ],
                [
                    'name'        => 'API Questions',
                    'description' => 'Common questions about our API, limits, and documentation.',
                    'is_active'   => true,
                    'created_by'  => 1,
                    'icon'        => 'code',
                ],
                [
                    'name'        => 'Billing',
                    'description' => 'Information regarding invoices, payment methods, and refunds.',
                    'is_active'   => true,
                    'created_by'  => 1,
                    'icon'        => 'file-text',
                ],
                [
                    'name'        => 'Copyright & Legal',
                    'description' => 'Guides on copyright, legal inquiries, and our privacy policy.',
                    'is_active'   => true,
                    'created_by'  => 1,
                    'icon'        => 'award',
                ],
                [
                    'name'        => 'Mobile Apps',
                    'description' => 'Guides for downloading and using our mobile applications.',
                    'is_active'   => true,
                    'created_by'  => 1,
                    'icon'        => 'smartphone',
                ],
                [
                    'name'        => 'Using KnowHow',
                    'description' => 'Guides for customizing, upgrading, and using the KnowHow theme.',
                    'is_active'   => true,
                    'created_by'  => 1,
                    'icon'        => 'help-circle',
                ],
            ];

            foreach ($categories as $categoryData) {
                // Take the first agent ID from the shuffled array
                $assignedAgentId = array_shift($agentIds);

                // If we run out of unique agents, reuse the first one
                if (is_null($assignedAgentId)) {
                    $assignedAgentId = SupportAgent::first()->id;
                }

                SupportCategory::firstOrCreate(
                    ['name' => $categoryData['name']],
                    [
                        'uid'         => (string) Str::uuid(),
                        'slug'        => Str::slug($categoryData['name']),
                        'icon'        => $categoryData['icon'],
                        'description' => $categoryData['description'],
                        'is_active'   => $categoryData['is_active'],
                        'created_by'  => $categoryData['created_by'],
                        'agent_id'    => $assignedAgentId,
                    ]
                );
            }

            $this->command->info('Support categories seeded successfully!');
        }

    }
