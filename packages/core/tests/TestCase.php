<?php

namespace Gerenuk\Marketing\Tests;

use Gerenuk\Marketing\Actions\Workspaces\CreateWorkspace;
use Gerenuk\Marketing\MarketingServiceProvider;
use Gerenuk\Marketing\Models\Membership;
use Gerenuk\Marketing\Models\Role;
use Gerenuk\Marketing\Models\Workspace;
use Gerenuk\Marketing\Tenancy\CurrentWorkspace;
use Gerenuk\Marketing\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\ServiceProvider as InertiaServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [InertiaServiceProvider::class, MarketingServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('marketing.user_model', User::class);
        $app['config']->set('marketing.web_middleware', ['web', 'auth']);
        $app['config']->set('inertia.testing.ensure_pages_exist', true);
        $app['config']->set('view.paths', [__DIR__.'/Fixtures/views']);
        $app['config']->set('auth.providers.users.model', User::class);
    }

    /**
     * The host app's users table must exist before the package migrations alter it,
     * so Laravel's migrations are registered on the same migrator rather than run separately.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->app['migrator']->path(base_path('migrations'));
    }

    /**
     * A user who owns a fresh workspace, which is also made current.
     *
     * @return array{0: User, 1: Workspace}
     */
    protected function createOwner(string $workspaceName = 'Acme'): array
    {
        $user = User::factory()->create();
        $workspace = app(CreateWorkspace::class)->handle($user, $workspaceName);

        app(CurrentWorkspace::class)->set($workspace);

        return [$user->refresh(), $workspace];
    }

    /**
     * Add a member to the workspace with one of its roles, by name.
     */
    protected function addMember(Workspace $workspace, string $roleName): User
    {
        $user = User::factory()->create(['current_workspace_id' => $workspace->id]);
        $role = Role::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('name', $roleName)
            ->firstOrFail();

        Membership::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
        ]);

        return $user;
    }

    protected function useWorkspace(Workspace $workspace): void
    {
        app(CurrentWorkspace::class)->set($workspace);
    }
}
