<?php

namespace Gerenuk\Nudge\Http\Controllers;

use Gerenuk\Nudge\Contracts\NudgeUser;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

abstract class Controller extends BaseController
{
    /**
     * The signed-in user. Every dashboard route requires authentication.
     */
    protected function user(Request $request): NudgeUser
    {
        $user = $request->user();

        abort_unless($user instanceof NudgeUser, 401);

        return $user;
    }
}
