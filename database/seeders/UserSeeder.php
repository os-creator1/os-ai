<?php

    namespace Database\Seeders;


    use App\Models\Role;
    use App\Models\User;
    use App\Models\Customer;
    use Illuminate\Database\Seeder;
    use Illuminate\Support\Facades\DB;

    /**
     * Seeds the `administrator` role and its permission set — infrastructure
     * every install needs regardless of who the first owner turns out to be.
     *
     * IT NO LONGER CREATES AN ADMINISTRATOR ACCOUNT. It used to insert one
     * with a hardcoded vendor email and a hardcoded/weak password, which
     * meant every install of this codebase shipped the same predictable
     * super-admin credential unless an operator remembered to change it by
     * hand. `platform:create-owner` (`app/Console/Commands/CreatePlatformOwnerCommand.php`)
     * is the explicit, authorized replacement — run it once, interactively,
     * after seeding.
     */
    class UserSeeder extends Seeder
    {
        /**
         * Run the database seeders.
         */
        public function run()
        {
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
        }

    }
