<?php

    namespace Database\Seeders;


    use App\Models\Role;
    use Illuminate\Database\Seeder;

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
     *
     * IT NO LONGER TRUNCATES `users`, `customers`, `roles` OR `role_user`
     * EITHER. The truncate-then-recreate shape came from the same
     * fresh-install-only assumption as the hardcoded admin: running `db:seed`
     * again — a re-deploy, a CI step invoked twice, an operator syncing
     * permissions after a new one is added to `config('permissions')` — must
     * never destroy every account and membership the install has accumulated
     * since. `firstOrCreate` makes the role and each permission idempotent:
     * present if missing, untouched if already there.
     */
    class UserSeeder extends Seeder
    {
        /**
         * Run the database seeders.
         */
        public function run()
        {
            $administratorRole = Role::firstOrCreate(
                ['name' => 'administrator'],
                ['status' => true],
            );

            foreach (config('permissions') as $key => $name) {
                $administratorRole->permissions()->firstOrCreate(['name' => $key]);
            }
        }

    }
