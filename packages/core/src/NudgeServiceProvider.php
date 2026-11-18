<?php

namespace Gerenuk\Nudge;

use Gerenuk\Nudge\Contracts\AccountCreationPolicy;
use Gerenuk\Nudge\Contracts\NudgeUser;
use Gerenuk\Nudge\Enums\AutomationTrigger;
use Gerenuk\Nudge\Enums\Permission;
use Gerenuk\Nudge\Http\ApiError;
use Gerenuk\Nudge\Http\ApiErrorCode;
use Gerenuk\Nudge\Http\Controllers\ApiDocsController;
use Gerenuk\Nudge\Http\Middleware\AuthenticateApiToken;
use Gerenuk\Nudge\Http\Middleware\EnsureTokenCan;
use Gerenuk\Nudge\Http\Middleware\LogApiRequest;
use Gerenuk\Nudge\Http\Middleware\SetCurrentWorkspace;
use Gerenuk\Nudge\Jobs\AdvanceDueAutomationRuns;
use Gerenuk\Nudge\Jobs\DispatchDueCampaigns;
use Gerenuk\Nudge\Jobs\EnrollScheduledTriggers;
use Gerenuk\Nudge\Jobs\PruneApiRequests;
use Gerenuk\Nudge\Jobs\PruneStaleImports;
use Gerenuk\Nudge\Jobs\RecoverStalledSends;
use Gerenuk\Nudge\Sending\ProviderTransports;
use Gerenuk\Nudge\Support\AllowAccountCreation;
use Gerenuk\Nudge\Tenancy\CurrentWorkspace;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class NudgeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/nudge.php', 'nudge');

        $this->app->scoped(CurrentWorkspace::class);
        // Scoped, so each request or queued job builds transports from the latest saved credentials.
        $this->app->scoped(ProviderTransports::class);
        // The host overrides this to decide who may start a new account.
        $this->app->bindIf(AccountCreationPolicy::class, AllowAccountCreation::class);

        // Lets Inertia, and its test assertions, find the package's pages under the `nudge/` prefix.
        $this->app->make('config')->push('inertia.pages.paths', __DIR__.'/../resources/js/pages');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'nudge');

        $this->publishes([
            __DIR__.'/../config/nudge.php' => config_path('nudge.php'),
        ], 'nudge-config');

        $this->registerMiddleware();
        $this->registerGates();
        $this->registerRateLimiting();
        $this->registerApiErrors();
        $this->registerRoutes();
        $this->registerSchedule();
    }

    /**
     * Every error on the API prefix is rendered as `{code, message}`, whatever the host app does for its own routes.
     */
    private function registerApiErrors(): void
    {
        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler): void {
            if (! $handler instanceof Handler) {
                return;
            }

            $handler->renderable(function (Throwable $e, Request $request): ?JsonResponse {
                if (! $request->is(trim((string) config('nudge.api_prefix'), '/').'/*')) {
                    return null;
                }

                return match (true) {
                    $e instanceof AuthenticationException => ApiError::response(ApiErrorCode::Unauthenticated, 'Unauthenticated.', 401),
                    $e instanceof ValidationException => ApiError::response(ApiErrorCode::ValidationFailed, $e->getMessage(), 422, errors: $e->errors()),
                    $e instanceof NotFoundHttpException, $e instanceof ModelNotFoundException => ApiError::response(ApiErrorCode::NotFound, 'Not found.', 404),
                    $e instanceof MethodNotAllowedHttpException => ApiError::response(ApiErrorCode::MethodNotAllowed, $e->getMessage(), 405, $e->getHeaders()),
                    $e instanceof ThrottleRequestsException => ApiError::response(ApiErrorCode::RateLimited, 'Too many requests.', 429, $e->getHeaders()),
                    $e instanceof HttpExceptionInterface => ApiError::response(
                        ApiErrorCode::HttpError,
                        $e->getMessage() !== '' ? $e->getMessage() : (Response::$statusTexts[$e->getStatusCode()] ?? 'Error'),
                        $e->getStatusCode(),
                        $e->getHeaders(),
                    ),
                    config('app.debug') => null,
                    default => ApiError::response(ApiErrorCode::ServerError, 'Server Error.', 500),
                };
            });
        });
    }

    private function registerSchedule(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->call(new DispatchDueCampaigns)->everyMinute()->name('nudge:dispatch-due-campaigns')->withoutOverlapping(10);
            $schedule->call(new PruneStaleImports)->daily()->name('nudge:prune-stale-imports')->withoutOverlapping(60);
            $schedule->call(new PruneApiRequests)->daily()->name('nudge:prune-api-requests')->withoutOverlapping(60);
            $schedule->call(new AdvanceDueAutomationRuns)->everyMinute()->name('nudge:advance-automation-runs')->withoutOverlapping(10);
            $schedule->job(new EnrollScheduledTriggers([AutomationTrigger::SegmentEntered]))->everyFiveMinutes()->name('nudge:segment-triggers')->withoutOverlapping(30);
            $schedule->job(new EnrollScheduledTriggers([AutomationTrigger::DateField, AutomationTrigger::Inactivity]))->dailyAt('08:00')->name('nudge:daily-triggers')->withoutOverlapping(120);
            $schedule->call(new RecoverStalledSends)->everyFiveMinutes()->name('nudge:recover-stalled-sends')->withoutOverlapping(10);
        });
    }

    private function registerMiddleware(): void
    {
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('nudge.workspace', SetCurrentWorkspace::class);
        $router->aliasMiddleware('nudge.api', AuthenticateApiToken::class);
        $router->aliasMiddleware('nudge.api-log', LogApiRequest::class);
        $router->aliasMiddleware('nudge.token-can', EnsureTokenCan::class);

        // The workspace must be set before route model binding runs, so bound models are workspace-scoped.
        $kernel = $this->app->make(Kernel::class);
        $kernel->addToMiddlewarePriorityBefore(SubstituteBindings::class, SetCurrentWorkspace::class);
        $kernel->addToMiddlewarePriorityBefore(SubstituteBindings::class, AuthenticateApiToken::class);
    }

    /**
     * Each permission is a gate checked against the user's role in the current workspace.
     */
    private function registerGates(): void
    {
        foreach (Permission::cases() as $permission) {
            Gate::define($permission->value, function (NudgeUser $user) use ($permission): bool {
                $workspace = $this->app->make(CurrentWorkspace::class)->get();

                return $workspace !== null && $user->hasWorkspacePermission($workspace, $permission);
            });
        }
    }

    private function registerRateLimiting(): void
    {
        // Keyed on the bearer token itself: throttling runs before the token is authenticated.
        RateLimiter::for('nudge-api', function (Request $request): Limit {
            $token = $request->bearerToken();

            return Limit::perMinute(config('nudge.api_requests_per_minute'))
                ->by($token !== null ? 'token:'.hash('sha256', $token) : 'ip:'.$request->ip());
        });

        // Test sends go through the real provider to any address, so a compromised account could spam with them.
        RateLimiter::for('nudge-test-sends', fn (Request $request): Limit => Limit::perHour(20)
            ->by('test-send:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }

    private function registerRoutes(): void
    {
        Route::middleware(config('nudge.web_middleware'))
            ->name('nudge.')
            ->group(__DIR__.'/../routes/web.php');

        Route::name('nudge.')->group(__DIR__.'/../routes/public.php');

        Route::middleware('web')->name('nudge.')->group(__DIR__.'/../routes/public-pages.php');

        Route::prefix(config('nudge.api_prefix'))
            ->middleware(['api', 'nudge.api', 'nudge.api-log', 'throttle:nudge-api'])
            ->name('nudge.api.')
            ->group(__DIR__.'/../routes/api.php');

        // Outside the token group: the reference is readable without a token.
        if (config('nudge.api_docs.enabled')) {
            Route::prefix(config('nudge.api_prefix'))
                ->middleware(['api', 'throttle:60,1'])
                ->name('nudge.api.docs')
                ->group(function (): void {
                    Route::get('openapi.json', [ApiDocsController::class, 'spec'])->name('.spec');
                    Route::get('docs', [ApiDocsController::class, 'page'])->name('');
                });
        }
    }
}
