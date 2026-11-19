<?php

namespace Gerenuk\Nudge\Contracts;

/**
 * Whether a user with no workspace may start a new account (their own sending setup). The host decides, because it
 * owns sign-up. The default allows it.
 */
interface AccountCreationPolicy
{
    public function allows(NudgeUser $user): bool;
}
