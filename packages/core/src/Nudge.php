<?php

namespace Gerenuk\Nudge;

use Gerenuk\Nudge\Contracts\NudgeUser;
use Illuminate\Database\Eloquent\Model;

class Nudge
{
    /**
     * The host application's user model class.
     *
     * @return class-string<Model&NudgeUser>
     */
    public static function userModel(): string
    {
        return config('nudge.user_model');
    }
}
