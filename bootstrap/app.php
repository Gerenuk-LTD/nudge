<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RedirectWhenRegistrationClosed;
use App\Http\Middleware\RequireVerifiedEmailForSignInMethods;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\ThrottlePasswordChecks;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Only APP_URL's exact host is accepted (Laravel skips this check locally and in tests).
        $middleware->trustHosts(
            at: fn (): array => ['^'.preg_quote((string) parse_url((string) config('app.url'), PHP_URL_HOST)).'$'],
            subdomains: false,
        );

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            SecurityHeaders::class,
            AuthenticateSession::class,
            RedirectWhenRegistrationClosed::class,
            ThrottlePasswordChecks::class,
            RequireVerifiedEmailForSignInMethods::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['setup_token']);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
