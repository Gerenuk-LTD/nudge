<?php

namespace App\Support;

use App\Models\User;
use Gerenuk\Marketing\Models\Invitation;

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
     * Whether the email address may create an account.
     */
    public function allows(string $email): bool
    {
        return $this->isFirstUser()
            || $this->mode() === 'open'
            || ($this->mode() === 'invite' && Invitation::isPendingFor($email));
    }

    /**
     * The message explaining why sign-up was refused in the current mode.
     */
    public function refusal(): string
    {
        return $this->mode() === 'closed'
            ? 'Sign-up is closed on this installation.'
            : 'Sign-up is by invitation. Use the email address your invitation was sent to.';
    }
}
