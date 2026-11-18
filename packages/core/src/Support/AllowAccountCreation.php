<?php

namespace Gerenuk\Marketing\Support;

use Gerenuk\Marketing\Contracts\AccountCreationPolicy;
use Gerenuk\Marketing\Contracts\MarketingUser;

/**
 * The default policy: anyone without a workspace may start an account.
 */
class AllowAccountCreation implements AccountCreationPolicy
{
    public function allows(MarketingUser $user): bool
    {
        return true;
    }
}
