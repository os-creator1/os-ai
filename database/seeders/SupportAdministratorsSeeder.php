<?php

    namespace Database\Seeders;

    use App\Models\Role;
    use App\Models\User;
    use Codeglen\Usupport\Models\SupportAgent;
    use Illuminate\Database\Seeder;
    use Illuminate\Support\Facades\Hash;
    use Illuminate\Support\Str;

    class SupportAdministratorsSeeder extends Seeder
    {
        /**
         * Run the database seeds.
         *
         * @return void
         */
        public function run(): void
        {
            $adminUsersData = [
                [
                    'first_name' => 'Support',
                    'last_name'  => 'Agent',
                    'email'      => 'agent@codeglen.com',
                ],
                [
                    'first_name' => 'Admin',
                    'last_name'  => 'Demo',
                    'email'      => 'demo@admin.com',
                ],
                [
                    'first_name' => 'John',
                    'last_name'  => 'Doe',
                    'email'      => 'john@support.com',
                ],
                [
                    'first_name' => 'Jane',
                    'last_name'  => 'Smith',
                    'email'      => 'jane@support.com',
                ],
                [
                    'first_name' => 'Peter',
                    'last_name'  => 'Jones',
                    'email'      => 'peter@support.com',
                ],
            ];

            $permissions = [
                'access backend',
                'view administrator',
                'create administrator',
                'edit administrator',
                'delete administrator',
                'view roles',
                'create roles',
                'edit roles',
                'delete roles',
                'view tickets',
                'create tickets',
                'edit tickets',
                'delete tickets',
                'assign tickets',
                'reply tickets',
                'edit ticket_replies',
                'delete ticket_replies',
                'delete ticket_attachments',
                'view ticket_tags',
                'create ticket_tags',
                'edit ticket_tags',
                'delete ticket_tags',
                'manage support_settings',
                'view support_agents',
                'create support_agents',
                'edit support_agents',
                'delete support_agents',
                'view support_categories',
                'create support_categories',
                'edit support_categories',
                'delete support_categories',
                'view support_articles',
                'create support_articles',
                'edit support_articles',
                'delete support_articles',
                'view support_analytics',
            ];

            // Ensure support role exists
            $supportRole = Role::firstOrCreate([
                'name'   => 'Support',
                'status' => true,
            ]);

            // Ensure permissions exist (no duplicates)
            foreach ($permissions as $name) {
                $supportRole->permissions()->firstOrCreate(['name' => $name]);
            }

            $createdUsers = collect();

            foreach ($adminUsersData as $userData) {
                $user = User::firstOrCreate(
                    ['email' => $userData['email']],
                    [
                        'uid'               => (string) Str::uuid(),
                        'first_name'        => $userData['first_name'],
                        'last_name'         => $userData['last_name'],
                        'password'          => Hash::make('12345678'),
                        'is_admin'          => true,
                        'email_verified_at' => now(),
                        'status'            => true,
                        'is_customer'       => false,
                        'active_portal'     => 'admin',
                    ]
                );

                // Assign token only if not already set
                if (empty($user->api_token)) {
                    $user->api_token = $user->createToken($user->email)->plainTextToken;
                    $user->save();
                }

                $createdUsers->push($user);
            }

            // Assign roles and create support agents
            $createdUsers->each(function ($user) use ($supportRole) {
                // Attach role only if missing
                if ( ! $user->roles->contains($supportRole->id)) {
                    $user->roles()->attach($supportRole->id);
                }

                // Create support agent if missing
                SupportAgent::firstOrCreate(
                    ['user_id' => $user->id],
                    [
                        'uid'         => (string) Str::uuid(),
                        'designation' => 'Support Agent',
                        'bio'         => 'Dedicated to providing excellent customer support.',
                        'is_active'   => true,
                    ]
                );

                $this->command->info("Administrator {$user->email} seeded as support agent!");
            });

        }

    }
