<?php

namespace App\Support;

use App\Models\User;
use Gerenuk\Nudge\Models\Invitation;

final class Registration
{
    /**
     * The configured registration mode, falling back to invite for unknown values.
     */
    public function mode(): string
    {
        $mode = config('registration.mode');

        return in_array($mode, ['open', 'invite', 'closed'], true) ? $mode : 'invite';
    }

    /**
     * Whether no user exists yet, so the next sign-up sets up the installation.
     */
    public function isFirstUser(): bool
    {
        return ! User::query()->exists();
    }

    /**
     * Whether the sign-up page and links should be shown.
     */
    public function isAvailable(): bool
    {
        return $this->isFirstUser() || $this->mode() !== 'closed';
    }

    /**
     * Whether the first account may be created on the website: only with the configured setup token, so nobody who
     * finds a fresh install can claim it before its owner.
     */
    public function firstUserAllowed(?string $token): bool
    {
        $configured = (string) config('registration.setup_token');

        return $this->isFirstUser() && $configured !== '' && hash_equals($configured, (string) $token);
    }

    /**
     * Whether the email address may create an account. The first account needs the setup token, whatever the mode.
     */
    public function allows(string $email, ?string $setupToken = null): bool
    {
        if ($this->isFirstUser()) {
            return $this->firstUserAllowed($setupToken);
        }

        return $this->mode() === 'open'
            || ($this->mode() === 'invite' && Invitation::isPendingFor($email));
    }

    /**
     * The message explaining why sign-up was refused in the current mode.
     */
    public function refusal(): string
    {
        if ($this->isFirstUser()) {
            return __('Setting up this installation needs the setup token from its configuration, or create the owner with `php artisan app:create-owner`.');
        }

        return $this->mode() === 'closed'
            ? __('Sign-up is closed on this installation.')
            : __('Sign-up is by invitation. Use the email address your invitation was sent to.');
    }
}
