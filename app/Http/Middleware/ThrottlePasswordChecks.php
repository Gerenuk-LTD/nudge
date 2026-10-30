<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

class ThrottlePasswordChecks
{
    /**
     * Rate limit Fortify's password confirmation route, which has no throttle of its own.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('password.confirm.store')) {
            return app(ThrottleRequests::class)->handle($request, $next, 6, 1, 'password-confirm');
        }

        return $next($request);
    }
}
