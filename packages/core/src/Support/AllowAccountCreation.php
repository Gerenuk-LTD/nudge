<?php

namespace Gerenuk\Nudge\Support;

use Gerenuk\Nudge\Contracts\AccountCreationPolicy;
use Gerenuk\Nudge\Contracts\NudgeUser;

/**
 * The default policy: anyone without a workspace may start an account.
 */
class AllowAccountCreation implements AccountCreationPolicy
{
    public function allows(NudgeUser $user): bool
    {
        return true;
    }
}
