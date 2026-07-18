<?php

    namespace Database\Seeders;


    use App\Models\Role;
    use App\Models\User;
    use App\Models\Customer;
    use Illuminate\Database\Seeder;
    use Illuminate\Support\Str;
    use Illuminate\Support\Facades\DB;

    class UserSeeder extends Seeder
    {
        /**
         * Run the database seeders.
         */
        public function run()
        {

            // Default password
            $defaultPassword = app()->environment('production') ? Str::random() : '12345678';
            $this->command->getOutput()->writeln("<info>Default password:</info> $defaultPassword");

            // Create super admin user
            $user     = new User();
            $role     = new Role();
            $customer = new Customer();

            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            $user->truncate();
            $role->truncate();
            $customer->truncate();
            DB::table('role_user')->truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            /*
             * Create roles
             */

            $superAdminRole = $role->create([
                'name'   => 'administrator',
                'status' => true,
            ]);

            foreach (config('permissions') as $key => $name) {
                $superAdminRole->permissions()->create(['name' => $key]);
            }

            $superAdmin = $user->create([
                'first_name'        => 'Super',
                'last_name'         => 'Admin',
                'image'             => null,
                'email'             => 'akasham67@gmail.com',
                'password'          => bcrypt($defaultPassword),
                'status'            => true,
                'is_admin'          => true,
                'locale'            => app()->getLocale(),
                'timezone'          => config('app.timezone'),
                'email_verified_at' => now(),
            ]);

            $superAdmin->api_token = $superAdmin->createToken('akasham67@gmail.com')->plainTextToken;
            $superAdmin->save();

            $superAdmin->roles()->save($superAdminRole);

        }

    }
