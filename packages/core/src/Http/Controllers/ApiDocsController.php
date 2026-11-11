<?php

namespace Gerenuk\Marketing\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;

/**
 * Serves the API reference that ships with the package, so each install documents its own API.
 */
class ApiDocsController extends Controller
{
    /**
     * The committed OpenAPI spec, with this install's base URL in place of the placeholder.
     */
    public function spec(): JsonResponse
    {
        $path = __DIR__.'/../../../docs/openapi.json';
        $spec = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (! is_array($spec)) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $spec['servers'] = [['url' => url((string) config('marketing.api_prefix'))]];

        return response()->json($spec)->header('Cache-Control', 'public, max-age=3600');
    }

    public function page(): View
    {
        return view('marketing::api-docs', ['specUrl' => route('marketing.api.docs.spec', absolute: false)]);
    }
}
