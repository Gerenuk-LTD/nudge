<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Support\Registration;
use Closure;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        $registration = app(Registration::class);
        $profileRules = $this->profileRules();

        $setupToken = $input['setup_token'] ?? null;

        $profileRules['email'][] = function (string $attribute, mixed $value, Closure $fail) use ($registration, $setupToken): void {
            if (! $registration->allows((string) $value, is_string($setupToken) ? $setupToken : null)) {
                $fail($registration->refusal());
            }
        };

        Validator::make($input, [
            ...$profileRules,
            'password' => $this->passwordRules(),
        ])->validate();

        return User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);
    }
}
