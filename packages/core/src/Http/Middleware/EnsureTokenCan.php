<?php

namespace Gerenuk\Marketing\Http\Middleware;

use Closure;
use Gerenuk\Marketing\Enums\Permission;
use Gerenuk\Marketing\Models\ApiToken;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTokenCan
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $token = $request->attributes->get('marketing_api_token');

        if (! $token instanceof ApiToken || ! $token->grants(Permission::from($permission))) {
            return response()->json(['message' => "This token does not have the {$permission} permission."], 403);
        }

        return $next($request);
    }
}
