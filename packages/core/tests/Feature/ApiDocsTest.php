<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * The spec is generated and committed (see packages/core/docs), so these tests read the file and
 * do not need the docs generator installed.
 *
 * @return array<string, mixed>
 */
function committedSpec(): array
{
    return json_decode((string) file_get_contents(__DIR__.'/../../docs/openapi.json'), true, flags: JSON_THROW_ON_ERROR);
}

test('the spec is served as JSON without a token', function () {
    $response = $this->getJson(route('marketing.api.docs.spec'))->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('max-age=3600')
        ->and($response->json('openapi'))->toBeString()
        ->and($response->json('servers.0.url'))->toEndWith('/'.config('marketing.api_prefix'))
        ->and($response->json('paths'))->not->toBeEmpty();
});

test('the spec names this install\'s own base URL, even when the prefix is changed', function () {
    config(['marketing.api_prefix' => 'api/v2']);

    $this->getJson(route('marketing.api.docs.spec'))
        ->assertOk()
        ->assertJsonPath('servers.0.url', url('api/v2'));
});

test('the docs page is a standalone page that points at the spec', function () {
    $response = $this->get(route('marketing.api.docs'))->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('text/html')
        ->and($response->getContent())->toContain(route('marketing.api.docs.spec', absolute: false));
});

test('the docs routes are not documented in the spec', function () {
    $paths = array_keys(committedSpec()['paths']);

    expect($paths)->not->toContain('/docs')->and($paths)->not->toContain('/openapi.json');
});

test('every API route is in the committed spec', function () {
    $spec = committedSpec();
    $prefix = trim((string) config('marketing.api_prefix'), '/');
    $missing = [];
    $checked = 0;

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $name = (string) $route->getName();

        if (! str_starts_with($name, 'marketing.api.') || str_starts_with($name, 'marketing.api.docs')) {
            continue;
        }

        $uri = '/'.Str::after($route->uri(), $prefix.'/');

        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $checked++;

            if (! isset($spec['paths'][$uri][strtolower($method)])) {
                $missing[] = "$method $uri ($name)";
            }
        }
    }

    expect($checked)->toBeGreaterThan(0)
        ->and($missing)->toBe([], 'Re-export the spec: cd packages/core && vendor/bin/testbench scramble:export --path=docs/openapi.json');
});

test('every operation in the spec is a route of the API', function () {
    $prefix = trim((string) config('marketing.api_prefix'), '/');
    $routes = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (str_starts_with((string) $route->getName(), 'marketing.api.') && ! str_starts_with((string) $route->getName(), 'marketing.api.docs')) {
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $routes[] = strtolower($method).' /'.Str::after($route->uri(), $prefix.'/');
            }
        }
    }

    $stale = [];

    foreach (committedSpec()['paths'] as $path => $operations) {
        foreach (array_keys($operations) as $method) {
            if (! in_array("$method $path", $routes, true)) {
                $stale[] = "$method $path";
            }
        }
    }

    expect($stale)->toBe([]);
});
