<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

class ThrottlePasswordChecks
{
    /**
     * Rate limit routes that check a password or reveal account state and have no throttle of their own: password
     * confirmation and profile updates (which check the current password on an email change) per user, and the
     * forgot-password and registration forms per IP.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('password.confirm.store')) {
            return $this->throttle($request, $next, 6, 'password-confirm');
        }

        if ($request->routeIs('profile.update') && $request->isMethod('PATCH')) {
            return $this->throttle($request, $next, 6, 'profile-update');
        }

        if ($request->routeIs('password.email')) {
            return $this->throttle($request, $next, 5, 'password-email');
        }

        if ($request->routeIs('register.store')) {
            return $this->throttle($request, $next, 5, 'register');
        }

        return $next($request);
    }

    /**
     * Allow the given attempts per minute, keyed on the user when signed in and the IP otherwise.
     *
     * @param  Closure(Request): Response  $next
     */
    private function throttle(Request $request, Closure $next, int $perMinute, string $prefix): Response
    {
        return app(ThrottleRequests::class)->handle($request, $next, $perMinute, 1, $prefix);
    }
}
