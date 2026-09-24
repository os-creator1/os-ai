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
 * never assumes an email or password: both are required, either as options
 * or via an interactive prompt, and the password is validated the same way
 * every other password in this application is. It is deliberately NOT wired
 * into `DatabaseSeeder`, so no install ever gets an administrator account it
 * did not explicitly ask for.
 *
 * IT REFUSES TO RUN AGAIN once an administrator already exists, unless
 * `--force` says otherwise. An accidental second run (a re-deploy, a CI
 * script invoked twice, a copy-pasted command) must not silently mint a
 * second owner or fail with a confusing database error — it should say
 * exactly why it stopped.
 */
class CreatePlatformOwnerCommand extends Command
{
    protected $signature = 'platform:create-owner
        {--email= : The new owner\'s email address}
        {--password= : The new owner\'s password (prompted securely if omitted)}
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

        $email = $this->option('email') ?: $this->ask('Owner email address');
        $password = $this->option('password') ?: $this->secret('Owner password');

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

        $administratorRole = Role::query()->where('name', 'administrator')->first();

        if ($administratorRole !== null) {
            $owner->roles()->save($administratorRole);
        }

        $this->info("Platform owner created: {$owner->email} (id {$owner->id}).");

        return self::SUCCESS;
    }
}
