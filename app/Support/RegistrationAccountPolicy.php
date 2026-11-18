<?php

namespace App\Support;

use Gerenuk\Nudge\Contracts\AccountCreationPolicy;
use Gerenuk\Nudge\Contracts\NudgeUser;
use Gerenuk\Nudge\Models\Account;

/**
 * In open mode anyone signed in may start an account. In invite and closed mode only the installation's first
 * account may be started this way; everyone else joins through an invitation.
 */
final class RegistrationAccountPolicy implements AccountCreationPolicy
{
    public function __construct(private readonly Registration $registration) {}

    public function allows(NudgeUser $user): bool
    {
        return $this->registration->mode() === 'open' || ! Account::query()->exists();
    }
}
