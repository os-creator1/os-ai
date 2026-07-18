<?php

    namespace Database\Seeders;

    use App\Models\Plugins;
    use Illuminate\Database\Seeder;

    class PluginsSeeder extends Seeder
    {
        /**
         * Run the database seeds.
         */
        public function run(): void
        {
            $plugin = new Plugins();
            $plugin->truncate();

            $plugins = [
                [
                    'name'        => 'codeglen/usupport',
                    'type'        => 'plugin',
                    'title'       => 'uSupport - Support Ticket Plugin for Ultimate SMS',
                    'description' => 'uSupport is a support ticket plugin for Ultimate SMS.',
                    'version'     => '1.0.1',
                    'status'      => 'active',
                ],

                [
                    'name'        => 'codeglen/ulanding',
                    'type'        => 'plugin',
                    'title'       => 'uLanding - Landing Page Plugin for Ultimate SMS',
                    'description' => 'uLanding is a landing page plugin for Ultimate SMS.',
                    'version'     => '1.0.0',
                    'status'      => 'active',
                ],
            ];

            foreach ($plugins as $plug) {
                $plugin->create($plug);
            }

            $this->command->info('Plugin added successfully');
        }

    }
