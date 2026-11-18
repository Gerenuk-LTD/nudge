<?php

namespace Gerenuk\Marketing\Http\Controllers;

use Gerenuk\Marketing\Contracts\MarketingUser;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

abstract class Controller extends BaseController
{
    /**
     * The signed-in user. Every dashboard route requires authentication.
     */
    protected function user(Request $request): MarketingUser
    {
        $user = $request->user();

        abort_unless($user instanceof MarketingUser, 401);

        return $user;
    }
}
