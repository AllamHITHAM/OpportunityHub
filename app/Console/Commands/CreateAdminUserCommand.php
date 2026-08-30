<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Backend Configuration & Safety Pass: replaces `AdminUserSeeder`, which
 * contained a fixed, source-visible plaintext password
* A fixed source-visible Admin credential was previously seeded by
* `DatabaseSeeder::run()` -- meaning any real `db:seed` run, including
 * against staging/production, would have silently created a predictable,
 * publicly-known Admin credential. `DatabaseSeeder` no longer touches
 * Admin creation at all; this command is the only way to create one now.
 *
 * Staging usage (see docs/DEPLOYMENT.md):
 *
 *     php artisan admin:create admin@example.com
 *
 * Prompts for the password interactively (hidden input, confirmed twice)
 * when `--password` isn't given -- never required on the command line
 * itself, where it could end up in shell history. Never overwrites an
 * existing account: if the email is already taken, the command fails
 * loudly instead of silently promoting or mutating whatever's already
 * there. The password is hashed by `User`'s own `'password' => 'hashed'`
 * cast (the same mechanism every other password write in this app already
 * goes through) -- never logged or echoed back.
 */
class CreateAdminUserCommand extends Command
{
    protected $signature = 'admin:create {email : The new Admin\'s email address} {--password= : Skip the interactive password prompt (not recommended -- may end up in shell history)}';

    protected $description = 'Create a new Admin account with an explicitly supplied or interactively-prompted password -- never a hardcoded one.';

    public function handle(): int
    {
        $email = (string) $this->argument('email');

        $emailErrors = Validator::make(
            ['email' => $email],
            ['email' => ['required', 'email', 'max:255']],
        )->errors();
        if ($emailErrors->isNotEmpty()) {
            $this->error($emailErrors->first('email'));

            return self::FAILURE;
        }

        if (User::where('email', $email)->exists()) {
            $this->error("A user with the email \"{$email}\" already exists -- refusing to overwrite it.");

            return self::FAILURE;
        }

        $password = $this->option('password');
        if ($password === null) {
            $password = $this->secret('Password for the new Admin account');
            if ($password !== $this->secret('Confirm password')) {
                $this->error('Passwords did not match.');

                return self::FAILURE;
            }
        }

        $passwordErrors = Validator::make(
            ['password' => $password],
            ['password' => ['required', Password::min(8)]],
        )->errors();
        if ($passwordErrors->isNotEmpty()) {
            $this->error($passwordErrors->first('password'));

            return self::FAILURE;
        }

        $admin = new User([
            'name' => 'Admin',
            'email' => $email,
            'password' => $password,
        ]);
        $admin->role = 'admin';
        $admin->status = 'active';
        $admin->save();

        $this->info("Admin account created for {$email}.");

        return self::SUCCESS;
    }
}
