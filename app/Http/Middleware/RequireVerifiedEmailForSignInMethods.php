<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Passkeys and two-factor authentication can only be added once the account's email is verified. Otherwise someone
 * who signs up with another person's address (before that person does) could add a passkey that outlives the real
 * owner's password reset.
 */
class RequireVerifiedEmailForSignInMethods
{
    /** Routes that add or reveal a sign-in method. Removing one is always allowed. */
    private const ROUTES = [
        'passkey.registration-options',
        'passkey.store',
        'two-factor.enable',
        'two-factor.confirm',
        'two-factor.qr-code',
        'two-factor.secret-key',
        'two-factor.recovery-codes',
        'two-factor.regenerate-recovery-codes',
    ];

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($request->routeIs(...self::ROUTES) && $user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
            abort(403, __('Verify your email address before adding a passkey or two-factor authentication.'));
        }

        return $next($request);
    }
}
