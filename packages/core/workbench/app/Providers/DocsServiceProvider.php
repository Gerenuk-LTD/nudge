<?php

namespace Workbench\App\Providers;

use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Routing\Route;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Scramble\TokenPermissionExtension;

/**
 * Configures Scramble for exporting the API spec. It only exists under Testbench, so nothing
 * Scramble-related is loaded in an install of the package.
 */
class DocsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        config([
            'scramble.api_path' => config('marketing.api_prefix'),
            'scramble.info.version' => '1',
            'scramble.ui.title' => 'Gerenuk Marketing API',
            // The served copy replaces this with each install's own base URL.
            'scramble.servers' => ['Default' => 'api/v1'],
        ]);
    }

    public function boot(): void
    {
        Scramble::configure()
            ->routes(fn (Route $route): bool => str_starts_with((string) $route->getName(), 'marketing.api.')
                && ! str_starts_with((string) $route->getName(), 'marketing.api.docs'))
            ->withOperationTransformers(TokenPermissionExtension::class)
            ->withDocumentTransformers(function (OpenApi $openApi): void {
                $openApi->secure(
                    SecurityScheme::http('bearer')
                        ->setDescription('A workspace API token (starts `gmk_`), created under Workspace settings → API tokens.')
                );
            });
    }
}
