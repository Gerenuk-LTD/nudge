<?php

namespace App\Http\Middleware;

use App\Support\Registration;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectWhenRegistrationClosed
{
    /**
     * Send every request to sign up, the page or the form, to the login page while registration is closed.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('register', 'register.store') && ! app(Registration::class)->isAvailable()) {
            return redirect()->route('login')->with('status', __('Sign-up is closed on this installation.'));
        }

        return $next($request);
    }
}
