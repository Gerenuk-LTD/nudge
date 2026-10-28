<?php

namespace Gerenuk\Marketing;

use Gerenuk\Marketing\Contracts\MarketingUser;
use Gerenuk\Marketing\Enums\AutomationTrigger;
use Gerenuk\Marketing\Enums\Permission;
use Gerenuk\Marketing\Http\Middleware\AuthenticateApiToken;
use Gerenuk\Marketing\Http\Middleware\EnsureTokenCan;
use Gerenuk\Marketing\Http\Middleware\SetCurrentWorkspace;
use Gerenuk\Marketing\Jobs\AdvanceDueAutomationRuns;
use Gerenuk\Marketing\Jobs\DispatchDueCampaigns;
use Gerenuk\Marketing\Jobs\EnrollScheduledTriggers;
use Gerenuk\Marketing\Sending\ProviderTransports;
use Gerenuk\Marketing\Tenancy\CurrentWorkspace;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class MarketingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/marketing.php', 'marketing');

        $this->app->scoped(CurrentWorkspace::class);
        // Scoped, so each request or queued job builds transports from the latest saved credentials.
        $this->app->scoped(ProviderTransports::class);

        // Lets Inertia, and its test assertions, find the package's pages under the `marketing/` prefix.
        $this->app->make('config')->push('inertia.pages.paths', __DIR__.'/../resources/js/pages');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([
            __DIR__.'/../config/marketing.php' => config_path('marketing.php'),
        ], 'marketing-config');

        $this->registerMiddleware();
        $this->registerGates();
        $this->registerRateLimiting();
        $this->registerRoutes();
        $this->registerSchedule();
    }

    private function registerSchedule(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->call(new DispatchDueCampaigns)->everyMinute()->name('marketing:dispatch-due-campaigns')->withoutOverlapping();
            $schedule->call(new AdvanceDueAutomationRuns)->everyMinute()->name('marketing:advance-automation-runs')->withoutOverlapping();
            $schedule->job(new EnrollScheduledTriggers([AutomationTrigger::SegmentEntered]))->everyFiveMinutes()->name('marketing:segment-triggers');
            $schedule->job(new EnrollScheduledTriggers([AutomationTrigger::DateField, AutomationTrigger::Inactivity]))->dailyAt('08:00')->name('marketing:daily-triggers');
        });
    }

    private function registerMiddleware(): void
    {
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('marketing.workspace', SetCurrentWorkspace::class);
        $router->aliasMiddleware('marketing.api', AuthenticateApiToken::class);
        $router->aliasMiddleware('marketing.token-can', EnsureTokenCan::class);

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
            Gate::define($permission->value, function (MarketingUser $user) use ($permission): bool {
                $workspace = $this->app->make(CurrentWorkspace::class)->get();

                return $workspace !== null && $user->hasWorkspacePermission($workspace, $permission);
            });
        }
    }

    private function registerRateLimiting(): void
    {
        // Keyed on the bearer token itself: throttling runs before the token is authenticated.
        RateLimiter::for('marketing-api', function (Request $request): Limit {
            $token = $request->bearerToken();

            return Limit::perMinute(config('marketing.api_requests_per_minute'))
                ->by($token !== null ? 'token:'.hash('sha256', $token) : 'ip:'.$request->ip());
        });
    }

    private function registerRoutes(): void
    {
        Route::middleware(config('marketing.web_middleware'))
            ->name('marketing.')
            ->group(__DIR__.'/../routes/web.php');

        Route::name('marketing.')->group(__DIR__.'/../routes/public.php');

        Route::middleware('web')->name('marketing.')->group(__DIR__.'/../routes/public-pages.php');

        Route::prefix(config('marketing.api_prefix'))
            ->middleware(['api', 'marketing.api', 'throttle:marketing-api'])
            ->name('marketing.api.')
            ->group(__DIR__.'/../routes/api.php');
    }
}
