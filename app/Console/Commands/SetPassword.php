<?php

namespace App\Console\Commands;

use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

use function Laravel\Prompts\password;

/**
 * Sets a user's password from the command line, e.g. when they're locked out and can't receive the reset email.
 * The password goes through the same validation as the normal reset flow.
 */
#[Signature('app:set-password {email : The email address of the user} {--password= : The new password (prompted for if left out)} {--clear-sign-in-methods : Also remove the user\'s passkeys and two-factor authentication}')]
#[Description("Manually set a user's password")]
class SetPassword extends Command
{
    public function handle(ResetUserPassword $resetUserPassword): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->components->error("No user found with the email [{$this->argument('email')}].");

            return self::FAILURE;
        }

        $password = $this->option('password');
        $confirmation = $password;

        if ($password === null) {
            $password = password('New password', required: true);
            $confirmation = password('Confirm password', required: true);
        }

        try {
            $resetUserPassword->reset($user, [
                'password' => $password,
                'password_confirmation' => $confirmation,
            ]);
        } catch (ValidationException $exception) {
            foreach ($exception->validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $this->components->info("Password updated for [{$user->email}].");

        if ($this->option('clear-sign-in-methods')) {
            $user->passkeys()->delete();
            $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();

            $this->components->info("Passkeys and two-factor authentication removed for [{$user->email}].");
        }

        return self::SUCCESS;
    }
}
