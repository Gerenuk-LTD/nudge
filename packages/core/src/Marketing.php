<?php

namespace Gerenuk\Marketing;

use Gerenuk\Marketing\Contracts\MarketingUser;
use Illuminate\Database\Eloquent\Model;

class Marketing
{
    /**
     * The host application's user model class.
     *
     * @return class-string<Model&MarketingUser>
     */
    public static function userModel(): string
    {
        return config('marketing.user_model');
    }
}
