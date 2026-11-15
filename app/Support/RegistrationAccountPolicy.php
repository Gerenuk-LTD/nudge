<?php

namespace App\Support;

use Gerenuk\Marketing\Contracts\AccountCreationPolicy;
use Gerenuk\Marketing\Contracts\MarketingUser;
use Gerenuk\Marketing\Models\Account;

/**
 * In open mode anyone signed in may start an account. In invite and closed mode only the installation's first
 * account may be started this way; everyone else joins through an invitation.
 */
final class RegistrationAccountPolicy implements AccountCreationPolicy
{
    public function __construct(private readonly Registration $registration) {}

    public function allows(MarketingUser $user): bool
    {
        return $this->registration->mode() === 'open' || ! Account::query()->exists();
    }
}
