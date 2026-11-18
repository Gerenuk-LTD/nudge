<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Creates the first account of a new installation from the server, so the owner doesn't need a setup token or to
 * race anyone to the sign-up page. The account is marked verified, because only someone with server access can run it.
 */
#[Signature('app:create-owner {email : The email address to sign in with} {--name=Owner : The name shown in the app}')]
#[Description('Create the first account for a new installation')]
class CreateOwner extends Command
{
    public function handle(): int
    {
        if (User::query()->exists()) {
            $this->components->error('An account already exists. Sign in, or invite people from the members page.');

            return self::FAILURE;
        }

        $email = mb_strtolower(trim((string) $this->argument('email')));
        $name = (string) $this->option('name');

        $validator = Validator::make(
            [
                'email' => $email,
                'name' => $name,
                'password' => (string) $this->secret('Password'),
                'password_confirmation' => (string) $this->secret('Confirm password'),
            ],
            [
                'email' => ['required', 'string', 'email:filter', 'max:255'],
                'name' => ['required', 'string', 'max:255'],
                'password' => ['required', 'string', Password::defaults(), 'confirmed'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $user = User::create(['name' => $name, 'email' => $email, 'password' => $validator->validated()['password']]);
        $user->forceFill(['email_verified_at' => now()])->save();

        $this->components->info("Created {$email}. Sign in at ".rtrim((string) config('app.url'), '/').'/login.');

        return self::SUCCESS;
    }
}
