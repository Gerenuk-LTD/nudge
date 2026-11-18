<?php

namespace Gerenuk\Marketing\Http\Middleware;

use Closure;
use Gerenuk\Marketing\Enums\Permission;
use Gerenuk\Marketing\Http\ApiError;
use Gerenuk\Marketing\Http\ApiErrorCode;
use Gerenuk\Marketing\Models\ApiToken;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTokenCan
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $token = $request->attributes->get('marketing_api_token');

        if (! $token instanceof ApiToken || ! $token->grants(Permission::from($permission))) {
            return ApiError::response(ApiErrorCode::MissingPermission, "This token does not have the {$permission} permission.", 403);
        }

        return $next($request);
    }
}
