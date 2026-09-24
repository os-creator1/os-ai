<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * The explicit, authorized initial-owner setup this codebase used to be
 * missing. `UserSeeder` used to insert an administrator account with a
 * hardcoded vendor email and a hardcoded (or, in production, merely
 * console-printed) password — every install got the same predictable
 * credential unless an operator remembered to change it by hand, and there
 * was no other supported way to create the first owner at all.
 *
 * THIS COMMAND IS THE ONLY SUPPORTED WAY TO CREATE A PLATFORM OWNER. It
 * never assumes an email, and the password is NEVER accepted as a
 * command-line argument — only through the concealed interactive prompt —
 * so it can never end up in shell history, a process list, or a CI log. It
 * is deliberately NOT wired into `DatabaseSeeder`, so no install ever gets
 * an administrator account it did not explicitly ask for.
 *
 * IT REFUSES TO RUN AGAIN once an administrator already exists, unless
 * `--force` says otherwise. An accidental second run (a re-deploy, a CI
 * script invoked twice, a copy-pasted command) must not silently mint a
 * second owner or fail with a confusing database error — it should say
 * exactly why it stopped.
 *
 * IT FAILS CLOSED WHEN THE `administrator` ROLE DOES NOT EXIST YET, before
 * touching the `users` table at all. An `is_admin` account with no role is a
 * half-built administrator: `EnsureUserIsAdministrator` would still let it
 * into every admin route on the `is_admin` flag alone, silently wider than
 * intended and easy to miss, so the command refuses to create one rather
 * than create it and hope the operator notices the gap.
 */
class CreatePlatformOwnerCommand extends Command
{
    protected $signature = 'platform:create-owner
        {--email= : The new owner\'s email address}
        {--first-name=Platform : The owner\'s first name}
        {--last-name=Owner : The owner\'s last name}
        {--force : Create another owner even though an administrator already exists}';

    protected $description = 'Create the platform\'s first administrator account (or, with --force, an additional one)';

    public function handle(): int
    {
        if (! $this->option('force') && User::query()->where('is_admin', true)->exists()) {
            $this->error('An administrator account already exists. Pass --force to create another one anyway.');

            return self::FAILURE;
        }

        $administratorRole = Role::query()->where('name', 'administrator')->first();

        if ($administratorRole === null) {
            $this->error('The "administrator" role does not exist yet. Run `php artisan db:seed --class=UserSeeder` first, then retry.');

            return self::FAILURE;
        }

        $email = $this->option('email') ?: $this->ask('Owner email address');
        $password = $this->secret('Owner password');

        $validator = Validator::make(
            ['email' => $email, 'password' => $password],
            [
                'email' => ['required', 'email', 'max:191', 'unique:users,email'],
                'password' => ['required', 'string', 'min:12'],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::INVALID;
        }

        $owner = User::create([
            'uid' => (string) Str::uuid(),
            'first_name' => (string) $this->option('first-name'),
            'last_name' => (string) $this->option('last-name'),
            'email' => $email,
            'password' => Hash::make($password),
            'is_admin' => true,
            'is_customer' => false,
            'status' => true,
            'email_verified_at' => now(),
            'active_portal' => 'admin',
        ]);

        $owner->roles()->save($administratorRole);

        $this->info("Platform owner created: {$owner->email} (id {$owner->id}).");

        return self::SUCCESS;
    }
}
